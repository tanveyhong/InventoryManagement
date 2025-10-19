<?php
require_once 'config.php';
require_once 'db.php';

$db = getSQLDB();

echo "=== POS Database Check ===\n\n";

// Check stores
$stores = $db->fetchAll("SELECT id, name, code FROM stores LIMIT 10");
echo "Stores: " . count($stores) . "\n";
foreach ($stores as $store) {
    echo "  - {$store['name']} ({$store['code']})\n";
}

echo "\n";

// Check products
$products = $db->fetchAll("SELECT id, name, sku, price, quantity, store_id FROM products LIMIT 10");
echo "Products: " . count($products) . "\n";
foreach ($products as $product) {
    echo "  - {$product['name']} (SKU: {$product['sku']}) - \${$product['price']} - Qty: {$product['quantity']} - Store ID: {$product['store_id']}\n";
}

echo "\n";

// Check recent sales
try {
    $sales = $db->fetchAll("SELECT id, transaction_id, total, created_at FROM sales ORDER BY created_at DESC LIMIT 5");
    echo "Recent Sales: " . count($sales) . "\n";
    foreach ($sales as $sale) {
        echo "  - {$sale['transaction_id']} - \${$sale['total']} - {$sale['created_at']}\n";
    }
} catch (Exception $e) {
    echo "Sales: No sales yet\n";
}
?>
