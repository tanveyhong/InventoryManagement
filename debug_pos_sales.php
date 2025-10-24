<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== DETAILED SALES CHECK ===\n\n";

// Check SQL sales
echo "1. SQL Database Sales:\n";
$sqlSales = $db->fetchAll("SELECT * FROM sales ORDER BY created_at DESC LIMIT 5");
echo "   Total in SQL: " . count($sqlSales) . "\n";
if (!empty($sqlSales)) {
    foreach ($sqlSales as $sale) {
        echo "   - Sale #{$sale['sale_number']}: RM {$sale['total_amount']} on {$sale['created_at']}\n";
    }
}
echo "\n";

// Check recent product updates
echo "2. Recent Product Updates (stock deductions):\n";
$recentUpdates = $db->fetchAll("SELECT id, name, sku, quantity, updated_at FROM products ORDER BY updated_at DESC LIMIT 10");
foreach ($recentUpdates as $product) {
    echo "   - {$product['name']} (SKU: {$product['sku']}): Qty {$product['quantity']} - Updated: {$product['updated_at']}\n";
}
echo "\n";

// Check stock movements/audits
echo "3. Recent Stock Movements:\n";
$movements = $db->fetchAll("SELECT * FROM stock_movements ORDER BY created_at DESC LIMIT 5");
if (empty($movements)) {
    echo "   No stock movements found in database\n";
} else {
    foreach ($movements as $move) {
        echo "   - Product {$move['product_id']}: {$move['movement_type']} {$move['quantity']} on {$move['created_at']}\n";
    }
}
echo "\n";

// Check if sales table has any data at all
echo "4. Sales Table Info:\n";
$count = $db->fetch("SELECT COUNT(*) as total FROM sales");
echo "   Total sales records: {$count['total']}\n";
