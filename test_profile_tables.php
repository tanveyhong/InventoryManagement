<?php
require 'config.php';
require 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== Testing Tables for Store Profile ===\n\n";

// Test store_staff table
echo "1. Testing store_staff table:\n";
try {
    $result = $db->fetch("SELECT COUNT(*) as count FROM store_staff WHERE store_id = 11");
    echo "   Staff count for store 11: " . $result['count'] . "\n";
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

// Test store_performance table
echo "\n2. Testing store_performance table:\n";
try {
    $result = $db->fetch("SELECT COUNT(*) as count FROM store_performance WHERE store_id = 11");
    echo "   Performance records for store 11: " . $result['count'] . "\n";
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

// Test store_alerts table
echo "\n3. Testing store_alerts table:\n";
try {
    $result = $db->fetch("SELECT COUNT(*) as count FROM store_alerts WHERE store_id = 11");
    echo "   Alerts for store 11: " . $result['count'] . "\n";
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

// Test shift_logs table
echo "\n4. Testing shift_logs table:\n";
try {
    $result = $db->fetch("SELECT COUNT(*) as count FROM shift_logs");
    echo "   Total shift logs: " . $result['count'] . "\n";
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

// List all available tables
echo "\n5. Available tables:\n";
try {
    $tables = $db->fetchAll("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' ORDER BY table_name");
    foreach ($tables as $table) {
        echo "   - " . $table['table_name'] . "\n";
    }
} catch (Exception $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}
