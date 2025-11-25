<?php
require_once 'config.php';
require_once 'db.php';

$db = getSQLDB();

// Find the Desk Lamp product
$main = $db->fetch("SELECT * FROM products WHERE sku = 'LAMP-037'");
if (!$main) {
    echo "Main product LAMP-037 not found.\n";
    exit;
}

echo "Main Product: ID={$main['id']}, SKU={$main['sku']}, Name={$main['name']}\n";

// Find potential variants
$variants = $db->fetchAll("SELECT id, sku, name, store_id FROM products WHERE sku LIKE 'LAMP-037-%'");
echo "Found " . count($variants) . " variants by SKU pattern 'LAMP-037-%':\n";
foreach ($variants as $v) {
    echo " - ID: {$v['id']}, SKU: {$v['sku']}, Name: {$v['name']}, StoreID: {$v['store_id']}\n";
}

// Search by Name
echo "\nSearching by Name 'Desk Lamp%':\n";
$byName = $db->fetchAll("SELECT id, sku, name, store_id FROM products WHERE name LIKE 'Desk Lamp%'");
foreach ($byName as $v) {
    echo " - ID: {$v['id']}, SKU: {$v['sku']}, Name: {$v['name']}, StoreID: {$v['store_id']}\n";
}

// Check what get_assigned_stores.php would return
$sku = $main['sku'];
$name = $main['name'];
$assigned = $db->fetchAll(
    "SELECT store_id FROM products WHERE (sku LIKE ? OR name = ?) AND store_id IS NOT NULL", 
    [$sku . '-%', $name]
);
echo "\nQuery Result for get_assigned_stores logic:\n";
print_r($assigned);

// List all stores
echo "\nStores:\n";
$stores = $db->fetchAll("SELECT id, name FROM stores");
foreach ($stores as $s) {
    echo " - ID: {$s['id']}, Name: {$s['name']}\n";
}
