<?php
/**
 * Fix Non-Food Products - Standardize non-food items as products
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$db = getSQLDB();

// Define sample non-food products
$nonFoodSamples = [
    ['name' => 'Hand Sanitizer 500ml', 'sku' => 'HS-500', 'category' => 'Product', 'quantity' => 200, 'price' => 15.90],
    ['name' => 'Hand Soap', 'sku' => 'HSOAP', 'category' => 'Product', 'quantity' => 150, 'price' => 7.50],
    ['name' => 'Sponges 5pk', 'sku' => 'SPONGE-5', 'category' => 'Product', 'quantity' => 80, 'price' => 5.20],
    ['name' => 'Packaging Tape', 'sku' => 'TAPE-PACK', 'category' => 'Product', 'quantity' => 60, 'price' => 3.80],
    ['name' => 'Office Paper A4', 'sku' => 'PAPER-A4', 'category' => 'Product', 'quantity' => 100, 'price' => 12.00],
];

// Remove any non-food items incorrectly labeled as 'Food'
$db->execute("DELETE FROM products WHERE category = 'Food' AND name IN ('Hand Sanitizer 500ml', 'Hand Soap', 'Sponges 5pk', 'Packaging Tape', 'Office Paper A4')");

// Update or insert each sample non-food product
foreach ($nonFoodSamples as $prod) {
    $existing = $db->fetch("SELECT id FROM products WHERE name = ? AND category = 'Product'", [$prod['name']]);
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

echo "\n✅ Non-food products standardized as products!\n";
?>
