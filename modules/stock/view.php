<?php
// View Product Details
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$product_id = intval($_GET['id'] ?? 0);

if (!$product_id) {
    header('Location: list.php');
    exit;
}

$db = getDB();

// Get product details with related data
$sql = "
    SELECT 
        p.*,
        c.name as category_name,
        s.name as store_name,
        s.address as store_address
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stores s ON p.store_id = s.id
    WHERE p.id = ?
";

$product = $db->fetch($sql, [$product_id]);

if (!$product) {
    header('Location: list.php?error=' . urlencode('Product not found'));
    exit;
}

// Get recent stock movements
$movements = $db->fetchAll("
    SELECT 
        sm.*,
        u.username as user_name
    FROM stock_movements sm
    LEFT JOIN users u ON sm.user_id = u.id
    WHERE sm.product_id = ?
    ORDER BY sm.created_at DESC
    LIMIT 10
", [$product_id]);

// Calculate stock status
$stock_status = 'normal';
if ($product['quantity'] == 0) $stock_status = 'out_of_stock';
elseif ($product['quantity'] <= $product['min_stock_level']) $stock_status = 'low_stock';

// Check expiry status
$expiry_status = 'normal';
if ($product['expiry_date']) {
    $expiry_timestamp = strtotime($product['expiry_date']);
    $now = time();
    $days_to_expiry = ($expiry_timestamp - $now) / (24 * 60 * 60);
    
    if ($days_to_expiry < 0) $expiry_status = 'expired';
    elseif ($days_to_expiry <= 7) $expiry_status = 'expiring_soon';
    elseif ($days_to_expiry <= 30) $expiry_status = 'expiring_warning';
}

$page_title = htmlspecialchars($product['name']) . ' - Product Details';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">
        <header>
            <h1>Product Details</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="list.php">Stock</a></li>
                    <li><a href="../stores/list.php">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="../alerts/low_stock.php">Alerts</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="page-header">
                <h2><?php echo htmlspecialchars($product['name']); ?></h2>
                <div class="page-actions">
                    <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-primary">Edit Product</a>
                    <a href="adjust.php?id=<?php echo $product['id']; ?>" class="btn btn-warning">Adjust Stock</a>
                    <a href="list.php" class="btn btn-secondary">← Back to List</a>
                </div>
            </div>

            <div class="product-details">
                <!-- Product Overview Card -->
                <div class="detail-card overview-card">
                    <h3>Product Overview</h3>
                    <div class="product-info-grid">
                        <div class="info-item">
                            <label>Product Name:</label>
                            <span><?php echo htmlspecialchars($product['name']); ?></span>
                        </div>
                        
                        <?php if ($product['sku']): ?>
                        <div class="info-item">
                            <label>SKU:</label>
                            <span class="sku"><?php echo htmlspecialchars($product['sku']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($product['description']): ?>
                        <div class="info-item full-width">
                            <label>Description:</label>
                            <span><?php echo htmlspecialchars($product['description']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="info-item">
                            <label>Category:</label>
                            <span><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></span>
                        </div>
                        
                        <div class="info-item">
                            <label>Store:</label>
                            <span><?php echo htmlspecialchars($product['store_name'] ?? 'No Store'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Stock Information Card -->
                <div class="detail-card stock-card">
                    <h3>Stock Information</h3>
                    <div class="stock-metrics">
                        <div class="metric-item <?php echo $stock_status; ?>">
                            <div class="metric-value"><?php echo number_format($product['quantity']); ?></div>
                            <div class="metric-label">Current Stock</div>
                        </div>
                        
                        <div class="metric-item">
                            <div class="metric-value"><?php echo number_format($product['min_stock_level']); ?></div>
                            <div class="metric-label">Minimum Level</div>
                        </div>
                        
                        <div class="metric-item">
                            <div class="metric-value">$<?php echo number_format($product['unit_price'], 2); ?></div>
                            <div class="metric-label">Unit Price</div>
                        </div>
                        
                        <div class="metric-item">
                            <div class="metric-value">$<?php echo number_format($product['quantity'] * $product['unit_price'], 2); ?></div>
                            <div class="metric-label">Total Value</div>
                        </div>
                    </div>
                    
                    <div class="status-indicators">
                        <div class="status-item">
                            <span class="status-label">Stock Status:</span>
                            <span class="status-badge <?php echo $stock_status; ?>">
                                <?php 
                                switch($stock_status) {
                                    case 'out_of_stock': echo 'Out of Stock'; break;
                                    case 'low_stock': echo 'Low Stock'; break;
                                    default: echo 'Normal'; break;
                                }
                                ?>
                            </span>
                        </div>
                        
                        <?php if ($product['expiry_date']): ?>
                        <div class="status-item">
                            <span class="status-label">Expiry Status:</span>
                            <span class="status-badge <?php echo $expiry_status; ?>">
                                <?php 
                                switch($expiry_status) {
                                    case 'expired': echo 'Expired'; break;
                                    case 'expiring_soon': echo 'Expiring Soon'; break;
                                    case 'expiring_warning': echo 'Expires This Month'; break;
                                    default: echo 'Good'; break;
                                }
                                ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Expiry Information Card (if applicable) -->
                <?php if ($product['expiry_date']): ?>
                <div class="detail-card expiry-card <?php echo $expiry_status; ?>">
                    <h3>Expiry Information</h3>
                    <div class="expiry-info">
                        <div class="expiry-date">
                            <label>Expiry Date:</label>
                            <span><?php echo date('F j, Y', strtotime($product['expiry_date'])); ?></span>
                        </div>
                        <div class="days-remaining">
                            <label>Days Remaining:</label>
                            <span class="<?php echo $expiry_status; ?>">
                                <?php 
                                if ($expiry_status === 'expired') {
                                    echo abs(floor($days_to_expiry)) . ' days ago';
                                } else {
                                    echo max(0, floor($days_to_expiry)) . ' days';
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Stock Movements -->
                <div class="detail-card movements-card">
                    <h3>Recent Stock Movements</h3>
                    <?php if (empty($movements)): ?>
                        <p class="no-data">No stock movements recorded yet.</p>
                    <?php else: ?>
                        <div class="movements-list">
                            <?php foreach ($movements as $movement): ?>
                                <div class="movement-item <?php echo $movement['movement_type']; ?>">
                                    <div class="movement-info">
                                        <div class="movement-type">
                                            <?php 
                                            switch($movement['movement_type']) {
                                                case 'in': echo '📈 Stock In'; break;
                                                case 'out': echo '📉 Stock Out'; break;
                                                case 'adjustment': echo '⚖️ Adjustment'; break;
                                                case 'transfer': echo '🔄 Transfer'; break;
                                                default: echo ucfirst($movement['movement_type']); break;
                                            }
                                            ?>
                                        </div>
                                        <div class="movement-details">
                                            <span class="quantity <?php echo $movement['quantity'] > 0 ? 'positive' : 'negative'; ?>">
                                                <?php echo ($movement['quantity'] > 0 ? '+' : '') . number_format($movement['quantity']); ?>
                                            </span>
                                            <?php if ($movement['reference']): ?>
                                                <span class="reference">Ref: <?php echo htmlspecialchars($movement['reference']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="movement-meta">
                                        <div class="movement-date"><?php echo formatDate($movement['created_at'], 'M j, Y g:i A'); ?></div>
                                        <?php if ($movement['user_name']): ?>
                                            <div class="movement-user">by <?php echo htmlspecialchars($movement['user_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="movements-footer">
                            <a href="movements.php?product=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline">View All Movements</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Product Metadata -->
                <div class="detail-card metadata-card">
                    <h3>Product Metadata</h3>
                    <div class="metadata-grid">
                        <div class="metadata-item">
                            <label>Created:</label>
                            <span><?php echo formatDate($product['created_at'], 'M j, Y g:i A'); ?></span>
                        </div>
                        <div class="metadata-item">
                            <label>Last Updated:</label>
                            <span><?php echo formatDate($product['updated_at'] ?? $product['created_at'], 'M j, Y g:i A'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>

    <style>
        .product-details {
            display: grid;
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .detail-card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .detail-card h3 {
            margin-bottom: 1.5rem;
            color: #333;
            font-size: 1.3rem;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 0.5rem;
        }
        
        .product-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .info-item.full-width {
            grid-column: 1 / -1;
        }
        
        .info-item label {
            font-weight: 600;
            color: #666;
        }
        
        .info-item span {
            color: #333;
        }
        
        .sku {
            font-family: monospace;
            background: #f5f5f5;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-weight: bold;
        }
        
        .stock-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .metric-item {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
            background: #f8f9fa;
        }
        
        .metric-item.out_of_stock {
            background: rgba(220, 53, 69, 0.1);
        }
        
        .metric-item.low_stock {
            background: rgba(255, 193, 7, 0.1);
        }
        
        .metric-value {
            font-size: 2rem;
            font-weight: bold;
            color: #333;
        }
        
        .metric-label {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
        }
        
        .status-indicators {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }
        
        .status-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-badge.normal {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.low_stock {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-badge.out_of_stock {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-badge.expired {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-badge.expiring_soon {
            background: #ffeaa7;
            color: #d63031;
        }
        
        .expiry-card.expired {
            border-left: 4px solid #dc3545;
        }
        
        .expiry-card.expiring_soon {
            border-left: 4px solid #ffc107;
        }
        
        .expiry-info {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }
        
        .movements-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .movement-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-radius: 6px;
            background: #f8f9fa;
            border-left: 4px solid #e0e0e0;
        }
        
        .movement-item.in {
            border-left-color: #28a745;
            background: rgba(40, 167, 69, 0.1);
        }
        
        .movement-item.out {
            border-left-color: #dc3545;
            background: rgba(220, 53, 69, 0.1);
        }
        
        .movement-item.adjustment {
            border-left-color: #ffc107;
            background: rgba(255, 193, 7, 0.1);
        }
        
        .quantity.positive {
            color: #28a745;
            font-weight: bold;
        }
        
        .quantity.negative {
            color: #dc3545;
            font-weight: bold;
        }
        
        .movement-meta {
            text-align: right;
            font-size: 0.875rem;
            color: #666;
        }
        
        .movements-footer {
            margin-top: 1rem;
            text-align: center;
        }
        
        .metadata-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .metadata-item {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .stock-metrics {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .status-indicators {
                flex-direction: column;
                gap: 1rem;
            }
            
            .movement-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
        }
    </style>
</body>
</html>