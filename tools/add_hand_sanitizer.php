<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$db = getSQLDB();

// Add single non-food product
$db->execute("INSERT INTO products (name, sku, category, quantity, price, active) VALUES (?, ?, ?, ?, ?, TRUE)", [
    'Hand Sanitizer 500ml', 'HS-500', 'Product', 200, 15.90
]);
echo "Added product: Hand Sanitizer 500ml\n";
?>
