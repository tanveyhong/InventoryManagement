<?php
// API endpoint for store performance data
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

// Check permission to view stores
if (!currentUserHasPermission('can_view_stores') && !currentUserHasPermission('can_view_reports')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    $sql_db = getSQLDB();
    $store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
    $days = min(365, max(7, intval($_GET['days'] ?? 30))); // Between 7 days and 1 year
    
    if (!$store_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store ID required']);
        exit;
    }
    
    // Get store performance data
    $performance_query = "SELECT DATE(metric_date) as date,
                                AVG(daily_sales) as daily_sales,
                                AVG(customer_count) as customer_count,
                                AVG(avg_transaction_value) as avg_transaction,
                                AVG(staff_rating) as staff_rating,
                                AVG(inventory_turnover) as inventory_turnover
                          FROM store_performance 
                          WHERE store_id = ? AND metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                          GROUP BY DATE(metric_date)
                          ORDER BY date DESC";
    
    $performance_data = $sql_db->fetchAll($performance_query, [$store_id, $days]);
    
    // Get current period summary
    $current_summary = $sql_db->fetch("SELECT 
                                        COUNT(DISTINCT DATE(metric_date)) as days_with_data,
                                        AVG(daily_sales) as avg_daily_sales,
                                        SUM(daily_sales) as total_sales,
                                        AVG(customer_count) as avg_daily_customers,
                                        SUM(customer_count) as total_customers,
                                        AVG(avg_transaction_value) as avg_transaction_value,
                                        AVG(staff_rating) as avg_staff_rating,
                                        AVG(inventory_turnover) as avg_inventory_turnover
                                       FROM store_performance 
                                       WHERE store_id = ? AND metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)",
                                      [$store_id, $days]);
    
    // Get comparison period summary (same period, previous timeframe)
    $comparison_summary = $sql_db->fetch("SELECT 
                                           AVG(daily_sales) as avg_daily_sales,
                                           SUM(daily_sales) as total_sales,
                                           AVG(customer_count) as avg_daily_customers,
                                           AVG(avg_transaction_value) as avg_transaction_value,
                                           AVG(staff_rating) as avg_staff_rating
                                          FROM store_performance 
                                          WHERE store_id = ? 
                                          AND metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                                          AND metric_date < DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)",
                                         [$store_id, $days * 2, $days]);
    
    // Calculate trends
    $trends = [];
    if ($comparison_summary && $current_summary) {
        $trends = [
            'sales' => $comparison_summary['total_sales'] > 0 
                      ? (($current_summary['total_sales'] - $comparison_summary['total_sales']) / $comparison_summary['total_sales']) * 100 
                      : 0,
            'customers' => $comparison_summary['avg_daily_customers'] > 0 
                          ? (($current_summary['avg_daily_customers'] - $comparison_summary['avg_daily_customers']) / $comparison_summary['avg_daily_customers']) * 100 
                          : 0,
            'transaction_value' => $comparison_summary['avg_transaction_value'] > 0 
                                  ? (($current_summary['avg_transaction_value'] - $comparison_summary['avg_transaction_value']) / $comparison_summary['avg_transaction_value']) * 100 
                                  : 0,
            'staff_rating' => $comparison_summary['avg_staff_rating'] > 0 
                             ? (($current_summary['avg_staff_rating'] - $comparison_summary['avg_staff_rating']) / $comparison_summary['avg_staff_rating']) * 100 
                             : 0
        ];
    }
    
    // Get top performing days
    $top_days = $sql_db->fetchAll("SELECT DATE(metric_date) as date, daily_sales, customer_count
                                  FROM store_performance 
                                  WHERE store_id = ? AND metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                                  ORDER BY daily_sales DESC 
                                  LIMIT 5", [$store_id, $days]);
    
    // Get performance by day of week
    $day_performance = $sql_db->fetchAll("SELECT 
                                           DAYNAME(metric_date) as day_name,
                                           DAYOFWEEK(metric_date) as day_number,
                                           AVG(daily_sales) as avg_sales,
                                           AVG(customer_count) as avg_customers
                                          FROM store_performance 
                                          WHERE store_id = ? AND metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
                                          GROUP BY DAYOFWEEK(metric_date), DAYNAME(metric_date)
                                          ORDER BY day_number", [$store_id, $days]);
    
    // Format data for charts
    $chart_data = [
        'sales_trend' => array_map(function($row) {
            return [
                'date' => $row['date'],
                'sales' => floatval($row['daily_sales']),
                'customers' => intval($row['customer_count'])
            ];
        }, array_reverse($performance_data)),
        
        'day_of_week' => array_map(function($row) {
            return [
                'day' => $row['day_name'],
                'sales' => floatval($row['avg_sales']),
                'customers' => floatval($row['avg_customers'])
            ];
        }, $day_performance)
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'current_summary' => $current_summary,
            'comparison_summary' => $comparison_summary,
            'trends' => $trends,
            'chart_data' => $chart_data,
            'top_days' => $top_days,
            'period_days' => $days
        ],
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    error_log("Store performance API error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve performance data',
        'message' => DEBUG_MODE ? $e->getMessage() : 'Internal server error'
    ]);
}
?>