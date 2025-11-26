<?php

// Common Helper Functions
require_once 'config.php';
require_once 'db.php';
require_once 'getDB.php';
require_once 'activity_logger.php';

// Authentication Functions
function isLoggedIn()
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getUserInfo($user_id)
{
    // Use cached version if available
    if (file_exists(__DIR__ . '/includes/database_cache.php')) {
        return getUserInfoCached($user_id);
    }
    
    // Fallback to direct database call
    $db = getDB();
    return $db->read('users', $user_id);
}

function hashPassword($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash)
{
    // Handle null or empty hash values
    if (empty($hash)) {
        return false;
    }
    // If this looks like a modern password_hash output (starts with $), use password_verify
    if (is_string($hash) && strlen($hash) > 0 && $hash[0] === '$') {
        return password_verify($password, $hash);
    }

    // Fallbacks for legacy hashing schemes
    // MD5 (32 hex chars)
    if (is_string($hash) && preg_match('/^[0-9a-f]{32}$/i', $hash)) {
        return hash_equals(strtolower($hash), strtolower(md5($password)));
    }

    // SHA1 (40 hex chars)
    if (is_string($hash) && preg_match('/^[0-9a-f]{40}$/i', $hash)) {
        return hash_equals(strtolower($hash), strtolower(sha1($password)));
    }

    // As a last resort, do a simple comparison (not recommended)
    return hash_equals((string)$hash, (string)$password);
}

function generateToken($length = 32)
{
    return bin2hex(random_bytes($length));
}

// Firebase User Functions
function createFirebaseUser($userData)
{
    $db = getDB();

    // Check if user already exists - use explicit limit to prevent excessive reads
    $existingUsers = $db->readAll('users', [
        ['username', '==', $userData['username']],
        ['email', '==', $userData['email']]
    ], null, 5); // Only need to check if any exist

    if (!empty($existingUsers)) {
        return false;
    }

    // Add timestamp
    $userData['created_at'] = date('c');
    $userData['updated_at'] = date('c');

    // Create user document
    return $db->create('users', $userData);
}

function findUserByUsernameOrEmail($usernameOrEmail)
{
    $db = getDB();
    // Always use Firebase-style queries
    // Try to find by username first
    $users = $db->readAll('users', [['username', '==', $usernameOrEmail]], null, 1);
    if (!empty($users)) {
        return $users[0];
    }
    // Try to find by email
    $users = $db->readAll('users', [['email', '==', $usernameOrEmail]], null, 1);
    if (!empty($users)) {
        return $users[0];
    }

    // Fallback: if Firebase doesn't have the user, try legacy SQL DB (for migrated setups)
    try {
        $sql_db = getSQLDB();
        $row = $sql_db->fetch("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1", [$usernameOrEmail, $usernameOrEmail]);
        if ($row) {
            // Map SQL row to Firebase-like structure
            return [
                'id' => $row['id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
                'password_hash' => $row['password_hash'] ?? '',
                'role' => $row['role'] ?? 'user',
                'is_active' => isset($row['active']) ? (bool)$row['active'] : true,
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null
            ];
        }
    } catch (Exception $e) {
        // ignore SQL errors
    }

    // If direct lookups failed above, try a scan-based normalized match as a last resort
    // (This is slower but helpful during migration; remove or optimize later)
    $scan = findUserByUsernameOrEmail_scan($usernameOrEmail);
    return $scan !== null ? $scan : null;
}

// Robust fallback: try scanning all user docs and perform normalized comparisons
function findUserByUsernameOrEmail_scan($usernameOrEmail)
{
    $db = getDB();
    $all = $db->readAll('users');
    if (!is_array($all)) return null;

    $needle = trim(strtolower($usernameOrEmail));
    foreach ($all as $u) {
        $uname = trim(strtolower($u['username'] ?? ''));
        $email = trim(strtolower($u['email'] ?? ''));
        if ($uname === $needle || $email === $needle) {
            return $u;
        }
    }
    return null;
}

function updateUserRememberToken($userId, $token, $expires)
{
    $db = getDB();
    return $db->update('users', $userId, [
        'remember_token' => $token,
        'remember_expires' => $expires,
        'updated_at' => date('c')
    ]);
}

function findUserByRememberToken($token)
{
    $db = getDB();
    $currentTime = date('c');

    $users = $db->readAll('users', [
        ['remember_token', '==', $token],
        ['remember_expires', '>', $currentTime],
        ['is_active', '==', true]
    ], null, 1);

    return !empty($users) ? $users[0] : null;
}

function clearUserRememberToken($userId)
{
    $db = getDB();
    return $db->update('users', $userId, [
        'remember_token' => null,
        'remember_expires' => null,
        'updated_at' => date('c')
    ]);
}

// Security Functions
function sanitizeInput($input)
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function preventCSRF()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF($token)
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Date and Time Functions
function formatDate($date, $format = 'Y-m-d H:i:s')
{
    if (empty($date)) return '—';
    $ts = @strtotime($date);
    if ($ts === false || $ts === -1) return $date;
    return date($format, $ts);
}

function timeAgo($datetime)
{
    if (empty($datetime)) return 'unknown';
    
    // Database stores in Local Time (Asia/Kuala_Lumpur), so we parse as is
    $ts = @strtotime($datetime);
    
    if ($ts === false || $ts === -1) {
        // Fallback for other formats
        $ts = @strtotime($datetime . ' UTC');
    }
    
    if ($ts === false || $ts === -1) return 'unknown';
    
    $time = time() - $ts;

    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time / 60) . ' min ago';
    if ($time < 86400) return floor($time / 3600) . ' hr ago';
    if ($time < 2592000) return floor($time / 86400) . ' days ago';

    return date('M j, Y', $ts);
}

