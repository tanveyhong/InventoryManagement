<?php
/**
 * Add Sample Products for POS Testing
 * Creates realistic products across different categories
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

$db = getSQLDB();

echo "=================================\n";
echo "Adding Sample Products for POS\n";
echo "=================================\n\n";

// Get a store to assign products to
$stores = $db->fetchAll("SELECT id, name FROM stores LIMIT 1");
if (empty($stores)) {
    die("❌ No stores found! Please add stores first.\n");
}

$storeId = $stores[0]['id'];
$storeName = $stores[0]['name'];

echo "📦 Assigning products to: $storeName (ID: $storeId)\n\n";

// Sample products across categories
$sampleProducts = [
    // Beverages
    ['name' => 'Coca-Cola 330ml', 'sku' => 'BEV001', 'barcode' => '5449000000996', 'category' => 'Beverages', 'price' => 2.50, 'quantity' => 100],
    ['name' => 'Pepsi 330ml', 'sku' => 'BEV002', 'barcode' => '1234567890123', 'category' => 'Beverages', 'price' => 2.50, 'quantity' => 80],
    ['name' => 'Mineral Water 500ml', 'sku' => 'BEV003', 'barcode' => '9876543210987', 'category' => 'Beverages', 'price' => 1.50, 'quantity' => 200],
    ['name' => 'Orange Juice 1L', 'sku' => 'BEV004', 'barcode' => '1111222233334', 'category' => 'Beverages', 'price' => 4.50, 'quantity' => 50],
    ['name' => 'Iced Coffee', 'sku' => 'BEV005', 'barcode' => '5555666677778', 'category' => 'Beverages', 'price' => 3.80, 'quantity' => 60],
    
    // Snacks
    ['name' => 'Potato Chips Classic', 'sku' => 'SNK001', 'barcode' => '2222333344445', 'category' => 'Snacks', 'price' => 3.20, 'quantity' => 120],
    ['name' => 'Chocolate Bar', 'sku' => 'SNK002', 'barcode' => '3333444455556', 'category' => 'Snacks', 'price' => 2.80, 'quantity' => 150],
    ['name' => 'Cookies Pack', 'sku' => 'SNK003', 'barcode' => '4444555566667', 'category' => 'Snacks', 'price' => 4.50, 'quantity' => 90],
    ['name' => 'Candy Mix', 'sku' => 'SNK004', 'barcode' => '6666777788889', 'category' => 'Snacks', 'price' => 2.00, 'quantity' => 200],
    ['name' => 'Nuts Pack', 'sku' => 'SNK005', 'barcode' => '7777888899990', 'category' => 'Snacks', 'price' => 5.50, 'quantity' => 70],
    
    // Food
    ['name' => 'Instant Noodles', 'sku' => 'FOOD001', 'barcode' => '8888999900001', 'category' => 'Food', 'price' => 1.80, 'quantity' => 300],
    ['name' => 'Bread Loaf', 'sku' => 'FOOD002', 'barcode' => '9999000011112', 'category' => 'Food', 'price' => 3.50, 'quantity' => 50],
    ['name' => 'Sandwich', 'sku' => 'FOOD003', 'barcode' => '0000111122223', 'category' => 'Food', 'price' => 5.00, 'quantity' => 40],
    ['name' => 'Rice 5kg', 'sku' => 'FOOD004', 'barcode' => '1111000022223', 'category' => 'Food', 'price' => 15.00, 'quantity' => 30],
    
    // Dairy
    ['name' => 'Fresh Milk 1L', 'sku' => 'DAIRY001', 'barcode' => '2222111133334', 'category' => 'Dairy', 'price' => 4.80, 'quantity' => 60],
    ['name' => 'Yogurt Cup', 'sku' => 'DAIRY002', 'barcode' => '3333222244445', 'category' => 'Dairy', 'price' => 2.20, 'quantity' => 100],
    ['name' => 'Cheese Slices', 'sku' => 'DAIRY003', 'barcode' => '4444333355556', 'category' => 'Dairy', 'price' => 6.50, 'quantity' => 45],
    
    // Personal Care
    ['name' => 'Shampoo 200ml', 'sku' => 'CARE001', 'barcode' => '5555444466667', 'category' => 'Personal Care', 'price' => 8.50, 'quantity' => 50],
    ['name' => 'Toothpaste', 'sku' => 'CARE002', 'barcode' => '6666555577778', 'category' => 'Personal Care', 'price' => 4.20, 'quantity' => 80],
    ['name' => 'Hand Soap', 'sku' => 'CARE003', 'barcode' => '7777666688889', 'category' => 'Personal Care', 'price' => 3.80, 'quantity' => 90],
    
    // Household
    ['name' => 'Tissue Box', 'sku' => 'HOUSE001', 'barcode' => '8888777799990', 'category' => 'Household', 'price' => 3.50, 'quantity' => 100],
    ['name' => 'Kitchen Roll', 'sku' => 'HOUSE002', 'barcode' => '9999888800001', 'category' => 'Household', 'price' => 4.00, 'quantity' => 85],
    ['name' => 'Garbage Bags', 'sku' => 'HOUSE003', 'barcode' => '0000999911112', 'category' => 'Household', 'price' => 5.80, 'quantity' => 60],
];

$added = 0;
$skipped = 0;

foreach ($sampleProducts as $product) {
    // Check if product already exists
    $existing = $db->fetch("SELECT id FROM products WHERE sku = ?", [$product['sku']]);
    
    if ($existing) {
        echo "⏭️  Skipped: {$product['name']} (already exists)\n";
        $skipped++;
        continue;
    }
    
    try {
        $db->execute("
            INSERT INTO products (
                name, sku, barcode, category, price, quantity, reorder_level, store_id, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ", [
            $product['name'],
            $product['sku'],
            $product['barcode'],
            $product['category'],
            $product['price'],
            $product['quantity'],
            20, // reorder level
            $storeId
        ]);
        
        echo "✅ Added: {$product['name']} - \${$product['price']} (Qty: {$product['quantity']})\n";
        $added++;
        
    } catch (Exception $e) {
        echo "❌ Error adding {$product['name']}: " . $e->getMessage() . "\n";
    }
}

echo "\n=================================\n";
echo "✅ Added: $added products\n";
echo "⏭️  Skipped: $skipped products\n";
echo "=================================\n\n";

// Show final count
$totalProducts = $db->fetch("SELECT COUNT(*) as count FROM products");
echo "📦 Total products in database: {$totalProducts['count']}\n\n";

echo "🎉 Done! You can now use the POS system.\n";
echo "🔗 Open: modules/pos/quick_service.php\n";
?>
