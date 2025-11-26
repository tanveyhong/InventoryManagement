<?php
require_once 'config.php';
require_once 'sql_db.php';
try {
    $db = SQLDatabase::getInstance();
    $rows = $db->fetchAll('SELECT * FROM stock_movements LIMIT 5');
    print_r($rows);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
