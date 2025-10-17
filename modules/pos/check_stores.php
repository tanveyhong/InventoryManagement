<?php
require_once 'db.php';

try {
    $db = getSQLDB();
    
    echo "==========================================\n";
    echo "Existing Stores in Database\n";
    echo "==========================================\n\n";
    
    $stores = $db->fetchAll("SELECT id, name, code, city, address FROM stores ORDER BY name");
    
    if (empty($stores)) {
        echo "No stores found in database.\n\n";
        echo "You can add stores via:\n";
        echo "1. Navigate to Store Management module\n";
        echo "2. Or run: INSERT INTO stores (name, code, city) VALUES ('Store Name', 'CODE', 'City');\n";
    } else {
        echo "Found " . count($stores) . " store(s):\n\n";
        
        foreach ($stores as $store) {
            echo "ID: " . $store['id'] . "\n";
            echo "Name: " . $store['name'] . "\n";
            echo "Code: " . ($store['code'] ?? 'N/A') . "\n";
            echo "City: " . ($store['city'] ?? 'N/A') . "\n";
            echo "Address: " . ($store['address'] ?? 'N/A') . "\n";
            echo "---\n";
        }
    }
    
    echo "\n==========================================\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
