<?php
/**
 * Remove Demo Products
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

echo "==========================================\n";
echo "Remove Demo Products\n";
echo "==========================================\n\n";

try {
    $db = getSQLDB();
    
    // Demo product SKUs
    $demoSKUs = ['LAP001', 'CHR001', 'PPR001', 'DLM001', 'MSE001', 'KEY001', 'MON001', 'FIL001'];
    
    echo "🔍 Checking for demo products...\n\n";
    
    $removed = 0;
    foreach ($demoSKUs as $sku) {
        $product = $db->fetch("SELECT id, name, sku FROM products WHERE sku = ?", [$sku]);
        
        if ($product) {
            echo "   🗑️  Removing: {$product['name']} ({$product['sku']})\n";
            $db->execute("DELETE FROM products WHERE id = ?", [$product['id']]);
            $removed++;
        }
    }
    
    echo "\n==========================================\n";
    echo "✅ Removed $removed demo product(s)\n";
    
    // Show remaining products
    $remaining = $db->fetch("SELECT COUNT(*) as count FROM products");
    echo "📦 Remaining products: {$remaining['count']}\n";
    
    if ($remaining['count'] > 0) {
        echo "\n📋 Your products:\n";
        $products = $db->fetchAll("SELECT name, sku, quantity FROM products ORDER BY name LIMIT 20");
        foreach ($products as $p) {
            echo "   • {$p['name']} ({$p['sku']}) - Qty: {$p['quantity']}\n";
        }
    }
    
    echo "\n==========================================\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
