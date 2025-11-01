<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();
$stores = $db->fetchAll('SELECT id, name, latitude, longitude FROM stores WHERE active = TRUE');

echo "Total stores: " . count($stores) . "\n\n";

foreach($stores as $s) {
    $lat = $s['latitude'] ?? 'NULL';
    $lon = $s['longitude'] ?? 'NULL';
    $hasLocation = ($lat !== 'NULL' && $lon !== 'NULL' && $lat !== '' && $lon !== '');
    echo "Store: " . $s['name'] . "\n";
    echo "  Lat: $lat, Lon: $lon " . ($hasLocation ? "✓ HAS LOCATION" : "✗ NO LOCATION") . "\n\n";
}

$withLocation = array_filter($stores, function($s) {
    $lat = $s['latitude'] ?? null;
    $lon = $s['longitude'] ?? null;
    return !empty($lat) && !empty($lon);
});

echo "\nStores with location data: " . count($withLocation) . " / " . count($stores) . "\n";
