<?php
require_once 'config.php';
require_once 'functions.php';
require_once 'sql_db.php';

echo "=== Migrating Store Location Data from Firebase to PostgreSQL ===\n\n";

$firebaseDb = getDB();
$sqlDb = SQLDatabase::getInstance();

// Fetch stores from Firebase
$firebaseStores = $firebaseDb->readAll('stores');
echo "Found " . count($firebaseStores) . " stores in Firebase\n\n";

$updated = 0;
$skipped = 0;
$errors = 0;

foreach ($firebaseStores as $store) {
    $firebaseId = $store['id'] ?? null;
    $name = $store['name'] ?? 'Unknown';
    $code = $store['code'] ?? null;
    $latitude = $store['latitude'] ?? null;
    $longitude = $store['longitude'] ?? null;
    
    if (!$name || $name === 'Unknown') {
        echo "⚠ Skipping store without name\n";
        $skipped++;
        continue;
    }
    
    // Try to find store in PostgreSQL by name or code
    $pgStore = null;
    if ($code) {
        $pgStore = $sqlDb->fetch("SELECT id, name FROM stores WHERE code = ?", [$code]);
    }
    if (!$pgStore) {
        $pgStore = $sqlDb->fetch("SELECT id, name FROM stores WHERE name = ?", [$name]);
    }
    
    if (!$pgStore) {
        echo "⚠ Store '$name' not found in PostgreSQL - skipping\n";
        $skipped++;
        continue;
    }
    
    $pgId = $pgStore['id'];
    
    // Update latitude and longitude
    if (!empty($latitude) && !empty($longitude)) {
        try {
            $sqlDb->execute(
                "UPDATE stores SET latitude = ?, longitude = ?, updated_at = NOW() WHERE id = ?",
                [$latitude, $longitude, $pgId]
            );
            echo "✓ Updated '$name': Lat=$latitude, Lon=$longitude\n";
            $updated++;
        } catch (Exception $e) {
            echo "✗ Error updating '$name': " . $e->getMessage() . "\n";
            $errors++;
        }
    } else {
        echo "- Store '$name' has no location data in Firebase\n";
        $skipped++;
    }
}

echo "\n=== Migration Complete ===\n";
echo "Updated: $updated stores\n";
echo "Skipped: $skipped stores (no location or not in PostgreSQL)\n";
echo "Errors: $errors\n";

// Verify the migration
echo "\n=== Verification ===\n";
$storesWithLocation = $sqlDb->fetchAll("SELECT id, name, latitude, longitude FROM stores WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
echo "Stores with location data in PostgreSQL: " . count($storesWithLocation) . "\n";

foreach ($storesWithLocation as $s) {
    echo "  - {$s['name']}: ({$s['latitude']}, {$s['longitude']})\n";
}
