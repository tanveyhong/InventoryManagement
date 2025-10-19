<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = getSQLDB();

echo "=== DATABASE TABLES ===" . PHP_EOL . PHP_EOL;

try {
    $tables = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
    
    echo "Current tables:" . PHP_EOL;
    foreach ($tables as $table) {
        echo "  - " . $table['name'] . PHP_EOL;
    }
    
    echo PHP_EOL;
    echo "Total: " . count($tables) . " tables" . PHP_EOL;
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
}
?>
