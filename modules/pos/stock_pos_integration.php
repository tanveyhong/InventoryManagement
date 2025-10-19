<?php
/**
 * Stock-POS Integration Dashboard
 * Shows real-time synchronization between stock and POS systems
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

$db = getSQLDB();

// Get stores with POS enabled
$posStores = $db->fetchAll("
    SELECT id, name, firebase_id,
    (SELECT COUNT(*) FROM products WHERE store_id = stores.id AND active = 1) as product_count,
    (SELECT SUM(quantity) FROM products WHERE store_id = stores.id AND active = 1) as total_stock,
    (SELECT COUNT(*) FROM sales WHERE store_id = stores.id) as total_sales
    FROM stores 
    WHERE has_pos = 1 AND (active = 1 OR active IS NULL)
    ORDER BY name
");

// Get recent sales from POS
$recentSales = $db->fetchAll("
    SELECT s.*, st.name as store_name,
           (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
    FROM sales s
    LEFT JOIN stores st ON s.store_id = st.id
    ORDER BY s.created_at DESC
    LIMIT 10
");

// Get low stock products across POS stores
$lowStockProducts = $db->fetchAll("
    SELECT p.*, st.name as store_name
    FROM products p
    LEFT JOIN stores st ON p.store_id = st.id
    WHERE st.has_pos = 1 
    AND p.active = 1
    AND p.quantity <= p.reorder_level
    AND p.quantity > 0
    ORDER BY p.quantity ASC
    LIMIT 20
");

// Get out of stock products
$outOfStockProducts = $db->fetchAll("
    SELECT p.*, st.name as store_name
    FROM products p
    LEFT JOIN stores st ON p.store_id = st.id
    WHERE st.has_pos = 1 
    AND p.active = 1
    AND p.quantity = 0
    ORDER BY p.name
    LIMIT 20
");

// Debug: Log data counts for troubleshooting
if (isset($_GET['debug'])) {
    echo "<pre style='background: #f0f0f0; padding: 20px; margin: 20px;'>";
    echo "POS Stores: " . count($posStores) . "\n";
    echo "Recent Sales: " . count($recentSales) . "\n";
    echo "Low Stock: " . count($lowStockProducts) . "\n";
    echo "Out of Stock: " . count($outOfStockProducts) . "\n\n";
    echo "First Store Data:\n";
    print_r($posStores[0] ?? 'No stores');
    echo "</pre>";
}

$page_title = 'Stock-POS Integration - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Main content spacing after navigation */
        .main-content {
            margin-top: 80px;
            padding: 20px 0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .integration-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .integration-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #4f46e5;
        }
        
        .integration-card.warning {
            border-left-color: #f59e0b;
        }
        
        .integration-card.danger {
            border-left-color: #ef4444;
        }
        
        .integration-card.success {
            border-left-color: #10b981;
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .card-icon.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .card-icon.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
        .card-icon.danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
        .card-icon.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .sync-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .sync-status.active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .sync-status.syncing {
            background: #fef3c7;
            color: #92400e;
        }
        
        .product-mini-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .product-mini-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .product-mini-item:last-child {
            border-bottom: none;
        }
        
        .product-mini-item:hover {
            background: #f9fafb;
        }
        
        .product-name {
            font-weight: 500;
            color: #1f2937;
            margin-bottom: 4px;
        }
        
        .product-store {
            font-size: 12px;
            color: #6b7280;
        }
        
        .quantity-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .quantity-badge.low {
            background: #fef3c7;
            color: #92400e;
        }
        
        .quantity-badge.out {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .quick-actions .btn {
            flex: 1;
            text-align: center;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <div class="page-header">
            <div class="header-left">
                <div class="header-icon"><i class="fas fa-sync-alt"></i></div>
                <div class="header-text">
                    <h1>Stock-POS Integration</h1>
                    <p>Real-time synchronization between inventory and point of sale</p>
                </div>
            </div>
            <div class="header-actions">
                <span class="sync-status active">
                    <i class="fas fa-check-circle"></i>
                    Synced
                </span>
            </div>
        </div>

        <!-- POS Stores Overview -->
        <div class="integration-grid">
            <?php foreach ($posStores as $store): ?>
            <div class="integration-card success">
                <div class="card-header">
                    <div class="card-icon success">
                        <i class="fas fa-store"></i>
                    </div>
                    <div>
                        <div class="card-title"><?php echo htmlspecialchars($store['name']); ?></div>
                        <small style="color: #6b7280;">POS Enabled</small>
                    </div>
                </div>
                <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); gap: 10px;">
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #4f46e5;">
                            <?php echo number_format((int)($store['product_count'] ?? 0)); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">Products</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #10b981;">
                            <?php echo number_format((int)($store['total_stock'] ?? 0)); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">Stock</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #f59e0b;">
                            <?php echo number_format((int)($store['total_sales'] ?? 0)); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">Sales</div>
                    </div>
                </div>
                <div class="quick-actions">
                    <a href="../pos/full_retail.php?store_firebase_id=<?php echo htmlspecialchars($store['firebase_id'] ?? $store['id']); ?>" class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <i class="fas fa-cash-register"></i> Open POS
                    </a>
                    <a href="../stock/list.php?store=<?php echo htmlspecialchars($store['id']); ?>" class="btn btn-sm btn-secondary">
                        <i class="fas fa-boxes"></i> View Stock
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Alerts Grid -->
        <div class="integration-grid">
            <!-- Low Stock Alert -->
            <div class="integration-card warning">
                <div class="card-header">
                    <div class="card-icon warning">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <div class="card-title">Low Stock Alert</div>
                        <small style="color: #6b7280;"><?php echo count($lowStockProducts); ?> items need reordering</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($lowStockProducts)): ?>
                        <div style="text-align: center; padding: 20px; color: #6b7280;">
                            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 10px;"></i>
                            <p>All products are well stocked!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lowStockProducts as $product): ?>
                        <div class="product-mini-item">
                            <div>
                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="product-store"><?php echo htmlspecialchars($product['store_name']); ?></div>
                            </div>
                            <span class="quantity-badge low"><?php echo $product['quantity']; ?> left</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Out of Stock Alert -->
            <div class="integration-card danger">
                <div class="card-header">
                    <div class="card-icon danger">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div>
                        <div class="card-title">Out of Stock</div>
                        <small style="color: #6b7280;"><?php echo count($outOfStockProducts); ?> items unavailable</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($outOfStockProducts)): ?>
                        <div style="text-align: center; padding: 20px; color: #6b7280;">
                            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 10px;"></i>
                            <p>No out of stock items!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($outOfStockProducts as $product): ?>
                        <div class="product-mini-item">
                            <div>
                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="product-store"><?php echo htmlspecialchars($product['store_name']); ?></div>
                            </div>
                            <span class="quantity-badge out">0 stock</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Sales -->
            <div class="integration-card primary">
                <div class="card-header">
                    <div class="card-icon primary">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div>
                        <div class="card-title">Recent POS Sales</div>
                        <small style="color: #6b7280;">Last <?php echo count($recentSales); ?> transactions</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($recentSales)): ?>
                        <div style="text-align: center; padding: 20px; color: #6b7280;">
                            <i class="fas fa-info-circle" style="font-size: 48px; color: #6b7280; margin-bottom: 10px;"></i>
                            <p>No sales recorded yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentSales as $sale): ?>
                        <div class="product-mini-item">
                            <div>
                                <div class="product-name">#<?php echo htmlspecialchars($sale['sale_number'] ?? $sale['id']); ?></div>
                                <div class="product-store">
                                    <?php echo htmlspecialchars($sale['store_name'] ?? 'Unknown'); ?> • 
                                    <?php echo $sale['item_count']; ?> items
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight: 600; color: #10b981;">
                                    $<?php echo number_format($sale['total_amount'], 2); ?>
                                </div>
                                <div style="font-size: 11px; color: #6b7280;">
                                    <?php echo date('M j, g:i A', strtotime($sale['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 15px; margin-top: 30px;">
            <a href="../stock/list.php" class="btn btn-primary" style="flex: 1;">
                <i class="fas fa-boxes"></i> View All Stock
            </a>
            <a href="../stores/list.php" class="btn btn-secondary" style="flex: 1;">
                <i class="fas fa-store"></i> Manage Stores
            </a>
            <a href="distribute_products.php" class="btn btn-secondary" style="flex: 1;">
                <i class="fas fa-random"></i> Distribute Products
            </a>
        </div>
        </div>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
