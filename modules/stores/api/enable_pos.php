<?php
/**
 * Enable POS for a Store
 * Activates POS functionality for an existing store
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../../../firebase_rest_client.php';

header('Content-Type: application/json');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db = getSQLDB(); // Use SQL database for stores
    
    // Get store ID from request
    $storeId = $_POST['store_id'] ?? null;
    
    if (!$storeId) {
        echo json_encode(['success' => false, 'message' => 'Store ID is required']);
        exit;
    }
    
    // Check if this is a Firebase ID (string) or SQL ID (numeric)
    // Try to find the store by firebase_id first, then by id
    if (is_numeric($storeId)) {
        // It's a SQL ID
        $store = $db->fetch("SELECT id, name, has_pos FROM stores WHERE id = ? AND (active = 1 OR active IS NULL)", [$storeId]);
    } else {
        // It's a Firebase ID
        $store = $db->fetch("SELECT id, name, has_pos FROM stores WHERE firebase_id = ? AND (active = 1 OR active IS NULL)", [$storeId]);
    }
    
    if (!$store) {
        echo json_encode(['success' => false, 'message' => 'Store not found']);
        exit;
    }
    
    // Use the SQL ID from this point forward
    $sqlStoreId = $store['id'];
    
    // Check if POS is already enabled
    if (isset($store['has_pos']) && $store['has_pos'] == 1) {
        echo json_encode(['success' => false, 'message' => 'POS is already enabled for this store']);
        exit;
    }
    
    // Enable POS for the store
    $result = $db->execute(
        "UPDATE stores SET has_pos = 1, updated_at = datetime('now') WHERE id = ?",
        [$sqlStoreId]
    );
    
    if ($result) {
        // Also update Firebase if the store has a firebase_id
        $firebaseId = $db->fetch("SELECT firebase_id FROM stores WHERE id = ?", [$sqlStoreId]);
        if ($firebaseId && !empty($firebaseId['firebase_id'])) {
            try {
                $firebaseClient = new FirebaseRestClient();
                $firebaseClient->updateDocument('stores', $firebaseId['firebase_id'], [
                    'has_pos' => true
                ]);
            } catch (Exception $e) {
                error_log("Failed to update Firebase for store {$sqlStoreId}: " . $e->getMessage());
                // Don't fail the request if Firebase update fails
            }
        }
        
        // Update the cache directly instead of clearing it
        $cacheDir = __DIR__ . '/../../../storage/cache/';
        $cacheFile = $cacheDir . 'stores_list_' . md5('stores_list_data') . '.cache';
        if (file_exists($cacheFile)) {
            try {
                $cacheData = json_decode(file_get_contents($cacheFile), true);
                if ($cacheData && isset($cacheData['stores'])) {
                    // Find and update the store in the cache
                    foreach ($cacheData['stores'] as &$cachedStore) {
                        if (($cachedStore['id'] ?? null) === $storeId || 
                            ($cachedStore['id'] ?? null) == $sqlStoreId) {
                            $cachedStore['has_pos'] = 1;
                            break;
                        }
                    }
                    // Save updated cache
                    file_put_contents($cacheFile, json_encode($cacheData));
                }
            } catch (Exception $e) {
                error_log("Failed to update cache: " . $e->getMessage());
                // If cache update fails, clear it to force refresh
                @unlink($cacheFile);
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'POS enabled successfully for ' . $store['name']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to enable POS']);
    }
    
} catch (Exception $e) {
    error_log("Error enabling POS: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'trace' => (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getTraceAsString() : null
    ]);
}
?>
