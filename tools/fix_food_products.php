<?php
/**
 * Fix Food Products - Ensure all food items are standardized as products
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$db = getSQLDB();

// Define sample food products for accuracy
$foodSamples = [
    ['name' => 'Eggs 12pcs', 'sku' => 'EGG-12', 'category' => 'Food', 'quantity' => 120, 'price' => 6.50],
    ['name' => 'Rice 5kg', 'sku' => 'RICE-5KG', 'category' => 'Food', 'quantity' => 80, 'price' => 25.00],
    ['name' => 'Yogurt 500g', 'sku' => 'YOG-500', 'category' => 'Food', 'quantity' => 60, 'price' => 8.90],
    ['name' => 'Cheese Slices', 'sku' => 'CHEESE-SLC', 'category' => 'Food', 'quantity' => 40, 'price' => 12.50],
    ['name' => 'Orange Juice 1L', 'sku' => 'OJ-1L', 'category' => 'Food', 'quantity' => 50, 'price' => 7.80],
    ['name' => 'Chocolate Chip Cookies', 'sku' => 'COOK-CHIP', 'category' => 'Food', 'quantity' => 100, 'price' => 9.90],
];

// Update or insert each sample food product
foreach ($foodSamples as $prod) {
    $existing = $db->fetch("SELECT id FROM products WHERE name = ? AND category = 'Food'", [$prod['name']]);
    if ($existing && isset($existing['id'])) {
        // Update existing product
        $db->execute("UPDATE products SET sku = ?, quantity = ?, price = ?, active = TRUE WHERE id = ?", [
            $prod['sku'], $prod['quantity'], $prod['price'], $existing['id']
        ]);
        echo "✓ Updated product: {$prod['name']}\n";
    } else {
        // Insert new product
        $db->execute("INSERT INTO products (name, sku, category, quantity, price, active) VALUES (?, ?, ?, ?, ?, TRUE)", [
            $prod['name'], $prod['sku'], $prod['category'], $prod['quantity'], $prod['price']
        ]);
        echo "+ Added new product: {$prod['name']}\n";
    }
}

echo "\n✅ Food products standardized as products!\n";
?>