// Dashboard Statistics Functions (without caching)
function getTotalProducts()
{
    try {
        $sql_db = getSQLDB();
        $db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
        $active_value = ($db_type === 'pgsql') ? 'TRUE' : '1';
        $result = $sql_db->fetch("SELECT COUNT(*) as count FROM products WHERE active = $active_value");
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error in getTotalProducts: " . $e->getMessage());
        return 0;
    }
}

function getLowStockCount()
{
    try {
        $sql_db = getSQLDB();
        $db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
        $active_value = ($db_type === 'pgsql') ? 'TRUE' : '1';
        $result = $sql_db->fetch("SELECT COUNT(*) as count FROM products WHERE quantity <= reorder_level AND active = $active_value");
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error in getLowStockCount: " . $e->getMessage());
        return 0;
    }
}

function getTotalStores()
{
    try {
        $sql_db = getSQLDB();
        $db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
        $active_value = ($db_type === 'pgsql') ? 'TRUE' : '1';
        $result = $sql_db->fetch("SELECT COUNT(*) as count FROM stores WHERE active = $active_value");
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error in getTotalStores: " . $e->getMessage());
        return 0;
    }
}

function getTodaysSales()
{
    try {
        $sql_db = getSQLDB();
        $today = date('Y-m-d');
        $result = $sql_db->fetch("SELECT COALESCE(SUM(CAST(total_amount AS DECIMAL)), 0) as total FROM sales WHERE DATE(created_at) = ?", [$today]);
        return floatval($result['total'] ?? 0);
    } catch (Exception $e) {
        error_log("Error in getTodaysSales: " . $e->getMessage());
        return 0;
    }
}

// Optimized: Get all dashboard stats in a single query to reduce database round trips
function getAllDashboardStats()
{
    try {
        $sql_db = getSQLDB();
        $today = date('Y-m-d');
        $db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
        
        // Boolean value differs between SQLite (1) and PostgreSQL (TRUE)
        $active_value = ($db_type === 'pgsql') ? 'TRUE' : '1';
        
        // Single optimized query for all stats
        $query = "
            SELECT 
                (SELECT COUNT(*) FROM products WHERE active = $active_value) as total_products,
                (SELECT COUNT(*) FROM products WHERE quantity <= reorder_level AND active = $active_value) as low_stock,
                (SELECT COUNT(*) FROM stores WHERE active = $active_value) as total_stores,
                COALESCE((SELECT SUM(CAST(total_amount AS DECIMAL)) FROM sales WHERE DATE(created_at) = ?), 0) as todays_sales
        ";
        
        $result = $sql_db->fetch($query, [$today]);
        
        return [
            'total_products' => intval($result['total_products'] ?? 0),
            'low_stock' => intval($result['low_stock'] ?? 0),
            'total_stores' => intval($result['total_stores'] ?? 0),
            'todays_sales' => floatval($result['todays_sales'] ?? 0),
            'notifications' => getNotifications()
        ];
    } catch (Exception $e) {
        error_log("Error in getAllDashboardStats: " . $e->getMessage());
        return [
            'total_products' => 0,
            'low_stock' => 0,
            'total_stores' => 0,
            'todays_sales' => 0,
            'notifications' => []
        ];
    }
}

