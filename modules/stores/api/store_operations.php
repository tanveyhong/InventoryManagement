<?php
/**
 * Store Operations API Handler
 * Handles AJAX operations for store management
 */
require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

// Set JSON header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// Get database instance
$db = getDB();

// Get current user from database
$currentUserId = $_SESSION['user_id'] ?? null;
if (!$currentUserId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User ID not found in session']);
    exit;
}

$currentUser = $db->read('users', $currentUserId);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

$userRole = $currentUser['role'] ?? 'user';

// Only admin and manager can perform operations
if (!in_array($userRole, ['admin', 'manager'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions. Required role: admin or manager, Current role: ' . $userRole]);
    exit;
}

// $db is already initialized above
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        
        case 'toggle_status':
            toggleStoreStatus();
            break;
            
        case 'duplicate':
            duplicateStore();
            break;
            
        case 'quick_edit':
            quickEditStore();
            break;
            
        case 'bulk_activate':
            bulkActivate();
            break;
            
        case 'bulk_deactivate':
            bulkDeactivate();
            break;
            
        case 'bulk_delete':
            bulkDelete();
            break;
            
        case 'export':
            exportStores();
            break;
            
        case 'get_store':
            getStore();
            break;
            
        case 'search':
            searchStores();
            break;
            
        case 'get_analytics':
            getAnalytics();
            break;
            
        case 'get_templates':
            getTemplates();
            break;
            
        case 'save_template':
            saveTemplate();
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Store Operations API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}

/**
 * Toggle store active status
 */
function toggleStoreStatus() {
    global $db, $currentUser, $currentUserId;
    
    $store_id = $_POST['store_id'] ?? '';
    if (empty($store_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store ID required']);
        return;
    }
    
    // Get current store
    $store = $db->read('stores', $store_id);
    if (!$store) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found']);
        return;
    }
    
    // Toggle status (soft deactivate)
    $currentStatus = isset($store['active']) ? (bool)$store['active'] : false;
    $newStatus = !$currentStatus;
    
    $updateData = [
        'active' => $newStatus ? 1 : 0,
        'updated_at' => date('c')
    ];
    
    // Add updated_by if available
    if (!empty($currentUserId)) {
        $updateData['updated_by'] = $currentUserId;
    }
    
    $result = $db->update('stores', $store_id, $updateData);
    
    if ($result) {
        $statusText = $newStatus ? 'activated' : 'deactivated';
        echo json_encode([
            'success' => true,
            'message' => "Store '{$store['name']}' {$statusText} successfully",
            'new_status' => $newStatus
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update store status']);
    }
}

/**
 * Duplicate store
 */
function duplicateStore() {
    global $db, $currentUser;
    
    $store_id = $_POST['store_id'] ?? '';
    if (empty($store_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store ID required']);
        return;
    }
    
    // Get original store
    $store = $db->read('stores', $store_id);
    if (!$store) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found']);
        return;
    }
    
    // Remove ID and update fields
    unset($store['id']);
    $store['name'] = $store['name'] . ' (Copy)';
    $store['code'] = ($store['code'] ?? '') . '_COPY_' . time();
    $store['created_at'] = date('c');
    $store['updated_at'] = date('c');
    $store['created_by'] = $currentUser;
    $store['active'] = 1;
    
    $result = $db->create('stores', $store);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Store duplicated successfully',
            'store_id' => $result
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to duplicate store']);
    }
}

/**
 * Quick edit store
 */
function quickEditStore() {
    global $db, $currentUser;
    
    $store_id = $_POST['store_id'] ?? '';
    if (empty($store_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store ID required']);
        return;
    }
    
    // Get current store
    $store = $db->read('stores', $store_id);
    if (!$store) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found']);
        return;
    }
    
    // Update only provided fields
    $updateData = [
        'updated_at' => date('c'),
        'updated_by' => $currentUser
    ];
    
    $allowedFields = ['name', 'code', 'address', 'city', 'state', 'zip_code', 
                      'phone', 'email', 'manager_name', 'description'];
    
    foreach ($allowedFields as $field) {
        if (isset($_POST[$field])) {
            $updateData[$field] = sanitizeInput($_POST[$field]);
        }
    }
    
    $result = $db->update('stores', $store_id, $updateData);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Store updated successfully',
            'store' => array_merge($store, $updateData)
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update store']);
    }
}

/**
 * Bulk activate stores
 */
function bulkActivate() {
    global $db, $currentUser, $currentUserId;
    
    $store_ids = $_POST['store_ids'] ?? [];
    
    // Handle JSON string
    if (is_string($store_ids)) {
        $store_ids = json_decode($store_ids, true);
    }
    
    if (empty($store_ids) || !is_array($store_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store IDs required', 'received' => $_POST]);
        return;
    }
    
    $successCount = 0;
    $failCount = 0;
    
    foreach ($store_ids as $store_id) {
        $updateData = [
            'active' => 1,
            'updated_at' => date('c')
        ];
        
        if (!empty($currentUserId)) {
            $updateData['updated_by'] = $currentUserId;
        }
        
        $result = $db->update('stores', $store_id, $updateData);
        
        if ($result) {
            $successCount++;
        } else {
            $failCount++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Activated $successCount store(s)" . ($failCount > 0 ? ", $failCount failed" : ""),
        'success_count' => $successCount,
        'fail_count' => $failCount
    ]);
}

/**
 * Bulk deactivate stores
 */
function bulkDeactivate() {
    global $db, $currentUser, $currentUserId;
    
    $store_ids = $_POST['store_ids'] ?? [];
    
    // Handle JSON string
    if (is_string($store_ids)) {
        $store_ids = json_decode($store_ids, true);
    }
    
    if (empty($store_ids) || !is_array($store_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store IDs required']);
        return;
    }
    
    $successCount = 0;
    $failCount = 0;
    
    foreach ($store_ids as $store_id) {
        $updateData = [
            'active' => 0,
            'updated_at' => date('c')
        ];
        
        if (!empty($currentUserId)) {
            $updateData['updated_by'] = $currentUserId;
        }
        
        $result = $db->update('stores', $store_id, $updateData);
        
        if ($result) {
            $successCount++;
        } else {
            $failCount++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Deactivated $successCount store(s)" . ($failCount > 0 ? ", $failCount failed" : ""),
        'success_count' => $successCount,
        'fail_count' => $failCount
    ]);
}

/**
 * Bulk delete stores
 */
function bulkDelete() {
    global $db, $currentUser;
    
    $store_ids = $_POST['store_ids'] ?? [];
    
    // Handle JSON string
    if (is_string($store_ids)) {
        $store_ids = json_decode($store_ids, true);
    }
    
    if (empty($store_ids) || !is_array($store_ids)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store IDs required']);
        return;
    }
    
    $successCount = 0;
    $failCount = 0;
    
    foreach ($store_ids as $store_id) {
        $result = $db->delete('stores', $store_id);
        
        if ($result) {
            $successCount++;
        } else {
            $failCount++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Deleted $successCount store(s)" . ($failCount > 0 ? ", $failCount failed" : ""),
        'success_count' => $successCount,
        'fail_count' => $failCount
    ]);
}

/**
 * Export stores to CSV
 */
function exportStores() {
    global $db;
    
    $store_ids = $_GET['store_ids'] ?? [];
    $format = $_GET['format'] ?? 'csv';
    
    // Get stores
    if (!empty($store_ids) && is_array($store_ids)) {
        $stores = [];
        foreach ($store_ids as $store_id) {
            $store = $db->read('stores', $store_id);
            if ($store) {
                $stores[] = $store;
            }
        }
    } else {
        $stores = $db->readAll('stores', [['active', '==', true]]);
    }
    
    if (empty($stores)) {
        echo json_encode(['success' => false, 'message' => 'No stores to export']);
        return;
    }
    
    if ($format === 'json') {
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="stores_export_' . date('Y-m-d') . '.json"');
        echo json_encode($stores, JSON_PRETTY_PRINT);
    } else {
        // CSV export
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="stores_export_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        $headers = ['ID', 'Name', 'Code', 'Address', 'City', 'State', 'ZIP', 'Phone', 'Email', 'Manager', 'Status', 'Created At'];
        fputcsv($output, $headers);
        
        // Data
        foreach ($stores as $store) {
            $row = [
                $store['id'] ?? '',
                $store['name'] ?? '',
                $store['code'] ?? '',
                $store['address'] ?? '',
                $store['city'] ?? '',
                $store['state'] ?? '',
                $store['zip_code'] ?? '',
                $store['phone'] ?? '',
                $store['email'] ?? '',
                $store['manager_name'] ?? '',
                ($store['active'] ?? true) ? 'Active' : 'Inactive',
                $store['created_at'] ?? ''
            ];
            fputcsv($output, $row);
        }
        
        fclose($output);
    }
    exit;
}

/**
 * Get single store data
 */
function getStore() {
    global $db;
    
    $store_id = $_GET['store_id'] ?? '';
    if (empty($store_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store ID required']);
        return;
    }
    
    $store = $db->read('stores', $store_id);
    
    if ($store) {
        // Get additional stats
        $products = $db->readAll('products', [['store_id', '==', $store_id], ['active', '==', true]]);
        $store['product_count'] = count($products);
        $store['total_stock'] = array_sum(array_column($products, 'quantity'));
        
        echo json_encode([
            'success' => true,
            'store' => $store
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Store not found']);
    }
}

/**
 * Advanced search stores
 */
function searchStores() {
    global $db;
    
    $filters = [
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? '',
        'city' => $_GET['city'] ?? '',
        'state' => $_GET['state'] ?? '',
        'manager' => $_GET['manager'] ?? '',
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? ''
    ];
    
    // Get all stores with reasonable limit
    $conditions = [];
    if ($filters['status'] === 'active') {
        $conditions[] = ['active', '==', true];
    } elseif ($filters['status'] === 'inactive') {
        $conditions[] = ['active', '==', false];
    }
    
    // IMPORTANT: Add explicit limit to prevent excessive Firebase reads
    $stores = empty($conditions) ? $db->readAll('stores', [], null, 200) : $db->readAll('stores', $conditions, null, 200);
    
    // Apply filters
    $filtered = [];
    foreach ($stores as $store) {
        $match = true;
        
        // Search filter
        if (!empty($filters['search'])) {
            $search_lower = mb_strtolower($filters['search']);
            $searchable = mb_strtolower(implode(' ', [
                $store['name'] ?? '',
                $store['address'] ?? '',
                $store['city'] ?? '',
                $store['code'] ?? '',
                $store['manager_name'] ?? ''
            ]));
            if (strpos($searchable, $search_lower) === false) {
                $match = false;
            }
        }
        
        // City filter
        if (!empty($filters['city']) && strcasecmp($store['city'] ?? '', $filters['city']) !== 0) {
            $match = false;
        }
        
        // State filter
        if (!empty($filters['state']) && strcasecmp($store['state'] ?? '', $filters['state']) !== 0) {
            $match = false;
        }
        
        // Manager filter
        if (!empty($filters['manager'])) {
            $manager_lower = mb_strtolower($filters['manager']);
            $store_manager = mb_strtolower($store['manager_name'] ?? '');
            if (strpos($store_manager, $manager_lower) === false) {
                $match = false;
            }
        }
        
        // Date range filter
        if (!empty($filters['date_from']) && isset($store['created_at'])) {
            if (strtotime($store['created_at']) < strtotime($filters['date_from'])) {
                $match = false;
            }
        }
        if (!empty($filters['date_to']) && isset($store['created_at'])) {
            if (strtotime($store['created_at']) > strtotime($filters['date_to'])) {
                $match = false;
            }
        }
        
        if ($match) {
            $filtered[] = $store;
        }
    }
    
    echo json_encode([
        'success' => true,
        'stores' => $filtered,
        'total' => count($filtered),
        'filters_applied' => $filters
    ]);
}

/**
 * Get analytics data
 */
function getAnalytics() {
    global $db;
    
    // IMPORTANT: Add limits to prevent excessive Firebase reads
    $all_stores = $db->readAll('stores', [], null, 200);
    $active_stores = array_filter($all_stores, function($store) {
        return $store['active'] ?? true;
    });
    
    // Get products for all stores - limit to representative sample
    $all_products = $db->readAll('products', [['active', '==', true]], null, 500);
    
    // Calculate stats
    $stats = [
        'total_stores' => count($all_stores),
        'active_stores' => count($active_stores),
        'inactive_stores' => count($all_stores) - count($active_stores),
        'total_products' => count($all_products),
        'avg_products_per_store' => count($active_stores) > 0 ? round(count($all_products) / count($active_stores), 2) : 0
    ];
    
    // Group by city
    $by_city = [];
    foreach ($active_stores as $store) {
        $city = $store['city'] ?? 'Unknown';
        $by_city[$city] = ($by_city[$city] ?? 0) + 1;
    }
    arsort($by_city);
    
    // Group by state
    $by_state = [];
    foreach ($active_stores as $store) {
        $state = $store['state'] ?? 'Unknown';
        $by_state[$state] = ($by_state[$state] ?? 0) + 1;
    }
    arsort($by_state);
    
    // Recent activity
    $recent_stores = $all_stores;
    usort($recent_stores, function($a, $b) {
        $timeA = strtotime($a['created_at'] ?? 0);
        $timeB = strtotime($b['created_at'] ?? 0);
        return $timeB - $timeA;
    });
    $recent_stores = array_slice($recent_stores, 0, 10);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'by_city' => $by_city,
        'by_state' => $by_state,
        'recent_stores' => $recent_stores
    ]);
}

/**
 * Get store templates
 */
function getTemplates() {
    global $db, $currentUser;
    
    // Get templates for current user
    $templates = $db->readAll('store_templates', [['created_by', '==', $currentUser]]);
    
    echo json_encode([
        'success' => true,
        'templates' => $templates
    ]);
}

/**
 * Save store template
 */
function saveTemplate() {
    global $db, $currentUser;
    
    $template_name = sanitizeInput($_POST['template_name'] ?? '');
    $template_data = $_POST['template_data'] ?? [];
    
    if (empty($template_name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Template name required']);
        return;
    }
    
    $templateData = [
        'name' => $template_name,
        'data' => json_encode($template_data),
        'created_by' => $currentUser,
        'created_at' => date('c')
    ];
    
    $result = $db->create('store_templates', $templateData);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Template saved successfully',
            'template_id' => $result
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save template']);
    }
}
