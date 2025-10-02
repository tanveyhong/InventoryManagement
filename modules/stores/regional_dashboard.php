<?php
// Enhanced Regional Analytics Dashboard
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

// Get enhanced filter parameters
$selected_region = sanitizeInput($_GET['region'] ?? '');
$date_range = intval($_GET['date_range'] ?? 30);
$comparison_period = sanitizeInput($_GET['comparison'] ?? 'previous');
$metric_type = sanitizeInput($_GET['metric'] ?? 'sales');
$view_type = sanitizeInput($_GET['view'] ?? 'summary');

// Calculate date ranges with validation
$date_range = max(7, min(365, $date_range)); // Between 7 days and 1 year
$end_date = date('Y-m-d');
$start_date = date('Y-m-d', strtotime("-{$date_range} days"));

// Enhanced comparison period calculations
switch ($comparison_period) {
    case 'previous':
        $comp_end_date = date('Y-m-d', strtotime("-{$date_range} days"));
        $comp_start_date = date('Y-m-d', strtotime("-" . ($date_range * 2) . " days"));
        $comp_label = "Previous {$date_range} days";
        break;
    case 'last_year':
        $comp_end_date = date('Y-m-d', strtotime("-1 year", strtotime($end_date)));
        $comp_start_date = date('Y-m-d', strtotime("-1 year", strtotime($start_date)));
        $comp_label = "Same period last year";
        break;
    case 'last_quarter':
        $comp_end_date = date('Y-m-d', strtotime("-3 months", strtotime($end_date)));
        $comp_start_date = date('Y-m-d', strtotime("-3 months", strtotime($start_date)));
        $comp_label = "Same period last quarter";
        break;
    default:
        $comp_end_date = $comp_start_date = null;
        $comp_label = "No comparison";
}

// Get basic store data for regional dashboard
$stores_query = "SELECT s.*, 'Default Region' as region_name 
                FROM stores s 
                WHERE s.active = 1 
                ORDER BY s.name";
$regions = [['id' => 1, 'name' => 'Default Region', 'code' => 'DR001']];
$regional_overview = $sql_db->fetchAll($stores_query);

// Simple totals calculation
$totals = [
    'total_stores' => count($regional_overview),
    'active_stores' => count($regional_overview),
    'total_products' => count($regional_overview) * 100, // Mock data
    'total_sales' => count($regional_overview) * 5000, // Mock data
    'total_inventory' => count($regional_overview) * 1000, // Mock data
    'total_inventory_value' => count($regional_overview) * 25000, // Mock data
    'total_transactions' => count($regional_overview) * 200, // Mock data
    'avg_profit_margin' => 15.5 // Mock data
];

// Mock regional overview data
foreach ($regional_overview as &$store) {
    $store['total_stores'] = 1;
    $store['active_stores'] = 1;
    $store['total_products'] = 100;
    $store['total_sales'] = 5000;
    $store['avg_daily_sales'] = 167;
    $store['total_inventory_value'] = 25000;
    $store['avg_profit_margin'] = 15.5;
    $store['regional_manager'] = 'John Doe';
    $store['region_name'] = 'Default Region';
}
unset($store);

$store_rankings = array_slice($regional_overview, 0, 10);

// Store rankings is already set above

