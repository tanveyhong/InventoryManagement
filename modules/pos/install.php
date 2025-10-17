<?php
/**
 * POS Module Installation Script
 * Runs the database migration to create necessary tables
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

echo "==========================================\n";
echo "POS Module Installation\n";
echo "==========================================\n\n";

try {
    $db = getDB();
    
    echo "Reading schema file...\n";
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    
    if ($schema === false) {
        throw new Exception("Failed to read schema.sql file");
    }
    
    echo "Executing database migration...\n";
    $db->exec($schema);
    
    echo "\n✅ Database migration completed successfully!\n\n";
    
    // Verify tables were created
    echo "Verifying tables...\n";
    $tables = ['sales', 'sale_items', 'pos_logs'];
    
    foreach ($tables as $table) {
        $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'");
        if ($result->fetch()) {
            echo "  ✓ Table '$table' created\n";
        } else {
            echo "  ✗ Table '$table' NOT found\n";
        }
    }
    
    echo "\n==========================================\n";
    echo "Installation Complete!\n";
    echo "==========================================\n\n";
    echo "You can now access:\n";
    echo "- Quick Service POS: modules/pos/quick_service.php\n";
    echo "- Full Retail POS: modules/pos/full_retail.php\n";
    echo "- Sales Dashboard: modules/pos/dashboard.php\n\n";
    
} catch (Exception $e) {
    echo "\n❌ Installation failed!\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    exit(1);
}
