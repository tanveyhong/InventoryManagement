<?php
// Dashboard / Home Page
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: modules/users/login.php');
    exit;
}

$page_title = 'Dashboard - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Inventory Management System</h1>
            <nav>
                <ul>
                    <li><a href="index.php">Dashboard</a></li>
                    <li><a href="modules/stock/list.php">Stock</a></li>
                    <li><a href="pos_terminal.php">POS Terminal</a></li>
                    <li><a href="sync_dashboard.php">Sync Manager</a></li>
                    <li><a href="modules/stores/list.php">Stores</a></li>
                    <li><a href="modules/reports/dashboard.php">Reports</a></li>
                    <li><a href="modules/alerts/low_stock.php">Alerts</a></li>
                    <li><a href="database/web_explorer.php">Database</a></li>
                    <li><a href="modules/users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <h2>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?>!</h2>
            
            <div class="dashboard-widgets">
                <div class="widget">
                    <h3>Total Products</h3>
                    <p class="stat-number"><?php echo getTotalProducts(); ?></p>
                </div>
                
                <div class="widget">
                    <h3>Low Stock Items</h3>
                    <p class="stat-number alert"><?php echo getLowStockCount(); ?></p>
                </div>
                
                <div class="widget">
                    <h3>Stores</h3>
                    <p class="stat-number"><?php echo getTotalStores(); ?></p>
                </div>
                
                <div class="widget">
                    <h3>Today's Sales</h3>
                    <p class="stat-number">$<?php echo number_format(getTodaysSales(), 2); ?></p>
                </div>
            </div>

            <div class="quick-actions">
                <h3>Quick Actions</h3>
                <div class="action-buttons">
                    <a href="modules/stock/add.php" class="btn btn-primary">Add New Product</a>
                    <a href="pos_terminal.php" class="btn btn-success">POS Terminal (Offline Ready)</a>
                    <a href="sync_dashboard.php" class="btn btn-warning">Hybrid Database Manager</a>
                    <a href="modules/stock/adjust.php" class="btn btn-secondary">Stock Adjustment</a>
                    <a href="modules/reports/stock_report.php" class="btn btn-info">Generate Report</a>
                </div>
            </div>
        </main>
    </div>

    <script src="assets/js/main.js"></script>
</body>
</html>