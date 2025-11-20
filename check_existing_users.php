<?php
/**
 * Check what IDs are already in the users table
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sql_db.php';

echo "=== Checking Existing Users ===\n\n";

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // Get existing users
    $users = $sqlDb->fetchAll("SELECT id, username, email, role, status FROM users ORDER BY id LIMIT 20");
    
    echo "Existing Users:\n";
    echo str_repeat('=', 80) . "\n";
    printf("%-10s %-20s %-30s %-15s %-10s\n", "ID", "Username", "Email", "Role", "Status");
    echo str_repeat('=', 80) . "\n";
    
    foreach ($users as $user) {
        printf(
            "%-10s %-20s %-30s %-15s %-10s\n",
            $user['id'],
            substr($user['username'] ?? 'N/A', 0, 19),
            substr($user['email'] ?? 'N/A', 0, 29),
            $user['role'] ?? 'N/A',
            $user['status'] ?? 'N/A'
        );
    }
    
    echo str_repeat('=', 80) . "\n";
    echo "Total users: " . count($users) . "\n\n";
    
    // Check the sequence
    $seq = $sqlDb->fetch("SELECT last_value FROM users_id_seq");
    echo "Current sequence value: " . ($seq['last_value'] ?? 'Unknown') . "\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
