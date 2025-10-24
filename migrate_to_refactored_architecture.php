<?php
/**
 * MIGRATION SCRIPT: Convert to Main Product + Store Variants Architecture
 * ========================================================================
 * 
 * This script:
 * 1. Identifies unique products (by base SKU)
 * 2. Creates main products (without store_id)
 * 3. Converts existing products to store variants
 * 4. Recalculates main product quantities
 * 5. Syncs to Firebase
 */

require_once 'config.php';
require_once 'getDB.php';
require_once 'sql_db.php';

echo "=== INVENTORY ARCHITECTURE MIGRATION ===\n\n";
echo "This will convert your inventory to the new Main Product + Store Variants system.\n";
echo "A backup will be created before migration.\n\n";

// Confirm before proceeding
echo "Do you want to continue? (yes/no): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
if (strtolower($line) !== 'yes') {
    echo "Migration cancelled.\n";
    exit;
}

try {
    $db = getDB();
    $sqlDb = SQLDatabase::getInstance();
    
    // Step 1: Backup current data
    echo "\n[1/6] Creating backup...\n";
    $backupDir = __DIR__ . '/storage/backups/';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $backupFile = $backupDir . 'pre_migration_' . date('Y-m-d_His') . '.sql';
    $sqliteFile = __DIR__ . '/inventory.db';
    
    if (file_exists($sqliteFile)) {
        copy($sqliteFile, $backupFile);
        echo "✓ Backup created: $backupFile\n";
    }
    
    // Step 2: Analyze current products
    echo "\n[2/6] Analyzing current products...\n";
    $currentProducts = $sqlDb->fetchAll("SELECT * FROM products WHERE active = 1 ORDER BY sku");
    
    // Group by base SKU
    $productGroups = [];
    foreach ($currentProducts as $product) {
        $baseSku = preg_replace('/-S\d+$/', '', $product['sku']);
        if (!isset($productGroups[$baseSku])) {
            $productGroups[$baseSku] = [];
        }
        $productGroups[$baseSku][] = $product;
    }
    
    echo "Found " . count($currentProducts) . " products\n";
    echo "Grouped into " . count($productGroups) . " unique product types\n";
    
    // Step 3: Create main products
    echo "\n[3/6] Creating main products...\n";
    $sqlDb->execute("BEGIN TRANSACTION");
    
    $mainProductMap = []; // base_sku => main_product_id
    $created = 0;
    $skipped = 0;
    
    foreach ($productGroups as $baseSku => $variants) {
        // Check if main product already exists
        $existing = $sqlDb->fetch("SELECT id FROM products WHERE sku = ? AND store_id IS NULL", [$baseSku]);
        
        if ($existing) {
            $mainProductMap[$baseSku] = $existing['id'];
            $skipped++;
            echo "  ○ Skipped (exists): $baseSku\n";
            continue;
        }
        
        // Use first variant as template for main product
        $template = $variants[0];
        
        // Calculate total quantity across all variants
        $totalQty = 0;
        foreach ($variants as $v) {
            $totalQty += (int)$v['quantity'];
        }
        
        // Create main product
        $sql = "
            INSERT INTO products
                (name, sku, barcode, description, category, unit, cost_price, price, quantity, reorder_level, expiry_date, store_id, active, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ";
        
        $sqlDb->execute($sql, [
            $template['name'],
            $baseSku,
            $template['barcode'],
            $template['description'],
            $template['category'],
            $template['unit'] ?? 'pcs',
            $template['cost_price'] ?? 0,
            $template['price'],
            $totalQty,
            $template['reorder_level'],
            $template['expiry_date']
        ]);
        
        $mainProductId = $sqlDb->lastInsertId();
        $mainProductMap[$baseSku] = $mainProductId;
        $created++;
        
        echo "  ✓ Created: $baseSku (ID: $mainProductId, Qty: $totalQty)\n";
    }
    
    $sqlDb->execute("COMMIT");
    echo "Created $created main products, skipped $skipped existing\n";
    
    // Step 4: Update existing products to proper store variant SKUs
    echo "\n[4/6] Converting to store variants...\n";
    $sqlDb->execute("BEGIN TRANSACTION");
    
    $converted = 0;
    foreach ($currentProducts as $product) {
        if (empty($product['store_id'])) {
            echo "  ○ Skipped (already main): {$product['sku']}\n";
            continue;
        }
        
        $baseSku = preg_replace('/-S\d+$/', '', $product['sku']);
        $expectedSku = $baseSku . '-S' . $product['store_id'];
        
        // If SKU is already correct, skip
        if ($product['sku'] === $expectedSku) {
            echo "  ○ Already correct: {$product['sku']}\n";
            continue;
        }
        
        // Update to proper format
        $sqlDb->execute(
            "UPDATE products SET sku = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$expectedSku, $product['id']]
        );
        
        $converted++;
        echo "  ✓ Converted: {$product['sku']} → $expectedSku\n";
    }
    
    $sqlDb->execute("COMMIT");
    echo "Converted $converted products to store variants\n";
    
    // Step 5: Recalculate main product quantities
    echo "\n[5/6] Recalculating main product quantities...\n";
    $sqlDb->execute("BEGIN TRANSACTION");
    
    $recalculated = 0;
    foreach ($mainProductMap as $baseSku => $mainId) {
        // Get all variants
        $variants = $sqlDb->fetchAll(
            "SELECT quantity FROM products WHERE sku LIKE ? AND store_id IS NOT NULL",
            [$baseSku . '-S%']
        );
        
        // Sum quantities
        $totalQty = 0;
        foreach ($variants as $v) {
            $totalQty += (int)$v['quantity'];
        }
        
        // Update main product
        $sqlDb->execute(
            "UPDATE products SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            [$totalQty, $mainId]
        );
        
        $recalculated++;
        echo "  ✓ Recalculated: $baseSku → $totalQty units\n";
    }
    
    $sqlDb->execute("COMMIT");
    echo "Recalculated $recalculated main products\n";
    
    // Step 6: Sync to Firebase
    echo "\n[6/6] Syncing to Firebase...\n";
    $synced = 0;
    $errors = 0;
    
    $allProducts = $sqlDb->fetchAll("SELECT * FROM products WHERE active = 1");
    foreach ($allProducts as $product) {
        try {
            $firebaseData = [
                'name'          => $product['name'],
                'sku'           => $product['sku'],
                'barcode'       => $product['barcode'],
                'description'   => $product['description'],
                'category'      => $product['category'],
                'unit'          => $product['unit'] ?? 'pcs',
                'cost_price'    => floatval($product['cost_price'] ?? 0),
                'selling_price' => floatval($product['price'] ?? 0),
                'price'         => floatval($product['price'] ?? 0),
                'quantity'      => intval($product['quantity'] ?? 0),
                'reorder_level' => intval($product['reorder_level'] ?? 0),
                'min_stock_level' => intval($product['reorder_level'] ?? 0),
                'expiry_date'   => $product['expiry_date'],
                'store_id'      => $product['store_id'],
                'active'        => intval($product['active']),
                'created_at'    => $product['created_at'] ?? date('c'),
                'updated_at'    => date('c')
            ];
            
            $db->update('products', (string)$product['id'], $firebaseData);
            $synced++;
            
        } catch (Exception $e) {
            $errors++;
            echo "  ✗ Error syncing {$product['sku']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "Synced $synced products to Firebase ($errors errors)\n";
    
    // Clear caches
    echo "\nClearing caches...\n";
    @unlink(__DIR__ . '/storage/cache/stock_list_data.cache');
    @unlink(__DIR__ . '/storage/cache/pos_products.cache');
    echo "✓ Caches cleared\n";
    
    // Summary
    echo "\n=== MIGRATION COMPLETE ===\n";
    echo "Main products created: $created\n";
    echo "Store variants converted: $converted\n";
    echo "Quantities recalculated: $recalculated\n";
    echo "Firebase synced: $synced\n";
    echo "\n✓ Migration successful! Backup saved at: $backupFile\n";
    echo "\nNext steps:\n";
    echo "1. Refresh your stock list page\n";
    echo "2. Verify main products and variants display correctly\n";
    echo "3. Test stock adjustments to ensure cascading updates work\n";
    echo "4. Use add_main_product.php for new products going forward\n";
    
} catch (Exception $e) {
    echo "\n✗ MIGRATION FAILED: " . $e->getMessage() . "\n";
    echo "Your data has NOT been modified. Please check the error and try again.\n";
}
