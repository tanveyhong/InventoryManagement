<?php
/**
 * Ultra-Fast Database Cache Layer
 * Aggressive caching strategy to minimize all database calls
 */

class DatabaseCache {
    private static $memoryCache = [];
    private static $cacheStats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0
    ];
    
    /**
     * Get cache statistics
     */
    public static function getStats() {
        $total = self::$cacheStats['hits'] + self::$cacheStats['misses'];
        $hitRate = $total > 0 ? round((self::$cacheStats['hits'] / $total) * 100, 1) : 0;
        return array_merge(self::$cacheStats, ['hit_rate' => $hitRate . '%']);
    }
    
    /**
     * Multi-level cache get
     * 1. Memory cache (fastest)
     * 2. Session cache (fast)
     * 3. File cache (medium)
     * 4. Database (slowest - only if cache miss)
     */
    public static function get($table, $id, $callback, $ttl = 3600) {
        $cacheKey = "{$table}:{$id}";
        
        // Level 1: Memory cache (in-process, fastest)
        if (isset(self::$memoryCache[$cacheKey])) {
            self::$cacheStats['hits']++;
            return self::$memoryCache[$cacheKey];
        }
        
        // Level 2: Session cache (per-session)
        if (!isset($_SESSION['_db_cache'])) {
            $_SESSION['_db_cache'] = [];
        }
        
        if (isset($_SESSION['_db_cache'][$cacheKey])) {
            $cached = $_SESSION['_db_cache'][$cacheKey];
            if ($cached['expires'] > time()) {
                self::$memoryCache[$cacheKey] = $cached['data'];
                self::$cacheStats['hits']++;
                return $cached['data'];
            } else {
                // Expired
                unset($_SESSION['_db_cache'][$cacheKey]);
            }
        }
        
        // Level 3: File cache (persistent across sessions)
        $fileCache = self::getFromFileCache($cacheKey, $ttl);
        if ($fileCache !== null) {
            // Promote to higher cache levels
            self::$memoryCache[$cacheKey] = $fileCache;
            $_SESSION['_db_cache'][$cacheKey] = [
                'data' => $fileCache,
                'expires' => time() + $ttl
            ];
            self::$cacheStats['hits']++;
            return $fileCache;
        }
        
        // Level 4: Cache miss - fetch from database
        self::$cacheStats['misses']++;
        $data = $callback();
        
        if ($data !== null) {
            self::set($table, $id, $data, $ttl);
        }
        
        return $data;
    }
    
    /**
     * Store in all cache levels
     */
    public static function set($table, $id, $data, $ttl = 3600) {
        $cacheKey = "{$table}:{$id}";
        self::$cacheStats['writes']++;
        
        // Memory cache
        self::$memoryCache[$cacheKey] = $data;
        
        // Session cache
        if (!isset($_SESSION['_db_cache'])) {
            $_SESSION['_db_cache'] = [];
        }
        $_SESSION['_db_cache'][$cacheKey] = [
            'data' => $data,
            'expires' => time() + $ttl
        ];
        
        // File cache
        self::setFileCache($cacheKey, $data, $ttl);
    }
    
    /**
     * File cache operations
     */
    private static function getFromFileCache($key, $ttl) {
        $cacheDir = __DIR__ . '/../storage/cache/db/';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $file = $cacheDir . md5($key) . '.cache';
        
        if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
            $content = @file_get_contents($file);
            if ($content !== false) {
                $data = @unserialize($content);
                if ($data !== false) {
                    return $data;
                }
            }
        }
        
        return null;
    }
    
    private static function setFileCache($key, $data, $ttl) {
        $cacheDir = __DIR__ . '/../storage/cache/db/';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $file = $cacheDir . md5($key) . '.cache';
        @file_put_contents($file, serialize($data), LOCK_EX);
    }
    
    /**
     * Batch get multiple records at once
     */
    public static function getBatch($table, array $ids, $callback, $ttl = 3600) {
        $results = [];
        $uncachedIds = [];
        
        foreach ($ids as $id) {
            $cached = self::get($table, $id, function() { return null; }, $ttl);
            if ($cached !== null) {
                $results[$id] = $cached;
            } else {
                $uncachedIds[] = $id;
            }
        }
        
        // Fetch uncached items in batch
        if (!empty($uncachedIds)) {
            $fetchedData = $callback($uncachedIds);
            foreach ($fetchedData as $id => $data) {
                self::set($table, $id, $data, $ttl);
                $results[$id] = $data;
            }
        }
        
        return $results;
    }
    
    /**
     * Clear specific cache
     */
    public static function invalidate($table, $id = null) {
        if ($id === null) {
            // Clear all for table
            foreach (self::$memoryCache as $key => $value) {
                if (strpos($key, $table . ':') === 0) {
                    unset(self::$memoryCache[$key]);
                }
            }
            
            if (isset($_SESSION['_db_cache'])) {
                foreach ($_SESSION['_db_cache'] as $key => $value) {
                    if (strpos($key, $table . ':') === 0) {
                        unset($_SESSION['_db_cache'][$key]);
                    }
                }
            }
            
            // Clear file cache for table
            $cacheDir = __DIR__ . '/../storage/cache/db/';
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '*.cache') as $file) {
                    @unlink($file);
                }
            }
        } else {
            // Clear specific record
            $cacheKey = "{$table}:{$id}";
            unset(self::$memoryCache[$cacheKey]);
            if (isset($_SESSION['_db_cache'][$cacheKey])) {
                unset($_SESSION['_db_cache'][$cacheKey]);
            }
            $file = __DIR__ . '/../storage/cache/db/' . md5($cacheKey) . '.cache';
            if (file_exists($file)) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Clear all caches
     */
    public static function clearAll() {
        self::$memoryCache = [];
        if (isset($_SESSION['_db_cache'])) {
            $_SESSION['_db_cache'] = [];
        }
        
        $cacheDir = __DIR__ . '/../storage/cache/db/';
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*.cache') as $file) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Cache list queries (e.g., all users, all stores)
     */
    public static function getList($table, $callback, $ttl = 300) {
        $cacheKey = "{$table}:_list";
        
        // Check memory cache
        if (isset(self::$memoryCache[$cacheKey])) {
            self::$cacheStats['hits']++;
            return self::$memoryCache[$cacheKey];
        }
        
        // Check session cache
        if (!isset($_SESSION['_db_cache'])) {
            $_SESSION['_db_cache'] = [];
        }
        
        if (isset($_SESSION['_db_cache'][$cacheKey])) {
            $cached = $_SESSION['_db_cache'][$cacheKey];
            if ($cached['expires'] > time()) {
                self::$memoryCache[$cacheKey] = $cached['data'];
                self::$cacheStats['hits']++;
                return $cached['data'];
            }
        }
        
        // Cache miss - fetch from database
        self::$cacheStats['misses']++;
        $data = $callback();
        
        // Store in caches
        self::$memoryCache[$cacheKey] = $data;
        $_SESSION['_db_cache'][$cacheKey] = [
            'data' => $data,
            'expires' => time() + $ttl
        ];
        
        return $data;
    }
    
    /**
     * Cache query results by custom key
     */
    public static function getQuery($queryKey, $callback, $ttl = 300) {
        $cacheKey = "query:{$queryKey}";
        
        // Check memory cache
        if (isset(self::$memoryCache[$cacheKey])) {
            self::$cacheStats['hits']++;
            return self::$memoryCache[$cacheKey];
        }
        
        // Check session cache
        if (!isset($_SESSION['_db_cache'])) {
            $_SESSION['_db_cache'] = [];
        }
        
        if (isset($_SESSION['_db_cache'][$cacheKey])) {
            $cached = $_SESSION['_db_cache'][$cacheKey];
            if ($cached['expires'] > time()) {
                self::$memoryCache[$cacheKey] = $cached['data'];
                self::$cacheStats['hits']++;
                return $cached['data'];
            }
        }
        
        // Cache miss
        self::$cacheStats['misses']++;
        $data = $callback();
        
        // Store
        self::$memoryCache[$cacheKey] = $data;
        $_SESSION['_db_cache'][$cacheKey] = [
            'data' => $data,
            'expires' => time() + $ttl
        ];
        
        return $data;
    }
}

/**
 * Optimized database wrapper functions
 */

// Cached getUserInfo
function getUserInfoCached($userId, $ttl = 3600) {
    return DatabaseCache::get('users', $userId, function() use ($userId) {
        $db = getDB();
        return $db->read('users', $userId);
    }, $ttl);
}

// Cached batch user info
function getUserInfoBatch(array $userIds, $ttl = 3600) {
    return DatabaseCache::getBatch('users', $userIds, function($ids) {
        $db = getDB();
        $results = [];
        foreach ($ids as $id) {
            $results[$id] = $db->read('users', $id);
        }
        return $results;
    }, $ttl);
}

// Cached list queries
function getAllUsersCached($ttl = 300) {
    return DatabaseCache::getList('users', function() {
        $db = getDB();
        // LIMIT users to prevent excessive Firebase reads
        return $db->readAll('users', [], null, 200);
    }, $ttl);
}

function getAllStoresCached($ttl = 300) {
    return DatabaseCache::getList('stores', function() {
        $db = getDB();
        // LIMIT stores to prevent excessive Firebase reads
        return $db->readAll('stores', [], null, 200);
    }, $ttl);
}

function getAllProductsCached($ttl = 300) {
    return DatabaseCache::getList('products', function() {
        $db = getDB();
        // LIMIT products to prevent excessive Firebase reads
        // Consider adding pagination for pages that need all products
        return $db->readAll('products', [], null, 500);
    }, $ttl);
}

// Invalidate cache after updates
function invalidateUserCache($userId) {
    DatabaseCache::invalidate('users', $userId);
    DatabaseCache::invalidate('users'); // Also clear list cache
    // Clear permission cache too
    if (isset($_SESSION['_cache'])) {
        unset($_SESSION['_cache']["user_info_{$userId}"]);
        unset($_SESSION['_cache']["user_permissions_{$userId}"]);
        unset($_SESSION['_cache']["nav_permissions_{$userId}"]);
        unset($_SESSION['_cache']["perm_indicator_{$userId}"]);
    }
}

function invalidateStoreCache($storeId = null) {
    DatabaseCache::invalidate('stores', $storeId);
}

function invalidateProductCache($productId = null) {
    DatabaseCache::invalidate('products', $productId);
}
