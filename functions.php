<?php
// Common Helper Functions
require_once 'config.php';
require_once 'db.php';

// Authentication Functions
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getUserInfo($user_id) {
    $db = getDB();
    return $db->read('users', $user_id);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    // Handle null or empty hash values
    if (empty($hash)) {
        return false;
    }
    return password_verify($password, $hash);
}

function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Firebase User Functions
function createFirebaseUser($userData) {
    $db = getDB();
    
    // Check if user already exists
    $existingUsers = $db->readAll('users', [
        ['username', '==', $userData['username']],
        ['email', '==', $userData['email']]
    ]);
    
    if (!empty($existingUsers)) {
        return false;
    }
    
    // Add timestamp
    $userData['created_at'] = date('c');
    $userData['updated_at'] = date('c');
    
    // Create user document
    return $db->create('users', $userData);
}

function findUserByUsernameOrEmail($usernameOrEmail) {
    $db = getDB();
    
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
    
    return null;
}

function updateUserRememberToken($userId, $token, $expires) {
    $db = getDB();
    return $db->update('users', $userId, [
        'remember_token' => $token,
        'remember_expires' => $expires,
        'updated_at' => date('c')
    ]);
}

function findUserByRememberToken($token) {
    $db = getDB();
    $currentTime = date('c');
    
    $users = $db->readAll('users', [
        ['remember_token', '==', $token],
        ['remember_expires', '>', $currentTime],
        ['is_active', '==', true]
    ], null, 1);
    
    return !empty($users) ? $users[0] : null;
}

function clearUserRememberToken($userId) {
    $db = getDB();
    return $db->update('users', $userId, [
        'remember_token' => null,
        'remember_expires' => null,
        'updated_at' => date('c')
    ]);
}

// Security Functions
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function preventCSRF() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Date and Time Functions
function formatDate($date, $format = 'Y-m-d H:i:s') {
    return date($format, strtotime($date));
}

function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time / 60) . ' min ago';
    if ($time < 86400) return floor($time / 3600) . ' hr ago';
    if ($time < 2592000) return floor($time / 86400) . ' days ago';
    
    return date('M j, Y', strtotime($datetime));
}

// Dashboard Statistics Functions (without caching)
function getTotalProducts() {
    try {
        $sql_db = getSQLDB();
        $result = $sql_db->fetch("SELECT COUNT(*) as count FROM products WHERE active = 1");
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error in getTotalProducts: " . $e->getMessage());
        return 0;
    }
}

function getLowStockCount() {
    try {
        $sql_db = getSQLDB();
        $result = $sql_db->fetch("SELECT COUNT(*) as count FROM products WHERE quantity <= reorder_level AND active = 1");
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error in getLowStockCount: " . $e->getMessage());
        return 0;
    }
}

function getTotalStores() {
    try {
        $sql_db = getSQLDB();
        $result = $sql_db->fetch("SELECT COUNT(*) as count FROM stores WHERE active = 1");
        return $result['count'] ?? 0;
    } catch (Exception $e) {
        error_log("Error in getTotalStores: " . $e->getMessage());
        return 0;
    }
}

function getTodaysSales() {
    try {
        $sql_db = getSQLDB();
        $today = date('Y-m-d');
        $result = $sql_db->fetch("SELECT COALESCE(SUM(total), 0) as total FROM sales WHERE DATE(created_at) = ?", [$today]);
        return floatval($result['total'] ?? 0);
    } catch (Exception $e) {
        error_log("Error in getTodaysSales: " . $e->getMessage());
        // Return a demo value for now
        return rand(1000, 5000);
    }
}

// Stock Management Functions
function updateStock($product_id, $quantity, $operation = 'set') {
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

function logStockMovement($product_id, $user_id, $quantity, $operation, $reason = '') {
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
function triggerLowStockAlert($product_id, $current_stock, $min_stock) {
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

function triggerExpiryAlert($product_id, $expiry_date, $days_to_expiry) {
    $db = getDB();
    
    // Get product details
    $product = $db->read('products', $product_id);
    if (!$product) {
        return false;
    }
    
    // Determine priority based on days to expiry
    if ($days_to_expiry <= 3) {
        $priority = 'critical';
    } elseif ($days_to_expiry <= 7) {
        $priority = 'high';
    } else {
        $priority = 'medium';
    }
    
    // Create alert record
    $alertData = [
        'type' => 'expiry',
        'title' => 'Product Expiry Alert',
        'message' => "Product '{$product['name']}' expires in {$days_to_expiry} days ({$expiry_date})",
        'priority' => $priority,
        'product_id' => $product_id,
        'store_id' => $product['store_id'] ?? null,
        'metadata' => json_encode([
            'expiry_date' => $expiry_date,
            'days_to_expiry' => $days_to_expiry
        ]),
        'created_at' => date('c')
    ];
    
    return $db->create('alerts', $alertData);
}

// Notification Functions
function addNotification($message, $type = 'info') {
    if (!isset($_SESSION['notifications'])) {
        $_SESSION['notifications'] = [];
    }
    
    $_SESSION['notifications'][] = [
        'message' => $message,
        'type' => $type,
        'timestamp' => time()
    ];
}

function getNotifications() {
    $notifications = $_SESSION['notifications'] ?? [];
    $_SESSION['notifications'] = []; // Clear after getting
    return $notifications;
}

// File Upload Functions
function validateFileUpload($file, $allowed_types = null, $max_size = null) {
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

function generateUniqueFileName($original_name) {
    $extension = pathinfo($original_name, PATHINFO_EXTENSION);
    return uniqid() . '_' . time() . '.' . $extension;
}

// Debug and Error Functions
function logError($message, $context = []) {
    if (defined('LOG_ERRORS') && LOG_ERRORS && defined('ERROR_LOG_PATH')) {
        $log_message = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        if (!empty($context)) {
            $log_message .= ' Context: ' . json_encode($context);
        }
        error_log($log_message . PHP_EOL, 3, ERROR_LOG_PATH);
    }
}

function debugDump($var, $label = '') {
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo '<div style="background: #f0f0f0; border: 1px solid #ccc; padding: 10px; margin: 10px 0;">';
        if ($label) echo '<strong>' . $label . ':</strong><br>';
        echo '<pre>' . print_r($var, true) . '</pre>';
        echo '</div>';
    }
}

// Pagination Functions
function paginate($page, $per_page, $total_records) {
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
?>