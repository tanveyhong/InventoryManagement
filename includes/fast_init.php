<?php
/**
 * Fast Startup Configuration
 * Optimized loading order for maximum performance
 */

// Start output buffering with compression immediately
if (!ob_get_level()) {
    ob_start('ob_gzhandler');
}

// Performance timing
$GLOBALS['_perf_start'] = microtime(true);
$GLOBALS['_firebase_calls'] = 0;
$GLOBALS['_cache_hits'] = 0;
$GLOBALS['_cache_misses'] = 0;

// Start session with optimized settings
if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings (but don't change serializer - causes decode errors)
    ini_set('session.gc_maxlifetime', 7200); // 2 hours
    ini_set('session.cookie_lifetime', 7200);
    
    session_start();
}

// Determine base directory (parent of includes folder)
$baseDir = dirname(__DIR__);

// Load core files in optimal order
require_once $baseDir . '/config.php';
require_once $baseDir . '/db.php';

// Load cache layer BEFORE functions.php so functions can use it
require_once __DIR__ . '/database_cache.php';

// Now load functions (will auto-use cache)
require_once $baseDir . '/functions.php';

// Load performance cache for permissions
require_once __DIR__ . '/performance_cache.php';

// Initialize cache arrays if not exists
if (!isset($_SESSION['_cache'])) {
    $_SESSION['_cache'] = [];
}

if (!isset($_SESSION['_db_cache'])) {
    $_SESSION['_db_cache'] = [];
}

/**
 * Ultra-fast permission check (uses all cache layers)
 */
function quickPermissionCheck($permission) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $userId = $_SESSION['user_id'];
    $cacheKey = "quick_perm_{$userId}_{$permission}";
    
    // Memory cache (fastest)
    static $permCache = [];
    if (isset($permCache[$cacheKey])) {
        return $permCache[$cacheKey];
    }
    
    // Session cache
    if (isset($_SESSION['_cache'][$cacheKey])) {
        $permCache[$cacheKey] = $_SESSION['_cache'][$cacheKey];
        return $_SESSION['_cache'][$cacheKey];
    }
    
    // Use cached permission check (performance_cache.php)
    $result = hasPermissionCached($userId, $permission);
    
    // Cache the result in memory and session
    $permCache[$cacheKey] = $result;
    $_SESSION['_cache'][$cacheKey] = $result;
    
    return $result;
}

/**
 * Preload common data for current user
 */
function preloadUserData() {
    if (!isset($_SESSION['user_id'])) {
        return;
    }
    
    $userId = $_SESSION['user_id'];
    
    // Preload in background if not cached
    if (!isset($_SESSION['_preloaded'])) {
        // Get user info (will cache)
        getUserInfoCached($userId);
        
        // Get permissions (will cache)
        getUserPermissions($userId);
        
        $_SESSION['_preloaded'] = true;
    }
}

// Auto-preload on every request (async, non-blocking)
register_shutdown_function(function() {
    // Preload for next request
    preloadUserData();
});

/**
 * Clean up old cache entries (run periodically)
 */
function cleanupCache() {
    // Only run 1% of requests
    if (rand(1, 100) === 1) {
        $now = time();
        
        // Clean session cache
        if (isset($_SESSION['_db_cache'])) {
            foreach ($_SESSION['_db_cache'] as $key => $value) {
                if (isset($value['expires']) && $value['expires'] < $now) {
                    unset($_SESSION['_db_cache'][$key]);
                }
            }
        }
        
        // Clean old file cache (older than 1 hour)
        $baseDir = dirname(__DIR__);
        $cacheDir = $baseDir . '/storage/cache/db/';
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . '*.cache') as $file) {
                if (filemtime($file) < ($now - 3600)) {
                    @unlink($file);
                }
            }
        }
    }
}

register_shutdown_function('cleanupCache');

/**
 * Get performance stats
 */
function getPerformanceStats() {
    $duration = round((microtime(true) - $GLOBALS['_perf_start']) * 1000, 2);
    $cacheStats = DatabaseCache::getStats();
    
    return [
        'load_time' => $duration . 'ms',
        'firebase_calls' => $GLOBALS['_firebase_calls'],
        'cache_hits' => $cacheStats['hits'],
        'cache_misses' => $cacheStats['misses'],
        'hit_rate' => $cacheStats['hit_rate'],
        'memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB'
    ];
}
