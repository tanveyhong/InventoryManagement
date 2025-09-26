<?php
// Test SQL database connection and initialization
require_once 'sql_db.php';

try {
    echo "Testing SQL database connection...\n";
    
    $db = getSQLDB();
    echo "✓ Database connection successful\n";
    
    // Test basic query
    $stores = $db->fetchAll("SELECT COUNT(*) as total FROM stores");
    echo "✓ Stores table accessible\n";
    echo "Total stores: " . $stores[0]['total'] . "\n";
    
    // Test sample data
    $stores = $db->fetchAll("SELECT name, city, state FROM stores LIMIT 3");
    echo "✓ Sample store data:\n";
    foreach ($stores as $store) {
        echo "  - " . $store['name'] . " in " . $store['city'] . ", " . $store['state'] . "\n";
    }
    
    echo "\n✅ Database setup successful!\n";
    
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
}