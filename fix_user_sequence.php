<?php
/**
 * Fix the users_id_seq sequence
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sql_db.php';

echo "=== Fixing Users ID Sequence ===\n\n";

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // Get the max ID
    $result = $sqlDb->fetch("SELECT MAX(id) as max_id FROM users");
    $maxId = $result['max_id'] ?? 0;
    
    echo "Current max ID: $maxId\n";
    
    // Set the sequence to max_id + 1
    $sqlDb->execute("SELECT setval('users_id_seq', ?, false)", [$maxId + 1]);
    
    echo "Sequence updated to: " . ($maxId + 1) . "\n";
    
    // Verify
    $seq = $sqlDb->fetch("SELECT last_value FROM users_id_seq");
    echo "New sequence value: " . $seq['last_value'] . "\n";
    
    echo "\n✅ Sequence fixed! Next user will get ID: " . ($maxId + 1) . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
