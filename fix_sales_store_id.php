<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== FIXING EXISTING SALES WITH 'main' STORE ID ===\n\n";

// Get first POS-enabled store
$firstPosStore = $db->fetch("SELECT id, name FROM stores WHERE has_pos = 1 ORDER BY id ASC LIMIT 1");

if (!$firstPosStore) {
    echo "❌ No POS-enabled stores found!\n";
    exit;
}

$storeId = $firstPosStore['id'];
$storeName = $firstPosStore['name'];

echo "Will update sales with store_id='main' to use:\n";
echo "   Store ID: $storeId\n";
echo "   Store Name: $storeName\n\n";

// Check how many sales have 'main'
$count = $db->fetch("SELECT COUNT(*) as total FROM sales WHERE store_id = 'main'");
echo "Found {$count['total']} sales with store_id='main'\n\n";

if ($count['total'] > 0) {
    // Update them
    $db->execute("UPDATE sales SET store_id = ? WHERE store_id = 'main'", [$storeId]);
    echo "✅ Updated {$count['total']} sales to use store ID $storeId\n\n";
    
    // Verify
    $updated = $db->fetchAll("SELECT sale_number, store_id FROM sales WHERE store_id = ?", [$storeId]);
    echo "Verification - Sales now with store ID $storeId:\n";
    foreach ($updated as $sale) {
        echo "   - {$sale['sale_number']}: Store ID {$sale['store_id']}\n";
    }
} else {
    echo "No sales need updating.\n";
}
