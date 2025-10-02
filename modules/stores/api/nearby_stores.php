<?php
/**
 * API Endpoint: Nearby Stores
 * Finds stores within a certain radius of a location
 */

header('Content-Type: application/json');
require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized access'
    ]);
    exit;
}

// Get request parameters
$latitude = floatval($_GET['latitude'] ?? 0);
$longitude = floatval($_GET['longitude'] ?? 0);
$radius = floatval($_GET['radius'] ?? 50); // Default 50 km

if ($latitude === 0 || $longitude === 0) {
    echo json_encode([
        'success' => false,
        'error' => 'Latitude and longitude are required'
    ]);
    exit;
}

try {
    $db = getDB();
    $all_stores = $db->readAll('stores', [['active', '==', 1]]);
    
    // Filter stores within radius using Haversine formula
    $nearby_stores = [];
    
    foreach ($all_stores as $store) {
        if (!empty($store['latitude']) && !empty($store['longitude'])) {
            $storeLat = floatval($store['latitude']);
            $storeLon = floatval($store['longitude']);
            
            $distance = calculateDistance($latitude, $longitude, $storeLat, $storeLon);
            
            if ($distance <= $radius) {
                $store['distance'] = round($distance, 2);
                $nearby_stores[] = $store;
            }
        }
    }
    
    // Sort by distance
    usort($nearby_stores, function($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });
    
    echo json_encode([
        'success' => true,
        'stores' => $nearby_stores,
        'count' => count($nearby_stores),
        'radius' => $radius,
        'center' => [
            'latitude' => $latitude,
            'longitude' => $longitude
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error fetching nearby stores: ' . $e->getMessage()
    ]);
}

/**
 * Calculate distance between two coordinates using Haversine formula
 * Returns distance in kilometers
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // Earth's radius in kilometers
    
    $latDiff = deg2rad($lat2 - $lat1);
    $lonDiff = deg2rad($lon2 - $lon1);
    
    $a = sin($latDiff / 2) * sin($latDiff / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($lonDiff / 2) * sin($lonDiff / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}
