<?php
require_once 'config.php';
require_once 'getDB.php';

$sqlDb = getSQLDB();

echo "=== Deleting Firebase Duplicate Products ===\n\n";

// Find products with Firebase random IDs in SKU
$malformed = $sqlDb->fetchAll("
    SELECT id, name, sku, store_id 
    FROM products 
    WHERE active = 1 
    AND sku LIKE '%-S%'
");

$toDelete = [];
foreach ($malformed as $p) {
    // Check if it has Firebase random ID (20+ chars after -S)
    if (preg_match('/-S[a-zA-Z0-9]{20,}/', $p['sku'])) {
        $toDelete[] = $p;
    }
}

echo "Found " . count($toDelete) . " Firebase duplicate products:\n\n";

foreach ($toDelete as $p) {
    echo "  {$p['name']} | SKU: {$p['sku']} | Store: {$p['store_id']}\n";
}

if (empty($toDelete)) {
    echo "✅ No Firebase duplicates found!\n";
    exit(0);
}

echo "\nDelete these " . count($toDelete) . " duplicate products? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if (strtolower($line) !== 'yes') {
    echo "❌ Cancelled.\n";
    exit(0);
}

echo "\nDeleting...\n";
$deleted = 0;

foreach ($toDelete as $p) {
    try {
        $sqlDb->execute("DELETE FROM products WHERE id = ?", [$p['id']]);
        echo "✓ Deleted: {$p['name']} (SKU: {$p['sku']})\n";
        $deleted++;
    } catch (Exception $e) {
        echo "✗ Failed: {$p['sku']} - " . $e->getMessage() . "\n";
    }
}

echo "\n✅ Deleted $deleted Firebase duplicates\n";

// Clear caches
@unlink(__DIR__ . '/storage/cache/pos_products.cache');
@unlink(__DIR__ . '/storage/cache/stock_list_data.cache');
echo "✓ Caches cleared\n";
