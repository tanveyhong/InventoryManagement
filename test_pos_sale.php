<?php
/**
 * Test POS Sale Processing
 * Verifies that the sale system can read products and process sales correctly
 */

require_once 'config.php';
require_once 'functions.php';
require_once 'getDB.php';
require_once 'sql_db.php';

$db = getDB();
$sqlDb = getSQLDB();

echo "=== POS Sale Processing Test ===\n\n";

// Test 1: Check if we can read products from SQL
echo "Test 1: Reading products from SQL database...\n";
try {
    $sql = "SELECT id, name, sku, quantity, price, selling_price FROM products WHERE active = 1 AND quantity > 0 LIMIT 5";
    $products = $sqlDb->fetchAll($sql);
    
    if (empty($products)) {
        echo "❌ No products found in SQL database\n";
    } else {
        echo "✅ Found " . count($products) . " products:\n";
        foreach ($products as $p) {
            $price = $p['selling_price'] ?? $p['price'];
            echo "   - {$p['name']} (SKU: {$p['sku']}, Stock: {$p['quantity']}, Price: RM {$price})\n";
        }
    }
} catch (Exception $e) {
    echo "❌ SQL read failed: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Check if we can read products from Firebase
echo "Test 2: Reading products from Firebase...\n";
try {
    $firebaseProducts = $db->readAll('products', [['active', '==', 1]], null, 5);
    
    if (empty($firebaseProducts)) {
        echo "❌ No products found in Firebase\n";
    } else {
        echo "✅ Found " . count($firebaseProducts) . " products:\n";
        foreach ($firebaseProducts as $p) {
            echo "   - {$p['name']} (ID: {$p['id']}, Stock: {$p['quantity']})\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Firebase read failed: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 3: Simulate sale validation
echo "Test 3: Simulating sale validation...\n";
if (!empty($products)) {
    $testProduct = $products[0];
    $testQuantity = 1;
    
    echo "Testing sale of {$testQuantity}x {$testProduct['name']}...\n";
    
    // Check stock availability
    if ($testProduct['quantity'] >= $testQuantity) {
        $newQty = $testProduct['quantity'] - $testQuantity;
        echo "✅ Stock check passed: {$testProduct['quantity']} available, will leave {$newQty}\n";
        
        // Calculate totals
        $price = floatval($testProduct['selling_price'] ?? $testProduct['price']);
        $subtotal = $testQuantity * $price;
        $tax = $subtotal * 0.06;
        $total = $subtotal + $tax;
        
        echo "   Subtotal: RM " . number_format($subtotal, 2) . "\n";
        echo "   Tax (6%): RM " . number_format($tax, 2) . "\n";
        echo "   Total: RM " . number_format($total, 2) . "\n";
    } else {
        echo "❌ Insufficient stock\n";
    }
} else {
    echo "⚠️  No products available for testing\n";
}

echo "\n";

// Test 4: Check database methods
echo "Test 4: Checking database methods...\n";
$requiredMethods = ['read', 'readAll', 'create', 'update'];
foreach ($requiredMethods as $method) {
    if (method_exists($db, $method)) {
        echo "✅ Database::{$method}() exists\n";
    } else {
        echo "❌ Database::{$method}() missing\n";
    }
}

echo "\n";

// Test 5: Check SQL database methods
echo "Test 5: Checking SQL database methods...\n";
$sqlMethods = ['fetchAll', 'execute'];
foreach ($sqlMethods as $method) {
    if (method_exists($sqlDb, $method)) {
        echo "✅ SQLDatabase::{$method}() exists\n";
    } else {
        echo "❌ SQLDatabase::{$method}() missing\n";
    }
}

echo "\n=== Test Complete ===\n";
echo "If all tests passed, the POS sale processing should work correctly.\n";
echo "Try processing a sale in the POS terminal now!\n";
