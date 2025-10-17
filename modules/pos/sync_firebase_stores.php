<?php
/**
 * Sync Firebase Stores to SQL Database for POS
 * This script pulls your actual stores from Firebase and adds them to SQL
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../firebase_rest_client.php';

echo "==========================================\n";
echo "Firebase → SQL Store Sync\n";
echo "==========================================\n\n";

try {
    $db = getSQLDB();
    $client = new FirebaseRestClient();
    
    // Step 1: Get Firebase stores
    echo "📥 Fetching stores from Firebase...\n";
    $firebaseStores = $client->queryCollection('stores');
    
    if (empty($firebaseStores) || !is_array($firebaseStores)) {
        throw new Exception("No stores found in Firebase!");
    }
    
    // Filter active stores
    $activeStores = array_filter($firebaseStores, function($s) {
        return isset($s['active']) && $s['active'] == 1;
    });
    
    echo "✅ Found " . count($activeStores) . " active stores in Firebase\n\n";
    
    // Step 2: Clear existing SQL stores (optional - uncomment to replace all)
    // echo "🗑️  Clearing existing SQL stores...\n";
    // $db->execute("DELETE FROM stores");
    // echo "✅ Cleared\n\n";
    
    // Step 3: Sync each store
    echo "🔄 Syncing stores to SQL...\n";
    echo "-------------------------------------------\n";
    
    $synced = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ($activeStores as $firebaseId => $store) {
        $name = $store['name'] ?? 'Unnamed Store';
        $code = $store['code'] ?? '';
        $address = $store['address'] ?? 'Not provided';
        $city = $store['city'] ?? '';
        $phone = $store['phone'] ?? '';
        $manager = $store['manager'] ?? '';
        
        // Check if store already exists (by code or name)
        $existing = null;
        if (!empty($code)) {
            $existing = $db->fetch("SELECT id FROM stores WHERE code = ?", [$code]);
        }
        if (!$existing) {
            $existing = $db->fetch("SELECT id FROM stores WHERE name = ?", [$name]);
        }
        
        try {
            if ($existing) {
                // Update existing store
                $db->execute(
                    "UPDATE stores SET 
                        name = ?, 
                        code = ?, 
                        address = ?, 
                        city = ?, 
                        phone = ?, 
                        manager = ?,
                        firebase_id = ?
                    WHERE id = ?",
                    [$name, $code, $address, $city, $phone, $manager, $firebaseId, $existing['id']]
                );
                echo "   ✏️  Updated: $name ($code)\n";
                $skipped++;
            } else {
                // Insert new store
                $db->execute(
                    "INSERT INTO stores (name, code, address, city, phone, manager, firebase_id, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))",
                    [$name, $code, $address, $city, $phone, $manager, $firebaseId]
                );
                echo "   ✅ Added: $name ($code)\n";
                $synced++;
            }
        } catch (Exception $e) {
            echo "   ❌ Error with $name: " . $e->getMessage() . "\n";
            $errors++;
        }
    }
    
    echo "\n==========================================\n";
    echo "SYNC COMPLETE\n";
    echo "==========================================\n";
    echo "✅ New stores added: $synced\n";
    echo "✏️  Existing stores updated: $skipped\n";
    echo "❌ Errors: $errors\n";
    
    // Step 4: Show final count
    echo "\n📊 Final Store Count:\n";
    $totalStores = $db->fetch("SELECT COUNT(*) as count FROM stores");
    echo "   Total stores in SQL: " . $totalStores['count'] . "\n";
    
    echo "\n✨ Your POS systems can now use these stores!\n";
    echo "==========================================\n";
    
} catch (Exception $e) {
    echo "\n❌ SYNC FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
?>
