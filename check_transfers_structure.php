<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    $columns = $db->fetchAll("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'stock_movements'");
    
    echo "Columns in stock_movements:\n";
    foreach ($columns as $col) {
        echo "- " . $col['column_name'] . " (" . $col['data_type'] . ")\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>