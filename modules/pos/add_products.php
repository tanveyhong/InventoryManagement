<?php
/**
 * Add Products to Database
 * This script inserts products into the database for POS use
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

// Get the store to assign products to (first available store with POS enabled)
$db = getSQLDB();

try {
    // Get a store with POS enabled
    $store = $db->fetch("SELECT id, name FROM stores WHERE has_pos = 1 AND (active = 1 OR active IS NULL) LIMIT 1");
    
    if (!$store) {
        // If no POS-enabled store, use any active store
        $store = $db->fetch("SELECT id, name FROM stores WHERE active = 1 OR active IS NULL LIMIT 1");
    }
    
    if (!$store) {
        die("Error: No stores found in database. Please add a store first.");
    }
    
    $storeId = $store['id'];
    $storeName = $store['name'];
    
    echo "Adding products to store: {$storeName} (ID: {$storeId})" . PHP_EOL . PHP_EOL;
    
    // Define products to add
    $products = [
        // Beverages
        ['name' => 'Coca-Cola 330ml', 'sku' => 'BEV-001', 'barcode' => '5000112637724', 'category' => 'Beverages', 'price' => 2.50, 'cost_price' => 1.50, 'quantity' => 100, 'reorder_level' => 20, 'description' => 'Classic Coca-Cola can', 'unit' => 'can'],
        ['name' => 'Pepsi 330ml', 'sku' => 'BEV-002', 'barcode' => '12000810060', 'category' => 'Beverages', 'price' => 2.50, 'cost_price' => 1.50, 'quantity' => 100, 'reorder_level' => 20, 'description' => 'Pepsi cola can', 'unit' => 'can'],
        ['name' => 'Mineral Water 500ml', 'sku' => 'BEV-003', 'barcode' => '8718114743486', 'category' => 'Beverages', 'price' => 1.50, 'cost_price' => 0.80, 'quantity' => 150, 'reorder_level' => 30, 'description' => 'Pure mineral water', 'unit' => 'bottle'],
        ['name' => 'Orange Juice 1L', 'sku' => 'BEV-004', 'barcode' => '5060155210103', 'category' => 'Beverages', 'price' => 4.50, 'cost_price' => 2.80, 'quantity' => 60, 'reorder_level' => 15, 'description' => 'Fresh orange juice', 'unit' => 'bottle'],
        ['name' => 'Coffee Arabica 250g', 'sku' => 'BEV-005', 'barcode' => '8901491101790', 'category' => 'Beverages', 'price' => 8.99, 'cost_price' => 5.50, 'quantity' => 40, 'reorder_level' => 10, 'description' => 'Premium Arabica coffee beans', 'unit' => 'pack'],
        
        // Snacks
        ['name' => 'Lays Potato Chips', 'sku' => 'SNK-001', 'barcode' => '28400060844', 'category' => 'Snacks', 'price' => 3.50, 'cost_price' => 2.00, 'quantity' => 80, 'reorder_level' => 20, 'description' => 'Classic salted chips', 'unit' => 'pack'],
        ['name' => 'Chocolate Bar', 'sku' => 'SNK-002', 'barcode' => '40111393', 'category' => 'Snacks', 'price' => 2.80, 'cost_price' => 1.60, 'quantity' => 150, 'reorder_level' => 30, 'description' => 'Milk chocolate bar', 'unit' => 'bar'],
        ['name' => 'Oreo Cookies', 'sku' => 'SNK-003', 'barcode' => '44000048426', 'category' => 'Snacks', 'price' => 4.20, 'cost_price' => 2.50, 'quantity' => 90, 'reorder_level' => 20, 'description' => 'Classic Oreo cookies', 'unit' => 'pack'],
        ['name' => 'Candy Mix', 'sku' => 'SNK-004', 'barcode' => '40000536901', 'category' => 'Snacks', 'price' => 2.00, 'cost_price' => 1.00, 'quantity' => 200, 'reorder_level' => 40, 'description' => 'Assorted candies', 'unit' => 'pack'],
        ['name' => 'Mixed Nuts 200g', 'sku' => 'SNK-005', 'barcode' => '8711200421107', 'category' => 'Snacks', 'price' => 6.50, 'cost_price' => 4.00, 'quantity' => 50, 'reorder_level' => 15, 'description' => 'Roasted mixed nuts', 'unit' => 'pack'],
        
        // Food Items
        ['name' => 'Instant Noodles', 'sku' => 'FOOD-001', 'barcode' => '8851234567890', 'category' => 'Food', 'price' => 1.50, 'cost_price' => 0.80, 'quantity' => 120, 'reorder_level' => 30, 'description' => 'Quick meal noodles', 'unit' => 'pack'],
        ['name' => 'Bread Loaf', 'sku' => 'FOOD-002', 'barcode' => '5410063014858', 'category' => 'Food', 'price' => 3.50, 'cost_price' => 2.00, 'quantity' => 50, 'reorder_level' => 15, 'description' => 'Fresh white bread', 'unit' => 'loaf'],
        ['name' => 'Sandwich Pack', 'sku' => 'FOOD-003', 'barcode' => '5000291061053', 'category' => 'Food', 'price' => 5.50, 'cost_price' => 3.20, 'quantity' => 30, 'reorder_level' => 10, 'description' => 'Ready-to-eat sandwich', 'unit' => 'pack'],
        ['name' => 'Rice 5kg', 'sku' => 'FOOD-004', 'barcode' => '8850367000047', 'category' => 'Food', 'price' => 15.00, 'cost_price' => 10.00, 'quantity' => 25, 'reorder_level' => 8, 'description' => 'Premium jasmine rice', 'unit' => 'bag'],
        
        // Dairy Products
        ['name' => 'Fresh Milk 1L', 'sku' => 'DAIRY-001', 'barcode' => '9300652001144', 'category' => 'Dairy', 'price' => 4.50, 'cost_price' => 2.80, 'quantity' => 40, 'reorder_level' => 12, 'description' => 'Full cream milk', 'unit' => 'bottle'],
        ['name' => 'Yogurt 500g', 'sku' => 'DAIRY-002', 'barcode' => '3083681067040', 'category' => 'Dairy', 'price' => 3.80, 'cost_price' => 2.20, 'quantity' => 60, 'reorder_level' => 15, 'description' => 'Greek yogurt', 'unit' => 'tub'],
        ['name' => 'Cheese Slices', 'sku' => 'DAIRY-003', 'barcode' => '21000102037', 'category' => 'Dairy', 'price' => 6.50, 'cost_price' => 4.00, 'quantity' => 45, 'reorder_level' => 12, 'description' => 'Cheddar cheese slices', 'unit' => 'pack'],
        
        // Personal Care
        ['name' => 'Shampoo 400ml', 'sku' => 'CARE-001', 'barcode' => '8901030753558', 'category' => 'Personal Care', 'price' => 7.50, 'cost_price' => 4.50, 'quantity' => 35, 'reorder_level' => 10, 'description' => 'Anti-dandruff shampoo', 'unit' => 'bottle'],
        ['name' => 'Toothpaste', 'sku' => 'CARE-002', 'barcode' => '3014230901058', 'category' => 'Personal Care', 'price' => 4.20, 'cost_price' => 2.50, 'quantity' => 70, 'reorder_level' => 20, 'description' => 'Whitening toothpaste', 'unit' => 'tube'],
        ['name' => 'Bar Soap', 'sku' => 'CARE-003', 'barcode' => '8901030685866', 'category' => 'Personal Care', 'price' => 2.50, 'cost_price' => 1.30, 'quantity' => 100, 'reorder_level' => 25, 'description' => 'Antibacterial soap', 'unit' => 'bar'],
        
        // Household
        ['name' => 'Tissues Box', 'sku' => 'HOUSE-001', 'barcode' => '036000406535', 'category' => 'Household', 'price' => 3.00, 'cost_price' => 1.80, 'quantity' => 80, 'reorder_level' => 20, 'description' => 'Soft facial tissues', 'unit' => 'box'],
        ['name' => 'Kitchen Roll', 'sku' => 'HOUSE-002', 'barcode' => '8720182001061', 'category' => 'Household', 'price' => 4.50, 'cost_price' => 2.70, 'quantity' => 60, 'reorder_level' => 15, 'description' => 'Paper towel roll', 'unit' => 'roll'],
        ['name' => 'Garbage Bags', 'sku' => 'HOUSE-003', 'barcode' => '3228880013002', 'category' => 'Household', 'price' => 5.50, 'cost_price' => 3.20, 'quantity' => 50, 'reorder_level' => 12, 'description' => '30L garbage bags', 'unit' => 'pack'],
    ];
    
    $addedCount = 0;
    $skippedCount = 0;
    
    foreach ($products as $product) {
        // Check if product already exists
        $existing = $db->fetch("SELECT id FROM products WHERE sku = ? OR barcode = ?", [$product['sku'], $product['barcode']]);
        
        if ($existing) {
            echo "⏭️  Skipped: {$product['name']} (already exists)" . PHP_EOL;
            $skippedCount++;
            continue;
        }
        
        // Insert product
        $query = "INSERT INTO products (
            name, sku, barcode, description, category, unit,
            cost_price, selling_price, price, quantity, reorder_level,
            store_id, active, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, datetime('now'), datetime('now'))";
        
        $params = [
            $product['name'],
            $product['sku'],
            $product['barcode'],
            $product['description'],
            $product['category'],
            $product['unit'],
            $product['cost_price'],
            $product['price'], // selling_price
            $product['price'], // price
            $product['quantity'],
            $product['reorder_level'],
            $storeId
        ];
        
        $result = $db->execute($query, $params);
        
        if ($result) {
            echo "✅ Added: {$product['name']} (\${$product['price']}, Stock: {$product['quantity']})" . PHP_EOL;
            $addedCount++;
        } else {
            echo "❌ Failed: {$product['name']}" . PHP_EOL;
        }
    }
    
    echo PHP_EOL . "=== SUMMARY ===" . PHP_EOL;
    echo "✅ Added: {$addedCount} products" . PHP_EOL;
    echo "⏭️  Skipped: {$skippedCount} products (already exist)" . PHP_EOL;
    
    // Show total products in database
    $total = $db->fetch("SELECT COUNT(*) as count FROM products WHERE store_id = ?", [$storeId]);
    echo "📦 Total products for {$storeName}: {$total['count']}" . PHP_EOL;
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . PHP_EOL;
}
?>
