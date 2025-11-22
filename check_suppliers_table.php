<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    $result = $db->fetchAll("SELECT * FROM information_schema.tables WHERE table_name = 'suppliers'");
    if (count($result) > 0) {
        echo "Table 'suppliers' exists.\n";
        // Check columns
        $columns = $db->fetchAll("SELECT column_name FROM information_schema.columns WHERE table_name = 'suppliers'");
        echo "Columns: " . implode(", ", array_column($columns, 'column_name')) . "\n";
    } else {
        echo "Table 'suppliers' does NOT exist.\n";
    }

    $resultPO = $db->fetchAll("SELECT * FROM information_schema.tables WHERE table_name = 'purchase_orders'");
    if (count($resultPO) > 0) {
        echo "Table 'purchase_orders' exists.\n";
    } else {
        echo "Table 'purchase_orders' does NOT exist.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>