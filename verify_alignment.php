<?php
require_once 'config.php';
require_once 'sql_db.php';
require_once 'getDB.php';

$sqlDb = SQLDatabase::getInstance();
$firebaseDb = getDB();

echo "=== PEPSI ALIGNMENT VERIFICATION ===\n\n";

// Check main product
$sqlMain = $sqlDb->fetch("SELECT * FROM products WHERE sku = 'BEV002' AND store_id IS NULL");
$fbMain = $firebaseDb->read('products', $sqlMain['id']);

echo "Main Product (BEV002):\n";
echo "  SQL Quantity: {$sqlMain['quantity']}\n";
echo "  Firebase Quantity: {$fbMain['quantity']}\n";
echo "  Status: " . ($sqlMain['quantity'] == $fbMain['quantity'] ? "✅ ALIGNED" : "❌ MISMATCH") . "\n\n";

// Check variant
$sqlVariant = $sqlDb->fetch("SELECT * FROM products WHERE sku = 'BEV002-S6'");
$fbVariant = $firebaseDb->read('products', $sqlVariant['id']);

echo "Store Variant (BEV002-S6):\n";
echo "  SQL Quantity: {$sqlVariant['quantity']}\n";
echo "  Firebase Quantity: {$fbVariant['quantity']}\n";
echo "  Status: " . ($sqlVariant['quantity'] == $fbVariant['quantity'] ? "✅ ALIGNED" : "❌ MISMATCH") . "\n\n";

echo "=== OVERALL STATUS ===\n";
if ($sqlMain['quantity'] == $fbMain['quantity'] && 
    $sqlVariant['quantity'] == $fbVariant['quantity'] &&
    $sqlMain['quantity'] == $sqlVariant['quantity']) {
    echo "✅ ALL ALIGNED! Both databases show {$sqlMain['quantity']} units\n";
} else {
    echo "❌ Still have mismatches\n";
}
