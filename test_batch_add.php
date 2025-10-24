<?php
// Check what products are available and what might be failing
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    
    // Simulate what the batch add is trying to do
    $target_store_id = 7; // Use one of the stores from the list
    $test_products = [9, 10, 11]; // Sample product IDs
    
    echo "=== TESTING BATCH ADD SIMULATION ===\n\n";
    echo "Target Store ID: $target_store_id\n\n";
    
    foreach ($test_products as $product_id) {
        echo "--- Product ID: $product_id ---\n";
        
        // Get source product
        $source = $db->fetch("SELECT * FROM products WHERE id = ?", [$product_id]);
        if (!$source) {
            echo "  ✗ Product not found\n";
            continue;
        }
        
        echo "  Name: {$source['name']}\n";
        echo "  SKU: {$source['sku']}\n";
        echo "  Current Store: {$source['store_id']}\n";
        
        // Check if already exists in target store
        $existing = $db->fetch(
            "SELECT id, name FROM products WHERE store_id = ? AND sku = ? AND active = 1",
            [$target_store_id, $source['sku']]
        );
        
        if ($existing) {
            echo "  ✗ Already exists in target store (ID: {$existing['id']})\n";
        } else {
            echo "  ✓ Can be added to target store\n";
            
            // Try the insert
            $query = "INSERT INTO products (
                name, sku, barcode, description, category, unit,
                cost_price, selling_price, price, quantity, reorder_level,
                store_id, active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))";
            
            $params = [
                $source['name'],
                $source['sku'],
                $source['barcode'],
                $source['description'],
                $source['category'],
                $source['unit'],
                $source['cost_price'] ?? 0,
                $source['selling_price'] ?? $source['price'] ?? 0,
                $source['price'] ?? $source['selling_price'] ?? 0,
                0, // Start with 0 quantity
                $source['reorder_level'] ?? 10,
                $target_store_id,
                1
            ];
            
            try {
                $result = $db->execute($query, $params);
                if ($result) {
                    echo "  ✓ INSERT would succeed\n";
                    // Rollback by deleting
                    $lastId = $db->lastInsertId();
                    $db->execute("DELETE FROM products WHERE id = ?", [$lastId]);
                    echo "  (Test insert rolled back)\n";
                } else {
                    echo "  ✗ INSERT returned false\n";
                }
            } catch (Exception $e) {
                echo "  ✗ INSERT error: " . $e->getMessage() . "\n";
            }
        }
        echo "\n";
    }
    
    echo "\n=== CHECK UNIQUE CONSTRAINTS ===\n\n";
    $indexes = $db->fetchAll("PRAGMA index_list(products)");
    echo "Indexes on products table:\n";
    foreach ($indexes as $idx) {
        echo "  - {$idx['name']} (unique: {$idx['unique']})\n";
        $info = $db->fetchAll("PRAGMA index_info({$idx['name']})");
        foreach ($info as $col) {
            echo "    Column: {$col['name']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>
