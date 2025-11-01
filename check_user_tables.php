<?php
require 'config.php';
require 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "PostgreSQL Tables:\n";
echo str_repeat("=", 40) . "\n";

$tables = $db->fetchAll("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
foreach ($tables as $t) {
    echo "- " . $t['tablename'] . "\n";
}

echo "\nChecking for user-related tables...\n";
$userTables = $db->fetchAll("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE '%user%' ORDER BY tablename");
if (empty($userTables)) {
    echo "❌ No user tables found in PostgreSQL\n";
    echo "\n💡 Users are currently stored in Firebase only\n";
} else {
    echo "✅ Found user tables:\n";
    foreach ($userTables as $t) {
        echo "  - " . $t['tablename'] . "\n";
    }
}
