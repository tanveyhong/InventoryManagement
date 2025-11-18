<?php
// Store Inventory Viewer - PostgreSQL Optimized
ob_start('ob_gzhandler');

require_once '../../config.php';
require_once '../../db.php';
require_once '../../sql_db.php';
require_once '../../functions.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();

// Get store ID
$store_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$store_id) {
    $_SESSION['error'] = 'Store ID is required';
    header('Location: list.php');
    exit;
}

// Get store info
try {
    $store = $sqlDb->fetch("SELECT s.*, r.name as region_name 
                            FROM stores s 
                            LEFT JOIN regions r ON s.region_id = r.id 
                            WHERE s.id = ? AND s.active = TRUE", [$store_id]);
    
    if (!$store) {
        $_SESSION['error'] = 'Store not found';
        header('Location: list.php');
        exit;
    }
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Get profile data
try {
    // Get inventory summary
    $inventory = $sqlDb->fetch("SELECT 
                                    COUNT(*) as total_products,
                                    COALESCE(SUM(quantity), 0) as total_stock,
                                    COALESCE(SUM(quantity * CAST(price AS NUMERIC)), 0) as inventory_value,
                                    COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock,
                                    COUNT(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 END) as low_stock,
                                    COUNT(CASE WHEN expiry_date < CURRENT_DATE THEN 1 END) as expired,
                                    COUNT(CASE WHEN expiry_date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '30 days') THEN 1 END) as expiring_soon,
                                    COUNT(DISTINCT category) as categories
                                FROM products 
                                WHERE store_id = ? AND active = TRUE", [$store_id]);
    
    // Get recent products (last 10 added)
    $recent_products = $sqlDb->fetchAll("SELECT id, name, sku, quantity, CAST(price AS NUMERIC) as price, category, created_at
                                         FROM products 
                                         WHERE store_id = ? AND active = TRUE 
                                         ORDER BY created_at DESC 
                                         LIMIT 10", [$store_id]);
    
    // Get low stock products
    $low_stock_products = $sqlDb->fetchAll("SELECT id, name, sku, quantity, reorder_level
                                            FROM products 
                                            WHERE store_id = ? AND active = TRUE 
                                            AND quantity <= reorder_level AND quantity > 0
                                            ORDER BY quantity ASC 
                                            LIMIT 10", [$store_id]);
    
    // Get recent sales (if sales table has data)
    $recent_sales = $sqlDb->fetchAll("SELECT s.*, 
                                      COUNT(si.id) as items_count,
                                      COALESCE(SUM(CAST(si.subtotal AS NUMERIC)), 0) as total_amount
                                      FROM sales s
                                      LEFT JOIN sale_items si ON s.id = si.sale_id
                                      WHERE s.store_id = ?
                                      GROUP BY s.id
                                      ORDER BY s.created_at DESC
                                      LIMIT 10", [$store_id]);
    
    // Get sales summary (last 30 days)
    $sales_summary = $sqlDb->fetch("SELECT 
                                    COUNT(DISTINCT s.id) as total_transactions,
                                    COALESCE(SUM(CAST(si.subtotal AS NUMERIC)), 0) as total_revenue,
                                    COALESCE(AVG(CAST(si.subtotal AS NUMERIC)), 0) as avg_transaction
                                    FROM sales s
                                    LEFT JOIN sale_items si ON s.id = si.sale_id
                                    WHERE s.store_id = ? 
                                    AND s.created_at >= CURRENT_DATE - INTERVAL '30 days'", [$store_id]);
    
} catch (Exception $e) {
    error_log('Error fetching store profile data: ' . $e->getMessage());
    $inventory = ['total_products' => 0, 'total_stock' => 0, 'inventory_value' => 0, 'out_of_stock' => 0, 'low_stock' => 0, 'expired' => 0, 'expiring_soon' => 0, 'categories' => 0];
    $recent_products = [];
    $low_stock_products = [];
    $recent_sales = [];
    $sales_summary = ['total_transactions' => 0, 'total_revenue' => 0, 'avg_transaction' => 0];
}

$page_title = "Store Profile - {$store['name']} - Inventory System";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            margin: 0 0 5px 0;
            color: #667eea;
            font-size: 28px;
        }
        
        .header .subtitle {
            color: #666;
            font-size: 14px;
            margin-bottom: 20px;
        }
        
        .back-btn {
            display: inline-block;
            padding: 8px 15px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .stat-card .icon {
            font-size: 36px;
            margin-bottom: 10px;
            color: #667eea;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #333;
            margin-bottom: 5px;
        }
        
        .stat-card .label {
            font-size: 14px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card.warning .icon { color: #f39c12; }
        .stat-card.danger .icon { color: #e74c3c; }
        .stat-card.success .icon { color: #27ae60; }
        
        .filters {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-size: 13px;
            color: #666;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select {
            padding: 10px 12px;
            border: 2px solid #e1e4e8;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .products-table-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow-x: auto;
        }
        
        .products-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .products-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .products-table th,
        .products-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e1e4e8;
        }
        
        .products-table th {
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .products-table tbody tr {
            transition: background 0.2s;
        }
        
        .products-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.in-stock {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.low-stock {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-badge.out-of-stock {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-badge.expired {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-badge.expiring-soon {
            background: #fff3cd;
            color: #856404;
        }
        
        .pagination {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .pagination-info {
            color: #6b7280;
            font-size: 14px;
        }
        
        .pagination-links {
            display: flex;
            gap: 8px;
        }
        
        .pagination-links a,
        .pagination-links span {
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        
        .pagination-links a {
            background: #f3f4f6;
            color: #667eea;
        }
        
        .pagination-links a:hover {
            background: #667eea;
            color: white;
        }
        
        .pagination-links .current {
            background: #667eea;
            color: white;
        }
        
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .no-products {
            background: white;
            border-radius: 12px;
            padding: 60px 20px;
            text-align: center;
            color: #6b7280;
        }
        
        .no-products i {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <a href="list.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Stores
            </a>
            <h1><i class="fas fa-store"></i> <?php echo htmlspecialchars($store['name']); ?></h1>
            <div class="subtitle">
                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($store['address'] ?? 'N/A'); ?>, 
                <?php echo htmlspecialchars($store['city'] ?? 'N/A'); ?>
                <?php if (!empty($store['region_name'])): ?>
                    · <i class="fas fa-map"></i> <?php echo htmlspecialchars($store['region_name']); ?>
                <?php endif; ?>
            </div>
            
            <!-- Inventory Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="icon"><i class="fas fa-box"></i></div>
                    <div class="value"><?php echo number_format($inventory['total_products']); ?></div>
                    <div class="label">Total Products</div>
                </div>
                <div class="stat-card success">
                    <div class="icon"><i class="fas fa-cubes"></i></div>
                    <div class="value"><?php echo number_format($inventory['total_stock']); ?></div>
                    <div class="label">Total Stock</div>
                </div>
                <div class="stat-card success">
                    <div class="icon"><i class="fas fa-dollar-sign"></i></div>
                    <div class="value">$<?php echo number_format($inventory['inventory_value'], 2); ?></div>
                    <div class="label">Inventory Value</div>
                </div>
                <div class="stat-card warning">
                    <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="value"><?php echo number_format($inventory['low_stock']); ?></div>
                    <div class="label">Low Stock</div>
                </div>
                <div class="stat-card danger">
                    <div class="icon"><i class="fas fa-times-circle"></i></div>
                    <div class="value"><?php echo number_format($inventory['out_of_stock']); ?></div>
                    <div class="label">Out of Stock</div>
                </div>
                <div class="stat-card">
                    <div class="icon"><i class="fas fa-tags"></i></div>
                    <div class="value"><?php echo number_format($inventory['categories']); ?></div>
                    <div class="label">Categories</div>
                </div>
            </div>
            
            <!-- Sales Statistics (Last 30 Days) -->
            <?php if ($sales_summary['total_transactions'] > 0): ?>
            <div class="stats-grid" style="margin-top: 20px;">
                <div class="stat-card success">
                    <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                    <div class="value"><?php echo number_format($sales_summary['total_transactions']); ?></div>
                    <div class="label">Total Sales (30d)</div>
                </div>
                <div class="stat-card success">
                    <div class="icon"><i class="fas fa-dollar-sign"></i></div>
                    <div class="value">$<?php echo number_format($sales_summary['total_revenue'], 2); ?></div>
                    <div class="label">Revenue (30d)</div>
                </div>
                <div class="stat-card">
                    <div class="icon"><i class="fas fa-receipt"></i></div>
                    <div class="value">$<?php echo number_format($sales_summary['avg_transaction'], 2); ?></div>
                    <div class="label">Avg Transaction</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Store Profile Content -->
        <div class="content-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
            <!-- Main Content -->
            <div>
                <!-- Store Information Card -->
                <div class="card" style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                    <h2 style="margin: 0 0 20px 0; color: #333; font-size: 18px; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-info-circle"></i> Store Information</h2>
                    <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="font-weight: 600; color: #666;">Store Code:</span>
                        <span style="color: #333;"><?php echo htmlspecialchars($store['code'] ?? 'N/A'); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="font-weight: 600; color: #666;">Phone:</span>
                        <span style="color: #333;"><?php echo htmlspecialchars($store['phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="font-weight: 600; color: #666;">Email:</span>
                        <span style="color: #333;"><?php echo htmlspecialchars($store['email'] ?? 'N/A'); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f0f0f0;">
                        <span style="font-weight: 600; color: #666;">Manager:</span>
                        <span style="color: #333;"><?php echo htmlspecialchars($store['contact_person'] ?? 'Not assigned'); ?></span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 10px 0;">
                        <span style="font-weight: 600; color: #666;">Created:</span>
                        <span style="color: #333;"><?php echo date('M d, Y', strtotime($store['created_at'])); ?></span>
                    </div>
                </div>
                
                <!-- Recent Products Card -->
                <div class="card" style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                    <h2 style="margin: 0 0 20px 0; color: #333; font-size: 18px; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-box-open"></i> Recently Added Products</h2>
                    <?php if (!empty($recent_products)): ?>
                    <div style="overflow-x: auto;">
                        <table class="products-table">
                            <thead>
                                <tr>
                                    <th>Product Name</th>
                                    <th>SKU</th>
                                    <th>Category</th>
                                    <th>Quantity</th>
                                    <th>Price</th>
                                    <th>Added</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_products as $product): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product['name']); ?></td>
                                    <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                    <td><?php echo htmlspecialchars($product['category'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($product['quantity']); ?></td>
                                    <td>$<?php echo number_format($product['price'], 2); ?></td>
                                    <td><?php echo date('M d', strtotime($product['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <p>No products added yet</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Sales Card -->
                <?php if (!empty($recent_sales)): ?>
                <div class="card" style="background: white; border-radius: 12px; padding: 20px; margin-top: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                    <h2 style="margin: 0 0 20px 0; color: #333; font-size: 18px; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-shopping-cart"></i> Recent Sales</h2>
                    <div style="overflow-x: auto;">
                        <table class="products-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_sales as $sale): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i', strtotime($sale['created_at'])); ?></td>
                                    <td><?php echo $sale['items_count']; ?> items</td>
                                    <td>$<?php echo number_format($sale['total_amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($sale['payment_method'] ?? 'N/A'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Sidebar -->
            <div>
                <!-- Low Stock Alert Card -->
                <?php if (!empty($low_stock_products)): ?>
                <div class="card" style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                    <h2 style="margin: 0 0 20px 0; color: #333; font-size: 18px; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-exclamation-triangle"></i> Low Stock Alert</h2>
                    <table class="products-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Min</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock_products as $product): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($product['name']); ?>
                                    <br><small style="color: #999;"><?php echo htmlspecialchars($product['sku']); ?></small>
                                </td>
                                <td><span class="badge badge-warning"><?php echo $product['quantity']; ?></span></td>
                                <td><?php echo $product['reorder_level']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Quick Actions Card -->
                <div class="card" style="background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08);">
                    <h2 style="margin: 0 0 20px 0; color: #333; font-size: 18px; border-bottom: 2px solid #667eea; padding-bottom: 10px;"><i class="fas fa-bolt"></i> Quick Actions</h2>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="inventory_viewer.php?id=<?php echo $store_id; ?>" style="width: 100%; text-align: center; background: #667eea; color: white; padding: 10px; border-radius: 6px; text-decoration: none;">
                            <i class="fas fa-boxes"></i> View Full Inventory
                        </a>
                        <a href="map.php" style="width: 100%; text-align: center; background: #6c757d; color: white; padding: 10px; border-radius: 6px; text-decoration: none;">
                            <i class="fas fa-map"></i> Back to Map
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
