<?php
// Offline Synchronization Handler
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$db = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => []];

try {
    switch ($action) {
        case 'sync_pending':
            // Sync pending offline changes to the server
            $pending_data = json_decode($_POST['data'] ?? '[]', true);
            
            if (empty($pending_data)) {
                $response['message'] = 'No data to sync';
                break;
            }
            
            $db->beginTransaction();
            $synced_items = [];
            
            foreach ($pending_data as $item) {
                $result = processSyncItem($item);
                $synced_items[] = [
                    'local_id' => $item['local_id'] ?? null,
                    'success' => $result['success'],
                    'server_id' => $result['server_id'] ?? null,
                    'message' => $result['message'] ?? ''
                ];
            }
            
            $db->commit();
            
            $response['success'] = true;
            $response['data'] = $synced_items;
            $response['message'] = 'Sync completed';
            break;
            
        case 'get_updates':
            // Get updates from server since last sync
            $last_sync = $_GET['last_sync'] ?? '1970-01-01 00:00:00';
            
            $updates = [
                'products' => getUpdatedProducts($last_sync),
                'stores' => getUpdatedStores($last_sync),
                'categories' => getUpdatedCategories($last_sync),
                'sales' => getUpdatedSales($last_sync)
            ];
            
            $response['success'] = true;
            $response['data'] = $updates;
            $response['sync_timestamp'] = date('Y-m-d H:i:s');
            break;
            
        case 'download_data':
            // Download all data for offline use
            $data = [
                'products' => getAllProducts(),
                'stores' => getAllStores(),
                'categories' => getAllCategories(),
                'suppliers' => getAllSuppliers()
            ];
            
            $response['success'] = true;
            $response['data'] = $data;
            $response['download_timestamp'] = date('Y-m-d H:i:s');
            break;
            
        case 'check_conflicts':
            // Check for conflicts before syncing
            $items = json_decode($_POST['items'] ?? '[]', true);
            $conflicts = [];
            
            foreach ($items as $item) {
                $conflict = checkForConflicts($item);
                if ($conflict) {
                    $conflicts[] = $conflict;
                }
            }
            
            $response['success'] = true;
            $response['data'] = $conflicts;
            break;
            
        default:
            $response['message'] = 'Invalid action';
            break;
    }
    
} catch (Exception $e) {
    if ($db->getConnection()->inTransaction()) {
        $db->rollback();
    }
    
    $response['success'] = false;
    $response['message'] = 'Sync error: ' . $e->getMessage();
    
    // Log the error
    error_log("Sync Error: " . $e->getMessage(), 3, ERROR_LOG_PATH);
}

echo json_encode($response);

// Helper Functions

