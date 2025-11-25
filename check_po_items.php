<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    $columns = $db->fetchAll("SELECT column_name FROM information_schema.columns WHERE table_name = 'purchase_order_items'");
    print_r($columns);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>