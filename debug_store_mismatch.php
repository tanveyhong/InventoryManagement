<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== STORE ID MISMATCH CHECK ===\n\n";

// Check stores
echo "1. Stores in Database:\n";
$stores = $db->fetchAll("SELECT id, name, has_pos FROM stores ORDER BY id");
foreach ($stores as $store) {
    $posStatus = $store['has_pos'] ? '✓ POS Enabled' : '✗ No POS';
    echo "   Store ID: {$store['id']} - {$store['name']} - $posStatus\n";
}
echo "\n";

// Check sales and their store_ids
echo "2. Sales and Their Store IDs:\n";
$sales = $db->fetchAll("SELECT id, sale_number, store_id, total_amount, created_at FROM sales ORDER BY created_at DESC LIMIT 5");
foreach ($sales as $sale) {
    echo "   Sale: {$sale['sale_number']} - Store ID: '{$sale['store_id']}' (type: " . gettype($sale['store_id']) . ")\n";
}
echo "\n";

// Check what the POS integration page is looking for
echo "3. POS-Enabled Store IDs:\n";
$posStores = $db->fetchAll("SELECT id FROM stores WHERE has_pos = 1");
$posStoreIds = array_map(function($s) { return $s['id']; }, $posStores);
echo "   Looking for store IDs: " . implode(', ', $posStoreIds) . "\n";
echo "   Types: " . implode(', ', array_map('gettype', $posStoreIds)) . "\n";
echo "\n";

// Check if there's a match
echo "4. Testing Query Match:\n";
if (!empty($posStoreIds)) {
    $query = "SELECT store_id, COUNT(*) as total_sales 
             FROM sales 
             WHERE store_id IN (" . implode(',', array_map('intval', $posStoreIds)) . ") 
             GROUP BY store_id";
    echo "   Query: $query\n\n";
    
    $results = $db->fetchAll($query);
    if (empty($results)) {
        echo "   ❌ NO MATCHES FOUND!\n";
        echo "   This means store_id in sales table doesn't match POS-enabled stores\n";
    } else {
        echo "   ✅ Found matches:\n";
        foreach ($results as $result) {
            echo "      Store {$result['store_id']}: {$result['total_sales']} sales\n";
        }
    }
}
