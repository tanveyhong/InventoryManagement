<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$db = getSQLDB();

// List of food products to update (ID, new expiry, new price, new quantity, new SKU if needed)
$updates = [
    // Bread Loaf
    ['id' => 43, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 50, 'sku' => 'BREAD-LOAF'],
    ['id' => 20, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 50, 'sku' => 'BREAD-LOAF'],
    ['id' => 152, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 50, 'sku' => 'BREAD-LOAF'],
    ['id' => 148, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 50, 'sku' => 'BREAD-LOAF'],
    // Bread White
    ['id' => 97, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 74, 'sku' => 'BREAD-WHITE'],
    ['id' => 57, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 80, 'sku' => 'BREAD-WHITE'],
    ['id' => 67, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 80, 'sku' => 'BREAD-WHITE'],
    ['id' => 77, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 80, 'sku' => 'BREAD-WHITE'],
    ['id' => 87, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 80, 'sku' => 'BREAD-WHITE'],
    ['id' => 107, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 80, 'sku' => 'BREAD-WHITE'],
    ['id' => 136, 'expiry' => date('Y-m-d', strtotime('+5 days')), 'price' => 3.50, 'qty' => 474, 'sku' => 'BREAD-WHITE'],
    // Canned Tuna
    ['id' => 82, 'expiry' => date('Y-m-d', strtotime('+365 days')), 'price' => 4.50, 'qty' => 80, 'sku' => 'CANNED-TUNA'],
    ['id' => 172, 'expiry' => date('Y-m-d', strtotime('+365 days')), 'price' => 4.50, 'qty' => 80, 'sku' => 'CANNED-TUNA'],
    // Instant Noodles
    ['id' => 151, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 1.80, 'qty' => 300, 'sku' => 'INSTANT-NOODLES'],
    ['id' => 19, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 1.80, 'qty' => 300, 'sku' => 'INSTANT-NOODLES'],
    ['id' => 147, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 1.50, 'qty' => 120, 'sku' => 'INSTANT-NOODLES'],
    ['id' => 42, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 1.50, 'qty' => 120, 'sku' => 'INSTANT-NOODLES'],
    // Olive Oil
    ['id' => 186, 'expiry' => date('Y-m-d', strtotime('+365 days')), 'price' => 12.99, 'qty' => 25, 'sku' => 'OLIVE-OIL-1L'],
    ['id' => 83, 'expiry' => date('Y-m-d', strtotime('+365 days')), 'price' => 12.99, 'qty' => 25, 'sku' => 'OLIVE-OIL-1L'],
    // Pasta Spaghetti
    ['id' => 81, 'expiry' => date('Y-m-d', strtotime('+365 days')), 'price' => 3.99, 'qty' => 70, 'sku' => 'PASTA-SPAGHETTI'],
    ['id' => 188, 'expiry' => date('Y-m-d', strtotime('+365 days')), 'price' => 3.99, 'qty' => 70, 'sku' => 'PASTA-SPAGHETTI'],
    // Rice 5kg
    ['id' => 154, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 15.00, 'qty' => 30, 'sku' => 'RICE-5KG'],
    ['id' => 45, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 15.00, 'qty' => 1675, 'sku' => 'RICE-5KG'],
    ['id' => 22, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 25.00, 'qty' => 80, 'sku' => 'RICE-5KG'],
    ['id' => 150, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 15.00, 'qty' => 25, 'sku' => 'RICE-5KG'],
    // Rice Premium
    ['id' => 80, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 18.99, 'qty' => 30, 'sku' => 'RICE-PREMIUM'],
    ['id' => 192, 'expiry' => date('Y-m-d', strtotime('+180 days')), 'price' => 18.99, 'qty' => 30, 'sku' => 'RICE-PREMIUM'],
    // Sandwich
    ['id' => 21, 'expiry' => date('Y-m-d', strtotime('+3 days')), 'price' => 5.00, 'qty' => 40, 'sku' => 'SANDWICH'],
    ['id' => 153, 'expiry' => date('Y-m-d', strtotime('+3 days')), 'price' => 5.00, 'qty' => 40, 'sku' => 'SANDWICH'],
    // Sandwich Pack
    ['id' => 149, 'expiry' => date('Y-m-d', strtotime('+3 days')), 'price' => 5.50, 'qty' => 30, 'sku' => 'SANDWICH-PACK'],
    ['id' => 44, 'expiry' => date('Y-m-d', strtotime('+3 days')), 'price' => 5.50, 'qty' => 30, 'sku' => 'SANDWICH-PACK'],
    // Tomato Sauce
    ['id' => 84, 'expiry' => date('Y-m-d', strtotime('+365 days')), 'price' => 3.50, 'qty' => 60, 'sku' => 'TOMATO-SAUCE'],
    ['id' => 197, 'expiry' => date('Y-m-d', strtotime('+365 days')), 'price' => 3.50, 'qty' => 60, 'sku' => 'TOMATO-SAUCE'],
];

foreach ($updates as $prod) {
    $db->execute("UPDATE products SET expiry_date = ?, price = ?, quantity = ?, sku = ? WHERE id = ?", [
        $prod['expiry'], $prod['price'], $prod['qty'], $prod['sku'], $prod['id']
    ]);
    echo "✓ Updated product ID {$prod['id']} ({$prod['sku']})\n";
}

echo "\n✅ Existing food products updated for accuracy!\n";
?>
