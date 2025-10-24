<?php
// Check database structure
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    
    echo "=== PRODUCTS TABLE STRUCTURE ===\n\n";
    $info = $db->fetchAll('PRAGMA table_info(products)');
    
    echo "Columns:\n";
    foreach ($info as $column) {
        echo "  - {$column['name']} ({$column['type']}) " . 
             ($column['notnull'] ? "NOT NULL" : "NULL") . 
             ($column['dflt_value'] ? " DEFAULT {$column['dflt_value']}" : "") . "\n";
    }
    
    echo "\n=== STORES TABLE STRUCTURE ===\n\n";
    $storesInfo = $db->fetchAll('PRAGMA table_info(stores)');
    
    echo "Columns:\n";
    foreach ($storesInfo as $column) {
        echo "  - {$column['name']} ({$column['type']}) " . 
             ($column['notnull'] ? "NOT NULL" : "NULL") . 
             ($column['dflt_value'] ? " DEFAULT {$column['dflt_value']}" : "") . "\n";
    }
    
    echo "\n=== CHECK IF TABLES EXIST ===\n\n";
    $tables = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    echo "Available tables:\n";
    foreach ($tables as $table) {
        echo "  - {$table['name']}\n";
    }
    
    echo "\n=== SAMPLE STORES ===\n\n";
    $stores = $db->fetchAll("SELECT id, name, has_pos FROM stores LIMIT 5");
    foreach ($stores as $store) {
        echo "  Store ID: {$store['id']}, Name: {$store['name']}, POS: " . ($store['has_pos'] ?? 'NULL') . "\n";
    }
    
    echo "\n=== SAMPLE PRODUCTS ===\n\n";
    $products = $db->fetchAll("SELECT id, name, sku, store_id FROM products LIMIT 5");
    foreach ($products as $product) {
        echo "  Product ID: {$product['id']}, Name: {$product['name']}, SKU: {$product['sku']}, Store: {$product['store_id']}\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>
