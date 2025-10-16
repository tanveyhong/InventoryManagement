<?php
// Enhanced Dashboard with Performance Optimization
ob_start('ob_gzhandler'); // Enable compression

require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: modules/users/login.php');
    exit;
}

// Helper function for caching
function getCachedStats($key, $callback, $ttl = 180) {
    $cache_dir = 'storage/cache/';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0755, true);
    }
    
    $cache_file = $cache_dir . md5($key) . '.json';
    
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        $data = json_decode(file_get_contents($cache_file), true);
        if ($data !== null) {
            return $data;
        }
    }
    
    $data = $callback();
    file_put_contents($cache_file, json_encode($data));
    return $data;
}

// Get dashboard statistics with caching (3 min cache)
$stats = getCachedStats('dashboard_stats_' . $_SESSION['user_id'], function() {
    return [
        'total_products' => getTotalProducts(),
        'low_stock' => getLowStockCount(),
        'total_stores' => getTotalStores(),
        'todays_sales' => getTodaysSales(),
        'notifications' => getNotifications()
    ];
}, 180);

// Get recent activity (you can expand this based on your needs)
$db = getDB();
$recent_activity = [];

$page_title = 'Dashboard - Inventory Management System';

// Add caching headers
header('Cache-Control: private, max-age=180');
header('Vary: Cookie');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- Chart.js for interactive charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .welcome-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .welcome-section h1 {
            margin: 0 0 10px 0;
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .welcome-section p {
            margin: 0;
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        
        .stat-card.alert::before {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
        }
        
        .stat-card.success::before {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
        }
        
        .stat-card.warning::before {
            background: linear-gradient(135deg, #feca57, #ff9ff3);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        
        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }
        
        .stat-icon.products { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-icon.alert { background: linear-gradient(135deg, #ff6b6b, #ee5a24); }
        .stat-icon.stores { background: linear-gradient(135deg, #4ecdc4, #44a08d); }
        .stat-icon.sales { background: linear-gradient(135deg, #feca57, #ff9ff3); }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 1rem;
            color: #7f8c8d;
            margin: 5px 0 0 0;
        }
        
        .stat-trend {
            font-size: 0.9rem;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .trend-up { color: #27ae60; }
        .trend-down { color: #e74c3c; }
        
        .btn-toggle-compact {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-toggle-compact:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .compact-view .chart-container {
            height: 250px; /* Smaller height in compact mode */
        }
        
        .compact-view .chart-wrapper {
            height: 180px; /* Adjust chart wrapper accordingly */
        }
        
        .compact-view .activity-feed {
            height: 250px; /* Match the compact chart height */
        }
        
        .compact-view .stats-grid {
            margin-bottom: 20px;
        }
        
        .compact-view .dashboard-grid {
            margin-bottom: 20px;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
            align-items: start; /* Prevents stretching */
        }
        
        .chart-container {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            height: 350px; /* Fixed height for better control */
        }
        
        .chart-container h3 {
            margin: 0 0 15px 0;
            color: #2c3e50;
            font-size: 1.3rem;
        }
        
        .chart-wrapper {
            height: 280px; /* Reduced height for more compact display */
            position: relative;
        }
        
        .activity-feed {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            height: 350px; /* Match chart container height */
            overflow-y: auto; /* Allow scrolling if content is too long */
        }
        
        .activity-feed h3 {
            margin: 0 0 15px 0;
            color: #2c3e50;
            font-size: 1.3rem;
            position: sticky;
            top: 0;
            background: white;
            padding-bottom: 10px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            text-align: center;
            text-decoration: none;
            color: inherit;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            text-decoration: none;
        }
        
        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, #667eea, #764ba2);
        }
        
        .action-icon {
            font-size: 2rem;
            margin-bottom: 15px;
            color: #667eea;
        }
        
        .action-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        
        .action-desc {
            font-size: 0.9rem;
            color: #7f8c8d;
        }
        
        .notifications-widget {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .notification-item {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 0.9rem;
        }
        
        .notification-icon.alert {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .notification-icon.info {
            background: #dbeafe;
            color: #2563eb;
        }
        
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .welcome-section {
                padding: 25px;
            }
            
            .welcome-section h1 {
                font-size: 2rem;
            }
        }
        
        /* Profile Section Styling (for Management Dashboard) */
        .profile-section {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .profile-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .section-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 1.2rem;
        }
        
        .section-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.4rem;
        }
        
        .info-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }
        
        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 35px rgba(0,0,0,0.15) !important;
        }
    </style>
</head>
<body>
    <!-- Dashboard Header -->
    <?php 
    $header_title = "Welcome back, " . htmlspecialchars($_SESSION['username']) . "!";
    $header_subtitle = "Here's what's happening with your inventory today";
    $header_icon = "fas fa-user-circle";
    $show_compact_toggle = true;
    $header_stats = [
        [
            'value' => number_format($stats['total_products']),
            'label' => 'Total Products',
            'icon' => 'fas fa-boxes',
            'type' => 'primary',
            'trend' => [
                'type' => 'trend-up',
                'icon' => 'arrow-up',
                'text' => '+12% from last month'
            ]
        ],
        [
            'value' => number_format($stats['low_stock']),
            'label' => 'Low Stock Items',
            'icon' => 'fas fa-exclamation-triangle',
            'type' => 'alert',
            'trend' => [
                'type' => $stats['low_stock'] > 0 ? 'trend-down' : 'trend-up',
                'icon' => $stats['low_stock'] > 0 ? 'exclamation-circle' : 'check-circle',
                'text' => $stats['low_stock'] > 0 ? 'Requires attention' : 'All good!'
            ]
        ],
        [
            'value' => number_format($stats['total_stores']),
            'label' => 'Active Stores',
            'icon' => 'fas fa-store',
            'type' => 'success',
            'trend' => [
                'type' => 'trend-up',
                'icon' => 'arrow-up',
                'text' => '+2 new stores'
            ]
        ],
        [
            'value' => '$' . number_format($stats['todays_sales'], 0),
            'label' => "Today's Sales",
            'icon' => 'fas fa-dollar-sign',
            'type' => 'warning',
            'trend' => [
                'type' => 'trend-up',
                'icon' => 'arrow-up',
                'text' => '+8% vs yesterday'
            ]
        ]
    ];
    include 'includes/dashboard_header.php'; 
    ?>
    <div class="container">
        <div class="dashboard-container">

            <!-- Page header -->
            <div class="page-header">
                <div class="header-left">
                    <div class="header-icon"><i class="<?php echo htmlspecialchars($header_icon ?? 'fas fa-tachometer-alt'); ?>"></i></div>
                    <div class="header-text">
                        <h1><?php echo htmlspecialchars($header_title ?? 'Dashboard'); ?></h1>
                        <p><?php echo htmlspecialchars($header_subtitle ?? 'System overview and analytics'); ?></p>
                    </div>
                </div>
                <?php if (!empty($show_compact_toggle)): ?>
                <div class="header-actions">
                    <button class="btn-compact-toggle" onclick="toggleCompactView()">
                        <i class="fas fa-compress"></i>
                        <span>Compact View</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($header_stats)): ?>
            <div class="stats-grid">
                <?php foreach ($header_stats as $stat): ?>
                <div class="stat-card">
                    <div class="stat-card-inner">
                        <div class="stat-icon-wrapper">
                            <div class="stat-icon <?php echo htmlspecialchars($stat['type'] ?? 'primary'); ?>">
                                <i class="<?php echo htmlspecialchars($stat['icon'] ?? 'fas fa-info'); ?>"></i>
                            </div>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo htmlspecialchars($stat['value']); ?></div>
                            <div class="stat-label"><?php echo htmlspecialchars($stat['label']); ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Management Dashboard Section -->
            <?php if (isset($_SESSION['user_id'])): 
                // Check user role for conditional display
                $currentUser = $db->read('users', $_SESSION['user_id']);
                $userRole = $currentUser['role'] ?? 'user';
                $canManage = in_array($userRole, ['admin', 'manager']);
            ?>
            <?php if ($canManage): ?>
            <div class="profile-section" style="margin-bottom: 20px;">
                <div class="section-header">
                    <div class="section-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <h3>Management Dashboard</h3>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                    <!-- Activity Manager Card -->
                    <a href="modules/users/profile/activity_manager.php" style="text-decoration: none; color: inherit;">
                        <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-history" style="font-size: 24px; color: white;"></i>
                                </div>
                                <div>
                                    <h4 style="margin: 0 0 5px 0;">Activity Manager</h4>
                                    <p style="margin: 0; font-size: 14px; color: #666;">Track and manage all user activities</p>
                                </div>
                            </div>
                        </div>
                    </a>
                    
                    <!-- Permissions Manager Card -->
                    <?php if ($userRole === 'admin'): ?>
                    <a href="modules/users/profile/permissions_manager.php" style="text-decoration: none; color: inherit;">
                        <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-shield-alt" style="font-size: 24px; color: white;"></i>
                                </div>
                                <div>
                                    <h4 style="margin: 0 0 5px 0;">Permissions Manager</h4>
                                    <p style="margin: 0; font-size: 14px; color: #666;">Manage roles and user permissions</p>
                                </div>
                            </div>
                        </div>
                    </a>
                    <?php endif; ?>
                    
                    <!-- Stores Manager Card -->
                    <a href="modules/users/profile/stores_manager.php" style="text-decoration: none; color: inherit;">
                        <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-store" style="font-size: 24px; color: white;"></i>
                                </div>
                                <div>
                                    <h4 style="margin: 0 0 5px 0;">Stores Manager</h4>
                                    <p style="margin: 0; font-size: 14px; color: #666;">Manage stores and user access</p>
                                </div>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Charts Section -->
                <div class="chart-container">
                    <h3><i class="fas fa-chart-line"></i> Sales Overview</h3>
                    <div class="chart-wrapper">
                        <canvas id="salesChart"></canvas>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="activity-feed">
                    <h3><i class="fas fa-clock"></i> Recent Activity</h3>
                    <div id="activity-list">
                        <div class="notification-item">
                            <div class="notification-icon info">
                                <i class="fas fa-plus"></i>
                            </div>
                            <div>
                                <strong>New product added</strong><br>
                                <small>2 minutes ago</small>
                            </div>
                        </div>
                        <div class="notification-item">
                            <div class="notification-icon alert">
                                <i class="fas fa-exclamation"></i>
                            </div>
                            <div>
                                <strong>Low stock alert</strong><br>
                                <small>15 minutes ago</small>
                            </div>
                        </div>
                        <div class="notification-item">
                            <div class="notification-icon info">
                                <i class="fas fa-sync"></i>
                            </div>
                            <div>
                                <strong>Inventory synced</strong><br>
                                <small>1 hour ago</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notifications Widget -->
            <?php if (!empty($stats['notifications'])): ?>
            <div class="notifications-widget">
                <h3><i class="fas fa-bell"></i> Notifications</h3>
                <?php foreach ($stats['notifications'] as $notification): ?>
                    <div class="notification-item">
                        <div class="notification-icon alert">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div>
                            <strong><?php echo htmlspecialchars($notification['title']); ?></strong><br>
                            <small><?php echo htmlspecialchars($notification['message']); ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="quick-actions-grid">
                <a href="modules/stock/add.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-plus-circle"></i>
                    </div>
                    <div class="action-title">Add Product</div>
                    <div class="action-desc">Add new items to inventory</div>
                </a>

                <a href="pos_terminal.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-cash-register"></i>
                    </div>
                    <div class="action-title">POS Terminal</div>
                    <div class="action-desc">Process sales transactions</div>
                </a>

                <a href="modules/stores/enhanced_map.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-map-marked-alt"></i>
                    </div>
                    <div class="action-title">Store Map</div>
                    <div class="action-desc">View store locations</div>
                </a>

                <a href="modules/reports/dashboard.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="action-title">Reports</div>
                    <div class="action-desc">Generate analytics reports</div>
                </a>

                <a href="sync_dashboard.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <div class="action-title">Sync Manager</div>
                    <div class="action-desc">Database synchronization</div>
                </a>

                <a href="modules/alerts/low_stock.php" class="action-card">
                    <div class="action-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div class="action-title">Alerts</div>
                    <div class="action-desc">View system alerts</div>
                </a>
            </div>
        </div>
    </div>

    <!-- Chart.js Script -->
    <script>
        // Dashboard Management
        class DashboardManager {
            constructor() {
                this.salesChart = null;
                this.refreshInterval = null;
                this.init();
            }
            
            init() {
                this.createSalesChart();
                this.startAutoRefresh();
                this.setupEventListeners();
            }
            
            createSalesChart() {
                const ctx = document.getElementById('salesChart').getContext('2d');
                this.salesChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                        datasets: [{
                            label: 'Sales ($)',
                            data: [1200, 1900, 3000, 2100, 3200, 2800, <?php echo $stats['todays_sales']; ?>],
                            borderColor: '#667eea',
                            backgroundColor: 'rgba(102, 126, 234, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.3,
                            pointBackgroundColor: '#667eea',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                backgroundColor: 'rgba(0,0,0,0.8)',
                                titleColor: '#fff',
                                bodyColor: '#fff',
                                cornerRadius: 6,
                                displayColors: false,
                                padding: 10
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: '#f1f5f9',
                                    drawBorder: false
                                },
                                ticks: {
                                    color: '#64748b',
                                    font: {
                                        size: 11
                                    },
                                    padding: 10,
                                    callback: function(value) {
                                        return '$' + (value >= 1000 ? (value/1000).toFixed(0) + 'k' : value);
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    color: '#f8fafc',
                                    drawBorder: false
                                },
                                ticks: {
                                    color: '#64748b',
                                    font: {
                                        size: 11
                                    },
                                    padding: 8
                                }
                            }
                        },
                        elements: {
                            point: {
                                hoverRadius: 6
                            }
                        },
                        animation: {
                            duration: 800,
                            easing: 'easeInOutQuart'
                        },
                        layout: {
                            padding: {
                                top: 10,
                                right: 10,
                                bottom: 10,
                                left: 10
                            }
                        }
                    }
                });
            }
            
            async refreshDashboardData() {
                try {
                    const response = await fetch('api/dashboard/real-time.php');
                    if (!response.ok) throw new Error('Network response was not ok');
                    
                    const data = await response.json();
                    if (data.success) {
                        this.updateStatistics(data.data.stats);
                        this.updateSalesChart(data.data.sales_chart);
                        this.updateRecentActivity(data.data.recent_activity);
                        this.showSuccessIndicator();
                    }
                } catch (error) {
                    console.warn('Dashboard refresh failed:', error);
                    this.showErrorIndicator();
                }
            }
            
            updateStatistics(stats) {
                // Update stat numbers with animation
                this.animateNumber('.stat-number', stats.total_products, 0);
                this.animateNumber('.stat-card.alert .stat-number', stats.low_stock, 1);
                this.animateNumber('.stat-card.success .stat-number', stats.total_stores, 2);
                this.animateNumber('.stat-card.warning .stat-number', stats.todays_sales, 3, '$');
            }
            
            animateNumber(selector, newValue, index, prefix = '') {
                const elements = document.querySelectorAll(selector);
                if (elements[index]) {
                    const element = elements[index];
                    const currentValue = parseInt(element.textContent.replace(/[^0-9]/g, ''));
                    
                    if (currentValue !== newValue) {
                        this.countUp(element, currentValue, newValue, prefix);
                    }
                }
            }
            
            countUp(element, start, end, prefix = '', duration = 1000) {
                const startTime = Date.now();
                const step = () => {
                    const progress = Math.min((Date.now() - startTime) / duration, 1);
                    const current = Math.floor(start + (end - start) * progress);
                    element.textContent = prefix + current.toLocaleString();
                    
                    if (progress < 1) {
                        requestAnimationFrame(step);
                    }
                };
                step();
            }
            
            updateSalesChart(salesData) {
                if (this.salesChart && salesData && salesData.length > 0) {
                    const labels = salesData.map(d => d.day);
                    const data = salesData.map(d => d.sales);
                    
                    this.salesChart.data.labels = labels;
                    this.salesChart.data.datasets[0].data = data;
                    this.salesChart.update('none');
                }
            }
            
            updateRecentActivity(activities) {
                const activityList = document.getElementById('activity-list');
                if (activityList && activities) {
                    activityList.innerHTML = activities.map(activity => `
                        <div class="notification-item">
                            <div class="notification-icon ${activity.color}">
                                <i class="fas fa-${activity.icon}"></i>
                            </div>
                            <div>
                                <strong>${activity.message}</strong><br>
                                <small>${activity.time}</small>
                            </div>
                        </div>
                    `).join('');
                }
            }
            
            setupEventListeners() {
                // Add click handlers for stat cards
                document.querySelectorAll('.stat-card').forEach(card => {
                    card.addEventListener('click', () => {
                        card.style.transform = 'scale(0.98)';
                        setTimeout(() => {
                            card.style.transform = '';
                        }, 150);
                    });
                });
                
                // Compact view toggle
                const toggleBtn = document.getElementById('toggleCompact');
                if (toggleBtn) {
                    toggleBtn.addEventListener('click', () => {
                        const body = document.body;
                        const isCompact = body.classList.contains('compact-view');
                        
                        if (isCompact) {
                            body.classList.remove('compact-view');
                            toggleBtn.innerHTML = '<i class="fas fa-compress-alt"></i> Compact View';
                        } else {
                            body.classList.add('compact-view');
                            toggleBtn.innerHTML = '<i class="fas fa-expand-alt"></i> Expanded View';
                        }
                        
                        // Resize chart after a brief delay
                        setTimeout(() => {
                            if (this.salesChart) {
                                this.salesChart.resize();
                            }
                        }, 100);
                    });
                }
                
                // Add refresh button if needed
                this.addRefreshButton();
            }
            
            addRefreshButton() {
                const refreshBtn = document.createElement('button');
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
                refreshBtn.className = 'refresh-btn';
                refreshBtn.style.cssText = `
                    position: fixed;
                    bottom: 30px;
                    right: 30px;
                    background: #667eea;
                    color: white;
                    border: none;
                    padding: 12px 20px;
                    border-radius: 25px;
                    cursor: pointer;
                    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
                    z-index: 1000;
                    transition: all 0.3s ease;
                `;
                
                refreshBtn.addEventListener('click', () => {
                    refreshBtn.style.transform = 'rotate(360deg)';
                    this.refreshDashboardData();
                    setTimeout(() => {
                        refreshBtn.style.transform = '';
                    }, 500);
                });
                
                document.body.appendChild(refreshBtn);
            }
            
            startAutoRefresh() {
                // Refresh every 60 seconds
                this.refreshInterval = setInterval(() => {
                    this.refreshDashboardData();
                }, 60000);
            }
            
            showSuccessIndicator() {
                this.showIndicator('✓ Dashboard updated', '#27ae60');
            }
            
            showErrorIndicator() {
                this.showIndicator('⚠ Update failed', '#e74c3c');
            }
            
            showIndicator(message, color) {
                const indicator = document.createElement('div');
                indicator.textContent = message;
                indicator.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: ${color};
                    color: white;
                    padding: 10px 20px;
                    border-radius: 5px;
                    z-index: 10000;
                    font-size: 14px;
                    opacity: 0;
                    transition: opacity 0.3s ease;
                `;
                
                document.body.appendChild(indicator);
                
                setTimeout(() => indicator.style.opacity = '1', 10);
                setTimeout(() => {
                    indicator.style.opacity = '0';
                    setTimeout(() => document.body.removeChild(indicator), 300);
                }, 3000);
            }
            
            destroy() {
                if (this.refreshInterval) {
                    clearInterval(this.refreshInterval);
                }
            }
        }
        
        // Initialize dashboard when page loads
        document.addEventListener('DOMContentLoaded', function() {
            window.dashboardManager = new DashboardManager();
        });
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (window.dashboardManager) {
                window.dashboardManager.destroy();
            }
        });
    </script>

    <script src="assets/js/main.js"></script>
</body>
</html>