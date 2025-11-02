<?php
/**
 * API to fetch stores from Firebase for map display
 */

header('Content-Type: application/json');

require_once '../../../config.php';
require_once '../../../functions.php';
require_once '../../../firebase_rest_client.php';

session_start();

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check permission
if (!currentUserHasPermission('can_view_stores')) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied']);
    exit;
}

try {
    $client = new FirebaseRestClient();
    
    // Fetch stores from Firebase
    $firebaseStores = $client->queryCollection('stores');
    
    if (!is_array($firebaseStores)) {
        throw new Exception('Invalid data from Firebase');
    }
    
    // Filter active stores only
    $activeStores = array_filter($firebaseStores, function($store) {
        return isset($store['active']) && $store['active'] == 1;
    });
    
    // Format stores for map display
    $formattedStores = [];
    foreach ($activeStores as $id => $store) {
        $formattedStores[] = [
            'id' => $id,
            'name' => $store['name'] ?? 'Unnamed Store',
            'code' => $store['code'] ?? '',
            'address' => $store['address'] ?? 'Not provided',
            'city' => $store['city'] ?? '',
            'state' => $store['state'] ?? '',
            'country' => $store['country'] ?? '',
            'latitude' => floatval($store['latitude'] ?? 0),
            'longitude' => floatval($store['longitude'] ?? 0),
            'store_type' => $store['store_type'] ?? 'retail',
            'region_name' => $store['region_name'] ?? '',
            'active' => 1,
            'phone' => $store['phone'] ?? '',
            'manager' => $store['manager'] ?? '',
            'opening_date' => $store['opening_date'] ?? '',
            'operating_hours' => $store['operating_hours'] ?? ''
        ];
    }
    
    echo json_encode([
        'success' => true,
        'stores' => $formattedStores,
        'count' => count($formattedStores)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
