<?php
require 'config.php';
require 'sql_db.php';

$db = SQLDatabase::getInstance();

// Test 1: Check if products table exists
echo "=== Test 1: Check products table ===\n";
try {
    $tables = $db->fetchAll("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'products'");
    print_r($tables);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test 2: Count all products
echo "\n=== Test 2: Count all products ===\n";
try {
    $result = $db->fetch("SELECT COUNT(*) as count FROM products");
    echo "Total products: " . $result['count'] . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test 3: Count products for store_id=11
echo "\n=== Test 3: Count products for store_id=11 ===\n";
try {
    $result = $db->fetch("SELECT COUNT(*) as count FROM products WHERE store_id = 11");
    echo "Products in store 11: " . $result['count'] . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test 4: Check if store 11 exists
echo "\n=== Test 4: Check if store 11 exists ===\n";
try {
    $result = $db->fetch("SELECT * FROM stores WHERE id = 11");
    if ($result) {
        echo "Store found: " . $result['name'] . "\n";
    } else {
        echo "Store 11 not found\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test 5: Sample products for any store
echo "\n=== Test 5: Sample products from first 5 stores ===\n";
try {
    $result = $db->fetchAll("SELECT store_id, COUNT(*) as count FROM products GROUP BY store_id ORDER BY store_id LIMIT 5");
    print_r($result);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Test 6: Get actual products for store 11
echo "\n=== Test 6: Get products for store 11 (limit 5) ===\n";
try {
    $result = $db->fetchAll("SELECT id, name, sku, quantity, price FROM products WHERE store_id = 11 AND active = TRUE LIMIT 5");
    print_r($result);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
