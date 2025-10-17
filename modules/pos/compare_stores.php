<?php
/**
 * Check Stores from Both Firebase and SQL
 * This helps identify which source has your actual store records
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../firebase_rest_client.php';

echo "==========================================\n";
echo "Store Data Source Comparison\n";
echo "==========================================\n\n";

// Check SQL Database
echo "1. SQL DATABASE (Currently used by POS):\n";
echo "-------------------------------------------\n";
try {
    $db = getSQLDB();
    $sqlStores = $db->fetchAll("SELECT id, name, code, city, address FROM stores ORDER BY name");
    
    if (empty($sqlStores)) {
        echo "❌ No stores found in SQL database\n";
    } else {
        echo "✅ Found " . count($sqlStores) . " store(s):\n";
        foreach ($sqlStores as $store) {
            echo "   • {$store['name']} ({$store['code']}) - {$store['city']}\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error reading SQL: " . $e->getMessage() . "\n";
}

echo "\n";

// Check Firebase
echo "2. FIREBASE DATABASE (Used by Store Management Module):\n";
echo "-------------------------------------------\n";
try {
    $client = new FirebaseRestClient();
    $firebaseStores = $client->queryCollection('stores');
    
    if (empty($firebaseStores) || !is_array($firebaseStores)) {
        echo "❌ No stores found in Firebase\n";
    } else {
        // Filter active stores
        $activeStores = array_filter($firebaseStores, function($s) {
            return isset($s['active']) && $s['active'] == 1;
        });
        
        echo "✅ Found " . count($activeStores) . " active store(s):\n";
        foreach ($activeStores as $storeId => $store) {
            $name = $store['name'] ?? 'Unnamed';
            $code = $store['code'] ?? 'N/A';
            $city = $store['city'] ?? 'N/A';
            echo "   • $name ($code) - $city [Firebase ID: $storeId]\n";
        }
    }
} catch (Exception $e) {
    echo "❌ Error reading Firebase: " . $e->getMessage() . "\n";
}

echo "\n==========================================\n";
echo "RECOMMENDATION:\n";
echo "==========================================\n\n";

try {
    $sqlCount = count($sqlStores ?? []);
    $fbCount = count($activeStores ?? []);
    
    if ($sqlCount > 0 && $fbCount > 0) {
        if ($sqlCount == $fbCount) {
            echo "✅ Both databases have stores. POS is using SQL stores.\n";
            echo "   These appear to be the same stores already synced.\n";
        } else {
            echo "⚠️  SQL has $sqlCount stores, Firebase has $fbCount stores.\n";
            echo "   You may want to sync them.\n";
        }
    } elseif ($sqlCount > 0 && $fbCount == 0) {
        echo "✅ POS is using SQL stores successfully.\n";
        echo "   Your stores are in the SQL database.\n";
    } elseif ($sqlCount == 0 && $fbCount > 0) {
        echo "❗ POS has no stores! But Firebase has $fbCount stores.\n";
        echo "   Would you like to sync Firebase stores to SQL?\n";
    } else {
        echo "❌ No stores found in either database!\n";
        echo "   Please add stores via Store Management module.\n";
    }
} catch (Exception $e) {
    echo "Unable to determine recommendation.\n";
}

echo "\n==========================================\n";
?>