function processSyncItem($item) {
    global $db;
    
    $type = $item['type'] ?? '';
    $operation = $item['operation'] ?? '';
    $data = $item['data'] ?? [];
    $local_id = $item['local_id'] ?? null;
    
    try {
        switch ($type) {
            case 'product':
                return syncProduct($operation, $data, $local_id);
                
            case 'stock_adjustment':
                return syncStockAdjustment($operation, $data, $local_id);
                
            case 'sale':
                return syncSale($operation, $data, $local_id);
                
            default:
                return ['success' => false, 'message' => 'Unknown sync type'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function syncProduct($operation, $data, $local_id) {
    global $db;
    
    switch ($operation) {
        case 'create':
            $sql = "INSERT INTO products (name, description, sku, price, quantity, category_id, store_id, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $params = [
                $data['name'], $data['description'], $data['sku'],
                $data['price'], $data['quantity'], $data['category_id'], $data['store_id']
            ];
            
            $result = $db->query($sql, $params);
            if ($result) {
                $server_id = $db->lastInsertId();
                return ['success' => true, 'server_id' => $server_id, 'message' => 'Product created'];
            }
            break;
            
        case 'update':
            $sql = "UPDATE products SET name = ?, description = ?, price = ?, quantity = ?, 
                    category_id = ?, updated_at = NOW() WHERE id = ?";
            $params = [
                $data['name'], $data['description'], $data['price'],
                $data['quantity'], $data['category_id'], $data['id']
            ];
            
            $result = $db->query($sql, $params);
            if ($result) {
                return ['success' => true, 'server_id' => $data['id'], 'message' => 'Product updated'];
            }
            break;
            
        case 'delete':
            $sql = "UPDATE products SET active = 0, updated_at = NOW() WHERE id = ?";
            $result = $db->query($sql, [$data['id']]);
            if ($result) {
                return ['success' => true, 'message' => 'Product deleted'];
            }
            break;
    }
    
    return ['success' => false, 'message' => 'Product sync failed'];
}

function syncStockAdjustment($operation, $data, $local_id) {
    global $db;
    
    if ($operation === 'create') {
        // Update product quantity
        $sql = "UPDATE products SET quantity = quantity + ? WHERE id = ?";
        $result = $db->query($sql, [$data['quantity_change'], $data['product_id']]);
        
        if ($result) {
            // Log the stock movement
            logStockMovement($data['product_id'], $data['quantity_change'], 
                           $data['operation'], $data['reason'] ?? 'Offline sync');
            
            return ['success' => true, 'message' => 'Stock adjustment synced'];
        }
    }
    
    return ['success' => false, 'message' => 'Stock adjustment sync failed'];
}

function syncSale($operation, $data, $local_id) {
    global $db;
    
    if ($operation === 'create') {
        $db->beginTransaction();
        
        try {
            // Create sale record
            $sql = "INSERT INTO sales (store_id, user_id, total_amount, created_at) VALUES (?, ?, ?, ?)";
            $result = $db->query($sql, [
                $data['store_id'], $_SESSION['user_id'], 
                $data['total_amount'], $data['created_at']
            ]);
            
            if (!$result) {
                throw new Exception('Failed to create sale record');
            }
            
            $sale_id = $db->lastInsertId();
            
            // Create sale items and update stock
            foreach ($data['items'] as $item) {
                $sql = "INSERT INTO sale_items (sale_id, product_id, quantity, price, subtotal) VALUES (?, ?, ?, ?, ?)";
                $db->query($sql, [$sale_id, $item['product_id'], $item['quantity'], $item['price'], $item['subtotal']]);
                
                // Update product quantity
                $db->query("UPDATE products SET quantity = quantity - ? WHERE id = ?", 
                          [$item['quantity'], $item['product_id']]);
            }
            
            $db->commit();
            return ['success' => true, 'server_id' => $sale_id, 'message' => 'Sale synced'];
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }
    
    return ['success' => false, 'message' => 'Sale sync failed'];
}

function getUpdatedProducts($last_sync) {
    global $db;
    return $db->fetchAll("SELECT * FROM products WHERE updated_at > ? AND active = 1", [$last_sync]);
}

function getUpdatedStores($last_sync) {
    global $db;
    return $db->fetchAll("SELECT * FROM stores WHERE updated_at > ? AND active = 1", [$last_sync]);
}

function getUpdatedCategories($last_sync) {
    global $db;
    return $db->fetchAll("SELECT * FROM categories WHERE updated_at > ? AND active = 1", [$last_sync]);
}

function getUpdatedSales($last_sync) {
    global $db;
    return $db->fetchAll("SELECT s.*, si.* FROM sales s 
                         LEFT JOIN sale_items si ON s.id = si.sale_id 
                         WHERE s.updated_at > ?", [$last_sync]);
}

function getAllProducts() {
    global $db;
    return $db->fetchAll("SELECT * FROM products WHERE active = 1 ORDER BY name");
}

function getAllStores() {
    global $db;
    return $db->fetchAll("SELECT * FROM stores WHERE active = 1 ORDER BY name");
}

function getAllCategories() {
    global $db;
    return $db->fetchAll("SELECT * FROM categories WHERE active = 1 ORDER BY name");
}

function getAllSuppliers() {
    global $db;
    return $db->fetchAll("SELECT * FROM suppliers WHERE active = 1 ORDER BY name");
}

function checkForConflicts($item) {
    global $db;
    
    if ($item['type'] === 'product' && $item['operation'] === 'update') {
        $current = $db->fetch("SELECT updated_at FROM products WHERE id = ?", [$item['data']['id']]);
        
        if ($current && $current['updated_at'] > $item['last_modified']) {
            return [
                'type' => 'product',
                'id' => $item['data']['id'],
                'conflict_type' => 'timestamp',
                'server_modified' => $current['updated_at'],
                'client_modified' => $item['last_modified']
            ];
        }
    }
    
    return null;
}
?>