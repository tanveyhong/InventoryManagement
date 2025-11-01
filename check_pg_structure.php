<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== STORES TABLE STRUCTURE ===\n";
$cols = $db->fetchAll("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'stores' ORDER BY ordinal_position");
foreach($cols as $c) {
    echo $c['column_name'] . ' (' . $c['data_type'] . ')' . "\n";
}

echo "\n=== PRODUCTS TABLE STRUCTURE ===\n";
$cols = $db->fetchAll("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'products' ORDER BY ordinal_position");
foreach($cols as $c) {
    echo $c['column_name'] . ' (' . $c['data_type'] . ')' . "\n";
}
