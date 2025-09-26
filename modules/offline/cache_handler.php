<?php
// Offline Cache Handler
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

class OfflineCacheHandler {
    private $cache_dir;
    private $db;
    
    public function __construct() {
        $this->cache_dir = '../../storage/cache/offline/';
        $this->db = getDB();
        
        // Create cache directory if it doesn't exist
        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }
    
    /**
     * Cache essential data for offline use
     */
    public function cacheEssentialData() {
        $data = [
            'products' => $this->getProducts(),
            'stores' => $this->getStores(),
            'categories' => $this->getCategories(),
            'suppliers' => $this->getSuppliers(),
            'users' => $this->getUsers(),
            'settings' => $this->getSettings()
        ];
        
        return $this->writeCache('essential_data', $data);
    }
    
    /**
     * Cache product data with inventory levels
     */
    public function cacheProductData() {
        $products = $this->db->fetchAll("
            SELECT p.*, c.name as category_name, s.name as store_name, 
                   sup.name as supplier_name
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN stores s ON p.store_id = s.id
            LEFT JOIN suppliers sup ON p.supplier_id = sup.id
            WHERE p.active = 1
            ORDER BY p.name
        ");
        
        return $this->writeCache('products', $products);
    }
    
    /**
     * Cache user preferences and settings
     */
    public function cacheUserData($user_id) {
        $user_data = [
            'user_info' => getUserInfo($user_id),
            'permissions' => $this->getUserPermissions($user_id),
            'preferences' => $this->getUserPreferences($user_id)
        ];
        
        return $this->writeCache("user_data_{$user_id}", $user_data);
    }
    
    /**
     * Get cached data
     */
    public function getCachedData($key) {
        $cache_file = $this->cache_dir . $key . '.json';
        
        if (!file_exists($cache_file)) {
            return null;
        }
        
        $cache_data = json_decode(file_get_contents($cache_file), true);
        
        // Check if cache is still valid
        if (isset($cache_data['expires']) && $cache_data['expires'] < time()) {
            unlink($cache_file);
            return null;
        }
        
        return $cache_data['data'] ?? null;
    }
    
    /**
     * Store pending changes for offline sync
     */
    public function storePendingChange($type, $operation, $data, $local_id = null) {
        $pending_file = $this->cache_dir . 'pending_changes.json';
        $pending_changes = [];
        
        if (file_exists($pending_file)) {
            $pending_changes = json_decode(file_get_contents($pending_file), true) ?? [];
        }
        
        $change = [
            'id' => uniqid(),
            'type' => $type,
            'operation' => $operation,
            'data' => $data,
            'local_id' => $local_id,
            'timestamp' => time(),
            'synced' => false
        ];
        
        $pending_changes[] = $change;
        
        return file_put_contents($pending_file, json_encode($pending_changes, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Get pending changes for sync
     */
    public function getPendingChanges() {
        $pending_file = $this->cache_dir . 'pending_changes.json';
        
        if (!file_exists($pending_file)) {
            return [];
        }
        
        $pending_changes = json_decode(file_get_contents($pending_file), true) ?? [];
        
        // Filter out already synced changes
        return array_filter($pending_changes, function($change) {
            return !$change['synced'];
        });
    }
    
    /**
     * Mark changes as synced
     */
    public function markChangesSynced($change_ids) {
        $pending_file = $this->cache_dir . 'pending_changes.json';
        
        if (!file_exists($pending_file)) {
            return false;
        }
        
        $pending_changes = json_decode(file_get_contents($pending_file), true) ?? [];
        
        foreach ($pending_changes as &$change) {
            if (in_array($change['id'], $change_ids)) {
                $change['synced'] = true;
                $change['synced_at'] = time();
            }
        }
        
        return file_put_contents($pending_file, json_encode($pending_changes, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Clear old cache files
     */
    public function cleanupCache($max_age_hours = 24) {
        $max_age = time() - ($max_age_hours * 3600);
        $files = glob($this->cache_dir . '*.json');
        
        foreach ($files as $file) {
            if (filemtime($file) < $max_age) {
                unlink($file);
            }
        }
    }
    
    /**
     * Get cache statistics
     */
    public function getCacheStats() {
        $files = glob($this->cache_dir . '*.json');
        $total_size = 0;
        $file_count = count($files);
        
        foreach ($files as $file) {
            $total_size += filesize($file);
        }
        
        return [
            'file_count' => $file_count,
            'total_size' => $total_size,
            'total_size_formatted' => $this->formatBytes($total_size),
            'cache_dir' => $this->cache_dir
        ];
    }
    
    /**
     * Export offline data package
     */
    public function exportOfflinePackage($user_id) {
        $package = [
            'timestamp' => time(),
            'user_id' => $user_id,
            'data' => [
                'products' => $this->getProducts(),
                'stores' => $this->getStores(),
                'categories' => $this->getCategories(),
                'suppliers' => $this->getSuppliers(),
                'low_stock_threshold' => LOW_STOCK_THRESHOLD
            ],
            'user_data' => [
                'info' => getUserInfo($user_id),
                'permissions' => $this->getUserPermissions($user_id)
            ]
        ];
        
        $filename = 'offline_package_' . $user_id . '_' . date('Y-m-d_H-i-s') . '.json';
        $filepath = $this->cache_dir . $filename;
        
        if (file_put_contents($filepath, json_encode($package, JSON_PRETTY_PRINT))) {
            return $filename;
        }
        
        return false;
    }
    
    // Private helper methods
    
    private function writeCache($key, $data, $ttl = null) {
        if (!$ttl) {
            $ttl = CACHE_TTL;
        }
        
        $cache_data = [
            'data' => $data,
            'created' => time(),
            'expires' => time() + $ttl
        ];
        
        $cache_file = $this->cache_dir . $key . '.json';
        return file_put_contents($cache_file, json_encode($cache_data, JSON_PRETTY_PRINT)) !== false;
    }
    
    private function getProducts() {
        return $this->db->fetchAll("SELECT * FROM products WHERE active = 1 ORDER BY name");
    }
    
    private function getStores() {
        return $this->db->fetchAll("SELECT * FROM stores WHERE active = 1 ORDER BY name");
    }
    
    private function getCategories() {
        return $this->db->fetchAll("SELECT * FROM categories WHERE active = 1 ORDER BY name");
    }
    
    private function getSuppliers() {
        return $this->db->fetchAll("SELECT * FROM suppliers WHERE active = 1 ORDER BY name");
    }
    
    private function getUsers() {
        return $this->db->fetchAll("SELECT id, username, first_name, last_name, email, role FROM users WHERE active = 1");
    }
    
    private function getSettings() {
        return [
            'low_stock_threshold' => LOW_STOCK_THRESHOLD,
            'expiry_alert_days' => EXPIRY_ALERT_DAYS,
            'app_name' => APP_NAME,
            'timezone' => APP_TIMEZONE
        ];
    }
    
    private function getUserPermissions($user_id) {
        // In a real application, you would fetch user permissions from database
        // For now, return basic permissions based on role
        $user = getUserInfo($user_id);
        $role = $user['role'] ?? 'user';
        
        $permissions = [
            'can_add_products' => true,
            'can_edit_products' => true,
            'can_delete_products' => $role === 'admin',
            'can_manage_stores' => $role === 'admin',
            'can_view_reports' => true,
            'can_export_data' => true
        ];
        
        return $permissions;
    }
    
    private function getUserPreferences($user_id) {
        // Fetch user preferences - for now return defaults
        return [
            'default_store' => null,
            'items_per_page' => 20,
            'notifications_enabled' => true,
            'auto_sync' => true
        ];
    }
    
    private function formatBytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

// API endpoint for cache operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    session_start();
    
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    header('Content-Type: application/json');
    
    $cache_handler = new OfflineCacheHandler();
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $response = ['success' => false, 'message' => '', 'data' => null];
    
    try {
        switch ($action) {
            case 'cache_essential':
                $result = $cache_handler->cacheEssentialData();
                $response['success'] = $result;
                $response['message'] = $result ? 'Essential data cached successfully' : 'Failed to cache data';
                break;
                
            case 'cache_products':
                $result = $cache_handler->cacheProductData();
                $response['success'] = $result;
                $response['message'] = $result ? 'Product data cached successfully' : 'Failed to cache products';
                break;
                
            case 'get_cached':
                $key = $_GET['key'] ?? '';
                $data = $cache_handler->getCachedData($key);
                $response['success'] = $data !== null;
                $response['data'] = $data;
                $response['message'] = $data ? 'Data retrieved from cache' : 'No cached data found';
                break;
                
            case 'store_pending':
                $type = $_POST['type'] ?? '';
                $operation = $_POST['operation'] ?? '';
                $data = json_decode($_POST['data'] ?? '{}', true);
                $local_id = $_POST['local_id'] ?? null;
                
                $result = $cache_handler->storePendingChange($type, $operation, $data, $local_id);
                $response['success'] = $result;
                $response['message'] = $result ? 'Change stored for sync' : 'Failed to store change';
                break;
                
            case 'get_pending':
                $changes = $cache_handler->getPendingChanges();
                $response['success'] = true;
                $response['data'] = $changes;
                $response['message'] = count($changes) . ' pending changes found';
                break;
                
            case 'cleanup':
                $cache_handler->cleanupCache();
                $response['success'] = true;
                $response['message'] = 'Cache cleanup completed';
                break;
                
            case 'stats':
                $stats = $cache_handler->getCacheStats();
                $response['success'] = true;
                $response['data'] = $stats;
                break;
                
            case 'export_package':
                $filename = $cache_handler->exportOfflinePackage($_SESSION['user_id']);
                $response['success'] = $filename !== false;
                $response['data'] = ['filename' => $filename];
                $response['message'] = $filename ? 'Offline package created' : 'Failed to create package';
                break;
                
            default:
                $response['message'] = 'Invalid action';
        }
        
    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = 'Cache error: ' . $e->getMessage();
        error_log("Cache Error: " . $e->getMessage(), 3, ERROR_LOG_PATH);
    }
    
    echo json_encode($response);
}
?>