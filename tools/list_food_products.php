<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$db = getSQLDB();

$foodProducts = $db->fetchAll("SELECT id, name, sku, quantity, price FROM products WHERE category = 'Food' ORDER BY name ASC");

echo "=== Existing Food Products ===\n";
foreach ($foodProducts as $prod) {
    echo sprintf("ID: %d | Name: %s | SKU: %s | Qty: %d | Price: %.2f\n",
        $prod['id'], $prod['name'], $prod['sku'], $prod['quantity'], $prod['price']);
}
?>
