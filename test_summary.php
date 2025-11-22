<?php
require 'config.php';
require 'sql_db.php';

$db = SQLDatabase::getInstance();
$store_id = 11;

echo "=== Testing Summary Query ===\n";
try {
    $summary = $db->fetch("SELECT 
                            COUNT(*) as total_products,
                            SUM(quantity) as total_quantity,
                            SUM(quantity * price) as total_value,
                            COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock,
                            COUNT(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 END) as low_stock
                        FROM products 
                        WHERE store_id = ? AND active = TRUE", [$store_id]);
    
    echo "Summary results:\n";
    print_r($summary);
    
    echo "\n=== Testing Individual Values ===\n";
    echo "Total Products: " . ($summary['total_products'] ?? 0) . "\n";
    echo "Total Quantity: " . ($summary['total_quantity'] ?? 0) . "\n";
    echo "Total Value: $" . number_format($summary['total_value'] ?? 0, 2) . "\n";
    echo "Out of Stock: " . ($summary['out_of_stock'] ?? 0) . "\n";
    echo "Low Stock: " . ($summary['low_stock'] ?? 0) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Check actual products data ===\n";
try {
    $products = $db->fetchAll("SELECT name, quantity, price, reorder_level FROM products WHERE store_id = ? AND active = TRUE", [$store_id]);
    print_r($products);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
