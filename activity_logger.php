<?php
/**
 * Activity Logging Functions
 * Comprehensive activity tracking for user actions
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sql_db.php';

/**
 * Log user activity to PostgreSQL
 * 
 * @param string $action Action type (e.g., 'store_created', 'profile_updated', 'product_added')
 * @param string $description Human-readable description of the activity
 * @param array $metadata Additional data about the activity (optional)
 * @return bool Success status
 */
function logActivity($action, $description, $metadata = []) {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        
        // Allow overriding user_id via metadata (useful for system tasks or when session is not available but user is known)
        if (is_array($metadata) && isset($metadata['_override_user_id'])) {
            $userId = $metadata['_override_user_id'];
            unset($metadata['_override_user_id']);
        }

        // Check if user is logged in or identified
        if (empty($userId)) {
            error_log("logActivity failed: No user_id in session or metadata");
            return false;
        }
        
        $sqlDb = SQLDatabase::getInstance();
        
        // Convert metadata to JSON string if it's an array
        if (is_array($metadata)) {
            $metadata = json_encode($metadata);
        }
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $created_at = date('Y-m-d H:i:s');
        
        // Save to PostgreSQL
        $sql = "INSERT INTO user_activities (user_id, action, description, metadata, ip_address, user_agent, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
                
        $result = $sqlDb->execute($sql, [
            $userId,
            $action,
            $description,
            $metadata,
            $ip_address,
            $user_agent,
            $created_at
        ]);
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Activity logging error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log store-related activity
 * 
 * @param string $action Action type (e.g., 'created', 'updated', 'deleted')
 * @param string $storeId Store ID
 * @param string $storeName Store name
 * @param array $changes Changed fields (optional)
 * @return bool Success status
 */
function logStoreActivity($action, $storeId, $storeName, $changes = []) {
    $descriptions = [
        'created' => "Created new store: {$storeName}",
        'updated' => "Updated store: {$storeName}",
        'deleted' => "Deleted store: {$storeName}",
        'viewed' => "Viewed store details: {$storeName}",
    ];
    
    $description = $descriptions[$action] ?? "Performed {$action} on store: {$storeName}";
    
    $metadata = [
        'module' => 'stores',
        'store_id' => $storeId,
        'store_name' => $storeName,
        'action_type' => $action
    ];
    
    if (!empty($changes)) {
        $metadata['changes'] = $changes;
    }
    
    return logActivity("store_{$action}", $description, $metadata);
}

/**
 * Log profile-related activity
 * 
 * @param string $action Action type (e.g., 'updated', 'password_changed', 'avatar_uploaded')
 * @param string $userId User ID whose profile was modified
 * @param array $changes Changed fields (optional)
 * @return bool Success status
 */
function logProfileActivity($action, $userId, $changes = []) {
    $db = getDB();
    $user = $db->read('users', $userId);
    $username = $user['username'] ?? 'Unknown User';
    
    $descriptions = [
        'updated' => "Updated profile for: {$username}",
        'password_changed' => "Changed password for: {$username}",
        'avatar_uploaded' => "Uploaded new avatar for: {$username}",
        'role_changed' => "Changed role for: {$username}",
        'permissions_updated' => "Updated permissions for: {$username}",
    ];
    
    $description = $descriptions[$action] ?? "Performed {$action} on profile: {$username}";
    
    $metadata = [
        'module' => 'users',
        'target_user_id' => $userId,
        'target_username' => $username,
        'action_type' => $action
    ];
    
    if (!empty($changes)) {
        $metadata['changes'] = $changes;
        
        // Add human-readable change summary
        $changeList = [];
        foreach ($changes as $field => $value) {
            if (is_array($value) && isset($value['old']) && isset($value['new'])) {
                $changeList[] = ucfirst(str_replace('_', ' ', $field)) . ": {$value['old']} → {$value['new']}";
            } else {
                $changeList[] = ucfirst(str_replace('_', ' ', $field));
            }
        }
        if (!empty($changeList)) {
            $metadata['change_summary'] = implode(', ', $changeList);
        }
    }
    
    return logActivity("profile_{$action}", $description, $metadata);
}

/**
 * Log product-related activity
 * 
 * @param string $action Action type (e.g., 'created', 'updated', 'deleted')
 * @param string $productId Product ID
 * @param string $productName Product name
 * @param array $changes Changed fields (optional)
 * @return bool Success status
 */
function logProductActivity($action, $productId, $productName, $changes = []) {
    $descriptions = [
        'created' => "Added new product: {$productName}",
        'updated' => "Updated product: {$productName}",
        'deleted' => "Deleted product: {$productName}",
        'stock_adjusted' => "Adjusted stock for: {$productName}",
        'viewed' => "Viewed product details: {$productName}",
    ];
    
    $description = $descriptions[$action] ?? "Performed {$action} on product: {$productName}";
    
    $metadata = [
        'module' => 'products',
        'product_id' => $productId,
        'product_name' => $productName,
        'action_type' => $action
    ];
    
    if (!empty($changes)) {
        $metadata['changes'] = $changes;
    }
    
    return logActivity("product_{$action}", $description, $metadata);
}
