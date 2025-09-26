<?php
// Dashboard API - Real-time data for dashboard widgets
require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Check if user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $db = getDB();
    
    // Get dashboard statistics
    $stats = [
        'total_products' => getTotalProducts(),
        'low_stock' => getLowStockCount(),
        'total_stores' => getTotalStores(),
        'todays_sales' => getTodaysSales(),
        'last_updated' => date('Y-m-d H:i:s')
    ];
    
    // Get sales data for chart (last 7 days)
    $sales_data = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $daily_sales = $db->fetch(
            "SELECT COALESCE(SUM(total), 0) as sales FROM sales WHERE DATE(created_at) = ?",
            [$date]
        );
        $sales_data[] = [
            'date' => $date,
            'day' => date('D', strtotime($date)),
            'sales' => floatval($daily_sales['sales'] ?? 0)
        ];
    }
    
    // Get recent activity
    $recent_activity = [];
    
    // Recent stock additions
    $recent_stock = $db->fetchAll(
        "SELECT name, created_at, 'stock_added' as type 
         FROM products 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) 
         ORDER BY created_at DESC LIMIT 3"
    );
    
    foreach ($recent_stock as $item) {
        $recent_activity[] = [
            'type' => 'stock_added',
            'message' => "New product added: " . $item['name'],
            'time' => time_ago($item['created_at']),
            'icon' => 'plus',
            'color' => 'info'
        ];
    }
    
    // Low stock alerts
    $low_stock_items = $db->fetchAll(
        "SELECT name, quantity, reorder_level 
         FROM products 
         WHERE quantity <= reorder_level AND active = 1 
         LIMIT 3"
    );
    
    foreach ($low_stock_items as $item) {
        $recent_activity[] = [
            'type' => 'low_stock',
            'message' => "Low stock: " . $item['name'] . " (" . $item['quantity'] . " remaining)",
            'time' => 'Now',
            'icon' => 'exclamation-triangle',
            'color' => 'alert'
        ];
    }
    
    // Sort by time (most recent first)
    usort($recent_activity, function($a, $b) {
        return strcmp($b['time'], $a['time']);
    });
    
    // Limit to 5 most recent items
    $recent_activity = array_slice($recent_activity, 0, 5);
    
    // Get system alerts/notifications
    $notifications = getNotifications();
    
    $response = [
        'success' => true,
        'data' => [
            'stats' => $stats,
            'sales_chart' => $sales_data,
            'recent_activity' => $recent_activity,
            'notifications' => $notifications
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ]);
}

function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' min ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    
    return date('M j', strtotime($datetime));
}
?>