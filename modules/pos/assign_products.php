<?php
/**
 * Quick Setup: Assign Products to Stores
 * This script helps you assign products to your existing stores
 */

require_once __DIR__ . '/../../db.php';

try {
    $db = getSQLDB();
    
    echo "==========================================\n";
    echo "Product-Store Assignment Tool\n";
    echo "==========================================\n\n";
    
    // Get stores
    $stores = $db->fetchAll("SELECT id, name FROM stores ORDER BY name");
    echo "Available Stores:\n";
    foreach ($stores as $store) {
        echo "  [{$store['id']}] {$store['name']}\n";
    }
    echo "\n";
    
    // Check products without stores
    $unassigned = $db->fetchAll("
        SELECT COUNT(*) as count 
        FROM products 
        WHERE store_id IS NULL OR store_id = 0 OR store_id = ''
    ");
    $unassignedCount = $unassigned[0]['count'];
    
    // Check total products
    $total = $db->fetchAll("SELECT COUNT(*) as count FROM products");
    $totalCount = $total[0]['count'];
    
    echo "Product Status:\n";
    echo "  Total Products: {$totalCount}\n";
    echo "  Unassigned Products: {$unassignedCount}\n";
    echo "  Assigned Products: " . ($totalCount - $unassignedCount) . "\n\n";
    
    if ($unassignedCount > 0) {
        echo "==========================================\n";
        echo "Quick Assignment Options:\n";
        echo "==========================================\n\n";
        
        echo "Choose an option:\n";
        echo "1. Assign ALL products to first store ({$stores[0]['name']})\n";
        echo "2. Distribute products evenly across all stores\n";
        echo "3. Show unassigned products and assign manually\n";
        echo "4. Exit without changes\n\n";
        
        echo "Enter choice (1-4): ";
        $choice = trim(fgets(STDIN));
        
        switch ($choice) {
            case '1':
                // Assign all to first store
                $db->execute("
                    UPDATE products 
                    SET store_id = ? 
                    WHERE store_id IS NULL OR store_id = 0 OR store_id = ''
                ", [$stores[0]['id']]);
                
                echo "\n✅ Success! All {$unassignedCount} products assigned to {$stores[0]['name']}\n";
                break;
                
            case '2':
                // Distribute evenly
                $productsToAssign = $db->fetchAll("
                    SELECT id FROM products 
                    WHERE store_id IS NULL OR store_id = 0 OR store_id = ''
                ");
                
                $storeCount = count($stores);
                $assigned = 0;
                
                foreach ($productsToAssign as $index => $product) {
                    $storeIndex = $index % $storeCount;
                    $storeId = $stores[$storeIndex]['id'];
                    
                    $db->execute("UPDATE products SET store_id = ? WHERE id = ?", [$storeId, $product['id']]);
                    $assigned++;
                }
                
                echo "\n✅ Success! {$assigned} products distributed across {$storeCount} stores\n";
                
                // Show distribution
                echo "\nDistribution:\n";
                foreach ($stores as $store) {
                    $count = $db->fetchAll("SELECT COUNT(*) as count FROM products WHERE store_id = ?", [$store['id']]);
                    echo "  {$store['name']}: {$count[0]['count']} products\n";
                }
                break;
                
            case '3':
                // Show unassigned products
                $products = $db->fetchAll("
                    SELECT id, name, sku, category 
                    FROM products 
                    WHERE store_id IS NULL OR store_id = 0 OR store_id = ''
                    LIMIT 20
                ");
                
                echo "\n First 20 Unassigned Products:\n";
                echo "==========================================\n";
                foreach ($products as $product) {
                    echo "ID: {$product['id']}\n";
                    echo "Name: {$product['name']}\n";
                    echo "SKU: " . ($product['sku'] ?? 'N/A') . "\n";
                    echo "Category: " . ($product['category'] ?? 'N/A') . "\n";
                    echo "---\n";
                }
                
                echo "\nTo assign manually, use:\n";
                echo "UPDATE products SET store_id = X WHERE id = Y;\n";
                break;
                
            case '4':
                echo "\nNo changes made. Exiting...\n";
                break;
                
            default:
                echo "\nInvalid choice. No changes made.\n";
        }
    } else {
        echo "✅ All products are already assigned to stores!\n\n";
        
        // Show distribution
        echo "Product Distribution by Store:\n";
        echo "==========================================\n";
        foreach ($stores as $store) {
            $count = $db->fetchAll("SELECT COUNT(*) as count FROM products WHERE store_id = ?", [$store['id']]);
            echo "{$store['name']}: {$count[0]['count']} products\n";
        }
    }
    
    echo "\n==========================================\n";
    echo "Done! You can now use the POS systems.\n";
    echo "==========================================\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