// Get last 7 days sales data for chart
function getWeeklySalesData()
{
    try {
        $sql_db = getSQLDB();
        $db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
        
        // Get sales for last 7 days with day names (database-specific queries)
        if ($db_type === 'pgsql') {
            // PostgreSQL version
            $query = "
                SELECT 
                    DATE(created_at) as sale_date,
                    TO_CHAR(created_at, 'Dy') as day_name,
                    COALESCE(SUM(CAST(total_amount AS DECIMAL)), 0) as total_sales
                FROM sales 
                WHERE created_at >= CURRENT_DATE - INTERVAL '7 days'
                GROUP BY DATE(created_at), TO_CHAR(created_at, 'Dy')
                ORDER BY sale_date ASC
            ";
        } else {
            // SQLite version
            $query = "
                SELECT 
                    DATE(created_at) as sale_date,
                    CASE strftime('%w', created_at)
                        WHEN '0' THEN 'Sun'
                        WHEN '1' THEN 'Mon'
                        WHEN '2' THEN 'Tue'
                        WHEN '3' THEN 'Wed'
                        WHEN '4' THEN 'Thu'
                        WHEN '5' THEN 'Fri'
                        WHEN '6' THEN 'Sat'
                    END as day_name,
                    COALESCE(SUM(total_amount), 0) as total_sales
                FROM sales 
                WHERE created_at >= date('now', '-7 days')
                GROUP BY DATE(created_at)
                ORDER BY sale_date ASC
            ";
        }
        
        $results = $sql_db->fetchAll($query);
        
        // Fill in missing days with zero sales
        $sales_data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $day_name = date('D', strtotime("-$i days"));
            
            $found = false;
            foreach ($results as $row) {
                if ($row['sale_date'] === $date) {
                    $sales_data[] = [
                        'day' => $day_name,
                        'sales' => floatval($row['total_sales'])
                    ];
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $sales_data[] = [
                    'day' => $day_name,
                    'sales' => 0
                ];
            }
        }
        
        return $sales_data;
    } catch (Exception $e) {
        error_log("Error in getWeeklySalesData: " . $e->getMessage());
        // Return dummy data for 7 days
        return [
            ['day' => 'Mon', 'sales' => 0],
            ['day' => 'Tue', 'sales' => 0],
            ['day' => 'Wed', 'sales' => 0],
            ['day' => 'Thu', 'sales' => 0],
            ['day' => 'Fri', 'sales' => 0],
            ['day' => 'Sat', 'sales' => 0],
            ['day' => 'Sun', 'sales' => 0]
        ];
    }
}

// Stock Management Functions
function updateStock($product_id, $quantity, $operation = 'set')
{
    $db = getDB();

    // Get current product
    $product = $db->read('products', $product_id);
    if (!$product) {
        return false;
    }

    $current_quantity = $product['quantity'] ?? 0;

    // Calculate new quantity based on operation
    switch ($operation) {
        case 'add':
            $new_quantity = $current_quantity + $quantity;
            break;
        case 'subtract':
            $new_quantity = max(0, $current_quantity - $quantity);
            break;
        case 'set':
        default:
            $new_quantity = $quantity;
            break;
    }

    // Update product quantity
    return $db->update('products', $product_id, [
        'quantity' => $new_quantity,
        'updated_at' => date('c')
    ]);
}

function logStockMovement($product_id, $user_id, $quantity, $operation, $reason = '')
{
    $db = getDB();

    $movementData = [
        'product_id' => $product_id,
        'user_id' => $user_id,
        'quantity' => $quantity,
        'operation' => $operation,
        'reason' => $reason,
        'created_at' => date('c')
    ];

    return $db->create('stock_movements', $movementData);
}

// Alert Functions (simplified without Redis pub/sub)
function triggerLowStockAlert($product_id, $current_stock, $min_stock)
{
    $db = getDB();

    // Get product details
    $product = $db->read('products', $product_id);
    if (!$product) {
        return false;
    }

    // Create alert record
    $alertData = [
        'type' => 'low_stock',
        'title' => 'Low Stock Alert',
        'message' => "Product '{$product['name']}' is running low. Current stock: {$current_stock}, Minimum: {$min_stock}",
        'priority' => 'high',
        'product_id' => $product_id,
        'store_id' => $product['store_id'] ?? null,
        'metadata' => json_encode([
            'current_stock' => $current_stock,
            'min_stock' => $min_stock
        ]),
        'created_at' => date('c')
    ];

    return $db->create('alerts', $alertData);
}



// Notification Functions
function addNotification($message, $type = 'info')
{
    if (!isset($_SESSION['notifications'])) {
        $_SESSION['notifications'] = [];
    }

    $_SESSION['notifications'][] = [
        'message' => $message,
        'type' => $type,
        'timestamp' => time()
    ];
}

function getNotifications()
{
    $notifications = $_SESSION['notifications'] ?? [];
    $_SESSION['notifications'] = []; // Clear after getting
    return $notifications;
}

