<?php
// Test database connection and add sample data
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

try {
    $sql_db = getSQLDB();
    
    echo "Testing database connection...\n";
    
    // Check if we have any stores
    $store_count = $sql_db->fetch("SELECT COUNT(*) as count FROM stores");
    echo "Current stores: " . $store_count['count'] . "\n";
    
    // Check if we have any products
    $product_count = $sql_db->fetch("SELECT COUNT(*) as count FROM products");
    echo "Current products: " . $product_count['count'] . "\n";
    
    // If no products, add some sample ones
    if ($product_count['count'] == 0) {
        echo "Adding sample products...\n";
        
        // First, ensure we have a store
        if ($store_count['count'] == 0) {
            $sql_db->execute("INSERT INTO stores (name, address, manager_name, active) VALUES (?, ?, ?, ?)",
                ['Main Store', '123 Main St', 'John Manager', 1]);
            echo "Added sample store.\n";
        }
        
        // Get the first store ID
        $store = $sql_db->fetch("SELECT id FROM stores LIMIT 1");
        $store_id = $store['id'];
        
        // Add sample products
        $sample_products = [
            ['Product A', 'SKU001', 100, 10, 'pcs', 5.00, 10.00, $store_id],
            ['Product B', 'SKU002', 50, 20, 'pcs', 3.00, 7.00, $store_id],
            ['Product C', 'SKU003', 5, 25, 'pcs', 15.00, 30.00, $store_id], // Low stock
            ['Product D', 'SKU004', 200, 15, 'pcs', 2.50, 5.00, $store_id],
            ['Product E', 'SKU005', 2, 30, 'pcs', 8.00, 16.00, $store_id], // Low stock
        ];
        
        foreach ($sample_products as $product) {
            $sql_db->execute("
                INSERT INTO products (name, sku, quantity, reorder_level, unit, cost_price, selling_price, price, store_id, active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $product[0], $product[1], $product[2], $product[3], $product[4], 
                    $product[5], $product[6], $product[6], $product[7], 1
                ]);
        }
        
        echo "Added " . count($sample_products) . " sample products.\n";
    }
    
    // Test our dashboard functions
    echo "\nTesting dashboard functions:\n";
    echo "Total Products: " . getTotalProducts() . "\n";
    echo "Low Stock Count: " . getLowStockCount() . "\n";
    echo "Total Stores: " . getTotalStores() . "\n";
    echo "Today's Sales: $" . number_format(getTodaysSales(), 2) . "\n";
    
    echo "\nDatabase test completed successfully!\n";
    
} catch (Exception $e) {
    echo "Database test failed: " . $e->getMessage() . "\n";
}
?>