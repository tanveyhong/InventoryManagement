<?php
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

header('Content-Type: application/json');

session_start();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$product_id = $_GET['id'] ?? null;
if (!$product_id) {
    echo json_encode(['assigned_stores' => []]);
    exit;
}

try {
    $db = getSQLDB();
    
    // Log for debugging
    error_log("get_assigned_stores.php called for ID: " . $product_id);
    
    $product = $db->fetch("SELECT sku, name, barcode FROM products WHERE id = ?", [$product_id]);

    if (!$product) {
        error_log("Product not found for ID: " . $product_id);
        echo json_encode(['assigned_stores' => []]);
        exit;
    }

    $sku = $product['sku'];
    $name = $product['name'];
    $barcode = $product['barcode'] ?? '';
    
    error_log("Checking assignments for SKU: $sku, Name: $name");

    // Find variants by:
    // 1. Exact SKU match
    // 2. SKU pattern (case insensitive)
    // 3. Name match (case insensitive)
    // 4. Barcode match
    
    $params = [$sku, $sku . '-%', $name];
    
    $sql = "SELECT store_id FROM products WHERE (sku = ? OR LOWER(sku) LIKE LOWER(?) OR LOWER(name) = LOWER(?)";
    
    if (!empty($barcode)) {
        $sql .= " OR barcode = ?";
        $params[] = $barcode;
    }
    
    $sql .= ") AND store_id IS NOT NULL";
    
    // Debug log
    error_log("SQL: $sql");
    error_log("Params: " . json_encode($params));

    $assigned = $db->fetchAll($sql, $params);
    
    error_log("Found " . count($assigned) . " assignments");

    $ids = array_column($assigned, 'store_id');
    // Ensure IDs are integers and unique
    $ids = array_unique(array_map('intval', $ids));
    
    echo json_encode(['assigned_stores' => array_values($ids)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
