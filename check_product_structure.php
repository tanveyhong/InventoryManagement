<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();
$product = $db->fetch("SELECT * FROM products WHERE store_id IS NULL LIMIT 1");

echo "=== MAIN PRODUCT STRUCTURE ===\n\n";
echo json_encode($product, JSON_PRETTY_PRINT);
echo "\n\n=== COLUMN NAMES ===\n";
if ($product) {
    foreach (array_keys($product) as $col) {
        echo "- $col: " . ($product[$col] ?? 'NULL') . "\n";
    }
}
