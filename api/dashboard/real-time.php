<?php
// Dashboard API - Real-time data for dashboard widgets
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

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
    
    // Get dashboard statistics with caching
    $stats = [
        'total_products' => getTotalProducts(),
        'low_stock' => getLowStockCount(),
        'total_stores' => getTotalStores(),
        'todays_sales' => getTodaysSales(),
        'last_updated' => date('Y-m-d H:i:s')
    ];
    
    // Get sales data for chart (last 7 days)
    $sales_data = [];
    $sales = $db->readAll('sales') ?? [];
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-{$i} days"));
        $daily_total = 0;
        
        foreach ($sales as $sale) {
            $sale_date = date('Y-m-d', strtotime($sale['created_at'] ?? $sale['date'] ?? ''));
            if ($sale_date === $date) {
                $daily_total += floatval($sale['total'] ?? 0);
            }
        }
        
        $sales_data[] = [
            'date' => $date,
            'day' => date('D', strtotime($date)),
            'sales' => $daily_total
        ];
    }
    
    // Get recent activity
    $recent_activity = [];
    
    // Recent stock additions (last 24 hours) - Limit to prevent excessive reads
    $products = $db->readAll('products', [], null, 200) ?? [];
    $yesterday = strtotime('-24 hours');
    
    $recent_products = [];
    foreach ($products as $id => $product) {
        $created = strtotime($product['created_at'] ?? '');
        if ($created >= $yesterday) {
            $recent_products[] = [
                'name' => $product['name'] ?? 'Unknown',
                'created_at' => $product['created_at'] ?? '',
                'timestamp' => $created
            ];
        }
    }
    
    // Sort by timestamp
    usort($recent_products, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Add to activity
    foreach (array_slice($recent_products, 0, 3) as $item) {
        $recent_activity[] = [
            'type' => 'stock_added',
            'message' => "New product added: " . $item['name'],
            'time' => time_ago($item['created_at']),
            'icon' => 'plus',
            'color' => 'info'
        ];
    }
    
    // Low stock alerts
    $low_stock_items = [];
    foreach ($products as $id => $product) {
        $quantity = intval($product['quantity'] ?? 0);
        $reorder = intval($product['reorder_level'] ?? 0);
        $active = $product['active'] ?? true;
        
        if ($active && $quantity <= $reorder && $reorder > 0) {
            $low_stock_items[] = [
                'name' => $product['name'] ?? 'Unknown',
                'quantity' => $quantity,
                'reorder_level' => $reorder
            ];
        }
    }
    
    // Add low stock alerts to activity
    foreach (array_slice($low_stock_items, 0, 3) as $item) {
        $recent_activity[] = [
            'type' => 'low_stock',
            'message' => "Low stock: " . $item['name'] . " (" . $item['quantity'] . " remaining)",
            'time' => 'Now',
            'icon' => 'exclamation-triangle',
            'color' => 'alert'
        ];
    }
    
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
    if (empty($datetime)) return 'recently';
    
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' min ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    
    return date('M j', strtotime($datetime));
}
?>