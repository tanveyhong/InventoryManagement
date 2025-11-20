<?php
/**
 * Generate Dummy Data for POS Testing
 * Sets some products to high stock (Large Balance) and some to low stock (Low Balance)
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

$db = getSQLDB();

echo "=== Generating Dummy Data for POS ===\n\n";

try {
    // 1. Set High Stock (Large Balance) for 5 random products
    echo "Setting High Stock (1000+) for 5 products...\n";
    $highStockProducts = $db->fetchAll("SELECT id, name FROM products WHERE active = TRUE ORDER BY RANDOM() LIMIT 5");
    
    foreach ($highStockProducts as $p) {
        $newQty = rand(1000, 5000);
        $db->execute("UPDATE products SET quantity = ? WHERE id = ?", [$newQty, $p['id']]);
        echo "✓ Updated '{$p['name']}' to quantity: $newQty\n";
    }
    
    // 2. Set Low Stock (Low Balance) for 5 random products (different ones)
    echo "\nSetting Low Stock (< 10) for 5 products...\n";
    $lowStockProducts = $db->fetchAll("
        SELECT id, name FROM products 
        WHERE active = TRUE 
        AND id NOT IN (" . implode(',', array_column($highStockProducts, 'id')) . ") 
        ORDER BY RANDOM() LIMIT 5
    ");
    
    foreach ($lowStockProducts as $p) {
        $newQty = rand(1, 5);
        $db->execute("UPDATE products SET quantity = ? WHERE id = ?", [$newQty, $p['id']]);
        echo "✓ Updated '{$p['name']}' to quantity: $newQty\n";
    }
    
    echo "\n✅ Dummy data generation complete!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
