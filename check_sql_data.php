<?php
require_once 'config.php';
require_once 'sql_db.php';

echo "=== CHECKING SQL DATABASE ===\n\n";

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // Get a few products from SQL
    $products = $sqlDb->fetchAll("SELECT * FROM products LIMIT 5");
    
    echo "Found " . count($products) . " products in SQL (showing first 5)\n\n";
    
    foreach ($products as $idx => $product) {
        echo "Product #" . ($idx + 1) . ":\n";
        echo "  ID: " . ($product['id'] ?? 'N/A') . "\n";
        echo "  SKU: " . ($product['sku'] ?? 'N/A') . "\n";
        echo "  Name: " . ($product['name'] ?? 'N/A') . "\n";
        echo "  Quantity: " . ($product['quantity'] ?? 'N/A') . "\n";
        echo "  Price: " . ($product['price'] ?? 'N/A') . "\n";
        echo "  Store ID: " . ($product['store_id'] ?? 'N/A') . "\n";
        echo "  Category: " . ($product['category'] ?? 'N/A') . "\n";
        echo "  Active: " . ($product['active'] ?? 'N/A') . "\n";
        echo "  All keys: " . implode(', ', array_keys($product)) . "\n";
        echo "\n";
    }
    
    // Count total products
    $total = $sqlDb->fetch("SELECT COUNT(*) as total FROM products WHERE active = 1");
    echo "Total active products in SQL: " . ($total['total'] ?? 0) . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
