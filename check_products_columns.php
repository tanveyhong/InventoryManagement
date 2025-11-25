<?php
require_once 'config.php';
require_once 'db.php';

$db = getSQLDB();

try {
    // Query to get column names in PostgreSQL
    $sql = "SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = 'products' 
            ORDER BY ordinal_position";
            
    $columns = $db->fetchAll($sql);
    
    echo "Columns in 'products' table:\n";
    foreach ($columns as $col) {
        echo "- {$col['column_name']} ({$col['data_type']})\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