$page_title = "Regional Reporting Dashboard - Inventory System";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Chart.js for interactive charts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .filter-controls {
            background: white;
            padding: 25px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .filters-row {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 180px;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #374151;
            font-size: 0.9rem;
        }
        
        .filter-group select {
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
            background: white;
        }
        
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-filter {
            padding: 12px 25px;
            background: #667eea;
            color: white;
            border: 2px solid #667eea;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-filter:hover {
            background: #5a6fd8;
            transform: translateY(-2px);
        }
        
        .btn-filter.btn-outline {
            background: transparent;
            color: #64748b;
            border: 2px solid #e2e8f0;
        }
        
        .btn-filter.btn-outline:hover {
            border-color: #667eea;
            color: #667eea;
        }
        
        .region-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .region-card {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-left: 5px solid #667eea;
            transition: transform 0.3s ease;
        }
        
        .region-card:hover {
            transform: translateY(-5px);
        }
        
        .region-card h4 {
            margin: 0 0 15px 0;
            color: #2c3e50;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .region-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
        }
        
        .region-stat {
            text-align: center;
        }
        
        .region-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .region-stat-label {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 500;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .chart-container {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }
        
        .chart-container:hover {
            transform: translateY(-5px);
        }
        
        .chart-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .chart-wrapper {
            position: relative;
            height: 400px;
        }
        
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .data-table th {
            padding: 20px 15px;
            text-align: left;
            font-weight: 700;
            font-size: 1rem;
        }
        
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
            color: #64748b;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .rankings-list {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .rankings-header {
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .rankings-header h3 {
            margin: 0 0 5px 0;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .rankings-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .ranking-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #f1f5f9;
            transition: background-color 0.3s ease;
        }
        
        .ranking-item:hover {
            background: #f8fafc;
        }
        
        .ranking-item:last-child {
            border-bottom: none;
        }
        
        .ranking-position {
            font-size: 1.5rem;
            font-weight: bold;
            color: #667eea;
            margin-right: 15px;
            min-width: 30px;
        }
        
        .ranking-info {
            flex: 1;
        }
        
        .ranking-name {
            font-weight: 600;
            margin-bottom: 3px;
            color: #2c3e50;
        }
        
        .ranking-region {
            color: #64748b;
            font-size: 0.9rem;
        }
        
        .ranking-value {
            text-align: right;
            font-weight: 600;
            color: #059669;
            font-size: 1.1rem;
        }
        
        .performance-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .indicator-excellent { background: #059669; }
        .indicator-good { background: #0891b2; }
        .indicator-average { background: #d97706; }
        .indicator-poor { background: #dc2626; }
        
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 0.875rem;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5a6fd8;
            transform: translateY(-1px);
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-success {
            background: #dcfce7;
            color: #059669;
        }
        
        @media (max-width: 768px) {
            .filters-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                min-width: auto;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
            
            .region-stats {
                grid-template-columns: 1fr;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Dashboard Header -->
    <?php 
    $header_title = "Regional Dashboard";
    $header_subtitle = "Comprehensive analytics and performance metrics across all regions";
    $header_icon = "fas fa-chart-area";
    $show_compact_toggle = true;
    $header_stats = [
        [
            'value' => count($regions),
            'label' => 'Total Regions',
            'icon' => 'fas fa-map-marked-alt',
            'type' => 'primary',
                'trend' => [
                    'type' => 'trend-up',
                    'icon' => 'arrow-up',
                    'text' => 'All regions active'
                ]
            ],
            [
                'value' => number_format($totals['active_stores']),
                'label' => 'Active Stores',
                'icon' => 'fas fa-store',
                'type' => 'success',
                'trend' => [
                    'type' => 'trend-up',
                    'icon' => 'arrow-up',
                    'text' => '+2% growth'
                ]
            ],
            [
                'value' => '$' . number_format($totals['total_sales'], 0),
                'label' => 'Total Revenue',
                'icon' => 'fas fa-dollar-sign',
                'type' => 'warning',
                'trend' => [
                    'type' => 'trend-up',
                    'icon' => 'arrow-up',
                    'text' => '+15% from last month'
                ]
            ],
            [
                'value' => number_format($totals['total_products']),
                'label' => 'Products Tracked',
                'icon' => 'fas fa-boxes',
                'type' => 'info',
                'trend' => [
                    'type' => 'trend-up',
                    'icon' => 'arrow-up',
                    'text' => '+8% inventory growth'
                ]
            ]
        ];
        include '../../includes/dashboard_header.php'; 
        ?>
    <div class="main-content">
        <!-- Page header -->
        <div class="page-header">
            <div class="header-left">
                <div class="header-icon"><i class="<?php echo htmlspecialchars($header_icon ?? 'fas fa-chart-line'); ?>"></i></div>
                <div class="header-text">
                    <h1><?php echo htmlspecialchars($header_title ?? 'Regional Reporting Dashboard'); ?></h1>
                    <p><?php echo htmlspecialchars($header_subtitle ?? 'Insights and analytics by region'); ?></p>
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

        <!-- Filter Controls -->
        <div class="filter-controls">
            <form method="GET" action="">
                <div class="filters-row">
                    <div class="filter-group">
                        <label>Region:</label>
                        <select name="region" onchange="this.form.submit()">
                            <option value="">All Regions</option>
                            <?php foreach ($regions as $region): ?>
                                <option value="<?php echo htmlspecialchars($region['name']); ?>" 
                                        <?php echo ($selected_region === $region['name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($region['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Date Range:</label>
                        <select name="date_range" onchange="this.form.submit()">
                            <option value="7" <?php echo ($date_range == '7') ? 'selected' : ''; ?>>Last 7 days</option>
                            <option value="30" <?php echo ($date_range == '30') ? 'selected' : ''; ?>>Last 30 days</option>
                            <option value="90" <?php echo ($date_range == '90') ? 'selected' : ''; ?>>Last 90 days</option>
                            <option value="365" <?php echo ($date_range == '365') ? 'selected' : ''; ?>>Last year</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Compare to:</label>
                        <select name="comparison" onchange="this.form.submit()">
                            <option value="previous" <?php echo ($comparison_period === 'previous') ? 'selected' : ''; ?>>Previous period</option>
                            <option value="last_year" <?php echo ($comparison_period === 'last_year') ? 'selected' : ''; ?>>Same period last year</option>
                            <option value="none" <?php echo ($comparison_period === 'none') ? 'selected' : ''; ?>>No comparison</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-filter">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="regional_dashboard.php" class="btn-filter btn-outline">
                        <i class="fas fa-refresh"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        
        <!-- Regional Statistics -->
        <div class="region-stats">
            <?php foreach ($regional_overview as $region): ?>
            <div class="region-card">
                <h4><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($region['name']); ?></h4>
                <div class="region-stats-grid">
                    <div class="region-stat">
                        <div class="region-stat-value"><?php echo number_format($region['total_stores']); ?></div>
                        <div class="region-stat-label">Stores</div>
                    </div>
                    <div class="region-stat">
                        <div class="region-stat-value">$<?php echo number_format($region['total_sales'], 0); ?></div>
                        <div class="region-stat-label">Sales</div>
                    </div>
                    <div class="region-stat">
                        <div class="region-stat-value"><?php echo number_format($region['total_products']); ?></div>
                        <div class="region-stat-label">Products</div>
                    </div>
                    <div class="region-stat">
                        <div class="region-stat-value"><?php echo number_format($region['avg_profit_margin'], 1); ?>%</div>
                        <div class="region-stat-label">Margin</div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Charts Grid -->
        <div class="charts-grid">
            <!-- Sales Chart -->
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-chart-line"></i> Regional Sales Performance
                </div>
                <div class="chart-wrapper">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
            
            <!-- Inventory Health Chart -->
            <div class="chart-container">
                <div class="chart-title">
                    <i class="fas fa-boxes"></i> Regional Inventory Health
                </div>
                <div class="chart-wrapper">
                    <canvas id="inventoryChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Dashboard Grid for Rankings and Table -->
        <div class="dashboard-grid">
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-map-marker-alt"></i> Region</th>
                            <th><i class="fas fa-store"></i> Stores</th>
                            <th><i class="fas fa-user-tie"></i> Manager</th>
                            <th><i class="fas fa-dollar-sign"></i> Total Sales</th>
                            <th><i class="fas fa-chart-line"></i> Performance</th>
                            <th><i class="fas fa-cogs"></i> Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regional_overview as $region): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($region['name']); ?></strong>
                                    <br><small style="color: #64748b;"><?php echo htmlspecialchars($region['code'] ?? 'N/A'); ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-success">
                                        <?php echo number_format($region['active_stores']); ?> / <?php echo number_format($region['total_stores']); ?>
                                    </span>
                                    <br><small style="color: #64748b;">Active / Total</small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($region['regional_manager'] ?: 'Not assigned'); ?>
                                </td>
                                <td>
                                    <strong>$<?php echo number_format($region['total_sales'], 0); ?></strong>
                                </td>
                                <td>
                                    <?php 
                                    $performance_score = $region['avg_profit_margin'];
                                    if ($performance_score >= 25) {
                                        $indicator = 'excellent';
                                        $performance_text = 'Excellent';
                                    } elseif ($performance_score >= 20) {
                                        $indicator = 'good';
                                        $performance_text = 'Good';
                                    } elseif ($performance_score >= 15) {
                                        $indicator = 'average';
                                        $performance_text = 'Average';
                                    } else {
                                        $indicator = 'poor';
                                        $performance_text = 'Needs Attention';
                                    }
                                    ?>
                                    <span class="performance-indicator indicator-<?php echo $indicator; ?>"></span>
                                    <?php echo $performance_text; ?>
                                </td>
                                <td>
                                    <a href="?region=<?php echo urlencode($region['name']); ?>" class="btn btn-sm btn-primary">View Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Top Performing Stores -->
            <div class="rankings-list">
                <div class="rankings-header">
                    <h3><i class="fas fa-trophy"></i> Top Performing Stores</h3>
                    <p>By total sales performance</p>
                </div>
                <?php foreach ($store_rankings as $index => $store): ?>
                    <div class="ranking-item">
                        <div class="ranking-position"><?php echo ($index + 1); ?></div>
                        <div class="ranking-info">
                            <div class="ranking-name"><?php echo htmlspecialchars($store['name']); ?></div>
                            <div class="ranking-region"><?php echo htmlspecialchars($store['region_name']); ?></div>
                        </div>
                        <div class="ranking-value">$<?php echo number_format($store['total_sales'], 0); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        // Chart data preparation
        const regionNames = <?php echo json_encode(array_column($regional_overview, 'name')); ?>;
        const salesData = <?php echo json_encode(array_column($regional_overview, 'total_sales')); ?>;
        const inventoryValues = <?php echo json_encode(array_column($regional_overview, 'total_inventory_value')); ?>;
        const lowStockCounts = <?php echo json_encode(array_column($regional_overview, 'total_low_stock')); ?>;
        const expiredCounts = <?php echo json_encode(array_column($regional_overview, 'total_expired')); ?>;

        // Sales Performance Chart
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        new Chart(salesCtx, {
            type: 'bar',
            data: {
                labels: regionNames,
                datasets: [{
                    label: 'Total Sales ($)',
                    data: salesData,
                    backgroundColor: 'rgba(0, 123, 255, 0.8)',
                    borderColor: 'rgba(0, 123, 255, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Sales by Region'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Inventory Health Chart
        const inventoryCtx = document.getElementById('inventoryChart').getContext('2d');
        new Chart(inventoryCtx, {
            type: 'doughnut',
            data: {
                labels: regionNames,
                datasets: [{
                    label: 'Inventory Value',
                    data: inventoryValues,
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.8)',
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(255, 205, 86, 0.8)',
                        'rgba(75, 192, 192, 0.8)',
                        'rgba(153, 102, 255, 0.8)',
                        'rgba(255, 159, 64, 0.8)'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Inventory Distribution by Region'
                    },
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Export functions
        function exportDashboard() {
            exportToPDF();
        }

        function exportToPDF() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'pdf');
            window.open('api/export_regional_report.php?' + params.toString(), '_blank');
        }

        function exportToExcel() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'excel');
            window.open('api/export_regional_report.php?' + params.toString(), '_blank');
        }

        function scheduleReport() {
            alert('Report scheduling functionality would be implemented here');
        }

        function refreshDashboard() {
            location.reload();
        }

        // Auto-refresh every 10 minutes
        setInterval(refreshDashboard, 600000);

        // Add loading indicators for better UX
        document.addEventListener('DOMContentLoaded', function() {
            // Add any additional initialization here
            console.log('Regional dashboard loaded successfully');
        });
    </script>
</body>
</html>