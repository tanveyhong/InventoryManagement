<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting test...<br>";

$_GET['id'] = 11;

// Simulate session
$_SESSION['user'] = ['id' => 1];

echo "About to include inventory_viewer.php...<br>";

try {
    include 'modules/stores/inventory_viewer.php';
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "<br>";
    echo "Trace: " . $e->getTraceAsString();
}
