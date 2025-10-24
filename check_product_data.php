<?php
require_once 'config.php';
require_once 'getDB.php';

echo "=== CHECKING PRODUCT DATA ===\n\n";

try {
    $db = getDB();
    
    // Get a few products
    $products = $db->readAll('products', [], null, 5);
    
    echo "Found " . count($products) . " products (showing first 5)\n\n";
    
    foreach ($products as $idx => $product) {
        echo "Product #" . ($idx + 1) . ":\n";
        echo "  ID: " . ($product['id'] ?? 'N/A') . "\n";
        echo "  SKU: " . ($product['sku'] ?? 'N/A') . "\n";
        echo "  Name: " . ($product['name'] ?? 'N/A') . "\n";
        echo "  Quantity: " . ($product['quantity'] ?? 'N/A') . "\n";
        echo "  Price field: " . ($product['price'] ?? 'N/A') . "\n";
        echo "  Unit_price field: " . ($product['unit_price'] ?? 'N/A') . "\n";
        echo "  Store ID: " . ($product['store_id'] ?? 'N/A') . "\n";
        echo "  Category: " . ($product['category'] ?? 'N/A') . "\n";
        echo "  All keys: " . implode(', ', array_keys($product)) . "\n";
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