// File Upload Functions
function validateFileUpload($file, $allowed_types = null, $max_size = null)
{
    if (!$allowed_types) {
        $allowed_types = UPLOAD_ALLOWED_TYPES;
    }

    if (!$max_size) {
        $max_size = UPLOAD_MAX_SIZE;
    }

    $errors = [];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'File upload error occurred';
        return $errors;
    }

    if ($file['size'] > $max_size) {
        $errors[] = 'File size exceeds maximum allowed size';
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed_types)) {
        $errors[] = 'File type not allowed';
    }

    return $errors;
}

function generateUniqueFileName($original_name)
{
    $extension = pathinfo($original_name, PATHINFO_EXTENSION);
    return uniqid() . '_' . time() . '.' . $extension;
}

// Debug and Error Functions
function logError($message, $context = [])
{
    if (defined('LOG_ERRORS') && LOG_ERRORS && defined('ERROR_LOG_PATH')) {
        $log_message = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if (!empty($context)) {
            $log_message .= ' Context: ' . json_encode($context);
        }
        error_log($log_message . PHP_EOL, 3, ERROR_LOG_PATH);
    }
}

function debugDump($var, $label = '')
{
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo '<div style="background: #f0f0f0; border: 1px solid #ccc; padding: 10px; margin: 10px 0;">';
        if ($label) echo '<strong>' . $label . ':</strong><br>';
        echo '<pre>' . print_r($var, true) . '</pre>';
        echo '</div>';
    }
}

// Pagination Functions
function paginate($page, $per_page, $total_records)
{
    $page = max(1, intval($page));
    $per_page = max(1, intval($per_page));
    $total_pages = ceil($total_records / $per_page);
    $offset = ($page - 1) * $per_page;

    return [
        'current_page' => $page,
        'per_page' => $per_page,
        'total_records' => $total_records,
        'total_pages' => $total_pages,
        'offset' => $offset,
        'has_previous' => $page > 1,
        'has_next' => $page < $total_pages,
        'showing_start' => min($offset + 1, $total_records),
        'showing_end' => min($offset + $per_page, $total_records)
    ];
}


// ---------- Normalizer ----------
function _fs_normalize_product(string $docId, array $d): array
{
    // keep internal 'id' if it exists; store Firestore id as 'doc_id'
    $row = [
        'doc_id'        => $docId,                     // <- Firestore doc id (ALWAYS)
        'id'            => $d['id'] ?? $docId,         // keep internal id, else doc id as fallback
        'sku'           => $d['sku']           ?? '',
        'name'          => $d['name']          ?? '(no name)',
        'category'      => $d['category']      ?? 'General',
        'description'   => $d['description']   ?? '',
        'quantity'      => isset($d['quantity']) ? (int)$d['quantity'] : 0,
        'reorder_level' => isset($d['reorder_level']) ? (int)$d['reorder_level'] : 0,
        'price'         => isset($d['price']) ? (float)$d['price'] : 0.0,
        'store_id'      => $d['store_id']      ?? '',
        'created_at'    => $d['created_at']    ?? null,
        'updated_at'    => $d['updated_at']    ?? null,
        'expiry_date'   => null,
        'image_url'     => $d['image_url']     ?? '',
        'barcode'       => $d['barcode']       ?? '',
        'supplier'      => $d['supplier']      ?? '',
        'location'      => $d['location']      ?? '',
        'unit'          => $d['unit']          ?? '',
        'deleted_at'    => $d['deleted_at']    ?? null,
        'status_db'     => $d['status']        ?? null,
    ];
    return $row;
}

// Unwrap Firestore REST typed field
function _fs_unwrap($v)
{
    if (is_array($v)) {
        foreach (['stringValue', 'integerValue', 'doubleValue', 'booleanValue', 'timestampValue'] as $k) {
            if (array_key_exists($k, $v)) {
                if ($k === 'integerValue') return (int)$v[$k];
                if ($k === 'doubleValue')  return (float)$v[$k];
                if ($k === 'booleanValue') return (bool)$v[$k];
                return $v[$k];
            }
        }
    }
    return $v;
}

function _fs_fields_to_assoc(array $fields): array
{
    $out = [];
    foreach ($fields as $k => $v) $out[$k] = _fs_unwrap($v);
    return $out;
}

/**
 * Load ALL products using the same helpers your list uses.
 */
