<?php
/**
 * API Endpoint: Store Statistics
 * Returns aggregated statistics about stores
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

// Check permission to view stores
if (!currentUserHasPermission('can_view_stores') && !currentUserHasPermission('can_view_reports')) {
    echo json_encode([
        'success' => false,
        'error' => 'Permission denied'
    ]);
    exit;
}

try {
    $db = getDB();
    $all_stores = $db->readAll('stores', [['active', '==', 1]]);
    $regions = $db->readAll('regions', [['active', '==', 1]]);
    
    // Calculate statistics
    $total_stores = count($all_stores);
    $active_stores = count(array_filter($all_stores, function($s) {
        return ($s['status'] ?? 'active') === 'active';
    }));
    $stores_with_location = count(array_filter($all_stores, function($s) {
        return !empty($s['latitude']) && !empty($s['longitude']);
    }));
    
    // Count by type
    $by_type = [];
    foreach ($all_stores as $store) {
        $type = $store['store_type'] ?? 'retail';
        if (!isset($by_type[$type])) {
            $by_type[$type] = 0;
        }
        $by_type[$type]++;
    }
    
    // Count by region
    $by_region = [];
    foreach ($all_stores as $store) {
        $region_id = $store['region_id'] ?? 'unassigned';
        if (!isset($by_region[$region_id])) {
            $by_region[$region_id] = 0;
        }
        $by_region[$region_id]++;
    }
    
    // Count by state
    $by_state = [];
    foreach ($all_stores as $store) {
        $state = $store['state'] ?? 'Unknown';
        if (!isset($by_state[$state])) {
            $by_state[$state] = 0;
        }
        $by_state[$state]++;
    }
    
    // Calculate total square footage
    $total_square_footage = 0;
    foreach ($all_stores as $store) {
        if (isset($store['square_footage']) && is_numeric($store['square_footage'])) {
            $total_square_footage += intval($store['square_footage']);
        }
    }
    
    // Calculate total capacity
    $total_capacity = 0;
    foreach ($all_stores as $store) {
        if (isset($store['max_capacity']) && is_numeric($store['max_capacity'])) {
            $total_capacity += intval($store['max_capacity']);
        }
    }
    
    echo json_encode([
        'success' => true,
        'statistics' => [
            'total_stores' => $total_stores,
            'active_stores' => $active_stores,
            'inactive_stores' => $total_stores - $active_stores,
            'stores_with_location' => $stores_with_location,
            'stores_without_location' => $total_stores - $stores_with_location,
            'total_regions' => count($regions),
            'total_square_footage' => $total_square_footage,
            'total_capacity' => $total_capacity,
            'average_square_footage' => $total_stores > 0 ? round($total_square_footage / $total_stores, 2) : 0,
            'average_capacity' => $total_stores > 0 ? round($total_capacity / $total_stores, 2) : 0
        ],
        'by_type' => $by_type,
        'by_region' => $by_region,
        'by_state' => $by_state
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error fetching statistics: ' . $e->getMessage()
    ]);
}
