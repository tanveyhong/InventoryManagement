<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== SALES COUNT BY STORE ===\n\n";

$salesByStore = $db->fetchAll("SELECT store_id, COUNT(*) as total_sales FROM sales GROUP BY store_id");

if (empty($salesByStore)) {
    echo "No sales found in database!\n\n";
} else {
    foreach ($salesByStore as $row) {
        echo "Store ID {$row['store_id']}: {$row['total_sales']} sales\n";
    }
    echo "\n";
}

echo "=== RECENT SALES (Last 5) ===\n\n";
$recentSales = $db->fetchAll("SELECT * FROM sales ORDER BY created_at DESC LIMIT 5");

if (empty($recentSales)) {
    echo "No sales found!\n";
} else {
    foreach ($recentSales as $sale) {
        echo "Sale ID: {$sale['id']}, Store: {$sale['store_id']}, Total: {$sale['total_amount']}, Date: {$sale['created_at']}\n";
    }
}

echo "\n=== TOTAL SALES COUNT ===\n";
$totalCount = $db->fetch("SELECT COUNT(*) as total FROM sales");
echo "Total sales in database: {$totalCount['total']}\n";