function fs_fetch_all_products(): array
{
    @require_once __DIR__ . '/firebase_config.php';
    @require_once __DIR__ . '/firebase_rest_client.php';
    @require_once __DIR__ . '/getDB.php';

    $items = [];
    if (!function_exists('getDB')) return $items;

    $db = @getDB();
    if (!is_object($db) || !method_exists($db, 'readAll')) return $items;

    // IMPORTANT: Read products with reasonable limit to prevent excessive Firebase reads
    // Consider implementing pagination if you need all products
    $raw = $db->readAll('products', [], null, 500); // Limit to 500 products
    if (!is_array($raw)) return $items;

    // If Firestore REST returned the "documents" shape
    if (isset($raw['documents']) && is_array($raw['documents'])) {
        foreach ($raw['documents'] as $doc) {
            if (!is_array($doc) || empty($doc['name'])) continue;
            $name  = $doc['name'];                       // .../documents/products/{docId}
            $docId = substr($name, strrpos($name, '/') + 1);
            $fields = isset($doc['fields']) ? _fs_fields_to_assoc($doc['fields']) : [];
            $items[] = _fs_normalize_product($docId, $fields);
        }
        return $items;
    }

    // If readAll returned an indexed array of documents or associative map
    $isAssoc = array_keys($raw) !== range(0, count($raw) - 1);
    if ($isAssoc) {
        // keyed map: id => doc
        foreach ($raw as $docId => $doc) {
            if (!is_array($doc)) continue;
            $items[] = _fs_normalize_product((string)$docId, $doc);
        }
        return $items;
    }

    // indexed list with id inside each row
    foreach ($raw as $doc) {
        if (!is_array($doc)) continue;
        $docId = $doc['id'] ?? $doc['docId'] ?? $doc['name'] ?? '';
        if ($docId === '') continue;
        $items[] = _fs_normalize_product((string)$docId, $doc);
    }

    return $items;
}

/**
 * Get ONE product by Firestore document id or by SKU.
 * Tries direct path first (products/<id>), then falls back to scanning.
 */
function fs_get_product(string $key): ?array
{
    @require_once __DIR__ . '/firebase_config.php';
    @require_once __DIR__ . '/firebase_rest_client.php';
    @require_once __DIR__ . '/getDB.php';

    // 1) direct fetch using Database API (collection, documentId)
    if (function_exists('getDB')) {
        $db = @getDB();
        if (is_object($db) && method_exists($db, 'read')) {
            $single = $db->read('products', $key);
            if (is_array($single) && !empty($single)) {
                return _fs_normalize_product($key, $single);
            }
        }
    }

    // 2) fallback: scan the same dataset as list.php
    $all = fs_fetch_all_products();
    if (!$all) return null;

    // Prefer exact match on Firestore doc id
    foreach ($all as $p) {
        if (!empty($p['doc_id']) && (string)$p['doc_id'] === (string)$key) return $p;
    }
    // Then match on SKU
    foreach ($all as $p) {
        if (!empty($p['sku']) && (string)$p['sku'] === (string)$key) return $p;
    }
    // Finally (optional), match on *internal* id field
    foreach ($all as $p) {
        if (isset($p['id']) && (string)$p['id'] === (string)$key) return $p;
    }
    return null;
}

function fs_get_product_by_doc(string $docId): ?array
{
    require_once __DIR__ . '/firebase_config.php';
    require_once __DIR__ . '/firebase_rest_client.php';
    require_once __DIR__ . '/getDB.php';

    $db = function_exists('getDB') ? @getDB() : null;
    if ($db && is_object($db) && method_exists($db, 'read')) {
        // ✅ use Firestore document name, not internal id
        $doc = $db->read('products', $docId);
        if (is_array($doc) && !empty($doc)) {
            return _fs_normalize_product($docId, $doc);
        }
    }

    // fallback: scan all and match doc_id
    $all = fs_fetch_all_products();
    foreach ($all as $p) {
        if (!empty($p['doc_id']) && (string)$p['doc_id'] === (string)$docId) {
            return $p;
        }
    }
    return null;
}

function fs_sku_exists(string $sku): ?array
{
    $skuNorm = strtoupper(trim($sku));
    if ($skuNorm === '') return null;

    $all = fs_fetch_all_products(); // normalized rows
    foreach ($all as $p) {
        $rowSku = isset($p['sku']) ? strtoupper((string)$p['sku']) : '';
        if ($rowSku !== '' && $rowSku === $skuNorm) {
            return $p;
        }
    }
    return null;
}

