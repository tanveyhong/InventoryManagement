<?php
/**
 * API Endpoint: Store Geocoding
 * Geocodes a store address and returns coordinates
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

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    // Geocode an address
    $input = json_decode(file_get_contents('php://input'), true);
    
    $address = $input['address'] ?? '';
    $city = $input['city'] ?? '';
    $state = $input['state'] ?? '';
    $zip_code = $input['zip_code'] ?? '';
    
    if (empty($address) && empty($city)) {
        echo json_encode([
            'success' => false,
            'error' => 'Address or city is required'
        ]);
        exit;
    }
    
    // Build full address
    $fullAddress = trim(implode(', ', array_filter([$address, $city, $state, $zip_code])));
    
    // Use Nominatim API (OpenStreetMap) for geocoding
    $geocodeUrl = 'https://nominatim.openstreetmap.org/search?format=json&q=' . urlencode($fullAddress) . '&limit=1';
    
    // Set user agent (required by Nominatim)
    $options = [
        'http' => [
            'header' => "User-Agent: InventorySystem/1.0\r\n"
        ]
    ];
    $context = stream_context_create($options);
    
    try {
        $response = @file_get_contents($geocodeUrl, false, $context);
        
        if ($response === false) {
            echo json_encode([
                'success' => false,
                'error' => 'Failed to connect to geocoding service'
            ]);
            exit;
        }
        
        $data = json_decode($response, true);
        
        if (!empty($data) && isset($data[0]['lat']) && isset($data[0]['lon'])) {
            echo json_encode([
                'success' => true,
                'latitude' => $data[0]['lat'],
                'longitude' => $data[0]['lon'],
                'display_name' => $data[0]['display_name'] ?? $fullAddress
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Location not found. Please check the address and try again.'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Geocoding error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid request method'
    ]);
}
