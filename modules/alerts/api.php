<?php
// Real-time Alerts System using Redis Pub/Sub
require_once '../../config.php';
require_once '../../db.php';
require_once '../../redis.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$redis = getRedis();
$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_alerts':
            // Get recent alerts for the user
            $user_id = $_SESSION['user_id'];
            $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 10;
            
            $alerts = $db->fetchAll(
                "SELECT * FROM alerts 
                 WHERE (user_id IS NULL OR user_id = ?) 
                   AND is_active = TRUE 
                 ORDER BY created_at DESC 
                 LIMIT ?", 
                [$user_id, $limit]
            );
            
            // Also get cached real-time alerts from Redis
            $redis_alerts = [];
            if ($redis->isAvailable()) {
                $alert_keys = $redis->getConnection()->keys(REDIS_PREFIX . 'alert:*');
                foreach ($alert_keys as $key) {
                    $alert_data = $redis->get(str_replace(REDIS_PREFIX, '', $key));
                    if ($alert_data) {
                        $redis_alerts[] = json_decode($alert_data, true);
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'alerts' => $alerts,
                'real_time_alerts' => $redis_alerts
            ]);
            break;
            
        case 'mark_read':
            $alert_id = isset($_POST['alert_id']) ? intval($_POST['alert_id']) : 0;
            
            if ($alert_id > 0) {
                $result = $db->query(
                    "UPDATE alerts SET is_read = TRUE, updated_at = NOW() WHERE id = ?", 
                    [$alert_id]
                );
                
                echo json_encode(['success' => $result !== false]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid alert ID']);
            }
            break;
            
        case 'dismiss_alert':
            $alert_id = isset($_POST['alert_id']) ? intval($_POST['alert_id']) : 0;
            
            if ($alert_id > 0) {
                $result = $db->query(
                    "UPDATE alerts SET is_active = FALSE, updated_at = NOW() WHERE id = ?", 
                    [$alert_id]
                );
                
                echo json_encode(['success' => $result !== false]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid alert ID']);
            }
            break;
            
        case 'subscribe':
            // Server-Sent Events for real-time alerts
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            
            if (!$redis->isAvailable()) {
                echo "data: " . json_encode(['error' => 'Real-time alerts unavailable']) . "\n\n";
                flush();
                exit;
            }
            
            // Subscribe to alert channels
            $redis_conn = new Redis();
            $redis_conn->connect(REDIS_HOST, REDIS_PORT);
            if (!empty(REDIS_PASSWORD)) {
                $redis_conn->auth(REDIS_PASSWORD);
            }
            $redis_conn->select(REDIS_DATABASE);
            
            $channels = ['alerts:low_stock', 'alerts:expiry', 'alerts:custom'];
            $redis_conn->subscribe($channels, function($redis, $channel, $message) {
                $alert_data = json_decode($message, true);
                
                // Send to client
                echo "data: " . json_encode([
                    'type' => 'alert',
                    'channel' => $channel,
                    'data' => $alert_data,
                    'timestamp' => time()
                ]) . "\n\n";
                
                flush();
            });
            
            break;
            
        case 'check_stock_levels':
            // Manual trigger for stock level checks
            $products = $db->fetchAll(
                "SELECT id, name, sku, quantity, min_stock_level, store_id 
                 FROM products 
                 WHERE active = TRUE 
                   AND quantity <= min_stock_level"
            );
            
            $alerts_triggered = 0;
            foreach ($products as $product) {
                // Check if we already have a recent alert for this product
                $recent_alert = $db->fetch(
                    "SELECT id FROM alerts 
                     WHERE type = 'low_stock' 
                       AND product_id = ? 
                       AND created_at > NOW() - INTERVAL '1 hour'", 
                    [$product['id']]
                );
                
                if (!$recent_alert) {
                    triggerLowStockAlert(
                        $product['id'], 
                        $product['quantity'], 
                        $product['min_stock_level']
                    );
                    $alerts_triggered++;
                }
            }
            
            echo json_encode([
                'success' => true,
                'message' => "Triggered {$alerts_triggered} low stock alerts"
            ]);
            break;
            
        case 'check_expiry':
            // Manual trigger for expiry checks - DISABLED
            echo json_encode([
                'success' => true,
                'message' => "Triggered 0 expiry alerts (Feature Disabled)"
            ]);
            break;
            break;
            
        case 'get_stats':
            // Get alert statistics
            $stats = [
                'total_active' => $db->fetch("SELECT COUNT(*) as count FROM alerts WHERE is_active = TRUE")['count'] ?? 0,
                'unread' => $db->fetch("SELECT COUNT(*) as count FROM alerts WHERE is_read = FALSE AND is_active = TRUE")['count'] ?? 0,
                'high_priority' => $db->fetch("SELECT COUNT(*) as count FROM alerts WHERE priority = 'high' AND is_active = TRUE")['count'] ?? 0,
                'low_stock' => $db->fetch("SELECT COUNT(*) as count FROM alerts WHERE type = 'low_stock' AND is_active = TRUE")['count'] ?? 0,
                'expiry' => $db->fetch("SELECT COUNT(*) as count FROM alerts WHERE type = 'expiry' AND is_active = TRUE")['count'] ?? 0
            ];
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log("Alerts API Error: " . $e->getMessage(), 3, ERROR_LOG_PATH);
    echo json_encode([
        'success' => false, 
        'message' => DEBUG_MODE ? $e->getMessage() : 'An error occurred'
    ]);
}
?>