// Log a stock change/audit into PostgreSQL (table: stock_audits)
function log_stock_audit(array $opts): void
{
    // who did it
    $user_id = $opts['user_id'] ?? ($_SESSION['user_id'] ?? null);
    $username = $opts['username'] ?? ($_SESSION['username'] ?? null);
    if (!$username && $user_id) {
        // fallback to users collection
        $u = getUserInfo($user_id);
        if (is_array($u)) $username = $u['username'] ?? ($u['email'] ?? null);
    }

    $doc = [
        'action'          => $opts['action'] ?? 'update',
        'product_id'      => (string)($opts['product_id'] ?? ''),
        'sku'             => $opts['sku'] ?? null,
        'product_name'    => $opts['product_name'] ?? null,
        'store_id'        => $opts['store_id'] ?? null,

        'quantity_before' => isset($opts['before']['quantity']) ? (int)$opts['before']['quantity'] : null,
        'quantity_after'  => isset($opts['after']['quantity'])  ? (int)$opts['after']['quantity']  : null,
        'description_before' => $opts['before']['description'] ?? null,
        'description_after'  => $opts['after']['description']  ?? null,
        'reorder_before'  => isset($opts['before']['reorder_level']) ? (int)$opts['before']['reorder_level'] : null,
        'reorder_after'   => isset($opts['after']['reorder_level'])  ? (int)$opts['after']['reorder_level']  : null,

        'changed_by'      => $user_id,
        'changed_name'    => $username,
        'created_at'      => date('Y-m-d H:i:s'), // SQL format
    ];

    if ($doc['quantity_before'] !== null && $doc['quantity_after'] !== null) {
        $doc['quantity_delta'] = (int)$doc['quantity_after'] - (int)$doc['quantity_before'];
    } else {
        $doc['quantity_delta'] = null;
    }

    // 1. Write to SQL (Primary)
    try {
        $sqlDb = SQLDatabase::getInstance();
        $sql = "INSERT INTO stock_audits (
            action, product_id, sku, product_name, store_id, 
            quantity_before, quantity_after, quantity_delta, 
            description_before, description_after, 
            reorder_before, reorder_after, 
            changed_by, changed_name, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $sqlDb->execute($sql, [
            $doc['action'],
            $doc['product_id'],
            $doc['sku'],
            $doc['product_name'],
            $doc['store_id'],
            $doc['quantity_before'],
            $doc['quantity_after'],
            $doc['quantity_delta'],
            $doc['description_before'],
            $doc['description_after'],
            $doc['reorder_before'],
            $doc['reorder_after'],
            $doc['changed_by'],
            $doc['changed_name'],
            $doc['created_at']
        ]);
    } catch (Exception $e) {
        error_log('log_stock_audit SQL failed: ' . $e->getMessage());
    }

    // 2. Write to Activity Log (User Activities)
    if (function_exists('logActivity')) {
        try {
            $actionMap = [
                'create'           => 'product_added',
                'update'           => 'product_updated',
                'delete_product'   => 'product_deleted',
                'adjust_stock'     => 'stock_adjusted',
                'update_min_level' => 'stock_updated',
                'assign_store'     => 'stock_assigned'
            ];

            $actAction = $actionMap[$doc['action']] ?? 'stock_audit_' . $doc['action'];
            
            $desc = "Stock Action: " . ucfirst(str_replace('_', ' ', $doc['action']));
            if (!empty($doc['product_name'])) {
                $desc .= " - {$doc['product_name']}";
            }
            if (isset($doc['quantity_delta']) && $doc['quantity_delta'] !== null) {
                $sign = $doc['quantity_delta'] > 0 ? '+' : '';
                $desc .= " (Qty: {$sign}{$doc['quantity_delta']})";
            }

            // Add extra context
            $meta = $doc;
            $meta['source'] = 'log_stock_audit';
            if ($user_id) {
                $meta['_override_user_id'] = $user_id;
            }

            logActivity($actAction, $desc, $meta);
        } catch (Throwable $t) {
            error_log('log_stock_audit activity log failed: ' . $t->getMessage());
        }
    }

    // 3. Write to Firestore (Secondary)
    try {
        $db = db_obj();
        if ($db) {
            // Firestore prefers ISO 8601
            $doc['created_at'] = date('c'); 
            $db->create('stock_audits', $doc);
        }
    } catch (Throwable $t) {
        // error_log('log_stock_audit Firestore failed: ' . $t->getMessage());
    }
}

// ---- ALERT HELPERS ----

function alerts_db() { return getDB(); } // same wrapper you use elsewhere

/** Find an open (or pending) alert for a product+type */
function fs_alert_find_open(string $productId, string $type): ?array {
    $db = alerts_db();
    if (!$db || !method_exists($db, 'query')) return null;
    $rows = $db->query('alert_logs', [
        ['field' => 'product_id', 'op' => '==', 'value' => $productId],
        ['field' => 'type',       'op' => '==', 'value' => $type],
        ['field' => 'status',     'op' => 'in', 'value' => ['open','pending']],
    ], 'triggered_at', 'desc', 1); // newest first, limit 1
    return is_array($rows) && !empty($rows) ? $rows[0] : null;
}

