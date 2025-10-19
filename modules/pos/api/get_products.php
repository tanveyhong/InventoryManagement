<?php
/**
 * POS API - Get Products
 * Returns all available products with stock information
 */

require_once '../../../config.php';
require_once '../../../db.php';

header('Content-Type: application/json');

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db = getSQLDB(); // Use SQL database for POS
    
    // Get store filter from request
    $storeId = $_GET['store_id'] ?? $_SESSION['pos_store_id'] ?? null;
    
    // Build query with optional store filter
    $query = "
        SELECT 
            p.id,
            p.name,
            p.sku,
            p.barcode,
            p.category,
            p.price,
            p.quantity,
            p.reorder_level,
            p.description,
            p.store_id,
            p.created_at,
            s.name as store_name
        FROM products p
        LEFT JOIN stores s ON p.store_id = s.id
        WHERE (p.active = 1 OR p.active IS NULL)
    ";
    
    $params = [];
    if ($storeId) {
        $query .= " AND p.store_id = ?";
        $params[] = $storeId;
    }
    
    $query .= " ORDER BY p.name ASC";
    
    $products = $db->fetchAll($query, $params);
    
    // Format product data
    $formattedProducts = array_map(function($product) {
        return [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'sku' => $product['sku'],
            'barcode' => $product['barcode'],
            'category' => $product['category'],
            'price' => number_format((float)$product['price'], 2, '.', ''),
            'quantity' => (int)$product['quantity'],
            'reorder_level' => (int)$product['reorder_level'],
            'description' => $product['description'],
            'store_id' => (int)$product['store_id'],
            'store_name' => $product['store_name'],
            'in_stock' => (int)$product['quantity'] > 0,
            'low_stock' => (int)$product['quantity'] <= (int)$product['reorder_level'] && (int)$product['quantity'] > 0
        ];
    }, $products);
    
    echo json_encode([
        'success' => true,
        'products' => $formattedProducts,
        'count' => count($formattedProducts),
        'store_id' => $storeId
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching products: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching products: ' . $e->getMessage()
    ]);
}
