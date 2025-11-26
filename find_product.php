<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();
$sku = 'DARL-038-S8';
$product = $db->fetch("SELECT * FROM products WHERE sku = ?", [$sku]);

if ($product) {
    echo "Found Product: ID " . $product['id'] . " - " . $product['name'] . "\n";
    echo "Current Stock: " . $product['quantity'] . "\n";
} else {
    echo "Product not found for SKU: $sku\n";
}
