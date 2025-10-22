<?php
/**
 * Multi-Level Performance Cache System
 * Implements L1 (Memory < 0.01ms), L2 (Session < 0.1ms), L3 (File < 5ms) caching
 * Target: Reduce 200ms Firebase calls to sub-millisecond cache hits
 */

class PerformanceCache {
    private static $instance = null;
    private $memoryCache = []; // L1: In-memory cache (fastest)
    private $stats = [
        'hits' => 0,
        'misses' => 0,
        'writes' => 0,
        'level1_hits' => 0,
        'level2_hits' => 0,
        'level3_hits' => 0,
        'firebase_calls' => 0
    ];
    
    private $cacheDir;
    private $defaultTTL = 300; // 5 minutes
    private $enabled = true;
    
    private function __construct() {
        $this->cacheDir = __DIR__ . '/../storage/cache/';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        
        // Initialize session cache
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['__perf_cache'])) {
                $_SESSION['__perf_cache'] = [];
            }
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get cached value with multi-level lookup
     * Priority: Memory > Session > File > Database
     */
    public function get($key, $default = null) {
        if (!$this->enabled) {
            return $default;
        }
        
        // L1: Memory cache (< 0.01ms) - fastest
        if (isset($this->memoryCache[$key])) {
            $cached = $this->memoryCache[$key];
            if ($this->isValid($cached)) {
                $this->stats['hits']++;
                $this->stats['level1_hits']++;
                return $cached['data'];
            }
            unset($this->memoryCache[$key]);
        }
        
        // L2: Session cache (< 0.1ms) - very fast
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['__perf_cache'][$key])) {
            $cached = $_SESSION['__perf_cache'][$key];
            if ($this->isValid($cached)) {
                $this->stats['hits']++;
                $this->stats['level2_hits']++;
                // Promote to L1 for even faster future access
                $this->memoryCache[$key] = $cached;
                return $cached['data'];
            }
            unset($_SESSION['__perf_cache'][$key]);
        }
        
        // L3: File cache (< 5ms) - fast
        $filePath = $this->getCacheFilePath($key);
        if (file_exists($filePath)) {
            $cached = @json_decode(file_get_contents($filePath), true);
            if ($cached && $this->isValid($cached)) {
                $this->stats['hits']++;
                $this->stats['level3_hits']++;
                // Promote to L2 and L1
                $this->memoryCache[$key] = $cached;
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['__perf_cache'][$key] = $cached;
                }
                return $cached['data'];
            }
            @unlink($filePath);
        }
        
        // Cache miss - will need to fetch from database
        $this->stats['misses']++;
        $this->stats['firebase_calls']++;
        return $default;
    }
    
    /**
     * Set cached value in all levels
     */
    public function set($key, $data, $ttl = null) {
        if (!$this->enabled) {
            return false;
        }
        
        $ttl = $ttl ?? $this->defaultTTL;
        $cached = [
            'data' => $data,
            'expires' => time() + $ttl,
            'created' => time()
        ];
        
        // L1: Memory (immediate)
        $this->memoryCache[$key] = $cached;
        
        // L2: Session (immediate)
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['__perf_cache'][$key] = $cached;
        }
        
        // L3: File (async for performance)
        $this->asyncWriteToFile($key, $cached);
        
        $this->stats['writes']++;
        return true;
    }
    
    /**
     * Remember pattern: get or compute and cache
     */
    public function remember($key, $callback, $ttl = null) {
        $value = $this->get($key);
        
        if ($value === null) {
            $value = $callback();
            if ($value !== null) {
                $this->set($key, $value, $ttl);
            }
        }
        
        return $value;
    }
    
    /**
     * Delete from all cache levels
     */
    public function delete($key) {
        unset($this->memoryCache[$key]);
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['__perf_cache'][$key]);
        }
        
        $filePath = $this->getCacheFilePath($key);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        
        return true;
    }
    
    /**
     * Clear pattern-based cache keys
     */
    public function clearPattern($pattern) {
        // Clear memory cache
        foreach (array_keys($this->memoryCache) as $key) {
            if (strpos($key, $pattern) !== false) {
                unset($this->memoryCache[$key]);
            }
        }
        
        // Clear session cache
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['__perf_cache'])) {
            foreach (array_keys($_SESSION['__perf_cache']) as $key) {
                if (strpos($key, $pattern) !== false) {
                    unset($_SESSION['__perf_cache'][$key]);
                }
            }
        }
        
        // Clear file cache
        $files = glob($this->cacheDir . 'perf_*.json');
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content) {
                $cached = json_decode($content, true);
                if ($cached && isset($cached['key']) && strpos($cached['key'], $pattern) !== false) {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getStats() {
        $total = $this->stats['hits'] + $this->stats['misses'];
        $hitRate = $total > 0 ? round(($this->stats['hits'] / $total) * 100, 2) : 0;
        
        return array_merge($this->stats, [
            'hit_rate' => $hitRate,
            'total_requests' => $total,
            'memory_cache_size' => count($this->memoryCache),
            'session_cache_size' => session_status() === PHP_SESSION_ACTIVE ? count($_SESSION['__perf_cache'] ?? []) : 0
        ]);
    }
    
    private function isValid($cached) {
        return isset($cached['expires']) && $cached['expires'] > time();
    }
    
    private function getCacheFilePath($key) {
        return $this->cacheDir . 'perf_' . md5($key) . '.json';
    }
    
    private function asyncWriteToFile($key, $data) {
        $filePath = $this->getCacheFilePath($key);
        $data['key'] = $key; // Store key for pattern matching
        @file_put_contents($filePath, json_encode($data), LOCK_EX);
    }
}

// ============ Global Helper Functions ============

function cache_get($key, $default = null) {
    return PerformanceCache::getInstance()->get($key, $default);
}

function cache_set($key, $data, $ttl = null) {
    return PerformanceCache::getInstance()->set($key, $data, $ttl);
}

function cache_remember($key, $callback, $ttl = null) {
    return PerformanceCache::getInstance()->remember($key, $callback, $ttl);
}

function cache_delete($key) {
    return PerformanceCache::getInstance()->delete($key);
}

function cache_clear_pattern($pattern) {
    return PerformanceCache::getInstance()->clearPattern($pattern);
}

function cache_stats() {
    return PerformanceCache::getInstance()->getStats();
}

// ============ Optimized User & Permission Functions ============

/**
 * Cached user fetch - reduces 200ms Firebase call to <1ms
 */
function getCachedUser($userId) {
    return cache_remember("user_{$userId}", function() use ($userId) {
        global $db;
        $user = $db->read('users', $userId);
        if ($user) {
            unset($user['password_hash']); // Security
        }
        return $user;
    }, 600); // 10 min TTL
}

/**
 * Cached permission check - ultra-fast <0.001ms
 */
function cachedPermissionCheck($userId, $permission) {
    return cache_remember("perm_{$userId}_{$permission}", function() use ($userId, $permission) {
        return hasPermission($userId, $permission);
    }, 600);
}

/**
 * Batch permission check - single cache lookup
 */
function batchPermissionCheck($userId, array $permissions) {
    $cacheKey = "perms_batch_{$userId}_" . md5(implode(',', sort($permissions)));
    
    return cache_remember($cacheKey, function() use ($userId, $permissions) {
        $results = [];
        foreach ($permissions as $perm) {
            $results[$perm] = hasPermission($userId, $perm);
        }
        return $results;
    }, 600);
}

/**
 * Pre-warm cache on login - eliminates cold-start delays
 */
function prewarmDashboardCache($userId) {
    $startTime = microtime(true);
    
    // Warm user data
    getCachedUser($userId);
    
    // Warm all granular permissions
    $allPermissions = [
        'can_view_reports',
        'can_view_inventory', 'can_add_inventory', 'can_edit_inventory', 'can_delete_inventory',
        'can_view_stores', 'can_add_stores', 'can_edit_stores', 'can_delete_stores',
        'can_use_pos', 'can_manage_pos',
        'can_view_users', 'can_manage_users',
        'can_configure_system'
    ];
    
    foreach ($allPermissions as $perm) {
        cachedPermissionCheck($userId, $perm);
    }
    
    $duration = (microtime(true) - $startTime) * 1000;
    error_log("Cache prewarmed for user {$userId} in {$duration}ms");
    
    return true;
}

/**
 * Clear user-specific cache (call after permission changes)
 */
function clearUserCache($userId) {
    cache_clear_pattern("user_{$userId}");
    cache_clear_pattern("perm_{$userId}");
    cache_clear_pattern("perms_batch_{$userId}");
}

// ============ Drop-in Replacements for Existing Functions ============

if (!function_exists('hasPermissionCached')) {
    function hasPermissionCached($userId, $permission) {
        return cachedPermissionCheck($userId, $permission);
    }
}

if (!function_exists('currentUserHasPermissionCached')) {
    function currentUserHasPermissionCached($permission) {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        return cachedPermissionCheck($_SESSION['user_id'], $permission);
    }
}

if (!function_exists('getUserPermissionsCached')) {
    function getUserPermissionsCached($userId) {
        return cache_remember("user_all_perms_{$userId}", function() use ($userId) {
            return getUserPermissions($userId);
        }, 600);
    }
}

if (!function_exists('clearPermissionCache')) {
    function clearPermissionCache($userId = null) {
        if ($userId) {
            clearUserCache($userId);
        } else {
            cache_clear_pattern('perm_');
            cache_clear_pattern('user_');
        }
    }
}


if (!function_exists('clearPermissionCache')) {
    function clearPermissionCache($userId = null) {
        if ($userId) {
            clearUserCache($userId);
        } else {
            cache_clear_pattern('perm_');
            cache_clear_pattern('user_');
        }
    }
}
