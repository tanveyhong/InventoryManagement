<?php
/**
 * Migration Script: Add Reset Token Columns
 * Purpose: Add reset_token and reset_token_expires columns to users table
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sql_db.php';

echo "=== Password Reset Migration ===\n";
echo "Adding reset_token columns to users table...\n\n";

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // Check if columns already exist
    $checkSql = "SELECT column_name 
                 FROM information_schema.columns 
                 WHERE table_name = 'users' 
                 AND column_name IN ('reset_token', 'reset_token_expires')";
    
    $existingColumns = $sqlDb->fetchAll($checkSql);
    $existingColumnNames = array_column($existingColumns, 'column_name');
    
    echo "Existing columns: " . (empty($existingColumnNames) ? 'none' : implode(', ', $existingColumnNames)) . "\n\n";
    
    // Add reset_token column if not exists
    if (!in_array('reset_token', $existingColumnNames)) {
        echo "Adding reset_token column...\n";
        $sqlDb->execute("ALTER TABLE users ADD COLUMN reset_token VARCHAR(255)");
        echo "✓ reset_token column added\n\n";
    } else {
        echo "✓ reset_token column already exists\n\n";
    }
    
    // Add reset_token_expires column if not exists
    if (!in_array('reset_token_expires', $existingColumnNames)) {
        echo "Adding reset_token_expires column...\n";
        $sqlDb->execute("ALTER TABLE users ADD COLUMN reset_token_expires TIMESTAMP WITH TIME ZONE");
        echo "✓ reset_token_expires column added\n\n";
    } else {
        echo "✓ reset_token_expires column already exists\n\n";
    }
    
    // Add index if not exists
    echo "Creating index on reset_token...\n";
    $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_users_reset_token ON users(reset_token) WHERE reset_token IS NOT NULL");
    echo "✓ Index created\n\n";
    
    // Verify columns
    echo "Verifying columns...\n";
    $verifySql = "SELECT column_name, data_type, is_nullable 
                  FROM information_schema.columns 
                  WHERE table_name = 'users' 
                  AND column_name IN ('reset_token', 'reset_token_expires')
                  ORDER BY column_name";
    
    $columns = $sqlDb->fetchAll($verifySql);
    
    echo "\nColumn Details:\n";
    echo str_repeat('-', 60) . "\n";
    printf("%-25s %-20s %-10s\n", "Column Name", "Data Type", "Nullable");
    echo str_repeat('-', 60) . "\n";
    
    foreach ($columns as $col) {
        printf("%-25s %-20s %-10s\n", $col['column_name'], $col['data_type'], $col['is_nullable']);
    }
    echo str_repeat('-', 60) . "\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\nPassword reset functionality is now ready to use.\n";
    echo "Users can now reset their passwords via email at: modules/users/forgot_password.php\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
