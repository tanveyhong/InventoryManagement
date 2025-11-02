<?php
// API endpoint for store inventory data
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

// Check permission to view inventory/stores
if (!currentUserHasPermission('can_view_inventory') && !currentUserHasPermission('can_view_stores')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit;
}

try {
    $sql_db = getSQLDB();
    $store_id = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
    
    if (!$store_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Store ID required']);
        exit;
    }
    
    // Get filter parameters
    $category = isset($_GET['category']) ? sanitizeInput($_GET['category']) : '';
    $status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
    $search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = min(100, max(10, intval($_GET['per_page'] ?? 25)));
    
    // Build WHERE conditions
    $where_conditions = ['p.store_id = ?', 'p.active = 1'];
    $params = [$store_id];
    
    if (!empty($category)) {
        $where_conditions[] = 'p.category = ?';
        $params[] = $category;
    }
    
    if (!empty($status)) {
        if ($status === 'low_stock') {
            $where_conditions[] = 'p.quantity <= p.reorder_level AND p.quantity > 0';
        } elseif ($status === 'out_of_stock') {
            $where_conditions[] = 'p.quantity = 0';
        } elseif ($status === 'expired') {
            $where_conditions[] = 'p.expiry_date < CURRENT_DATE';
        } elseif ($status === 'expiring_soon') {
            $where_conditions[] = 'p.expiry_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)';
        }
    }
    
    if (!empty($search)) {
        $where_conditions[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)';
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM products p {$where_clause}";
    $total_result = $sql_db->fetch($count_query, $params);
    $total_products = $total_result['total'] ?? 0;
    
    // Calculate pagination
    $pagination = paginate($page, $per_page, $total_products);
    
    // Get products with status
    $query = "SELECT p.*, 
                     CASE 
                         WHEN p.quantity = 0 THEN 'out_of_stock'
                         WHEN p.quantity <= p.reorder_level THEN 'low_stock'
                         WHEN p.expiry_date < CURRENT_DATE THEN 'expired'
                         WHEN p.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN 'expiring_soon'
                         ELSE 'in_stock'
                     END as stock_status,
                     DATEDIFF(p.expiry_date, CURRENT_DATE) as days_to_expiry
              FROM products p 
              {$where_clause}
              ORDER BY p.name
              LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
    
    $products = $sql_db->fetchAll($query, $params);
    
    // Enhance product data
    foreach ($products as &$product) {
        $product['formatted_price'] = '$' . number_format($product['price'], 2);
        $product['total_value'] = $product['quantity'] * $product['price'];
        $product['formatted_total_value'] = '$' . number_format($product['total_value'], 2);
        
        // Status badge
        $product['status_badge'] = [
            'text' => ucfirst(str_replace('_', ' ', $product['stock_status'])),
            'class' => match($product['stock_status']) {
                'in_stock' => 'badge-success',
                'low_stock' => 'badge-warning',
                'out_of_stock' => 'badge-danger',
                'expired' => 'badge-danger',
                'expiring_soon' => 'badge-warning',
                default => 'badge-secondary'
            }
        ];
        
        // Expiry status
        if ($product['expiry_date']) {
            if ($product['days_to_expiry'] < 0) {
                $product['expiry_status'] = 'expired';
                $product['expiry_text'] = 'Expired ' . abs($product['days_to_expiry']) . ' days ago';
            } elseif ($product['days_to_expiry'] <= 30) {
                $product['expiry_status'] = 'expiring_soon';
                $product['expiry_text'] = 'Expires in ' . $product['days_to_expiry'] . ' days';
            } else {
                $product['expiry_status'] = 'good';
                $product['expiry_text'] = 'Expires ' . formatDate($product['expiry_date'], 'M j, Y');
            }
        } else {
            $product['expiry_status'] = 'no_expiry';
            $product['expiry_text'] = 'No expiry date';
        }
    }
    
    // Get summary statistics
    $summary_query = "SELECT 
                        COUNT(*) as total_products,
                        SUM(quantity) as total_quantity,
                        SUM(quantity * price) as total_value,
                        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                        SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) as low_stock_count,
                        SUM(CASE WHEN expiry_date < CURRENT_DATE THEN 1 ELSE 0 END) as expired_count
                      FROM products 
                      WHERE store_id = ? AND active = 1";
    
    $summary = $sql_db->fetch($summary_query, [$store_id]);
    $summary = $summary ?: ['total_products' => 0, 'total_quantity' => 0, 'total_value' => 0, 'out_of_stock_count' => 0, 'low_stock_count' => 0, 'expired_count' => 0];
    
    echo json_encode([
        'success' => true,
        'data' => $products,
        'pagination' => $pagination,
        'summary' => $summary,
        'filters' => [
            'category' => $category,
            'status' => $status,
            'search' => $search
        ],
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    error_log("Store inventory API error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve inventory data',
        'message' => DEBUG_MODE ? $e->getMessage() : 'Internal server error'
    ]);
}
?>