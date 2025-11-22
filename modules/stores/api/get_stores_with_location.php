<?php
// API endpoint to get stores with location data for mapping
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
    $sql_db = getSQLDB();
    
    // Get filter parameters
    $region_id = isset($_GET['region_id']) ? intval($_GET['region_id']) : 0;
    $status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
    $search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
    
    // Build query
    $query = "SELECT s.*, r.name as region_name, r.code as region_code,
                     COUNT(p.id) as product_count,
                     SUM(CASE WHEN p.quantity <= p.reorder_level THEN 1 ELSE 0 END) as low_stock_count,
                     COALESCE(sp.total_sales, 0) as total_sales,
                     COALESCE(sp.avg_rating, 0) as avg_rating
              FROM stores s
              LEFT JOIN regions r ON s.region_id = r.id
              LEFT JOIN products p ON p.store_id = s.id AND p.active = 1
              LEFT JOIN store_performance sp ON sp.store_id = s.id
              WHERE s.active = 1";
    
    $params = [];
    
    if ($region_id > 0) {
        $query .= " AND s.region_id = ?";
        $params[] = $region_id;
    }
    
    if (!empty($status)) {
        $query .= " AND s.status = ?";
        $params[] = $status;
    }
    
    if (!empty($search)) {
        $query .= " AND (s.name LIKE ? OR s.address LIKE ? OR s.manager_name LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $query .= " GROUP BY s.id ORDER BY s.name";
    
    $stores = $sql_db->fetchAll($query, $params);
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search = '%' . sanitizeInput($_GET['search']) . '%';
        $where_conditions[] = '(s.name LIKE ? OR s.city LIKE ? OR s.address LIKE ? OR r.name LIKE ?)';
        $params = array_merge($params, [$search, $search, $search, $search]);
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get stores with comprehensive data
    $sql = "SELECT 
                s.id,
                s.name,
                s.code,
                s.address,
                s.city,
                s.state,
                s.zip_code,
                s.phone,
                s.email,
                s.latitude,
                s.longitude,
                s.store_type,
                s.max_capacity,
                s.store_size,
                s.opening_date,
                s.timezone,
                s.contact_person,
                s.emergency_contact,
                s.last_inventory_update,
                s.active,
                s.created_at,
                r.name as region_name,
                r.code as region_code,
                r.regional_manager,
                COUNT(DISTINCT p.id) as total_products,
                COALESCE(SUM(p.quantity), 0) as total_inventory,
                COALESCE(SUM(p.quantity * p.cost_price), 0) as inventory_value,
                COUNT(DISTINCT CASE WHEN p.quantity <= p.reorder_level THEN p.id END) as low_stock_count,
                COUNT(DISTINCT ss.id) as staff_count,
                COALESCE(AVG(sp.daily_sales), 0) as avg_daily_sales,
                COALESCE(MAX(sp.metric_date), NULL) as last_performance_update
            FROM stores s
            LEFT JOIN regions r ON s.region_id = r.id
            LEFT JOIN products p ON s.id = p.store_id AND p.active = 1
            LEFT JOIN store_staff ss ON s.id = ss.store_id AND ss.active = 1
            LEFT JOIN store_performance sp ON s.id = sp.store_id 
                AND sp.metric_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            $where_clause
            GROUP BY s.id, r.id
            ORDER BY s.name";
    
    $stores = $db->fetchAll($sql, $params);
    
    // Format the data for frontend consumption
    $formatted_stores = [];
    foreach ($stores as $store) {
        $formatted_stores[] = [
            'id' => (int)$store['id'],
            'name' => $store['name'],
            'code' => $store['code'],
            'address' => $store['address'],
            'city' => $store['city'],
            'state' => $store['state'],
            'zip_code' => $store['zip_code'],
            'phone' => $store['phone'],
            'email' => $store['email'],
            'latitude' => $store['latitude'] ? (float)$store['latitude'] : null,
            'longitude' => $store['longitude'] ? (float)$store['longitude'] : null,
            'store_type' => $store['store_type'],
            'max_capacity' => (int)$store['max_capacity'],
            'store_size' => (float)$store['store_size'],
            'opening_date' => $store['opening_date'],
            'timezone' => $store['timezone'],
            'contact_person' => $store['contact_person'],
            'emergency_contact' => $store['emergency_contact'],
            'last_inventory_update' => $store['last_inventory_update'],
            'active' => (bool)$store['active'],
            'created_at' => $store['created_at'],
            'region_name' => $store['region_name'],
            'region_code' => $store['region_code'],
            'regional_manager' => $store['regional_manager'],
            'total_products' => (int)$store['total_products'],
            'total_inventory' => (int)$store['total_inventory'],
            'inventory_value' => (float)$store['inventory_value'],
            'low_stock_count' => (int)$store['low_stock_count'],
            'expired_count' => (int)$store['expired_count'],
            'staff_count' => (int)$store['staff_count'],
            'avg_daily_sales' => (float)$store['avg_daily_sales'],
            'last_performance_update' => $store['last_performance_update']
        ];
    }
    
    // Get summary statistics
    $stats_sql = "SELECT 
                    COUNT(*) as total_stores,
                    COUNT(CASE WHEN active = 1 THEN 1 END) as active_stores,
                    COUNT(DISTINCT region_id) as total_regions,
                    COALESCE(SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END), 0) as operational_stores
                  FROM stores";
    
    $stats = $db->fetch($stats_sql, []);
    
    // Calculate total inventory value from the filtered results
    $total_inventory_value = array_sum(array_column($formatted_stores, 'inventory_value'));
    
    echo json_encode([
        'success' => true,
        'stores' => $formatted_stores,
        'statistics' => [
            'total_stores' => (int)$stats['total_stores'],
            'active_stores' => (int)$stats['active_stores'],
            'total_regions' => (int)$stats['total_regions'],
            'operational_stores' => (int)$stats['operational_stores'],
            'total_inventory_value' => $total_inventory_value,
            'filtered_count' => count($formatted_stores)
        ],
        'filters_applied' => [
            'region' => $_GET['region'] ?? null,
            'type' => $_GET['type'] ?? null,
            'status' => $_GET['status'] ?? null,
            'search' => $_GET['search'] ?? null
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Store Map API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching store data',
        'error' => $e->getMessage()
    ]);
}