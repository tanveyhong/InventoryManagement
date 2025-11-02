<?php
/**
 * Demand Forecasting - Main Interface
 */

require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
require_once 'DemandForecast.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$db = SQLDatabase::getInstance();
$forecaster = new DemandForecast();

// Get parameters
$product_id = isset($_GET['product']) ? intval($_GET['product']) : 0;
$store_id = isset($_GET['store']) ? intval($_GET['store']) : 0;
$forecast_days = isset($_GET['days']) ? intval($_GET['days']) : 30;

// Get products and stores for filters
$products = $db->fetchAll("SELECT id, name, sku, category FROM products WHERE active = true ORDER BY name");
$stores = $db->fetchAll("SELECT id, name FROM stores WHERE active = true ORDER BY name");

// Generate forecast if product selected
$forecast = null;
$product_info = null;

if ($product_id > 0) {
    $product_info = $db->fetch("SELECT * FROM products WHERE id = ? AND active = true", [$product_id]);
    
    if ($product_info) {
        $forecast = $forecaster->forecast($product_id, $store_id ?: null, $forecast_days);
    }
}

$page_title = 'Demand Forecasting';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Inventory System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .forecast-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .page-header h1 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 36px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-header h1 i {
            color: #667eea;
        }
        
        .page-header p {
            margin: 5px 0 0 50px;
            color: #718096;
            font-size: 17px;
        }
        
        .filter-section {
            background: white;
            padding: 35px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 30px;
            border: 1px solid #e5e7eb;
        }

        .filter-section h3 {
            margin: 0 0 25px 0;
            color: #1f2937;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-section h3 i {
            color: #667eea;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1.5fr 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 10px;
            color: #374151;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group label i {
            margin-right: 8px;
            color: #667eea;
        }
        
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s;
            background: white;
            color: #1f2937;
        }

        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        /* Select2 Customization */
        .select2-container--default .select2-selection--single {
            height: 50px !important;
            border: 2px solid #e5e7eb !important;
            border-radius: 10px !important;
            padding: 8px 16px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px !important;
            color: #1f2937 !important;
            font-size: 15px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 48px !important;
            right: 10px !important;
        }

        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #667eea !important;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1) !important;
        }

        .select2-dropdown {
            border: 2px solid #667eea !important;
            border-radius: 10px !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1) !important;
        }

        .select2-search--dropdown .select2-search__field {
            border: 2px solid #e5e7eb !important;
            border-radius: 8px !important;
            padding: 10px !important;
            font-size: 14px !important;
        }

        .select2-search--dropdown .select2-search__field:focus {
            border-color: #667eea !important;
            outline: none !important;
        }

        .select2-results__option {
            padding: 12px 16px !important;
            font-size: 14px !important;
        }

        .select2-results__option--highlighted {
            background: #667eea !important;
        }
        
        .btn-generate {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 14px 35px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            font-size: 16px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            width: 100%;
            justify-content: center;
        }
        
        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
        }

        .btn-generate:active {
            transform: translateY(0);
        }

        .btn-generate i {
            font-size: 18px;
        }

        .quick-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding: 20px;
            background: #f9fafb;
            border-radius: 12px;
            border: 1px dashed #d1d5db;
        }

        .quick-filter-label {
            font-weight: 600;
            color: #6b7280;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            margin-right: 10px;
        }

        .quick-filter-btn {
            padding: 8px 16px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 20px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s;
            color: #6b7280;
        }

        .quick-filter-btn:hover,
        .quick-filter-btn.active {
            border-color: #667eea;
            background: #667eea;
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 4px solid #667eea;
        }
        
        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 13px;
            color: #9ca3af;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .status-badge.success { background: #d1fae5; color: #065f46; }
        .status-badge.warning { background: #fef3c7; color: #92400e; }
        .status-badge.danger { background: #fee2e2; color: #991b1b; }
        .status-badge.info { background: #dbeafe; color: #1e40af; }
        
        .chart-container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .chart-container h2 {
            margin: 0 0 20px 0;
            color: #1f2937;
        }
        
        .recommendations {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .recommendations h2 {
            margin: 0 0 20px 0;
            color: #1f2937;
        }
        
        .recommendation-item {
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            gap: 15px;
            align-items: start;
        }
        
        .recommendation-item.critical { background: #fee2e2; border-left: 4px solid #dc2626; }
        .recommendation-item.high { background: #fef3c7; border-left: 4px solid #f59e0b; }
        .recommendation-item.medium { background: #dbeafe; border-left: 4px solid #3b82f6; }
        .recommendation-item.low { background: #e0e7ff; border-left: 4px solid #6366f1; }
        .recommendation-item.success { background: #d1fae5; border-left: 4px solid #10b981; }
        .recommendation-item.info { background: #f3f4f6; border-left: 4px solid #6b7280; }
        
        .rec-icon {
            font-size: 24px;
        }
        
        .rec-content {
            flex: 1;
        }
        
        .rec-content h4 {
            margin: 0 0 8px 0;
            color: #1f2937;
            font-size: 16px;
        }
        
        .rec-content p {
            margin: 0;
            color: #4b5563;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .empty-state i {
            font-size: 80px;
            margin-bottom: 25px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .empty-state h3 {
            color: #1f2937;
            font-size: 24px;
            margin: 0 0 15px 0;
            font-weight: 700;
        }

        .empty-state p {
            font-size: 16px;
            margin: 0 0 30px 0;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.6;
        }

        .empty-state-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            max-width: 800px;
            margin: 0 auto;
            text-align: left;
        }

        .empty-state-feature {
            padding: 20px;
            background: #f9fafb;
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }

        .empty-state-feature i {
            font-size: 24px;
            margin-bottom: 10px;
        }

        .empty-state-feature h4 {
            margin: 0 0 8px 0;
            color: #1f2937;
            font-size: 16px;
        }

        .empty-state-feature p {
            margin: 0;
            font-size: 13px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="forecast-container">
        <div class="page-header">
            <h1><i class="fas fa-chart-line"></i> Demand Forecasting</h1>
            <p>Predict future demand and optimize inventory levels</p>
        </div>
        
        <!-- Filters -->
        <div class="filter-section">
            <h3><i class="fas fa-sliders-h"></i> Forecast Configuration</h3>
            
            <!-- Quick Filters for Forecast Period -->
            <div class="quick-filters">
                <span class="quick-filter-label"><i class="fas fa-clock"></i> Quick Period:</span>
                <button type="button" class="quick-filter-btn" data-days="7">1 Week</button>
                <button type="button" class="quick-filter-btn" data-days="14">2 Weeks</button>
                <button type="button" class="quick-filter-btn active" data-days="30">1 Month</button>
                <button type="button" class="quick-filter-btn" data-days="60">2 Months</button>
                <button type="button" class="quick-filter-btn" data-days="90">3 Months</button>
            </div>

            <form method="GET" action="" id="forecastForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label for="product">
                            <i class="fas fa-box"></i> Select Product
                        </label>
                        <select id="product" name="product" class="product-select" required>
                            <option value="">🔍 Search for a product...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo $product_id == $p['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($p['name']); ?> 
                                    <?php if ($p['sku']): ?>
                                        (SKU: <?php echo htmlspecialchars($p['sku']); ?>)
                                    <?php endif; ?>
                                    <?php if ($p['category']): ?>
                                        - <?php echo htmlspecialchars($p['category']); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #6b7280; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Type to search by name, SKU, or category
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="store">
                            <i class="fas fa-store"></i> Store Location
                        </label>
                        <select id="store" name="store" class="store-select">
                            <option value="0">📊 All Stores Combined</option>
                            <?php foreach ($stores as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo $store_id == $s['id'] ? 'selected' : ''; ?>>
                                    📍 <?php echo htmlspecialchars($s['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #6b7280; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> Optional: Filter by specific store
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="days">
                            <i class="fas fa-calendar-alt"></i> Forecast Period
                        </label>
                        <select id="days" name="days">
                            <option value="7" <?php echo $forecast_days == 7 ? 'selected' : ''; ?>>📅 7 Days</option>
                            <option value="14" <?php echo $forecast_days == 14 ? 'selected' : ''; ?>>📅 14 Days</option>
                            <option value="30" <?php echo $forecast_days == 30 ? 'selected' : ''; ?>>📅 30 Days</option>
                            <option value="60" <?php echo $forecast_days == 60 ? 'selected' : ''; ?>>📅 60 Days</option>
                            <option value="90" <?php echo $forecast_days == 90 ? 'selected' : ''; ?>>📅 90 Days</option>
                        </select>
                        <small style="color: #6b7280; margin-top: 5px; display: block;">
                            <i class="fas fa-info-circle"></i> How far into the future?
                        </small>
                    </div>
                </div>

                <div style="display: flex; gap: 15px; align-items: center;">
                    <button type="submit" class="btn-generate">
                        <i class="fas fa-magic"></i> Generate Forecast
                    </button>
                    <?php if ($product_id > 0): ?>
                        <a href="index.php" style="color: #6b7280; text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-redo"></i> Reset & Start Over
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <script>
            // Initialize Select2 for searchable dropdowns
            $(document).ready(function() {
                $('.product-select').select2({
                    placeholder: '🔍 Search for a product by name, SKU, or category...',
                    allowClear: true,
                    width: '100%',
                    theme: 'default'
                });

                $('.store-select').select2({
                    placeholder: '📍 Select a store (optional)',
                    allowClear: false,
                    width: '100%',
                    theme: 'default'
                });

                // Quick filter buttons
                $('.quick-filter-btn').on('click', function() {
                    const days = $(this).data('days');
                    $('#days').val(days);
                    
                    // Update active state
                    $('.quick-filter-btn').removeClass('active');
                    $(this).addClass('active');
                });

                // Sync dropdown with quick filters
                $('#days').on('change', function() {
                    const selectedDays = $(this).val();
                    $('.quick-filter-btn').removeClass('active');
                    $(`.quick-filter-btn[data-days="${selectedDays}"]`).addClass('active');
                });
            });
        </script>
        
        <?php if ($forecast): ?>
            <!-- Stats Summary -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-box"></i> Current Stock</h3>
                    <div class="stat-value"><?php echo number_format($forecast['current_stock']); ?></div>
                    <div class="stat-label">
                        <span class="status-badge <?php echo $forecast['stock_status']['class']; ?>">
                            <?php echo $forecast['stock_status']['label']; ?>
                        </span>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-chart-bar"></i> Predicted Demand</h3>
                    <div class="stat-value"><?php echo number_format($forecast['total_predicted_demand']); ?></div>
                    <div class="stat-label">
                        next <?php echo $forecast_days; ?> days
                        <?php if (isset($forecast['method_used'])): ?>
                            <br><small style="opacity: 0.7;">Method: <?php echo ucwords(str_replace('_', ' ', $forecast['method_used'])); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-sync"></i> Reorder Point</h3>
                    <div class="stat-value"><?php echo number_format($forecast['reorder_point']); ?></div>
                    <div class="stat-label">smart trigger level</div>
                </div>
                
                <div class="stat-card">
                    <h3><i class="fas fa-check-circle"></i> Forecast Quality</h3>
                    <div class="stat-value">
                        <span class="status-badge <?php echo $forecast['confidence_level'] >= 70 ? 'success' : ($forecast['confidence_level'] >= 50 ? 'warning' : 'danger'); ?>">
                            <?php echo $forecast['confidence_level']; ?>%
                        </span>
                    </div>
                    <div class="stat-label">
                        <?php if (isset($forecast['forecast_accuracy']) && $forecast['forecast_accuracy'] > 0): ?>
                            Accuracy: <?php echo round($forecast['forecast_accuracy']); ?>% |
                        <?php endif; ?>
                        Trend: <?php echo ucfirst($forecast['trend']); ?>
                        <?php if (isset($forecast['seasonality']['detected']) && $forecast['seasonality']['detected']): ?>
                            <br><small style="opacity: 0.7;">📊 Seasonality: <?php echo $forecast['seasonality']['strength']; ?>%</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
                    </div>
                    <div class="stat-label">
                        <?php echo $forecast['confidence_level']; ?>% confidence
                        <?php if ($forecast['trend'] != 'unknown'): ?>
                            | Trend: <?php echo ucfirst($forecast['trend']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Chart -->
            <div class="chart-container">
                <h2><i class="fas fa-chart-area"></i> Demand Forecast Chart</h2>
                <canvas id="forecastChart" height="100"></canvas>
            </div>
            
            <!-- Recommendations -->
            <div class="recommendations">
                <h2><i class="fas fa-lightbulb"></i> Recommendations</h2>
                <?php foreach ($forecast['recommendations'] as $rec): ?>
                    <div class="recommendation-item <?php echo $rec['type']; ?>">
                        <div class="rec-icon"><?php echo $rec['icon']; ?></div>
                        <div class="rec-content">
                            <h4><?php echo htmlspecialchars($rec['title']); ?></h4>
                            <p><?php echo htmlspecialchars($rec['message']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <script>
                // Render Chart with Confidence Intervals
                const ctx = document.getElementById('forecastChart').getContext('2d');
                const chartData = <?php echo json_encode($forecast['chart_data']); ?>;
                
                const datasets = [
                    {
                        label: 'Historical Sales',
                        data: chartData.historical,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        order: 2
                    },
                    {
                        label: 'Predicted Demand',
                        data: chartData.forecast,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        borderWidth: 3,
                        borderDash: [5, 5],
                        tension: 0.4,
                        fill: true,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        order: 1
                    }
                ];
                
                // Add confidence interval bands if available
                if (chartData.upper_bound && chartData.lower_bound) {
                    datasets.push({
                        label: 'Upper Bound (95% CI)',
                        data: chartData.upper_bound,
                        borderColor: 'rgba(239, 68, 68, 0.3)',
                        backgroundColor: 'rgba(239, 68, 68, 0.05)',
                        borderWidth: 1,
                        borderDash: [2, 2],
                        tension: 0.4,
                        fill: false,
                        pointRadius: 0,
                        order: 3
                    });
                    
                    datasets.push({
                        label: 'Lower Bound (95% CI)',
                        data: chartData.lower_bound,
                        borderColor: 'rgba(59, 130, 246, 0.3)',
                        backgroundColor: 'rgba(59, 130, 246, 0.05)',
                        borderWidth: 1,
                        borderDash: [2, 2],
                        tension: 0.4,
                        fill: false,
                        pointRadius: 0,
                        order: 3
                    });
                }
                
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: chartData.labels,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                display: true,
                                position: 'top'
                            },
                            tooltip: {
                                mode: 'index',
                                intersect: false
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
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-chart-line"></i>
                <h3>Ready to Forecast Demand?</h3>
                <p>Select a product from the dropdown above to generate an intelligent demand forecast based on historical sales data and advanced algorithms.</p>
                
                <div class="empty-state-features">
                    <div class="empty-state-feature">
                        <i class="fas fa-robot"></i>
                        <h4>Smart Algorithms</h4>
                        <p>5 forecasting methods compete, best one wins automatically</p>
                    </div>
                    <div class="empty-state-feature">
                        <i class="fas fa-chart-area"></i>
                        <h4>Seasonality Detection</h4>
                        <p>Identifies weekly patterns and adjusts predictions</p>
                    </div>
                    <div class="empty-state-feature">
                        <i class="fas fa-bullseye"></i>
                        <h4>Confidence Intervals</h4>
                        <p>Shows upper/lower bounds with 95% confidence</p>
                    </div>
                    <div class="empty-state-feature">
                        <i class="fas fa-bell"></i>
                        <h4>Smart Alerts</h4>
                        <p>Automatic reorder recommendations and warnings</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
