<?php
// Quick test to verify products are in SQL for the POS store
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    
    echo "=== POS STORES AND THEIR PRODUCTS ===\n\n";
    
    // Get all POS-enabled stores
    $stores = $db->fetchAll("SELECT id, name, has_pos FROM stores WHERE has_pos = 1 ORDER BY name");
    
    foreach ($stores as $store) {
        echo "Store: {$store['name']} (ID: {$store['id']})\n";
        echo str_repeat('-', 60) . "\n";
        
        // Get products for this store
        $products = $db->fetchAll(
            "SELECT id, name, sku, quantity, price, selling_price, active 
             FROM products 
             WHERE store_id = ? 
             ORDER BY name 
             LIMIT 20",
            [$store['id']]
        );
        
        if (empty($products)) {
            echo "  ⚠️  NO PRODUCTS FOUND for this store!\n";
        } else {
            echo "  Total products: " . count($products) . "\n\n";
            foreach ($products as $p) {
                $price = $p['selling_price'] ?? $p['price'] ?? 0;
                $status = $p['active'] ? '✓' : '✗';
                echo "  {$status} [{$p['id']}] {$p['name']}\n";
                echo "     SKU: {$p['sku']} | Qty: {$p['quantity']} | Price: RM {$price}\n";
            }
        }
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
