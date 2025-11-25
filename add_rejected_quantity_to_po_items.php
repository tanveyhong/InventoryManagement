<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    $db->execute("ALTER TABLE purchase_order_items ADD COLUMN rejected_quantity INTEGER DEFAULT 0");
    echo "Added rejected_quantity column to purchase_order_items table.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>