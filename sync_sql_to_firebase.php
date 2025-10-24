<?php
require_once 'config.php';
require_once 'getDB.php';
require_once 'sql_db.php';

echo "=== SYNCING SQL TO FIREBASE ===\n\n";

try {
    $db = getDB();
    $sqlDb = SQLDatabase::getInstance();
    
    // Get all products from SQL
    $sqlProducts = $sqlDb->fetchAll("SELECT * FROM products WHERE active = 1");
    
    echo "Found " . count($sqlProducts) . " products in SQL database\n";
    echo "Starting sync to Firebase...\n\n";
    
    $synced = 0;
    $errors = 0;
    
    foreach ($sqlProducts as $product) {
        try {
            // Prepare Firebase document
            $firebaseData = [
                'name' => $product['name'] ?? '',
                'sku' => $product['sku'] ?? '',
                'barcode' => $product['barcode'] ?? '',
                'description' => $product['description'] ?? '',
                'category' => $product['category'] ?? '',
                'unit' => $product['unit'] ?? '',
                'cost_price' => floatval($product['cost_price'] ?? 0),
                'selling_price' => floatval($product['selling_price'] ?? 0),
                'price' => floatval($product['price'] ?? 0),
                'quantity' => intval($product['quantity'] ?? 0),
                'reorder_level' => intval($product['reorder_level'] ?? 0),
                'min_stock_level' => intval($product['reorder_level'] ?? 0),
                'expiry_date' => $product['expiry_date'] ?? null,
                'store_id' => $product['store_id'] ?? null,
                'active' => intval($product['active'] ?? 1),
                'created_at' => $product['created_at'] ?? date('c'),
                'updated_at' => date('c')
            ];
            
            // Use the SQL id as the Firebase document ID
            $docId = (string)$product['id'];
            
            // Update Firebase
            $db->update('products', $docId, $firebaseData);
            
            $synced++;
            echo "✓ Synced: {$product['name']} (SKU: {$product['sku']})\n";
            
        } catch (Exception $e) {
            $errors++;
            echo "✗ Error syncing {$product['sku']}: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n=== SYNC COMPLETE ===\n";
    echo "Successfully synced: $synced products\n";
    echo "Errors: $errors\n";
    
    // Clear cache
    $cacheFile = __DIR__ . '/storage/cache/stock_list_data.cache';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
        echo "\n✓ Stock list cache cleared\n";
    }
    
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
}
