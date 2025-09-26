<?php
// API endpoint for regional analytics data
require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

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
    $days = min(365, max(7, intval($_GET['days'] ?? 30)));
    $metric = sanitizeInput($_GET['metric'] ?? 'sales');
    
    // Regional performance comparison
    $regional_comparison_query = "SELECT 
                                    r.id, r.name as region_name, r.code,
                                    COUNT(DISTINCT s.id) as store_count,
                                    COUNT(DISTINCT CASE WHEN s.status = 'active' THEN s.id END) as active_stores,
                                    COALESCE(AVG(sp.daily_sales), 0) as avg_daily_sales,
                                    COALESCE(SUM(sp.daily_sales), 0) as total_sales,
                                    COALESCE(AVG(sp.customer_count), 0) as avg_customers,
                                    COALESCE(AVG(sp.avg_transaction_value), 0) as avg_transaction_value,
                                    COALESCE(AVG(sp.staff_rating), 0) as avg_rating,
                                    COALESCE(AVG(sp.inventory_turnover), 0) as avg_inventory_turnover
                                  FROM regions r
                                  LEFT JOIN stores s ON r.id = s.region_id
                                  LEFT JOIN store_performance sp ON sp.store_id = s.id 
                                    AND sp.metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                                  WHERE r.active = 1";
    
    $params = [$days];
    
    if ($region_id > 0) {
        $regional_comparison_query .= " AND r.id = ?";
        $params[] = $region_id;
    }
    
    $regional_comparison_query .= " GROUP BY r.id ORDER BY total_sales DESC";
    
    $regional_data = $sql_db->fetchAll($regional_comparison_query, $params);
    
    // Store performance within regions
    $store_performance_query = "SELECT 
                                  s.id, s.name as store_name, s.address,
                                  r.name as region_name, r.id as region_id,
                                  COALESCE(AVG(sp.daily_sales), 0) as avg_daily_sales,
                                  COALESCE(SUM(sp.daily_sales), 0) as total_sales,
                                  COALESCE(AVG(sp.customer_count), 0) as avg_customers,
                                  COALESCE(AVG(sp.avg_transaction_value), 0) as avg_transaction_value,
                                  COALESCE(AVG(sp.staff_rating), 0) as avg_rating,
                                  COUNT(sp.id) as data_points
                                FROM stores s
                                JOIN regions r ON s.region_id = r.id
                                LEFT JOIN store_performance sp ON sp.store_id = s.id 
                                  AND sp.metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                                WHERE s.active = 1";
    
    $store_params = [$days];
    
    if ($region_id > 0) {
        $store_performance_query .= " AND r.id = ?";
        $store_params[] = $region_id;
    }
    
    $store_performance_query .= " GROUP BY s.id ORDER BY total_sales DESC";
    
    $store_data = $sql_db->fetchAll($store_performance_query, $store_params);
    
    // Time series data for trends
    $trend_query = "SELECT 
                      DATE(sp.metric_date) as date,
                      r.name as region_name,
                      AVG(sp.daily_sales) as daily_sales,
                      AVG(sp.customer_count) as customer_count,
                      AVG(sp.avg_transaction_value) as avg_transaction_value
                    FROM store_performance sp
                    JOIN stores s ON sp.store_id = s.id
                    JOIN regions r ON s.region_id = r.id
                    WHERE sp.metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                      AND s.active = 1";
    
    $trend_params = [$days];
    
    if ($region_id > 0) {
        $trend_query .= " AND r.id = ?";
        $trend_params[] = $region_id;
    }
    
    $trend_query .= " GROUP BY DATE(sp.metric_date), r.id
                     ORDER BY date DESC, r.name";
    
    $trend_data = $sql_db->fetchAll($trend_query, $trend_params);
    
    // Calculate regional rankings and insights
    foreach ($regional_data as &$region) {
        // Performance score calculation
        $region['performance_score'] = min(100, max(0,
            ($region['avg_rating'] * 20) +
            (min(50, ($region['total_sales'] / 1000) * 2)) +
            (min(20, $region['avg_inventory_turnover'] * 5))
        ));
        
        $region['formatted_sales'] = '$' . number_format($region['total_sales'], 2);
        $region['formatted_avg_sales'] = '$' . number_format($region['avg_daily_sales'], 2);
        $region['formatted_rating'] = number_format($region['avg_rating'], 1);
        
        // Status indicator
        if ($region['active_stores'] == 0) {
            $region['status'] = 'inactive';
            $region['status_class'] = 'badge-danger';
        } elseif ($region['active_stores'] < $region['store_count']) {
            $region['status'] = 'partial';
            $region['status_class'] = 'badge-warning';
        } else {
            $region['status'] = 'active';
            $region['status_class'] = 'badge-success';
        }
    }
    
    // Generate insights
    $insights = [];
    if (!empty($regional_data)) {
        $total_sales = array_sum(array_column($regional_data, 'total_sales'));
        $best_region = $regional_data[0];
        
        $insights[] = [
            'type' => 'best_performer',
            'title' => 'Top Performing Region',
            'message' => "{$best_region['region_name']} leads with " . $best_region['formatted_sales'] . " in sales",
            'region' => $best_region['region_name'],
            'value' => $best_region['formatted_sales']
        ];
        
        if ($total_sales > 0) {
            $market_share = ($best_region['total_sales'] / $total_sales) * 100;
            $insights[] = [
                'type' => 'market_share',
                'title' => 'Market Share',
                'message' => "{$best_region['region_name']} holds " . number_format($market_share, 1) . "% of total sales",
                'percentage' => number_format($market_share, 1)
            ];
        }
        
        // Find underperforming regions
        $avg_performance = array_sum(array_column($regional_data, 'performance_score')) / count($regional_data);
        $underperforming = array_filter($regional_data, fn($r) => $r['performance_score'] < $avg_performance * 0.8);
        
        if (!empty($underperforming)) {
            $insights[] = [
                'type' => 'improvement_needed',
                'title' => 'Regions Needing Attention',
                'message' => count($underperforming) . " region(s) performing below average",
                'count' => count($underperforming),
                'regions' => array_column($underperforming, 'region_name')
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'regional_comparison' => $regional_data,
            'store_performance' => $store_data,
            'trend_data' => $trend_data,
            'insights' => $insights,
            'summary' => [
                'total_regions' => count($regional_data),
                'total_stores' => array_sum(array_column($regional_data, 'store_count')),
                'active_stores' => array_sum(array_column($regional_data, 'active_stores')),
                'total_sales' => array_sum(array_column($regional_data, 'total_sales')),
                'avg_rating' => count($regional_data) > 0 ? array_sum(array_column($regional_data, 'avg_rating')) / count($regional_data) : 0,
                'period_days' => $days
            ]
        ],
        'filters' => [
            'region_id' => $region_id,
            'days' => $days,
            'metric' => $metric
        ],
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    error_log("Regional analytics API error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve regional analytics',
        'message' => DEBUG_MODE ? $e->getMessage() : 'Internal server error'
    ]);
}
?>