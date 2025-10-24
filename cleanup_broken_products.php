<?php
require_once 'config.php';
require_once 'getDB.php';

$sqlDb = getSQLDB();

echo "=== Checking for Broken Products ===\n\n";

// Check for products with missing essential data
$sql = "SELECT id, name, sku, price, selling_price, quantity, store_id, active 
        FROM products 
        WHERE active = 1
        ORDER BY sku";

$products = $sqlDb->fetchAll($sql);

$broken = [];
$goodVariants = [];
$needsDelete = [];

foreach ($products as $p) {
    $name = $p['name'] ?? '';
    $price = floatval($p['price'] ?? 0);
    $sellingPrice = floatval($p['selling_price'] ?? 0);
    $sku = $p['sku'];
    
    // Check if broken (missing name or both prices are 0)
    if (empty($name) || ($price == 0 && $sellingPrice == 0)) {
        $broken[] = $p;
    } elseif (preg_match('/-S\d+$/', $sku)) {
        // Good variant with proper format
        $goodVariants[] = $p;
    }
}

echo "Total active products: " . count($products) . "\n";
echo "Broken products (missing name/price): " . count($broken) . "\n";
echo "Good store variants: " . count($goodVariants) . "\n\n";

if (!empty($broken)) {
    echo "=== Broken Products (Should be Deleted) ===\n";
    foreach ($broken as $p) {
        echo "  ID: {$p['id']} | SKU: {$p['sku']} | Name: [{$p['name']}] | Price: {$p['price']}/{$p['selling_price']} | Store: {$p['store_id']}\n";
    }
    
    echo "\n";
    echo "These products are incomplete and should be deleted.\n";
    echo "Delete these " . count($broken) . " broken products? (yes/no): ";
    
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    
    if (strtolower($line) === 'yes') {
        echo "\nDeleting broken products...\n";
        $deleted = 0;
        
        foreach ($broken as $p) {
            try {
                $sqlDb->execute("DELETE FROM products WHERE id = ?", [$p['id']]);
                echo "✓ Deleted: SKU {$p['sku']}\n";
                $deleted++;
            } catch (Exception $e) {
                echo "✗ Failed to delete {$p['sku']}: " . $e->getMessage() . "\n";
            }
        }
        
        echo "\n✅ Deleted $deleted broken products\n";
        
        // Clear caches
        @unlink(__DIR__ . '/storage/cache/pos_products.cache');
        @unlink(__DIR__ . '/storage/cache/stock_list_data.cache');
        echo "✓ Caches cleared\n";
    } else {
        echo "Cancelled.\n";
    }
} else {
    echo "✅ No broken products found!\n";
}

echo "\n=== Sample of Good Store Variants ===\n";
foreach (array_slice($goodVariants, 0, 10) as $p) {
    echo "  {$p['name']} | SKU: {$p['sku']} | Price: RM{$p['selling_price']} | Qty: {$p['quantity']} | Store: {$p['store_id']}\n";
}
