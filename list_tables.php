<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    $tables = $db->fetchAll("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    
    echo "Tables in database:\n";
    foreach ($tables as $table) {
        echo "- " . $table['table_name'] . "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>