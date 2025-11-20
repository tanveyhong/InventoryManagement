<?php
/**
 * Add payment tracking columns to sales table
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../sql_db.php';

echo "=== Adding Payment Tracking Columns to Sales Table ===\n\n";

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // Check existing columns
    $columns = $sqlDb->fetchAll("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name = 'sales'
        ORDER BY ordinal_position
    ");
    
    $existingColumns = array_column($columns, 'column_name');
    
    echo "Existing columns in sales table:\n";
    echo implode(', ', $existingColumns) . "\n\n";
    
    // Add new columns if they don't exist
    $columnsToAdd = [
        'amount_paid' => 'ALTER TABLE sales ADD COLUMN IF NOT EXISTS amount_paid NUMERIC(10,2)',
        'payment_change' => 'ALTER TABLE sales ADD COLUMN IF NOT EXISTS payment_change NUMERIC(10,2)',
        'payment_reference' => 'ALTER TABLE sales ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(200)',
        'payment_details' => 'ALTER TABLE sales ADD COLUMN IF NOT EXISTS payment_details TEXT',
        'cashier_id' => 'ALTER TABLE sales ADD COLUMN IF NOT EXISTS cashier_id INTEGER',
        'cashier_name' => 'ALTER TABLE sales ADD COLUMN IF NOT EXISTS cashier_name VARCHAR(200)'
    ];
    
    foreach ($columnsToAdd as $colName => $sql) {
        if (!in_array($colName, $existingColumns)) {
            echo "Adding column: $colName... ";
            $sqlDb->execute($sql);
            echo "✓ Done\n";
        } else {
            echo "Column $colName already exists ✓\n";
        }
    }
    
    echo "\n✅ Sales table updated successfully!\n";
    echo "\nVerifying new columns...\n";
    
    $newColumns = $sqlDb->fetchAll("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_name = 'sales' 
        AND column_name IN ('amount_paid', 'payment_change', 'payment_reference', 'payment_details', 'cashier_id', 'cashier_name')
        ORDER BY column_name
    ");
    
    if (!empty($newColumns)) {
        echo "\nNew columns added:\n";
        foreach ($newColumns as $col) {
            echo "  - {$col['column_name']} ({$col['data_type']})\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
