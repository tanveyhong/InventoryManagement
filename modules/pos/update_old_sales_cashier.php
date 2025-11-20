<?php
/**
 * Update Old Sales - Populate cashier_id from user_id for existing sales
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

$db = getSQLDB();

echo "=== Updating Old Sales Records ===\n\n";

try {
    // Check how many sales have NULL cashier_id
    $nullCashierCount = $db->fetch("SELECT COUNT(*) as count FROM sales WHERE cashier_id IS NULL");
    echo "Sales with NULL cashier_id: " . $nullCashierCount['count'] . "\n";
    
    if ($nullCashierCount['count'] > 0) {
        // Update cashier_id from user_id where cashier_id is NULL
        echo "\nUpdating cashier_id from user_id...\n";
        
        $updated = $db->execute("
            UPDATE sales 
            SET cashier_id = user_id
            WHERE cashier_id IS NULL 
            AND user_id IS NOT NULL
        ");
        
        echo "✓ Updated sales with user_id\n";
        
        // Also try to populate cashier_name from users table
        echo "\nPopulating cashier_name from users table...\n";
        
        $updated2 = $db->execute("
            UPDATE sales s
            SET cashier_name = COALESCE(u.username, u.email)
            FROM users u
            WHERE s.cashier_id = u.id
            AND (s.cashier_name IS NULL OR s.cashier_name = '')
        ");
        
        echo "✓ Updated cashier names\n";
        
        // Verify update
        $nullCashierAfter = $db->fetch("SELECT COUNT(*) as count FROM sales WHERE cashier_id IS NULL");
        echo "\nSales with NULL cashier_id after update: " . $nullCashierAfter['count'] . "\n";
        
        $withCashier = $db->fetch("SELECT COUNT(*) as count FROM sales WHERE cashier_id IS NOT NULL");
        echo "Sales with cashier_id: " . $withCashier['count'] . "\n";
        
        echo "\n✅ Update completed successfully!\n";
    } else {
        echo "\n✅ All sales already have cashier_id set!\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
