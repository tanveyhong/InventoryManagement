<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$db = getSQLDB();

$baseProducts = [
    ['Hand Sanitizer', 'HS', 15.90],
    ['Hand Soap', 'HSOAP', 7.50],
    ['Sponges', 'SPONGE', 5.20],
    ['Packaging Tape', 'TAPE', 3.80],
    ['Office Paper', 'PAPER', 12.00],
    ['Dishwashing Liquid', 'DISH', 8.90],
    ['Laundry Detergent', 'LAUNDRY', 18.50],
    ['Trash Bags', 'TRASH', 6.50],
    ['Air Freshener', 'AIR', 9.90],
    ['Bleach', 'BLEACH', 7.80],
    ['Floor Cleaner', 'FLOOR', 13.50],
    ['Window Cleaner', 'WINDOW', 11.20],
    ['Toilet Paper', 'TPAPER', 22.00],
    ['Paper Towels', 'PTOWEL', 14.00],
    ['Mop', 'MOP', 25.00],
    ['Broom', 'BROOM', 18.00],
    ['Dustpan', 'DUSTPAN', 7.00],
    ['Bucket', 'BUCKET', 10.00],
    ['Gloves', 'GLOVES', 6.00],
    ['Face Mask', 'MASK', 3.00],
    ['Stapler', 'STAPLER', 8.00],
    ['Pens', 'PEN', 2.50],
    ['Markers', 'MARKER', 3.50],
    ['Folders', 'FOLDER', 4.00],
    ['Envelopes', 'ENV', 2.00],
    ['Calculator', 'CALC', 19.00],
    ['Scissors', 'SCISSOR', 5.00],
    ['Tape Dispenser', 'TAPEDISP', 7.50],
    ['Binder Clips', 'BINDER', 2.80],
    ['Push Pins', 'PIN', 1.50],
    ['Rubber Bands', 'RUBBER', 1.20],
    ['Whiteboard', 'WHITEBOARD', 45.00],
    ['Clipboard', 'CLIPBOARD', 6.50],
    ['Highlighter', 'HIGHLIGHT', 2.80],
    ['Sticky Notes', 'STICKY', 3.20],
    ['File Organizer', 'ORGANIZER', 12.00],
    ['Desk Lamp', 'LAMP', 29.00],
    ['Extension Cord', 'CORD', 16.00],
    ['Power Strip', 'STRIP', 22.00],
    ['USB Drive', 'USB', 25.00],
    ['Mouse', 'MOUSE', 18.00],
    ['Keyboard', 'KEYBOARD', 32.00],
    ['Monitor', 'MONITOR', 320.00],
    ['Printer Paper', 'PRINTERPAPER', 15.00],
    ['Printer Ink', 'INK', 65.00],
    ['Cleaning Cloth', 'CLOTH', 4.50],
    ['Spray Bottle', 'SPRAY', 6.80],
    ['Hand Towel', 'HTOWEL', 8.00],
    ['Soap Dispenser', 'SOAPDISP', 14.00],
    ['Toilet Brush', 'TBRUSH', 9.00],
    ['Plunger', 'PLUNGER', 11.00],
    ['Squeegee', 'SQUEEGEE', 7.50],
    ['Room Freshener', 'ROOMFRESH', 10.50],
    ['Table Cover', 'TABLECOVER', 13.00],
    ['Chair', 'CHAIR', 55.00],
    ['Table', 'TABLE', 120.00],
    ['Shelf', 'SHELF', 85.00],
    ['Storage Box', 'STORAGE', 19.00],
    ['Cart', 'CART', 75.00],
    ['Label Maker', 'LABEL', 39.00],
    ['Padlock', 'PADLOCK', 8.50],
    ['Safety Cone', 'CONE', 22.00],
    ['First Aid Kit', 'FIRSTAID', 49.00],
];

for ($i = 0; $i < 60; $i++) {
    $base = $baseProducts[$i % count($baseProducts)];
    $name = $base[0] . ($i < count($baseProducts) ? '' : ' ' . ($i+1));
    $sku = $base[1] . '-' . sprintf('%03d', $i+1);
    $qty = rand(10, 500);
    $price = $base[2] + rand(0, 100) / 10.0;
    $db->execute("INSERT INTO products (name, sku, category, quantity, price, active) VALUES (?, ?, 'Product', ?, ?, TRUE)", [
        $name, $sku, $qty, $price
    ]);
    echo "+ Added: $name ($sku)\n";
}
echo "\n✅ 60 non-food products added!\n";
?>
