<?php
// Enhanced Store Profile Manager with Advanced Analytics
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$sql_db = getSQLDB();
$store_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$store_id) {
    addNotification('Store ID required', 'error');
    header('Location: list.php');
    exit;
}

// Get comprehensive store information
$store = $sql_db->fetch("SELECT s.*, r.name as region_name, r.code as region_code, 
                               r.regional_manager, r.manager_email as region_manager_email,
                               sp.total_sales, sp.avg_rating, sp.customer_count, sp.last_updated as perf_updated
                        FROM stores s 
                        LEFT JOIN regions r ON s.region_id = r.id 
                        LEFT JOIN store_performance sp ON sp.store_id = s.id
                        WHERE s.id = ? AND s.active = 1", [$store_id]);

if (!$store) {
    addNotification('Store not found or inactive', 'error');
    header('Location: list.php');
    exit;
}

// Enhanced operating hours parsing
$operating_hours = [];
if (!empty($store['operating_hours'])) {
    $operating_hours = json_decode($store['operating_hours'], true) ?: [];
}

// Get store staff with enhanced details
$staff = $sql_db->fetchAll("SELECT ss.*, 
                                   u.email, u.phone, u.created_at as user_created,
                                   COUNT(sl.id) as shift_count
                            FROM store_staff ss
                            LEFT JOIN users u ON ss.user_id = u.id
                            LEFT JOIN shift_logs sl ON sl.staff_id = ss.id AND sl.date >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
                            WHERE ss.store_id = ? AND ss.active = 1 
                            GROUP BY ss.id
                            ORDER BY ss.is_manager DESC, ss.name", [$store_id]);

// Get performance metrics (last 90 days for trends)
$performance_query = "SELECT DATE(metric_date) as date, 
                            AVG(daily_sales) as daily_sales,
                            AVG(customer_count) as customer_count,
                            AVG(avg_transaction_value) as avg_transaction,
                            AVG(staff_rating) as staff_rating
                      FROM store_performance 
                      WHERE store_id = ? AND metric_date >= DATE_SUB(CURRENT_DATE, INTERVAL 90 DAY)
                      GROUP BY DATE(metric_date)
                      ORDER BY date DESC";
$performance = $sql_db->fetchAll($performance_query, [$store_id]);

// Get inventory summary
$inventory_summary = $sql_db->fetch("SELECT COUNT(*) as total_products,
                                           SUM(quantity) as total_stock,
                                           SUM(quantity * price) as inventory_value,
                                           SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) as low_stock_items,
                                           SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_items
                                    FROM products 
                                    WHERE store_id = ? AND active = 1", [$store_id]);

// Get recent alerts and notifications
$recent_alerts = $sql_db->fetchAll("SELECT sa.*, p.name as product_name
                                   FROM store_alerts sa
                                   LEFT JOIN products p ON sa.product_id = p.id
                                   WHERE sa.store_id = ? AND sa.is_resolved = 0
                                   ORDER BY sa.created_at DESC LIMIT 20", [$store_id]);
$alerts = $db->fetchAll("SELECT * FROM store_alerts 
                         WHERE store_id = ? AND resolved = 0 
                         ORDER BY severity DESC, created_at DESC LIMIT 10", [$store_id]);

// Get inventory summary
$inventory_summary = $db->fetch("SELECT 
                                    COUNT(*) as total_products,
                                    SUM(quantity) as total_quantity,
                                    SUM(quantity * cost_price) as total_cost_value,
                                    SUM(quantity * selling_price) as total_selling_value,
                                    COUNT(DISTINCT category) as categories_count,
                                    COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock,
                                    COUNT(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 END) as low_stock,
                                    COUNT(CASE WHEN expiry_date < CURDATE() THEN 1 END) as expired,
                                    AVG(quantity) as avg_quantity,
                                    MAX(created_at) as last_product_added
                                FROM products 
                                WHERE store_id = ? AND active = 1", [$store_id]);

// Calculate some performance metrics
$avg_performance = [];
if (!empty($performance)) {
    $avg_performance = [
        'avg_daily_sales' => array_sum(array_column($performance, 'daily_sales')) / count($performance),
        'avg_transactions' => array_sum(array_column($performance, 'transaction_count')) / count($performance),
        'avg_customers' => array_sum(array_column($performance, 'customer_count')) / count($performance),
        'avg_profit_margin' => array_sum(array_column($performance, 'profit_margin')) / count($performance),
        'total_sales_30d' => array_sum(array_column($performance, 'daily_sales'))
    ];
}

$page_title = "Store Profile - {$store['name']} - Inventory System";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .profile-container {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 20px;
        }
        
        .profile-main {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .profile-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .profile-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .profile-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .store-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        .store-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            margin-bottom: 15px;
        }
        
        .detail-label {
            font-weight: 600;
            color: #666;
            font-size: 0.9em;
            margin-bottom: 3px;
        }
        
        .detail-value {
            font-size: 1.1em;
            color: #333;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
        }
        
        .operating-hours {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        
        .day-hours {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .day-name {
            font-weight: 600;
        }
        
        .hours-text {
            color: #666;
        }
        
        .staff-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .staff-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .staff-info {
            flex: 1;
        }
        
        .staff-name {
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .staff-position {
            color: #666;
            font-size: 0.9em;
        }
        
        .staff-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .badge-manager {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-staff {
            background: #e2e3e5;
            color: #6c757d;
        }
        
        .alert-item {
            display: flex;
            align-items: start;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-bottom: 10px;
        }
        
        .alert-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin-right: 10px;
            flex-shrink: 0;
        }
        
        .alert-low { background: #ffc107; }
        .alert-medium { background: #fd7e14; }
        .alert-high { background: #dc3545; }
        .alert-critical { background: #6f42c1; }
        
        .alert-content {
            flex: 1;
        }
        
        .alert-title {
            font-weight: 600;
            margin-bottom: 3px;
        }
        
        .alert-message {
            color: #666;
            font-size: 0.9em;
        }
        
        .alert-time {
            color: #999;
            font-size: 0.8em;
            margin-top: 5px;
        }
        
        .chart-container {
            height: 200px;
            background: #f8f9fa;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            margin: 15px 0;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        
        .quick-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-active { background: #28a745; }
        .status-inactive { background: #dc3545; }
        
        @media (max-width: 768px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Store Profile Manager</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="list.php">Stores</a></li>
                    <li><a href="map.php">Store Map</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <!-- Store Header -->
            <div class="profile-header">
                <div style="display: flex; align-items: center; gap: 20px;">
                    <div class="store-avatar">
                        <?php echo strtoupper(substr($store['name'], 0, 2)); ?>
                    </div>
                    <div style="flex: 1;">
                        <h2 style="margin: 0; font-size: 2.5em;"><?php echo htmlspecialchars($store['name']); ?></h2>
                        <p style="margin: 5px 0; opacity: 0.9;">
                            <span class="status-indicator status-<?php echo $store['active'] ? 'active' : 'inactive'; ?>"></span>
                            <?php echo htmlspecialchars($store['code']); ?> • 
                            <?php echo htmlspecialchars(ucfirst($store['store_type'])); ?> Store • 
                            <?php echo $store['active'] ? 'Active' : 'Inactive'; ?>
                        </p>
                        <p style="margin: 0; opacity: 0.8;">
                            <?php echo htmlspecialchars($store['city'] . ', ' . $store['state']); ?> • 
                            <?php echo htmlspecialchars($store['region_name'] ?? 'No Region'); ?>
                        </p>
                    </div>
                    <div class="action-buttons">
                        <a href="edit.php?id=<?php echo $store_id; ?>" class="btn btn-light">Edit Store</a>
                        <a href="inventory_viewer.php?id=<?php echo $store_id; ?>" class="btn btn-light">View Inventory</a>
                    </div>
                </div>
            </div>

            <div class="profile-container">
                <!-- Main Content -->
                <div class="profile-main">
                    <!-- Store Details -->
                    <div class="profile-card">
                        <h3>Store Information</h3>
                        <div class="store-details-grid">
                            <div class="detail-item">
                                <div class="detail-label">Full Address</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($store['address']); ?><br>
                                    <?php echo htmlspecialchars($store['city'] . ', ' . $store['state'] . ' ' . $store['zip_code']); ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Contact Information</div>
                                <div class="detail-value">
                                    <?php if ($store['phone']): ?>
                                        Phone: <?php echo htmlspecialchars($store['phone']); ?><br>
                                    <?php endif; ?>
                                    <?php if ($store['email']): ?>
                                        Email: <?php echo htmlspecialchars($store['email']); ?><br>
                                    <?php endif; ?>
                                    <?php if ($store['emergency_contact']): ?>
                                        Emergency: <?php echo htmlspecialchars($store['emergency_contact']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Store Manager</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($store['contact_person'] ?: 'Not assigned'); ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Regional Manager</div>
                                <div class="detail-value">
                                    <?php echo htmlspecialchars($store['regional_manager'] ?: 'Not assigned'); ?>
                                    <?php if ($store['region_manager_email']): ?>
                                        <br><small style="color: #666;"><?php echo htmlspecialchars($store['region_manager_email']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Store Specifications</div>
                                <div class="detail-value">
                                    <?php if ($store['store_size'] > 0): ?>
                                        Size: <?php echo number_format($store['store_size'], 0); ?> sq ft<br>
                                    <?php endif; ?>
                                    <?php if ($store['max_capacity'] > 0): ?>
                                        Capacity: <?php echo number_format($store['max_capacity']); ?> items<br>
                                    <?php endif; ?>
                                    Timezone: <?php echo htmlspecialchars($store['timezone']); ?>
                                </div>
                            </div>
                            
                            <div class="detail-item">
                                <div class="detail-label">Opening Information</div>
                                <div class="detail-value">
                                    <?php if ($store['opening_date']): ?>
                                        Opened: <?php echo date('M j, Y', strtotime($store['opening_date'])); ?><br>
                                        <?php 
                                        $opening_date = new DateTime($store['opening_date']);
                                        $now = new DateTime();
                                        $diff = $now->diff($opening_date);
                                        echo "Operating for {$diff->y} years, {$diff->m} months";
                                        ?>
                                    <?php else: ?>
                                        Opening date not specified
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Operating Hours -->
                    <div class="profile-card">
                        <h3>Operating Hours</h3>
                        <?php if (!empty($operating_hours)): ?>
                            <div class="operating-hours">
                                <?php 
                                $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                                foreach ($days as $day): 
                                ?>
                                    <div class="day-hours">
                                        <span class="day-name"><?php echo ucfirst($day); ?></span>
                                        <span class="hours-text">
                                            <?php 
                                            if (isset($operating_hours[$day]) && $operating_hours[$day]['open']) {
                                                echo htmlspecialchars($operating_hours[$day]['open'] . ' - ' . $operating_hours[$day]['close']);
                                            } else {
                                                echo 'Closed';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: #666; text-align: center; padding: 20px;">Operating hours not configured</p>
                            <div style="text-align: center;">
                                <a href="edit.php?id=<?php echo $store_id; ?>" class="btn btn-primary">Set Operating Hours</a>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Performance Overview -->
                    <div class="profile-card">
                        <h3>Performance Overview (Last 30 Days)</h3>
                        <?php if (!empty($avg_performance)): ?>
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="stat-number">$<?php echo number_format($avg_performance['total_sales_30d'], 0); ?></div>
                                    <div class="stat-label">Total Sales</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number">$<?php echo number_format($avg_performance['avg_daily_sales'], 0); ?></div>
                                    <div class="stat-label">Avg Daily Sales</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo number_format($avg_performance['avg_transactions'], 0); ?></div>
                                    <div class="stat-label">Avg Transactions</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo number_format($avg_performance['avg_customers'], 0); ?></div>
                                    <div class="stat-label">Avg Customers</div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-number"><?php echo number_format($avg_performance['avg_profit_margin'], 1); ?>%</div>
                                    <div class="stat-label">Profit Margin</div>
                                </div>
                            </div>
                            
                            <div class="chart-container">
                                <div style="text-align: center;">
                                    <strong>Sales Trend Chart</strong><br>
                                    <small>Chart visualization would be implemented here using Chart.js or similar library</small>
                                </div>
                            </div>
                        <?php else: ?>
                            <p style="color: #666; text-align: center; padding: 20px;">No performance data available for the last 30 days</p>
                        <?php endif; ?>
                    </div>

                    <!-- Staff Management -->
                    <div class="profile-card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                            <h3>Staff Management</h3>
                            <a href="add_staff.php?store_id=<?php echo $store_id; ?>" class="btn btn-primary">Add Staff</a>
                        </div>
                        
                        <?php if (!empty($staff)): ?>
                            <div class="staff-list">
                                <?php foreach ($staff as $member): ?>
                                    <div class="staff-item">
                                        <div class="staff-info">
                                            <div class="staff-name"><?php echo htmlspecialchars($member['name']); ?></div>
                                            <div class="staff-position"><?php echo htmlspecialchars($member['position']); ?></div>
                                            <?php if ($member['email']): ?>
                                                <div style="font-size: 0.8em; color: #999; margin-top: 2px;">
                                                    <?php echo htmlspecialchars($member['email']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <span class="staff-badge badge-<?php echo $member['is_manager'] ? 'manager' : 'staff'; ?>">
                                                <?php echo $member['is_manager'] ? 'Manager' : 'Staff'; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: #666; text-align: center; padding: 20px;">No staff members assigned</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Sidebar -->
                <div class="profile-sidebar">
                    <!-- Quick Stats -->
                    <div class="profile-card">
                        <h4>Inventory Overview</h4>
                        <div class="quick-stats">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo number_format($inventory_summary['total_products'] ?? 0); ?></div>
                                <div class="stat-label">Products</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo number_format($inventory_summary['total_quantity'] ?? 0); ?></div>
                                <div class="stat-label">Total Stock</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number">$<?php echo number_format($inventory_summary['total_selling_value'] ?? 0, 0); ?></div>
                                <div class="stat-label">Value</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo number_format($inventory_summary['categories_count'] ?? 0); ?></div>
                                <div class="stat-label">Categories</div>
                            </div>
                        </div>
                        
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Low Stock:</span>
                                <span style="color: #ffc107; font-weight: 600;"><?php echo number_format($inventory_summary['low_stock'] ?? 0); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span>Out of Stock:</span>
                                <span style="color: #dc3545; font-weight: 600;"><?php echo number_format($inventory_summary['out_of_stock'] ?? 0); ?></span>
                            </div>
                            <div style="display: flex; justify-content: space-between;">
                                <span>Expired:</span>
                                <span style="color: #dc3545; font-weight: 600;"><?php echo number_format($inventory_summary['expired'] ?? 0); ?></span>
                            </div>
                        </div>
                        
                        <div style="margin-top: 15px;">
                            <a href="inventory_viewer.php?id=<?php echo $store_id; ?>" class="btn btn-primary" style="width: 100%;">View Full Inventory</a>
                        </div>
                    </div>

                    <!-- Recent Alerts -->
                    <div class="profile-card">
                        <h4>Recent Alerts</h4>
                        <?php if (!empty($alerts)): ?>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($alerts as $alert): ?>
                                    <div class="alert-item">
                                        <div class="alert-icon alert-<?php echo $alert['severity']; ?>"></div>
                                        <div class="alert-content">
                                            <div class="alert-title"><?php echo htmlspecialchars($alert['title']); ?></div>
                                            <div class="alert-message"><?php echo htmlspecialchars($alert['message']); ?></div>
                                            <div class="alert-time">
                                                <?php echo date('M j, Y g:i A', strtotime($alert['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: #666; text-align: center; padding: 20px;">No active alerts</p>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Actions -->
                    <div class="profile-card">
                        <h4>Quick Actions</h4>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <a href="../stock/add.php?store_id=<?php echo $store_id; ?>" class="btn btn-primary">Add Product</a>
                            <a href="stock_take.php?store_id=<?php echo $store_id; ?>" class="btn btn-secondary">Stock Take</a>
                            <a href="../reports/store_report.php?store_id=<?php echo $store_id; ?>" class="btn btn-outline">Generate Report</a>
                            <a href="settings.php?id=<?php echo $store_id; ?>" class="btn btn-outline">Store Settings</a>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Auto-refresh alerts every 5 minutes
        setInterval(function() {
            fetch('api/get_store_alerts.php?store_id=<?php echo $store_id; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.alerts.length > 0) {
                        // Update alerts section if needed
                        console.log('New alerts available:', data.alerts.length);
                    }
                })
                .catch(error => console.error('Error fetching alerts:', error));
        }, 300000);
        
        // Add smooth scroll behavior for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
    </script>
</body>
</html>