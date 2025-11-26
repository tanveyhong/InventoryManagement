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
                                      u.username as cashier_name,
                                      COUNT(si.id) as items_count,
                                      COALESCE(SUM(CAST(si.subtotal AS NUMERIC)), 0) as total_amount
                                      FROM sales s
                                      LEFT JOIN sale_items si ON s.id = si.sale_id
                                      LEFT JOIN users u ON s.user_id = u.id
                                      WHERE s.store_id = ?
                                      GROUP BY s.id, u.username
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
    $inventory = ['total_products' => 0, 'total_stock' => 0, 'inventory_value' => 0, 'out_of_stock' => 0, 'low_stock' => 0, 'categories' => 0];
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
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --background: #f3f4f6;
            --surface: #ffffff;
            --text-main: #1f2937;
            --text-light: #6b7280;
            --border: #e5e7eb;
        }

        body {
            background-color: var(--background);
            min-height: 100vh;
            margin: 0;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            color: var(--text-main);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: var(--surface);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-content h1 {
            margin: 0 0 8px 0;
            color: var(--text-main);
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header-content .subtitle {
            color: var(--text-light);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .header-content .subtitle span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: white;
            color: var(--text-main);
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            border: 1px solid var(--border);
            transition: all 0.2s;
            margin-bottom: 10px;
        }
        
        .back-btn:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: var(--surface);
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--border);
            transition: transform 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card .icon {
            font-size: 20px;
            color: var(--primary);
            background: #e0e7ff;
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            flex-shrink: 0;
            margin-bottom: 0;
        }
        
        .stat-card .content {
            display: flex;
            flex-direction: column;
        }
        
        .stat-card .value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
            margin-bottom: 0;
        }
        
        .stat-card .label {
            font-size: 11px;
            color: var(--text-light);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card.warning .icon { color: #d97706; background: #fef3c7; }
        .stat-card.danger .icon { color: #dc2626; background: #fee2e2; }
        .stat-card.success .icon { color: #059669; background: #d1fae5; }
        
        .card {
            background: var(--surface);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
            margin-bottom: 20px;
        }

        .card h2 {
            margin: 0 0 20px 0;
            color: var(--text-main);
            font-size: 16px;
            font-weight: 600;
            border-bottom: 1px solid var(--border);
            padding-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card h2 i {
            color: var(--primary);
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 14px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            font-weight: 500;
            color: var(--text-light);
        }

        .info-value {
            color: var(--text-main);
            font-weight: 500;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: white;
            color: var(--text-main);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .btn-edit {
            background: #ffedd5;
            color: #c2410c;
        }

        .btn-edit:hover {
            background: #c2410c;
            color: white;
        }
        
        .products-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .products-table th {
            background: #f9fafb;
            color: var(--text-light);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        
        .products-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: var(--text-main);
            font-size: 14px;
        }
        
        .products-table tr:last-child td {
            border-bottom: none;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-secondary { background: #f3f4f6; color: #6b7280; }
        .badge-warning { background: #fef3c7; color: #92400e; }

        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            color: #d1d5db;
        }
        
        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr !important;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <a href="list.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Stores
                </a>
                <h1>
                    <i class="fas fa-store"></i> <?php echo htmlspecialchars($store['name']); ?>
                    <?php if (!empty($store['has_pos'])): ?>
                        <span class="badge badge-success" style="font-size: 12px; vertical-align: middle;">POS Enabled</span>
                    <?php else: ?>
                        <span class="badge badge-secondary" style="font-size: 12px; vertical-align: middle;">POS Disabled</span>
                    <?php endif; ?>
                </h1>
                <div class="subtitle">
                    <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($store['address'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($store['city'] ?? 'N/A'); ?></span>
                    <?php if (!empty($store['region_name'])): ?>
                        <span><i class="fas fa-map"></i> <?php echo htmlspecialchars($store['region_name']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="header-actions">
                <?php if (currentUserHasPermission('can_edit_stores')): ?>
                <a href="edit.php?id=<?php echo $store_id; ?>" class="btn btn-edit">
                    <i class="fas fa-edit"></i> Edit Store
                </a>
                <?php endif; ?>
                <a href="inventory_viewer.php?id=<?php echo $store_id; ?>" class="btn btn-primary">
                    <i class="fas fa-boxes"></i> View Inventory
                </a>
            </div>
        </div>
            
        <!-- Inventory Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon"><i class="fas fa-box"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($inventory['total_products']); ?></div>
                    <div class="label">Total Products</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="icon"><i class="fas fa-cubes"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($inventory['total_stock']); ?></div>
                    <div class="label">Total Stock</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="content">
                    <div class="value">$<?php echo number_format($inventory['inventory_value'], 2); ?></div>
                    <div class="label">Inventory Value</div>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($inventory['low_stock']); ?></div>
                    <div class="label">Low Stock</div>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="icon"><i class="fas fa-times-circle"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($inventory['out_of_stock']); ?></div>
                    <div class="label">Out of Stock</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon"><i class="fas fa-tags"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($inventory['categories']); ?></div>
                    <div class="label">Categories</div>
                </div>
            </div>
            
            <!-- Sales Statistics (Last 30 Days) -->
            <?php if ($sales_summary['total_transactions'] > 0): ?>
            <div class="stat-card success">
                <div class="icon"><i class="fas fa-shopping-cart"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($sales_summary['total_transactions']); ?></div>
                    <div class="label">Total Sales (30d)</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="content">
                    <div class="value">$<?php echo number_format($sales_summary['total_revenue'], 2); ?></div>
                    <div class="label">Revenue (30d)</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="icon"><i class="fas fa-receipt"></i></div>
                <div class="content">
                    <div class="value">$<?php echo number_format($sales_summary['avg_transaction'], 2); ?></div>
                    <div class="label">Avg Transaction</div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Store Profile Content -->
        <div class="content-grid">
            <!-- Main Content -->
            <div>
                <!-- Recent Products Card -->
                <div class="card">
                    <h2><i class="fas fa-box-open"></i> Recently Added Products</h2>
                    <?php if (!empty($recent_products)): ?>
                    <div class="table-responsive">
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
                <div class="card">
                    <h2><i class="fas fa-shopping-cart"></i> Recent Sales</h2>
                    <div class="table-responsive">
                        <table class="products-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Customer</th>
                                    <th>Cashier</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_sales as $sale): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i', strtotime($sale['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer'); ?></td>
                                    <td><?php echo htmlspecialchars($sale['cashier_name'] ?? 'Unknown'); ?></td>
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
                <!-- Store Information Card -->
                <div class="card">
                    <h2><i class="fas fa-info-circle"></i> Store Information</h2>
                    <div class="info-row">
                        <span class="info-label">Store Code:</span>
                        <span class="info-value"><?php echo htmlspecialchars($store['code'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-value"><?php echo htmlspecialchars($store['phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($store['email'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Manager:</span>
                        <span class="info-value"><?php echo htmlspecialchars($store['manager_name'] ?? $store['contact_person'] ?? 'Not assigned'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">POS Status:</span>
                        <span class="info-value">
                            <?php if (!empty($store['has_pos'])): ?>
                                <span class="badge badge-success">Enabled</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Disabled</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Created:</span>
                        <span class="info-value"><?php echo date('M d, Y', strtotime($store['created_at'])); ?></span>
                    </div>
                </div>

                <!-- Low Stock Alert Card -->
                <?php if (!empty($low_stock_products)): ?>
                <div class="card">
                    <h2><i class="fas fa-exclamation-triangle"></i> Low Stock Alert</h2>
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
                                    <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($product['sku']); ?></small>
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
                <div class="card">
                    <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <a href="inventory_viewer.php?id=<?php echo $store_id; ?>" class="btn btn-primary" style="justify-content: center;">
                            <i class="fas fa-boxes"></i> View Full Inventory
                        </a>
                        <a href="map.php" class="btn btn-secondary" style="justify-content: center;">
                            <i class="fas fa-map"></i> Back to Map
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
