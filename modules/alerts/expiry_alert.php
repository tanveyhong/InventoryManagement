<?php
// Expiry Alerts Page
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

// Get expiring products using materialized view
$sql = "SELECT * FROM mv_expiring_products ORDER BY days_to_expiry ASC, name ASC";
$expiring_products = $db->fetchAll($sql);

// Pagination
$total_records = count($expiring_products);
$pagination = paginate($page, $per_page, $total_records);
$products_page = array_slice($expiring_products, $pagination['offset'], $pagination['per_page']);

$page_title = 'Expiry Alerts - Inventory System';
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
            <h1>Expiry Alerts</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="../stores/list.php">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="low_stock.php">Low Stock</a></li>
                    <li><a href="expiry_alert.php" class="active">Expiry</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="page-header">
                <h2>Products Nearing Expiry (<?php echo number_format($total_records); ?>)</h2>
                <div class="page-actions">
                    <button onclick="checkExpiryDates()" class="btn btn-primary">Refresh Alerts</button>
                    <a href="low_stock.php" class="btn btn-secondary">Low Stock Alerts</a>
                </div>
            </div>

            <!-- Alert Summary -->
            <div class="alert-summary" id="alertSummary">
                <div class="summary-card expired">
                    <h3>Expired</h3>
                    <p class="stat-number" id="expiredCount">-</p>
                </div>
                <div class="summary-card urgent">
                    <h3>Expiring Today</h3>
                    <p class="stat-number" id="todayCount">-</p>
                </div>
                <div class="summary-card warning">
                    <h3>Next 7 Days</h3>
                    <p class="stat-number" id="weekCount">-</p>
                </div>
                <div class="summary-card info">
                    <h3>Next 30 Days</h3>
                    <p class="stat-number" id="monthCount">-</p>
                </div>
            </div>

            <?php if (empty($products_page)): ?>
                <div class="no-data">
                    <h3>✅ All Clear!</h3>
                    <p>No products are nearing expiry within the alert timeframe.</p>
                    <a href="../stock/list.php" class="btn btn-primary">View All Products</a>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Expiry Date</th>
                                <th>Days Left</th>
                                <th>Current Stock</th>
                                <th>Status</th>
                                <th>Category</th>
                                <th>Store</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products_page as $product): ?>
                                <?php 
                                $days_left = $product['days_to_expiry'];
                                $urgency = 'info';
                                
                                if ($days_left < 0) $urgency = 'expired';
                                elseif ($days_left == 0) $urgency = 'urgent';
                                elseif ($days_left <= 7) $urgency = 'critical';
                                elseif ($days_left <= 30) $urgency = 'warning';
                                ?>
                                <tr class="alert-row <?php echo $urgency; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                        <?php if (!empty($product['sku'])): ?>
                                            <br><small>SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo date('M j, Y', strtotime($product['expiry_date'])); ?>
                                    </td>
                                    <td>
                                        <span class="days-left <?php echo $urgency; ?>">
                                            <?php 
                                            if ($days_left < 0) {
                                                echo abs($days_left) . ' days ago';
                                            } elseif ($days_left == 0) {
                                                echo 'Today';
                                            } else {
                                                echo $days_left . ' days';
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo number_format($product['quantity']); ?></td>
                                    <td>
                                        <span class="status <?php echo $urgency; ?>">
                                            <?php 
                                            if ($days_left < 0) echo 'EXPIRED';
                                            elseif ($days_left == 0) echo 'EXPIRES TODAY';
                                            elseif ($days_left <= 7) echo 'CRITICAL';
                                            elseif ($days_left <= 30) echo 'WARNING';
                                            else echo 'WATCH';
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td><?php echo htmlspecialchars($product['store_name'] ?? 'No Store'); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($days_left < 0 || $days_left <= 7): ?>
                                                <a href="../stock/dispose.php?product=<?php echo $product['id']; ?>" 
                                                   class="btn btn-sm btn-danger" title="Mark as Disposed">Dispose</a>
                                            <?php endif; ?>
                                            <a href="../stock/adjust.php?product=<?php echo $product['id']; ?>" 
                                               class="btn btn-sm btn-warning" title="Adjust Stock">Adjust</a>
                                            <a href="../promotions/create.php?product=<?php echo $product['id']; ?>" 
                                               class="btn btn-sm btn-success" title="Create Promotion">Promote</a>
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
            let expiredCount = 0;
            let todayCount = 0;
            let weekCount = 0;
            let monthCount = 0;
            
            rows.forEach(row => {
                const daysLeftText = row.querySelector('.days-left').textContent.toLowerCase();
                
                if (daysLeftText.includes('ago')) expiredCount++;
                else if (daysLeftText.includes('today')) todayCount++;
                else {
                    const days = parseInt(daysLeftText);
                    if (days <= 7) weekCount++;
                    if (days <= 30) monthCount++;
                }
            });
            
            document.getElementById('expiredCount').textContent = expiredCount;
            document.getElementById('todayCount').textContent = todayCount;
            document.getElementById('weekCount').textContent = weekCount;
            document.getElementById('monthCount').textContent = monthCount;
        }
        
        // Check expiry dates via AJAX
        function checkExpiryDates() {
            const button = document.querySelector('button[onclick="checkExpiryDates()"]');
            const originalText = button.textContent;
            button.textContent = 'Checking...';
            button.disabled = true;
            
            fetch('api.php?action=check_expiry_dates')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        InventoryApp.showNotification(data.message, 'success', 3000);
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        InventoryApp.showNotification('Failed to check expiry dates', 'error');
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
                    
                    if (data.type === 'alert' && data.channel === 'alerts:expiry') {
                        const alertHtml = `
                            <div class="notification notification-danger">
                                <strong>Expiry Alert!</strong><br>
                                ${data.data.product_name} expires in ${data.data.days_to_expiry} days
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
        
        .summary-card.expired {
            background-color: #ffebee;
            border-left: 4px solid #f44336;
        }
        
        .summary-card.urgent {
            background-color: #fff3e0;
            border-left: 4px solid #ff9800;
        }
        
        .summary-card.warning {
            background-color: #fff8e1;
            border-left: 4px solid #ffc107;
        }
        
        .summary-card.info {
            background-color: #e3f2fd;
            border-left: 4px solid #2196f3;
        }
        
        .alert-row.expired {
            background-color: rgba(244, 67, 54, 0.1);
        }
        
        .alert-row.urgent {
            background-color: rgba(255, 152, 0, 0.1);
        }
        
        .alert-row.critical {
            background-color: rgba(220, 53, 69, 0.1);
        }
        
        .alert-row.warning {
            background-color: rgba(255, 193, 7, 0.1);
        }
        
        .days-left.expired {
            color: #f44336;
            font-weight: bold;
        }
        
        .days-left.urgent {
            color: #ff9800;
            font-weight: bold;
        }
        
        .days-left.critical {
            color: #dc3545;
            font-weight: bold;
        }
        
        .days-left.warning {
            color: #ff9800;
            font-weight: bold;
        }
        
        .status.expired {
            background-color: #f44336;
            color: white;
        }
        
        .status.urgent {
            background-color: #ff9800;
            color: white;
        }
        
        .status.critical {
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