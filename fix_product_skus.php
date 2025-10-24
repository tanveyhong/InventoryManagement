<?php
/**
 * Fix Product SKUs - Add proper store suffix to malformed SKUs
 * This script finds products with store_id but missing store suffix in SKU
 * and updates them to the correct format (e.g., CARE-003 -> CARE-003-S6)
 */

require_once 'config.php';
require_once 'getDB.php';

$sqlDb = getSQLDB();
$db = getDB();

echo "=== Fixing Product SKUs ===\n\n";

// Step 1: Find products with store_id but no store suffix
echo "Step 1: Finding products with malformed SKUs...\n";

$malformedProducts = [];

try {
    $sql = "SELECT id, name, sku, store_id FROM products WHERE store_id IS NOT NULL AND store_id != ''";
    $products = $sqlDb->fetchAll($sql);
    
    foreach ($products as $product) {
        $sku = $product['sku'];
        $storeId = $product['store_id'];
        
        // Check if SKU has proper store suffix (-S followed by digits)
        if (!preg_match('/-S\d+$/', $sku)) {
            // Check if it has Firebase random ID (skip these, they're duplicates)
            if (preg_match('/-S[a-zA-Z0-9]{20,}/', $sku)) {
                continue; // Skip Firebase duplicates
            }
            
            $malformedProducts[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'old_sku' => $sku,
                'new_sku' => $sku . '-S' . $storeId,
                'store_id' => $storeId
            ];
        }
    }
    
    echo "Found " . count($malformedProducts) . " products with malformed SKUs:\n\n";
    
    foreach ($malformedProducts as $p) {
        echo "  {$p['name']}\n";
        echo "    Old SKU: {$p['old_sku']} (Store: {$p['store_id']})\n";
        echo "    New SKU: {$p['new_sku']}\n";
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($malformedProducts)) {
    echo "✅ No malformed SKUs found! All products have correct format.\n";
    exit(0);
}

// Step 2: Ask for confirmation
echo "\nDo you want to update these " . count($malformedProducts) . " products? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if (strtolower($line) !== 'yes') {
    echo "❌ Update cancelled. No changes made.\n";
    exit(0);
}

// Step 3: Update SKUs
echo "\nStep 2: Updating SKUs...\n\n";

$successCount = 0;
$failCount = 0;

foreach ($malformedProducts as $product) {
    try {
        // Update SQL database
        $updateSql = "UPDATE products SET sku = ?, updated_at = ? WHERE id = ?";
        $sqlDb->execute($updateSql, [$product['new_sku'], date('c'), $product['id']]);
        
        // Update Firebase
        try {
            $db->update('products', $product['id'], [
                'sku' => $product['new_sku'],
                'updated_at' => date('c')
            ]);
        } catch (Exception $e) {
            error_log("Firebase update failed for {$product['id']}: " . $e->getMessage());
        }
        
        echo "✓ Updated: {$product['name']}\n";
        echo "  {$product['old_sku']} → {$product['new_sku']}\n";
        $successCount++;
        
    } catch (Exception $e) {
        echo "✗ Failed: {$product['name']} - " . $e->getMessage() . "\n";
        $failCount++;
    }
}

echo "\n=== Summary ===\n";
echo "✅ Successfully updated: $successCount products\n";
if ($failCount > 0) {
    echo "❌ Failed to update: $failCount products\n";
}

echo "\nClearing caches...\n";
$cacheFiles = [
    __DIR__ . '/storage/cache/pos_products.cache',
    __DIR__ . '/storage/cache/stock_list_data.cache'
];

foreach ($cacheFiles as $file) {
    if (file_exists($file)) {
        @unlink($file);
    }
}

echo "✓ Done! Refresh your browser to see the changes.\n";
