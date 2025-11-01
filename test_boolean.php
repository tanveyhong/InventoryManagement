<?php
require 'config.php';
require 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "Testing boolean queries:\n";
echo str_repeat("-", 40) . "\n";

// Test 1: All products
$all = $db->fetchAll("SELECT COUNT(*) as cnt FROM products");
echo "Total products: " . $all[0]['cnt'] . "\n";

// Test 2: Active = TRUE
$active_true = $db->fetchAll("SELECT COUNT(*) as cnt FROM products WHERE active = TRUE");
echo "Active (TRUE): " . $active_true[0]['cnt'] . "\n";

// Test 3: Active = 1
$active_one = $db->fetchAll("SELECT COUNT(*) as cnt FROM products WHERE active = 1");
echo "Active (1): " . $active_one[0]['cnt'] . "\n";

// Test 4: Check actual values
$sample = $db->fetchAll("SELECT id, name, active FROM products LIMIT 5");
echo "\nSample products:\n";
foreach ($sample as $p) {
    echo "  ID {$p['id']}: {$p['name']} - active: " . var_export($p['active'], true) . "\n";
}
