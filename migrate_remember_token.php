<?php
/**
 * Migration Script: Add Remember Token Columns
 * Purpose: Add remember_token and remember_token_expires columns to users table
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sql_db.php';

echo "=== Remember Me Migration ===\n";
echo "Adding remember_token columns to users table...\n\n";

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // Check if columns already exist
    $checkSql = "SELECT column_name 
                 FROM information_schema.columns 
                 WHERE table_name = 'users' 
                 AND column_name IN ('remember_token', 'remember_token_expires')";
    
    $existingColumns = $sqlDb->fetchAll($checkSql);
    $existingColumnNames = array_column($existingColumns, 'column_name');
    
    echo "Existing columns: " . (empty($existingColumnNames) ? 'none' : implode(', ', $existingColumnNames)) . "\n\n";
    
    // Add remember_token column if not exists
    if (!in_array('remember_token', $existingColumnNames)) {
        echo "Adding remember_token column...\n";
        $sqlDb->execute("ALTER TABLE users ADD COLUMN remember_token VARCHAR(255)");
        echo "✓ remember_token column added\n\n";
    } else {
        echo "✓ remember_token column already exists\n\n";
    }
    
    // Add remember_token_expires column if not exists
    if (!in_array('remember_token_expires', $existingColumnNames)) {
        echo "Adding remember_token_expires column...\n";
        $sqlDb->execute("ALTER TABLE users ADD COLUMN remember_token_expires TIMESTAMP WITH TIME ZONE");
        echo "✓ remember_token_expires column added\n\n";
    } else {
        echo "✓ remember_token_expires column already exists\n\n";
    }
    
    // Add index if not exists
    echo "Creating index on remember_token...\n";
    $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_users_remember_token ON users(remember_token) WHERE remember_token IS NOT NULL");
    echo "✓ Index created\n\n";
    
    // Verify columns
    echo "Verifying columns...\n";
    $verifySql = "SELECT column_name, data_type, is_nullable 
                  FROM information_schema.columns 
                  WHERE table_name = 'users' 
                  AND column_name IN ('remember_token', 'remember_token_expires')
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
    echo "\nRemember Me functionality is now ready to use.\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
