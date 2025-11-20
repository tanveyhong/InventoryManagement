<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$db = getSQLDB();

$count = $db->fetch("SELECT COUNT(*) as count FROM products WHERE category = 'Food'");
$db->execute("DELETE FROM products WHERE category = 'Food'");
echo "Removed all food products. Total deleted: " . ($count['count'] ?? 0) . "\n";
?>
