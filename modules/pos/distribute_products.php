<?php
/**
 * Distribute Products to POS-Enabled Stores
 * This script distributes products across all stores with POS enabled
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

$db = getSQLDB();

try {
    // Get all POS-enabled stores
    $posStores = $db->fetchAll("SELECT id, name FROM stores WHERE has_pos = 1 AND (active = 1 OR active IS NULL) ORDER BY id");
    
    if (empty($posStores)) {
        die("No POS-enabled stores found. Please enable POS for at least one store first." . PHP_EOL);
    }
    
    echo "===== PRODUCT DISTRIBUTION TO POS STORES =====" . PHP_EOL . PHP_EOL;
    echo "Found " . count($posStores) . " POS-enabled stores:" . PHP_EOL;
    foreach ($posStores as $store) {
        echo "  - Store #{$store['id']}: {$store['name']}" . PHP_EOL;
    }
    echo PHP_EOL;
    
    // Check existing products
    $existingProducts = $db->fetchAll("SELECT store_id, COUNT(*) as count FROM products GROUP BY store_id");
    echo "Current product distribution:" . PHP_EOL;
    foreach ($existingProducts as $ep) {
        $storeName = $db->fetch("SELECT name FROM stores WHERE id = ?", [$ep['store_id']]);
        echo "  - Store #{$ep['store_id']} ({$storeName['name']}): {$ep['count']} products" . PHP_EOL;
    }
    echo PHP_EOL;
    
    // Store-specific products to add
    $storeSpecificProducts = [
        // Each store gets unique products plus some common ones
        'common' => [
            ['name' => 'Water 500ml', 'sku' => 'COM-WATER-500', 'barcode' => '8718114743486', 'category' => 'Beverages', 'price' => 1.50, 'cost_price' => 0.80, 'quantity' => 200],
            ['name' => 'Coca-Cola 330ml', 'sku' => 'COM-COKE-330', 'barcode' => '5000112637724', 'category' => 'Beverages', 'price' => 2.50, 'cost_price' => 1.50, 'quantity' => 150],
            ['name' => 'Bread White', 'sku' => 'COM-BREAD-WHT', 'barcode' => '5410063014858', 'category' => 'Food', 'price' => 3.50, 'cost_price' => 2.00, 'quantity' => 80],
            ['name' => 'Milk 1L', 'sku' => 'COM-MILK-1L', 'barcode' => '9300652001144', 'category' => 'Dairy', 'price' => 4.50, 'cost_price' => 2.80, 'quantity' => 60],
            ['name' => 'Eggs 12pcs', 'sku' => 'COM-EGG-12', 'barcode' => '4061458050203', 'category' => 'Dairy', 'price' => 5.50, 'cost_price' => 3.50, 'quantity' => 50],
        ],
        'unique' => [
            // Each store gets 5-10 unique products
            [
                ['name' => 'Premium Coffee Beans 500g', 'category' => 'Beverages', 'price' => 15.99, 'cost_price' => 10.00, 'quantity' => 25],
                ['name' => 'Organic Green Tea', 'category' => 'Beverages', 'price' => 8.99, 'cost_price' => 5.50, 'quantity' => 40],
                ['name' => 'Energy Drink 250ml', 'category' => 'Beverages', 'price' => 3.50, 'cost_price' => 2.00, 'quantity' => 100],
                ['name' => 'Fruit Juice Mix 1L', 'category' => 'Beverages', 'price' => 5.50, 'cost_price' => 3.50, 'quantity' => 60],
                ['name' => 'Sparkling Water 1L', 'category' => 'Beverages', 'price' => 2.99, 'cost_price' => 1.50, 'quantity' => 80],
            ],
            [
                ['name' => 'Chocolate Chip Cookies', 'category' => 'Snacks', 'price' => 4.99, 'cost_price' => 3.00, 'quantity' => 70],
                ['name' => 'Potato Chips Family Size', 'category' => 'Snacks', 'price' => 5.99, 'cost_price' => 3.50, 'quantity' => 60],
                ['name' => 'Mixed Nuts Premium', 'category' => 'Snacks', 'price' => 8.99, 'cost_price' => 5.50, 'quantity' => 40],
                ['name' => 'Chocolate Bar Dark', 'category' => 'Snacks', 'price' => 3.50, 'cost_price' => 2.00, 'quantity' => 90],
                ['name' => 'Popcorn Butter', 'category' => 'Snacks', 'price' => 3.99, 'cost_price' => 2.50, 'quantity' => 50],
            ],
            [
                ['name' => 'Rice Premium 5kg', 'category' => 'Food', 'price' => 18.99, 'cost_price' => 12.00, 'quantity' => 30],
                ['name' => 'Pasta Spaghetti 500g', 'category' => 'Food', 'price' => 3.99, 'cost_price' => 2.50, 'quantity' => 70],
                ['name' => 'Canned Tuna', 'category' => 'Food', 'price' => 4.50, 'cost_price' => 3.00, 'quantity' => 80],
                ['name' => 'Olive Oil 1L', 'category' => 'Food', 'price' => 12.99, 'cost_price' => 8.00, 'quantity' => 25],
                ['name' => 'Tomato Sauce 500g', 'category' => 'Food', 'price' => 3.50, 'cost_price' => 2.00, 'quantity' => 60],
            ],
            [
                ['name' => 'Yogurt Strawberry', 'category' => 'Dairy', 'price' => 3.99, 'cost_price' => 2.50, 'quantity' => 50],
                ['name' => 'Cheese Cheddar 200g', 'category' => 'Dairy', 'price' => 6.99, 'cost_price' => 4.50, 'quantity' => 40],
                ['name' => 'Butter 250g', 'category' => 'Dairy', 'price' => 5.50, 'cost_price' => 3.50, 'quantity' => 45],
                ['name' => 'Cream Fresh 200ml', 'category' => 'Dairy', 'price' => 4.50, 'cost_price' => 3.00, 'quantity' => 35],
                ['name' => 'Ice Cream Vanilla 1L', 'category' => 'Dairy', 'price' => 8.99, 'cost_price' => 5.50, 'quantity' => 30],
            ],
            [
                ['name' => 'Shampoo 400ml', 'category' => 'Personal Care', 'price' => 7.99, 'cost_price' => 5.00, 'quantity' => 40],
                ['name' => 'Toothpaste Whitening', 'category' => 'Personal Care', 'price' => 4.99, 'cost_price' => 3.00, 'quantity' => 60],
                ['name' => 'Soap Bar 3pk', 'category' => 'Personal Care', 'price' => 5.50, 'cost_price' => 3.50, 'quantity' => 70],
                ['name' => 'Deodorant Spray', 'category' => 'Personal Care', 'price' => 6.50, 'cost_price' => 4.00, 'quantity' => 50],
                ['name' => 'Hand Sanitizer 500ml', 'category' => 'Personal Care', 'price' => 8.99, 'cost_price' => 5.50, 'quantity' => 80],
            ],
            [
                ['name' => 'Kitchen Towels 6pk', 'category' => 'Household', 'price' => 9.99, 'cost_price' => 6.00, 'quantity' => 40],
                ['name' => 'Dish Soap 500ml', 'category' => 'Household', 'price' => 4.50, 'cost_price' => 2.50, 'quantity' => 60],
                ['name' => 'Laundry Detergent 2L', 'category' => 'Household', 'price' => 12.99, 'cost_price' => 8.00, 'quantity' => 30],
                ['name' => 'Trash Bags 30pk', 'category' => 'Household', 'price' => 7.99, 'cost_price' => 5.00, 'quantity' => 50],
                ['name' => 'Sponges 5pk', 'category' => 'Household', 'price' => 3.99, 'cost_price' => 2.50, 'quantity' => 70],
            ],
        ]
    ];
    
    $totalAdded = 0;
    $storeIndex = 0;
    
    foreach ($posStores as $store) {
        $storeId = $store['id'];
        $storeName = $store['name'];
        
        echo "Processing Store #{$storeId}: {$storeName}" . PHP_EOL;
        echo str_repeat('-', 60) . PHP_EOL;
        
        $addedForStore = 0;
        
        // Add common products
        echo "  Adding common products..." . PHP_EOL;
        foreach ($storeSpecificProducts['common'] as $product) {
            // Generate unique SKU and barcode for this store
            $sku = $product['sku'] . '-S' . $storeId;
            $barcode = isset($product['barcode']) ? $product['barcode'] . $storeId : null;
            
            // Check if product already exists
            $exists = $db->fetch("SELECT id FROM products WHERE sku = ?", [$sku]);
            if ($exists) {
                echo "    ⏭️  {$product['name']} (already exists)" . PHP_EOL;
                continue;
            }
            
            // Insert product
            $query = "INSERT INTO products (
                name, sku, barcode, description, category, 
                cost_price, selling_price, price, quantity, reorder_level,
                store_id, unit, active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, datetime('now'), datetime('now'))";
            
            $params = [
                $product['name'],
                $sku,
                $barcode,
                "Available at {$storeName}",
                $product['category'],
                $product['cost_price'],
                $product['price'],
                $product['price'],
                $product['quantity'],
                20,
                $storeId,
                'pcs'
            ];
            
            if ($db->execute($query, $params)) {
                echo "    ✅ {$product['name']} (\${$product['price']}, Stock: {$product['quantity']})" . PHP_EOL;
                $addedForStore++;
                $totalAdded++;
            }
        }
        
        // Add unique products for this store
        echo "  Adding unique products..." . PHP_EOL;
        $uniqueSet = $storeSpecificProducts['unique'][$storeIndex % count($storeSpecificProducts['unique'])];
        foreach ($uniqueSet as $product) {
            // Generate SKU
            $sku = 'UNIQ-' . strtoupper(substr($product['name'], 0, 3)) . '-S' . $storeId . '-' . rand(100, 999);
            
            // Check if similar product exists
            $exists = $db->fetch("SELECT id FROM products WHERE name = ? AND store_id = ?", [$product['name'], $storeId]);
            if ($exists) {
                echo "    ⏭️  {$product['name']} (already exists)" . PHP_EOL;
                continue;
            }
            
            // Insert product
            $query = "INSERT INTO products (
                name, sku, description, category, 
                cost_price, selling_price, price, quantity, reorder_level,
                store_id, unit, active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, datetime('now'), datetime('now'))";
            
            $params = [
                $product['name'],
                $sku,
                "Exclusive at {$storeName}",
                $product['category'],
                $product['cost_price'],
                $product['price'],
                $product['price'],
                $product['quantity'],
                15,
                $storeId,
                'pcs'
            ];
            
            if ($db->execute($query, $params)) {
                echo "    ✅ {$product['name']} (\${$product['price']}, Stock: {$product['quantity']})" . PHP_EOL;
                $addedForStore++;
                $totalAdded++;
            }
        }
        
        echo "  📦 Added {$addedForStore} products to {$storeName}" . PHP_EOL;
        echo PHP_EOL;
        
        $storeIndex++;
    }
    
    echo str_repeat('=', 60) . PHP_EOL;
    echo "SUMMARY:" . PHP_EOL;
    echo "✅ Total products added: {$totalAdded}" . PHP_EOL;
    echo PHP_EOL;
    
    // Show final distribution
    echo "Final product distribution:" . PHP_EOL;
    $finalDist = $db->fetchAll("SELECT store_id, COUNT(*) as count FROM products GROUP BY store_id ORDER BY store_id");
    foreach ($finalDist as $fd) {
        $storeName = $db->fetch("SELECT name FROM stores WHERE id = ?", [$fd['store_id']]);
        $hasPOS = $db->fetch("SELECT has_pos FROM stores WHERE id = ?", [$fd['store_id']]);
        $posLabel = ($hasPOS['has_pos'] == 1) ? ' [POS ENABLED]' : '';
        echo "  - Store #{$fd['store_id']} ({$storeName['name']}){$posLabel}: {$fd['count']} products" . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . PHP_EOL;
    echo "Stack trace: " . $e->getTraceAsString() . PHP_EOL;
}
?>
