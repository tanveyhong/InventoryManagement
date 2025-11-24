<?php
require 'config.php';
require 'sql_db.php';

$db = SQLDatabase::getInstance();

try {
    $products = $db->fetchAll("SELECT name, sku, price, cost_price FROM products LIMIT 10");
    echo "Name | SKU | Price | Cost Price\n";
    echo "--------------------------------\n";
    foreach ($products as $p) {
        echo "{$p['name']} | {$p['sku']} | {$p['price']} | {$p['cost_price']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>