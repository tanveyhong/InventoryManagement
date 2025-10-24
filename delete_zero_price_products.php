<?php
require_once 'config.php';
require_once 'getDB.php';

$sqlDb = getSQLDB();

echo "=== Finding Duplicate Products with Zero Price ===\n\n";

// Find products with store_id but price = 0 (these are likely old duplicates)
$sql = "SELECT id, name, sku, price, selling_price, quantity, store_id 
        FROM products 
        WHERE active = 1 
        AND store_id IS NOT NULL 
        AND price = 0 
        AND selling_price = 0
        ORDER BY name, sku";

$zeroPriceProducts = $sqlDb->fetchAll($sql);

echo "Found " . count($zeroPriceProducts) . " store products with zero prices:\n\n";

if (empty($zeroPriceProducts)) {
    echo "✅ No zero-price products found!\n";
    exit(0);
}

// Group by name to see duplicates
$byName = [];
foreach ($zeroPriceProducts as $p) {
    $name = $p['name'];
    if (!isset($byName[$name])) {
        $byName[$name] = [];
    }
    $byName[$name][] = $p;
}

foreach ($byName as $name => $products) {
    echo "$name (" . count($products) . " variants with RM0):\n";
    foreach ($products as $p) {
        echo "  SKU: {$p['sku']} | Store: {$p['store_id']} | Qty: {$p['quantity']}\n";
    }
    echo "\n";
}

echo "These products have zero prices and are likely duplicates of properly priced versions.\n";
echo "Delete these " . count($zeroPriceProducts) . " zero-price products? (yes/no): ";

$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if (strtolower($line) !== 'yes') {
    echo "❌ Cancelled. No changes made.\n";
    exit(0);
}

echo "\nDeleting zero-price products...\n\n";

$deleted = 0;
foreach ($zeroPriceProducts as $p) {
    try {
        $sqlDb->execute("DELETE FROM products WHERE id = ?", [$p['id']]);
        echo "✓ Deleted: {$p['name']} (SKU: {$p['sku']})\n";
        $deleted++;
    } catch (Exception $e) {
        echo "✗ Failed: {$p['sku']} - " . $e->getMessage() . "\n";
    }
}

echo "\n=== Summary ===\n";
echo "✅ Deleted $deleted zero-price duplicate products\n";

// Clear caches
echo "\nClearing caches...\n";
@unlink(__DIR__ . '/storage/cache/pos_products.cache');
@unlink(__DIR__ . '/storage/cache/stock_list_data.cache');
echo "✓ Caches cleared\n";
echo "\n✓ Done! Refresh your browser.\n";
