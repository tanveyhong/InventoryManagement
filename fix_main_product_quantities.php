<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== CHECKING MAIN PRODUCT vs VARIANT QUANTITIES ===\n\n";

// Get all main products with their variants
$mainProducts = $db->fetchAll("SELECT * FROM products WHERE store_id IS NULL ORDER BY sku");

foreach ($mainProducts as $main) {
    $baseSku = $main['sku'];
    $mainQty = $main['quantity'];
    
    // Get all variants for this main product
    $variants = $db->fetchAll(
        "SELECT id, sku, quantity, store_id FROM products WHERE sku LIKE ? AND store_id IS NOT NULL",
        [$baseSku . '-%']
    );
    
    if (!empty($variants)) {
        $totalVariantQty = 0;
        echo "Product: {$main['name']} (SKU: $baseSku)\n";
        echo "  Main Product Qty: $mainQty\n";
        echo "  Variants:\n";
        
        foreach ($variants as $variant) {
            echo "    - {$variant['sku']}: Qty {$variant['quantity']} (Store {$variant['store_id']})\n";
            $totalVariantQty += $variant['quantity'];
        }
        
        echo "  Total Variant Qty: $totalVariantQty\n";
        
        if ($mainQty != $totalVariantQty) {
            echo "  ❌ MISMATCH! Main should be $totalVariantQty but is $mainQty\n";
            echo "  → Need to recalculate\n";
        } else {
            echo "  ✅ Correct!\n";
        }
        echo "\n";
    }
}

echo "\n=== FIXING MISMATCHED QUANTITIES ===\n\n";

// Fix each main product
$fixed = 0;
foreach ($mainProducts as $main) {
    $baseSku = $main['sku'];
    
    // Calculate correct total from variants
    $result = $db->fetch(
        "SELECT SUM(quantity) as total FROM products WHERE sku LIKE ? AND store_id IS NOT NULL",
        [$baseSku . '-%']
    );
    
    $correctTotal = intval($result['total'] ?? 0);
    
    if ($correctTotal != $main['quantity']) {
        echo "Fixing: {$main['name']} (SKU: $baseSku)\n";
        echo "  Old: {$main['quantity']} → New: $correctTotal\n";
        
        $db->execute(
            "UPDATE products SET quantity = ?, updated_at = ? WHERE id = ?",
            [$correctTotal, date('Y-m-d H:i:s'), $main['id']]
        );
        
        $fixed++;
    }
}

echo "\n✅ Fixed $fixed main products\n";
