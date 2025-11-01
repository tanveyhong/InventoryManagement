<?php
require_once 'config.php';
require_once 'functions.php';

$sql_db = getSQLDB();
$today = date('Y-m-d');
$db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';

echo "Debug getAllDashboardStats:\n";
echo "DB Type: $db_type\n";
echo "Today: $today\n";

// Boolean value differs between SQLite (1) and PostgreSQL (TRUE)
$active_value = ($db_type === 'pgsql') ? 'TRUE' : '1';
echo "Active value: $active_value\n\n";

// Single optimized query for all stats
$query = "
    SELECT 
        (SELECT COUNT(*) FROM products WHERE active = $active_value) as total_products,
        (SELECT COUNT(*) FROM products WHERE quantity <= reorder_level AND active = $active_value) as low_stock,
        (SELECT COUNT(*) FROM stores WHERE active = $active_value) as total_stores,
        COALESCE((SELECT SUM(total) FROM sales WHERE DATE(created_at) = ?), 0) as todays_sales
";

echo "Query:\n$query\n\n";

try {
    $result = $sql_db->fetch($query, [$today]);
    echo "Result:\n";
    print_r($result);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
