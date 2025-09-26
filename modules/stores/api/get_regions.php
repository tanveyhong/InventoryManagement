<?php
// API endpoint to get regions data
require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

// Set JSON header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

try {
    $db = getDB();
    
    // Get all active regions with store counts
    $sql = "SELECT 
                r.id,
                r.name,
                r.code,
                r.description,
                r.regional_manager,
                r.manager_email,
                r.manager_phone,
                r.timezone,
                r.active,
                r.created_at,
                COUNT(s.id) as store_count,
                COUNT(CASE WHEN s.active = 1 THEN s.id END) as active_store_count,
                COALESCE(SUM(CASE WHEN s.active = 1 THEN 1 ELSE 0 END), 0) as operational_stores
            FROM regions r
            LEFT JOIN stores s ON r.id = s.region_id
            WHERE r.active = 1
            GROUP BY r.id
            ORDER BY r.name";
    
    $regions = $db->fetchAll($sql, []);
    
    // Format the data
    $formatted_regions = [];
    foreach ($regions as $region) {
        $formatted_regions[] = [
            'id' => (int)$region['id'],
            'name' => $region['name'],
            'code' => $region['code'],
            'description' => $region['description'],
            'regional_manager' => $region['regional_manager'],
            'manager_email' => $region['manager_email'],
            'manager_phone' => $region['manager_phone'],
            'timezone' => $region['timezone'],
            'active' => (bool)$region['active'],
            'created_at' => $region['created_at'],
            'store_count' => (int)$region['store_count'],
            'active_store_count' => (int)$region['active_store_count'],
            'operational_stores' => (int)$region['operational_stores']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'regions' => $formatted_regions,
        'total_regions' => count($formatted_regions)
    ]);
    
} catch (Exception $e) {
    error_log("Regions API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching regions data',
        'error' => $e->getMessage()
    ]);
}