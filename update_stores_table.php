<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    
    echo "Checking 'stores' table schema...\n";
    
    // List of columns to add
    $columns = [
        'latitude' => 'DECIMAL(10, 8) NULL',
        'longitude' => 'DECIMAL(11, 8) NULL',
        'store_type' => "VARCHAR(50) DEFAULT 'retail'",
        'created_by' => 'INTEGER NULL',
        'status' => "VARCHAR(20) DEFAULT 'active'"
    ];
    
    foreach ($columns as $column => $definition) {
        try {
            // Check if column exists
            $check = $db->fetch("
                SELECT column_name 
                FROM information_schema.columns 
                WHERE table_name = 'stores' AND column_name = ?
            ", [$column]);
            
            if (!$check) {
                echo "Adding column '$column'...\n";
                $db->execute("ALTER TABLE stores ADD COLUMN $column $definition");
                echo "Column '$column' added successfully.\n";
            } else {
                echo "Column '$column' already exists.\n";
            }
        } catch (Exception $e) {
            echo "Error adding column '$column': " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nMigration completed successfully!";
    
} catch (Exception $e) {
    echo "Critical Error: " . $e->getMessage();
}
?>