<?php
// modules/purchase_orders/search_products.php
require_once '../../config.php';
require_once '../../sql_db.php';

header('Content-Type: application/json');

// Check authentication
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once '../../functions.php';
if (!currentUserHasPermission('can_manage_purchase_orders')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$term = $_GET['term'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$db = SQLDatabase::getInstance();

try {
    // Base query
    $sql = "
        SELECT 
            p.id, 
            p.name, 
            p.sku, 
            p.cost_price, 
            p.price,
            p.quantity, 
            s.name as store_name, 
            p.category as category_name
        FROM products p
        LEFT JOIN stores s ON p.store_id = s.id
        WHERE p.active = TRUE
    ";
    
    $params = [];
    
    // Search filter
    if (!empty($term)) {
        // Use LOWER for case-insensitive search compatible with both MySQL and Postgres
        $sql .= " AND (LOWER(p.name) LIKE LOWER(?) OR LOWER(p.sku) LIKE LOWER(?))";
        $params[] = "%$term%";
        $params[] = "%$term%";
    }

    // Filter by Store (if provided)
    $store_id = $_GET['store_id'] ?? '';
    if ($store_id === 'MAIN') {
        $sql .= " AND p.store_id IS NULL";
    } elseif (is_numeric($store_id)) {
        $sql .= " AND p.store_id = ?";
        $params[] = $store_id;
    }
    
    // Count total for pagination
    // We need a separate count query or just return more: true/false based on result count
    // For simplicity, we'll just fetch limit + 1 to check if there are more
    
    $sql .= " ORDER BY s.name NULLS FIRST, p.name LIMIT ? OFFSET ?";
    $params[] = $limit + 1; // Fetch one extra to check for 'more'
    $params[] = $offset;
    
    $results = $db->fetchAll($sql, $params);
    
    $hasMore = false;
    if (count($results) > $limit) {
        $hasMore = true;
        array_pop($results); // Remove the extra one
    }
    
    // Format for Select2
    $formattedResults = [];
    foreach ($results as $row) {
        $storeName = $row['store_name'] ?? 'Main Warehouse / Unassigned';
        $categoryName = $row['category_name'] ?? 'Uncategorized';
        $cost = is_numeric($row['cost_price']) ? (float)$row['cost_price'] : 0.00;
        $price = is_numeric($row['price']) ? (float)$row['price'] : 0.00;
        
        $formattedResults[] = [
            'id' => $row['id'],
            'text' => $row['name'], // Standard text for fallback
            'sku' => $row['sku'],
            'cost' => $cost,
            'price' => $price,
            'stock' => $row['quantity'],
            'store' => $storeName,
            'category' => $categoryName,
            'image' => null // Image URL not available in schema
        ];
    }
    
    echo json_encode([
        'results' => $formattedResults,
        'pagination' => [
            'more' => $hasMore
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>