/** Upsert a low-stock alert (no duplicates) */
function log_low_stock_alert(array $p): void {
    $db = alerts_db(); if (!$db) return;
    $pid  = (string)($p['doc_id'] ?? '');
    if ($pid === '') return;

    $row = fs_alert_find_open($pid, 'low_stock');
    $payload = [
        'type'               => 'low_stock',
        'status'             => $row ? ($row['status'] ?? 'open') : 'open',
        'product_id'         => $pid,
        'sku'                => $p['sku'] ?? null,
        'product_name'       => $p['name'] ?? null,
        'quantity_at_alert'  => (int)($p['quantity'] ?? 0),
        'min_level_at_alert' => (int)($p['reorder_level'] ?? 0),
        'triggered_at'       => $row ? ($row['triggered_at'] ?? date('c')) : date('c'),
        'last_seen_at'       => date('c'),
    ];
    if ($row) $db->update('alert_logs', (string)$row['doc_id'], $payload);
    else      $db->create('alert_logs', $payload);
}

/** Upsert an expiry alert (expired vs soon) - REMOVED */
function log_expiry_alert(array $p, string $subtype): void {
    return;
}

/** Resolve helper */
function resolve_alerts_for_product(string $productId, array $types, string $resolution, ?string $uid, ?string $uname): void {
    $db = alerts_db(); if (!$db) return;
    if (!method_exists($db, 'query')) return;
    $rows = $db->query('alert_logs', [
        ['field' => 'product_id', 'op' => '==', 'value' => $productId],
        ['field' => 'type',       'op' => 'in', 'value' => $types],
        ['field' => 'status',     'op' => 'in', 'value' => ['open','pending']],
    ], 'triggered_at', 'desc', 50);
    foreach ($rows as $r) {
        $db->update('alert_logs', (string)$r['doc_id'], [
            'status'        => 'resolved',
            'resolution'    => $resolution,
            'resolved_at'   => date('c'),
            'resolved_by'   => $uid,
            'resolved_name' => $uname,
        ]);
    }
}

// ============================================================================
// PERMISSION FUNCTIONS
// ============================================================================

/**
 * Check if a user has a specific permission
 * @param string $userId User ID
 * @param string $permission Permission to check (e.g., 'manage_inventory', 'view_reports')
 * @return bool True if user has permission
 */
