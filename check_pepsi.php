<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== CURRENT PEPSI QUANTITIES ===\n\n";

$main = $db->fetch("SELECT * FROM products WHERE sku = 'BEV002' AND store_id IS NULL");
$variant = $db->fetch("SELECT * FROM products WHERE sku = 'BEV002-S6'");

if ($main) {
    echo "Main Product:\n";
    echo "  Name: {$main['name']}\n";
    echo "  SKU: {$main['sku']}\n";
    echo "  Quantity: {$main['quantity']}\n";
    echo "  Updated: {$main['updated_at']}\n\n";
}

if ($variant) {
    echo "Store Variant:\n";
    echo "  Name: {$variant['name']}\n";
    echo "  SKU: {$variant['sku']}\n";
    echo "  Quantity: {$variant['quantity']}\n";
    echo "  Store ID: {$variant['store_id']}\n";
    echo "  Updated: {$variant['updated_at']}\n\n";
}

if ($main && $variant) {
    if ($main['quantity'] == $variant['quantity']) {
        echo "✅ Quantities match: {$main['quantity']}\n";
    } else {
        echo "❌ MISMATCH!\n";
        echo "   Main: {$main['quantity']}\n";
        echo "   Variant: {$variant['quantity']}\n";
        echo "   Main should equal variant total\n";
    }
}
