<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sql_db.php';

$sqlDb = SQLDatabase::getInstance();

echo "Checking 'products' table schema...\n";

try {
    // Check if deleted_at column exists
    // This query works for PostgreSQL and MySQL
    $check = $sqlDb->fetch("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'products' AND column_name = 'deleted_at'
    ");

    if ($check) {
        echo "Column 'deleted_at' already exists.\n";
    } else {
        echo "Column 'deleted_at' missing. Adding it...\n";
        $sqlDb->execute("ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP WITH TIME ZONE NULL");
        echo "Column 'deleted_at' added successfully.\n";
    }

    // Check if active column exists
    $checkActive = $sqlDb->fetch("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'products' AND column_name = 'active'
    ");

    if ($checkActive) {
        echo "Column 'active' already exists.\n";
    } else {
        echo "Column 'active' missing. Adding it...\n";
        $sqlDb->execute("ALTER TABLE products ADD COLUMN active BOOLEAN DEFAULT TRUE");
        echo "Column 'active' added successfully.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    // Fallback for SQLite if needed (though user is likely on PG/MySQL)
    if (strpos($e->getMessage(), 'information_schema') !== false) {
        echo "Trying SQLite fallback...\n";
        try {
            $sqlDb->execute("ALTER TABLE products ADD COLUMN deleted_at DATETIME NULL");
            echo "Added deleted_at (SQLite).\n";
        } catch (Exception $e2) {
            echo "SQLite add deleted_at failed (maybe exists): " . $e2->getMessage() . "\n";
        }
    }
}

echo "Schema check complete.\n";