function hasPermission($userId, $permission) {
    if (empty($userId) || empty($permission)) {
        return false;
    }
    
    // Cache user data in session to avoid repeated database calls
    static $userCache = [];
    static $permissionCache = [];
    
    try {
        // Check cache first
        if (isset($userCache[$userId])) {
            $user = $userCache[$userId];
        } else {
            // Get user from PostgreSQL (integer ID only)
            require_once __DIR__ . '/sql_db.php';
            $sqlDb = SQLDatabase::getInstance();
            $user = $sqlDb->fetch("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
            
            if (!$user) {
                return false;
            }
            
            // Cache the result
            $userCache[$userId] = $user;
        }
        
        if (!$user || empty($user['role'])) {
            return false;
        }
        
        $role = strtolower($user['role']);
        
        // Map old permission names to new can_* field names
        $permissionMap = [
            'view_reports' => 'can_view_reports',
            'manage_inventory' => 'can_manage_inventory',
            'manage_users' => 'can_manage_users',
            'manage_stores' => 'can_manage_stores',
            'configure_system' => 'can_configure_system',
            'manage_pos' => 'can_manage_pos',
            'view_audit' => 'can_view_audit',
            'view_inventory' => 'can_view_inventory'
        ];
        
        // If using old permission name, convert to new format
        $permissionKey = $permission;
        if (isset($permissionMap[$permission])) {
            $permissionKey = $permissionMap[$permission];
        }
        
        // Check user_permissions table (with value column)
        $cacheKey = $userId . '_' . $permissionKey;
        if (!isset($permissionCache[$cacheKey])) {
            $sqlDb = SQLDatabase::getInstance();
            $permRecord = $sqlDb->fetch(
                "SELECT value FROM user_permissions WHERE user_id = ? AND permission = ?",
                [$user['id'], $permissionKey]
            );
            
            if ($permRecord !== false) {
                // Explicit grant/revoke found - properly handle PostgreSQL boolean
                // PostgreSQL returns 't' for true, 'f' for false
                $value = $permRecord['value'];
                if ($value === 't' || $value === true || $value === 1 || $value === '1') {
                    $permissionCache[$cacheKey] = true;
                } elseif ($value === 'f' || $value === false || $value === 0 || $value === '0') {
                    $permissionCache[$cacheKey] = false;
                } else {
                    // Default to boolean conversion for other types
                    $permissionCache[$cacheKey] = (bool)$value;
                }
            } else {
                // No record - use role-based default
                $rolePermissions = [
                    'manager' => ['can_view_reports', 'can_manage_inventory', 'can_manage_stores'],
                    'user' => ['can_view_reports']
                ];
                
                if ($role === 'admin') {
                    // Admin has all permissions by default
                    $permissionCache[$cacheKey] = true;
                } elseif (isset($rolePermissions[$role]) && in_array($permissionKey, $rolePermissions[$role])) {
                    $permissionCache[$cacheKey] = true;
                } else {
                    $permissionCache[$cacheKey] = false;
                }
            }
        }
        
        return $permissionCache[$cacheKey];
    } catch (Exception $e) {
        error_log('hasPermission error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if current logged-in user has a permission
 * @param string $permission Permission to check
 * @return bool True if current user has permission
 */
function currentUserHasPermission($permission) {
    if (!isLoggedIn()) {
        return false;
    }
    return hasPermission($_SESSION['user_id'], $permission);
}

/**
 * Require permission or exit with error
 * @param string $permission Required permission
 * @param string $redirectUrl URL to redirect to if permission denied (default: shows 403)
 */
function requirePermission($permission, $redirectUrl = null) {
    if (!currentUserHasPermission($permission)) {
        if ($redirectUrl) {
            $_SESSION['error'] = 'You do not have permission to access this resource.';
            // Add error parameter to URL for permission indicator detection
            $separator = (strpos($redirectUrl, '?') !== false) ? '&' : '?';
            header('Location: ' . $redirectUrl . $separator . 'error=permission_denied');
            exit;
        } else {
            // If no redirect URL, redirect to index with error parameter
            $_SESSION['error'] = 'Access Denied: You do not have permission to access this resource. Required: ' . $permission;
            header('Location: ../../index.php?error=permission_denied');
            exit;
        }
    }
}

/**
 * Check if user has ANY of the given permissions
 * @param string $userId User ID
 * @param array $permissions Array of permissions
 * @return bool True if user has at least one permission
 */
function hasAnyPermission($userId, $permissions) {
    foreach ($permissions as $permission) {
        if (hasPermission($userId, $permission)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has ALL of the given permissions
 * @param string $userId User ID
 * @param array $permissions Array of permissions
 * @return bool True if user has all permissions
 */
function hasAllPermissions($userId, $permissions) {
    foreach ($permissions as $permission) {
        if (!hasPermission($userId, $permission)) {
            return false;
        }
    }
    return true;
}

/**
 * Get all permissions for a user
 * @param string $userId User ID
 * @return array Array of permission names the user has
 */
function getUserPermissions($userId) {
    $allPermissions = [
        'view_reports', 
        'manage_inventory', 
        'manage_users', 
        'manage_stores', 
        'configure_system', 
        'manage_pos',
        'view_audit',
        'view_inventory'
    ];
    
    $userPermissions = [];
    foreach ($allPermissions as $permission) {
        if (hasPermission($userId, $permission)) {
            $userPermissions[] = $permission;
        }
    }
    
    return $userPermissions;
}

function alerts_ensure_low_stock(PDO $db, array $p): void {
    $pid   = $p['id'];          // your normalized product id
    $pname = $p['name'];
    $qty   = (int)$p['quantity'];
    $lvl   = (int)$p['reorder_level'];

    if ($qty > $lvl) return; // only create when actually low

    // Only one open alert per product+type
    $q = $db->prepare("SELECT 1 FROM alerts 
                       WHERE product_id=? AND alert_type='LOW_STOCK' 
                         AND status IN ('PENDING','UNRESOLVED') 
                       LIMIT 1");
    $q->execute([$pid]);
    if ($q->fetchColumn()) return;

    $ins = $db->prepare("INSERT INTO alerts
        (product_id, product_name, alert_type, quantity_at_alert, reorder_level, status, created_at)
        VALUES (?, ?, 'LOW_STOCK', ?, ?, 'PENDING', NOW())");
    $ins->execute([$pid, $pname, $qty, $lvl]);
}

/** Resolve any open LOW_STOCK alerts for a product (called after Add Stock). */
function alerts_resolve_low_stock(PDO $db, string $productId, string $who='system'): void {
    $upd = $db->prepare("UPDATE alerts
                         SET status='RESOLVED', resolved_at=NOW(), resolved_by=?, resolution_note='User added stock'
                         WHERE product_id=? AND alert_type='LOW_STOCK' 
                           AND status IN ('PENDING','UNRESOLVED')");
    $upd->execute([$who, $productId]);
}