<?php
require_once 'config.php';
require_once 'getDB.php';

$sqlDb = getSQLDB();

echo "=== Checking All Product SKU Patterns ===\n\n";

// Check Bar Soap specifically
$products = $sqlDb->fetchAll("SELECT id, name, sku, store_id, active FROM products WHERE name LIKE '%Bar Soap%' ORDER BY sku");

echo "Bar Soap Products (" . count($products) . "):\n";
foreach ($products as $p) {
    $sku = $p['sku'];
    $storeId = $p['store_id'] ?? 'NULL';
    
    // Check patterns
    $hasFirebaseId = preg_match('/-S[a-zA-Z0-9]{20,}/', $sku);
    $hasStoreFormat = preg_match('/-S\d+$/', $sku);
    
    echo "  SKU: $sku | Store: $storeId | Firebase: " . ($hasFirebaseId ? 'YES❌' : 'NO') . " | Store Format: " . ($hasStoreFormat ? 'YES✅' : 'NO') . "\n";
}

echo "\n";

// Check properly formatted store products
echo "=== Properly Formatted Store Products ===\n";
$storeProducts = $sqlDb->fetchAll("SELECT name, sku, store_id FROM products WHERE sku LIKE '%-S%' LIMIT 20");
echo "Found " . count($storeProducts) . " products with -S in SKU:\n";
foreach ($storeProducts as $p) {
    $hasProperFormat = preg_match('/^(.+)-S(\d+)$/', $p['sku'], $matches);
    $status = $hasProperFormat ? "✅" : "❌";
    echo "  $status {$p['name']} | SKU: {$p['sku']} | Store: {$p['store_id']}\n";
}

