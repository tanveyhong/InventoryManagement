<?php
// Demand Forecasting Module
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';
require_once 'model.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_view_forecasting')) {
    header('Location: ../../index.php');
    exit;
}

$db = getDB();
$forecasting_model = new ForecastingModel();

// Get parameters
$product_id = isset($_GET['product']) ? intval($_GET['product']) : 0;
$store_id = isset($_GET['store']) ? intval($_GET['store']) : 0;
$forecast_days = isset($_GET['days']) ? intval($_GET['days']) : 30;

// Get products and stores for dropdowns
$products = $db->fetchAll("SELECT id, name, sku FROM products WHERE active = 1 ORDER BY name");
$stores = $db->fetchAll("SELECT id, name FROM stores WHERE active = 1 ORDER BY name");

$forecast_data = null;
$recommendations = [];

if ($product_id > 0) {
    $product = $db->fetch("SELECT * FROM products WHERE id = ? AND active = 1", [$product_id]);
    
    if ($product) {
        // Generate forecast
        $forecast_data = $forecasting_model->generateForecast($product_id, $store_id, $forecast_days);
        
        // Generate recommendations
        $recommendations = $forecasting_model->generateRecommendations($product_id, $store_id, $forecast_data);
    }
}

$page_title = 'Demand Forecasting - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>Demand Forecasting</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="../stores/list.php">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="page-header">
                <h2>Demand Forecasting</h2>
                <p>Predict future demand based on historical sales data and trends</p>
            </div>

            <!-- Forecast Parameters Form -->
            <div class="forecast-form">
                <form method="GET" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="product">Product:</label>
                            <select id="product" name="product" required>
                                <option value="">Select a product</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>" 
                                            <?php echo $product_id == $product['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($product['name'] . ' (' . $product['sku'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="store">Store (Optional):</label>
                            <select id="store" name="store">
                                <option value="">All Stores</option>
                                <?php foreach ($stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>" 
                                            <?php echo $store_id == $store['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($store['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="days">Forecast Period (Days):</label>
                            <select id="days" name="days">
                                <option value="7" <?php echo $forecast_days == 7 ? 'selected' : ''; ?>>7 days</option>
                                <option value="14" <?php echo $forecast_days == 14 ? 'selected' : ''; ?>>14 days</option>
                                <option value="30" <?php echo $forecast_days == 30 ? 'selected' : ''; ?>>30 days</option>
                                <option value="60" <?php echo $forecast_days == 60 ? 'selected' : ''; ?>>60 days</option>
                                <option value="90" <?php echo $forecast_days == 90 ? 'selected' : ''; ?>>90 days</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Generate Forecast</button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($forecast_data): ?>
                <!-- Forecast Results -->
                <div class="forecast-results">
                    <div class="forecast-summary">
                        <h3>Forecast Summary</h3>
                        <div class="summary-cards">
                            <div class="summary-card">
                                <h4>Predicted Demand</h4>
                                <p class="stat-number"><?php echo number_format($forecast_data['total_predicted_demand']); ?></p>
                                <small>Next <?php echo $forecast_days; ?> days</small>
                            </div>
                            <div class="summary-card">
                                <h4>Current Stock</h4>
                                <p class="stat-number"><?php echo number_format($forecast_data['current_stock']); ?></p>
                            </div>
                            <div class="summary-card">
                                <h4>Reorder Point</h4>
                                <p class="stat-number"><?php echo number_format($forecast_data['reorder_point']); ?></p>
                            </div>
                            <div class="summary-card">
                                <h4>Stock Status</h4>
                                <p class="stat-number <?php echo $forecast_data['stock_status']['class']; ?>">
                                    <?php echo $forecast_data['stock_status']['text']; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Forecast Chart -->
                    <div class="forecast-chart">
                        <h3>Demand Forecast Chart</h3>
                        <canvas id="forecastChart" width="800" height="400"></canvas>
                    </div>

                    <!-- Recommendations -->
                    <?php if (!empty($recommendations)): ?>
                        <div class="recommendations">
                            <h3>Recommendations</h3>
                            <div class="recommendation-list">
                                <?php foreach ($recommendations as $rec): ?>
                                    <div class="recommendation-item <?php echo $rec['priority']; ?>">
                                        <div class="rec-icon">
                                            <?php 
                                            switch($rec['type']) {
                                                case 'reorder':
                                                    echo '🛒';
                                                    break;
                                                case 'warning':
                                                    echo '⚠️';
                                                    break;
                                                case 'info':
                                                    echo 'ℹ️';
                                                    break;
                                                default:
                                                    echo '💡';
                                            }
                                            ?>
                                        </div>
                                        <div class="rec-content">
                                            <h4><?php echo htmlspecialchars($rec['title']); ?></h4>
                                            <p><?php echo htmlspecialchars($rec['message']); ?></p>
                                            <?php if (isset($rec['action'])): ?>
                                                <a href="<?php echo $rec['action']['url']; ?>" class="btn btn-sm btn-primary">
                                                    <?php echo htmlspecialchars($rec['action']['text']); ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <script>
                    // Render forecast chart
                    const ctx = document.getElementById('forecastChart').getContext('2d');
                    const chartData = <?php echo json_encode($forecast_data['chart_data']); ?>;
                    
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: chartData.labels,
                            datasets: [{
                                label: 'Historical Sales',
                                data: chartData.historical,
                                borderColor: 'rgb(75, 192, 192)',
                                tension: 0.1,
                                fill: false
                            }, {
                                label: 'Predicted Demand',
                                data: chartData.forecast,
                                borderColor: 'rgb(255, 99, 132)',
                                borderDash: [5, 5],
                                tension: 0.1,
                                fill: false
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                title: {
                                    display: true,
                                    text: 'Demand Forecast'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: 'Quantity'
                                    }
                                },
                                x: {
                                    title: {
                                        display: true,
                                        text: 'Date'
                                    }
                                }
                            }
                        }
                    });
                </script>
            <?php endif; ?>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
</body>
</html>