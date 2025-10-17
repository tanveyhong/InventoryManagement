<?php
/**
 * Remove Demo Stores
 * This script removes the 5 demo stores and keeps only your Firebase stores
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

echo "==========================================\n";
echo "Remove Demo Stores\n";
echo "==========================================\n\n";

try {
    $db = getSQLDB();
    
    // Demo store codes
    $demoCodes = ['DT001', 'WW002', 'CD003', 'NR004', 'SS005'];
    
    echo "🔍 Checking for demo stores...\n\n";
    
    foreach ($demoCodes as $code) {
        $store = $db->fetch("SELECT id, name, code FROM stores WHERE code = ?", [$code]);
        
        if ($store) {
            echo "   🗑️  Removing: {$store['name']} ({$store['code']})\n";
            
            // Check if store has products
            $productCount = $db->fetch(
                "SELECT COUNT(*) as count FROM products WHERE store_id = ?", 
                [$store['id']]
            );
            
            if ($productCount['count'] > 0) {
                echo "      ⚠️  Warning: This store has {$productCount['count']} product(s)\n";
                echo "      💡 Reassigning products to NULL (unassigned)...\n";
                $db->execute("UPDATE products SET store_id = NULL WHERE store_id = ?", [$store['id']]);
            }
            
            // Delete the store
            $db->execute("DELETE FROM stores WHERE id = ?", [$store['id']]);
            echo "      ✅ Removed\n\n";
        } else {
            echo "   ℹ️  Store $code not found (already removed?)\n\n";
        }
    }
    
    // Show final count
    echo "==========================================\n";
    $finalCount = $db->fetch("SELECT COUNT(*) as count FROM stores");
    echo "✅ Done! Remaining stores: {$finalCount['count']}\n";
    
    // List remaining stores
    echo "\n📋 Your stores:\n";
    $stores = $db->fetchAll("SELECT name, code, city FROM stores ORDER BY name");
    foreach ($stores as $store) {
        $code = $store['code'] ?: '(no code)';
        $city = $store['city'] ?: '(no city)';
        echo "   • {$store['name']} ($code) - $city\n";
    }
    
    echo "\n✨ Your POS now shows only your actual stores!\n";
    echo "==========================================\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
