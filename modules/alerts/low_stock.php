<?php
// Low Stock Alerts Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$db = getDB();
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;

// Get low stock products using materialized view
$sql = "SELECT * FROM mv_low_stock_products ORDER BY category_name, name";
$low_stock_products = $db->fetchAll($sql);

// Pagination
$total_records = count($low_stock_products);
$pagination = paginate($page, $per_page, $total_records);
$products_page = array_slice($low_stock_products, $pagination['offset'], $pagination['per_page']);

$page_title = 'Low Stock Alerts - Inventory System';
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
    <div class="container">
        <header>
            <h1>Low Stock Alerts</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="../stores/list.php">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="low_stock.php" class="active">Alerts</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="page-header">
                <h2>Low Stock Products (<?php echo number_format($total_records); ?>)</h2>
                <div class="page-actions">
                    <button onclick="checkStockLevels()" class="btn btn-primary">Refresh Alerts</button>
                    <a href="expiry_alert.php" class="btn btn-secondary">Expiry Alerts</a>
                </div>
            </div>

            <!-- Alert Summary -->
            <div class="alert-summary" id="alertSummary">
                <div class="summary-card urgent">
                    <h3>Critical (≤5 units)</h3>
                    <p class="stat-number" id="criticalCount">-</p>
                </div>
                <div class="summary-card warning">
                    <h3>Low (≤10 units)</h3>
                    <p class="stat-number" id="lowCount">-</p>
                </div>
                <div class="summary-card info">
                    <h3>Total Products</h3>
                    <p class="stat-number"><?php echo number_format($total_records); ?></p>
                </div>
            </div>

            <?php if (empty($products_page)): ?>
                <div class="no-data">
                    <h3>🎉 Great News!</h3>
                    <p>No products are currently below their minimum stock levels.</p>
                    <a href="../stock/list.php" class="btn btn-primary">View All Products</a>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Current Stock</th>
                                <th>Minimum Level</th>
                                <th>Status</th>
                                <th>Category</th>
                                <th>Store</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products_page as $product): ?>
                                <?php 
                                $urgency = 'warning';
                                if ($product['quantity'] <= 5) $urgency = 'critical';
                                elseif ($product['quantity'] <= 2) $urgency = 'urgent';
                                ?>
                                <tr class="alert-row <?php echo $urgency; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                        <?php if (!empty($product['sku'])): ?>
                                            <br><small>SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="stock-level <?php echo $urgency; ?>">
                                            <?php echo number_format($product['quantity']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($product['min_stock_level']); ?></td>
                                    <td>
                                        <span class="status <?php echo $urgency; ?>">
                                            <?php 
                                            if ($product['quantity'] <= 2) echo 'URGENT';
                                            elseif ($product['quantity'] <= 5) echo 'CRITICAL';
                                            else echo 'LOW';
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td><?php echo htmlspecialchars($product['store_name'] ?? 'No Store'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="../stock/adjust.php?product=<?php echo $product['id']; ?>" 
                                               class="btn btn-sm btn-primary" title="Add Stock">Add Stock</a>
                                            <a href="../forecasting/forecast.php?product=<?php echo $product['id']; ?>" 
                                               class="btn btn-sm btn-info" title="View Forecast">Forecast</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($pagination['has_previous']): ?>
                            <a href="?page=<?php echo $pagination['page'] - 1; ?>" class="btn btn-sm btn-outline">Previous</a>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $pagination['page']; ?> of <?php echo $pagination['total_pages']; ?>
                            (<?php echo number_format($total_records); ?> total products)
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="?page=<?php echo $pagination['page'] + 1; ?>" class="btn btn-sm btn-outline">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Real-time notifications area -->
            <div id="realTimeAlerts"></div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Calculate alert summary
        function calculateSummary() {
            const rows = document.querySelectorAll('.alert-row');
            let criticalCount = 0;
            let lowCount = 0;
            
            rows.forEach(row => {
                const stockLevel = parseInt(row.querySelector('.stock-level').textContent.replace(/,/g, ''));
                if (stockLevel <= 5) criticalCount++;
                if (stockLevel <= 10) lowCount++;
            });
            
            document.getElementById('criticalCount').textContent = criticalCount;
            document.getElementById('lowCount').textContent = lowCount;
        }
        
        // Check stock levels via AJAX
        function checkStockLevels() {
            const button = document.querySelector('button[onclick="checkStockLevels()"]');
            const originalText = button.textContent;
            button.textContent = 'Checking...';
            button.disabled = true;
            
            fetch('api.php?action=check_stock_levels')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        InventoryApp.showNotification(data.message, 'success', 3000);
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        InventoryApp.showNotification('Failed to check stock levels', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    InventoryApp.showNotification('An error occurred', 'error');
                })
                .finally(() => {
                    button.textContent = originalText;
                    button.disabled = false;
                });
        }
        
        // Initialize real-time alerts
        function initRealTimeAlerts() {
            if (typeof(EventSource) !== "undefined") {
                const eventSource = new EventSource('api.php?action=subscribe');
                
                eventSource.onmessage = function(event) {
                    const data = JSON.parse(event.data);
                    
                    if (data.type === 'alert' && data.channel === 'alerts:low_stock') {
                        const alertHtml = `
                            <div class="notification notification-warning">
                                <strong>Low Stock Alert!</strong><br>
                                ${data.data.product_name} is now at ${data.data.current_stock} units
                                <button onclick="this.parentElement.remove()">&times;</button>
                            </div>
                        `;
                        
                        document.getElementById('realTimeAlerts').insertAdjacentHTML('beforeend', alertHtml);
                        
                        // Auto-remove after 10 seconds
                        setTimeout(() => {
                            const notifications = document.querySelectorAll('#realTimeAlerts .notification');
                            if (notifications.length > 0) {
                                notifications[0].remove();
                            }
                        }, 10000);
                    }
                };
                
                eventSource.onerror = function(event) {
                    console.log('EventSource error:', event);
                    eventSource.close();
                };
            }
        }
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateSummary();
            initRealTimeAlerts();
        });
    </script>
    
    <style>
        .alert-summary {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            flex: 1;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }
        
        .summary-card.urgent {
            background-color: #fee;
            border-left: 4px solid #dc3545;
        }
        
        .summary-card.warning {
            background-color: #fff8e1;
            border-left: 4px solid #ffc107;
        }
        
        .summary-card.info {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        
        .alert-row.critical {
            background-color: rgba(220, 53, 69, 0.1);
        }
        
        .alert-row.urgent {
            background-color: rgba(255, 87, 34, 0.1);
        }
        
        .alert-row.warning {
            background-color: rgba(255, 193, 7, 0.1);
        }
        
        .stock-level.critical {
            color: #dc3545;
            font-weight: bold;
        }
        
        .stock-level.urgent {
            color: #ff5722;
            font-weight: bold;
        }
        
        .stock-level.warning {
            color: #ff9800;
            font-weight: bold;
        }
        
        .status.critical,
        .status.urgent {
            background-color: #dc3545;
            color: white;
        }
        
        .status.warning {
            background-color: #ffc107;
            color: #212529;
        }
        
        #realTimeAlerts {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 400px;
        }
    </style>
</body>
</html>