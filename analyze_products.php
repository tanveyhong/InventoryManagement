<?php
require_once 'config.php';
require_once 'getDB.php';

$sqlDb = getSQLDB();

echo "=== Analyzing Product Data ===\n\n";

// Get all active products
$allProducts = $sqlDb->fetchAll("
    SELECT id, name, sku, price, selling_price, quantity, store_id, active 
    FROM products 
    WHERE active = 1 
    ORDER BY name, sku
");

echo "Total active products: " . count($allProducts) . "\n\n";

// Categorize products
$mainProducts = []; // No store_id
$storeVariants = []; // Has store_id and proper -S\d+ suffix
$malformed = []; // Has store_id but wrong SKU format
$duplicates = []; // Similar SKUs

foreach ($allProducts as $p) {
    $storeId = $p['store_id'];
    $sku = $p['sku'];
    $name = $p['name'] ?? '';
    $price = max(floatval($p['price'] ?? 0), floatval($p['selling_price'] ?? 0));
    
    if (empty($storeId)) {
        $mainProducts[] = $p;
    } elseif (preg_match('/-S\d+$/', $sku)) {
        $storeVariants[] = $p;
    } else {
        $malformed[] = $p;
    }
}

echo "Breakdown:\n";
echo "  Main products (no store): " . count($mainProducts) . "\n";
echo "  Store variants (proper format): " . count($storeVariants) . "\n";
echo "  Malformed (has store but wrong SKU): " . count($malformed) . "\n\n";

if (!empty($mainProducts)) {
    echo "=== Sample Main Products ===\n";
    foreach (array_slice($mainProducts, 0, 5) as $p) {
        echo "  {$p['name']} | SKU: {$p['sku']} | Price: RM{$p['selling_price']} | Store: " . ($p['store_id'] ?? 'NULL') . "\n";
    }
    echo "\n";
}

if (!empty($storeVariants)) {
    echo "=== Sample Store Variants (First 10) ===\n";
    foreach (array_slice($storeVariants, 0, 10) as $p) {
        $price = max(floatval($p['price']), floatval($p['selling_price']));
        echo "  {$p['name']} | SKU: {$p['sku']} | Price: RM{$price} | Qty: {$p['quantity']} | Store: {$p['store_id']}\n";
    }
    echo "\n";
}

if (!empty($malformed)) {
    echo "=== Malformed Products (Should Not Exist) ===\n";
    foreach ($malformed as $p) {
        echo "  {$p['name']} | SKU: {$p['sku']} | Store: {$p['store_id']}\n";
    }
    echo "\n";
}

// Check for SKU duplicates
echo "=== Checking for Duplicate Base SKUs ===\n";
$skuGroups = [];
foreach ($allProducts as $p) {
    // Extract base SKU
    $baseSku = preg_replace('/-S\d+$/', '', $p['sku']);
    if (!isset($skuGroups[$baseSku])) {
        $skuGroups[$baseSku] = [];
    }
    $skuGroups[$baseSku][] = $p;
}

$duplicateBases = array_filter($skuGroups, function($group) {
    return count($group) > 1;
});

echo "Found " . count($duplicateBases) . " base SKUs with multiple variants:\n";
$sample = 0;
foreach ($duplicateBases as $baseSku => $group) {
    if ($sample++ >= 5) break;
    echo "\nBase SKU: $baseSku (" . count($group) . " variants)\n";
    foreach ($group as $p) {
        $price = max(floatval($p['price']), floatval($p['selling_price']));
        echo "  - {$p['name']} | SKU: {$p['sku']} | Price: RM{$price} | Store: " . ($p['store_id'] ?? 'MAIN') . "\n";
    }
}
