<?php
/**
 * POS Sales Dashboard
 * Real-time sales monitoring and analytics
 */

require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

$db = getSQLDB();
$userId = $_SESSION['user_id'];

// Get today's sales stats
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');

$todaySales = 0;
$todayTransactions = 0;
$todayItems = 0;

try {
    $stats = $db->fetch("
        SELECT 
            COUNT(DISTINCT s.id) as transaction_count,
            COALESCE(SUM(s.total), 0) as total_sales,
            COALESCE(SUM(si.quantity), 0) as total_items
        FROM sales s
        LEFT JOIN sale_items si ON s.id = si.sale_id
        WHERE s.sale_date BETWEEN ? AND ?
    ", [$todayStart, $todayEnd]);
    
    $todaySales = $stats['total_sales'] ?? 0;
    $todayTransactions = $stats['transaction_count'] ?? 0;
    $todayItems = $stats['total_items'] ?? 0;
} catch (Exception $e) {
    error_log("Error fetching stats: " . $e->getMessage());
}

// Get recent transactions
$recentTransactions = [];
try {
    $recentTransactions = $db->fetchAll("
        SELECT 
            s.*,
            u.username as cashier_name,
            COUNT(si.id) as item_count
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        LEFT JOIN sale_items si ON s.id = si.sale_id
        GROUP BY s.id
        ORDER BY s.created_at DESC
        LIMIT 20
    ");
} catch (Exception $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Sales Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        .page-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn-primary {
            background: white;
            color: #667eea;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255,255,255,0.3);
        }
        
        .btn-secondary {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        
        .btn-secondary:hover {
            background: rgba(255,255,255,0.3);
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid;
        }
        
        .stat-card.primary {
            border-color: #667eea;
        }
        
        .stat-card.success {
            border-color: #27ae60;
        }
        
        .stat-card.warning {
            border-color: #f39c12;
        }
        
        .stat-card.info {
            border-color: #3498db;
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
            font-weight: 600;
        }
        
        .stat-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .stat-icon.primary { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-icon.success { background: linear-gradient(135deg, #27ae60, #229954); }
        .stat-icon.warning { background: linear-gradient(135deg, #f39c12, #e67e22); }
        .stat-icon.info { background: linear-gradient(135deg, #3498db, #2980b9); }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .transactions-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #ecf0f1;
        }
        
        .section-header h2 {
            font-size: 22px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transactions-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .transactions-table td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .transactions-table tr:hover {
            background: #f8f9fa;
        }
        
        .transaction-id {
            font-weight: 600;
            color: #667eea;
        }
        
        .payment-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .payment-cash {
            background: #d4edda;
            color: #155724;
        }
        
        .payment-card {
            background: #cce5ff;
            color: #004085;
        }
        
        .payment-digital {
            background: #fff3cd;
            color: #856404;
        }
        
        .payment-other {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .amount {
            font-weight: 700;
            color: #27ae60;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .stat-card, .transactions-section {
            animation: fadeInUp 0.5s ease;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-chart-line"></i> POS Sales Dashboard</h1>
            <p>Real-time sales monitoring and analytics</p>
            <div class="header-actions">
                <a href="quick_service.php" class="btn btn-primary">
                    <i class="fas fa-bolt"></i> Quick Service POS
                </a>
                <a href="full_retail.php" class="btn btn-primary">
                    <i class="fas fa-cash-register"></i> Full Retail POS
                </a>
                <a href="../../index.php" class="btn btn-secondary">
                    <i class="fas fa-home"></i> Dashboard
                </a>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-header">
                    <span class="stat-label">Today's Sales</span>
                    <div class="stat-icon primary">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                </div>
                <div class="stat-value">$<?php echo number_format($todaySales, 2); ?></div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-header">
                    <span class="stat-label">Transactions</span>
                    <div class="stat-icon success">
                        <i class="fas fa-receipt"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $todayTransactions; ?></div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-header">
                    <span class="stat-label">Items Sold</span>
                    <div class="stat-icon warning">
                        <i class="fas fa-box"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $todayItems; ?></div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-header">
                    <span class="stat-label">Average Sale</span>
                    <div class="stat-icon info">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                </div>
                <div class="stat-value">
                    $<?php echo $todayTransactions > 0 ? number_format($todaySales / $todayTransactions, 2) : '0.00'; ?>
                </div>
            </div>
        </div>
        
        <div class="transactions-section">
            <div class="section-header">
                <h2><i class="fas fa-list"></i> Recent Transactions</h2>
                <button class="btn btn-primary" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
            
            <?php if (empty($recentTransactions)): ?>
                <div style="text-align: center; padding: 60px 20px; color: #7f8c8d;">
                    <i class="fas fa-receipt" style="font-size: 60px; margin-bottom: 15px; opacity: 0.3;"></i>
                    <p><strong>No transactions yet</strong><br>Start using the POS system to see transactions here</p>
                </div>
            <?php else: ?>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>Transaction ID</th>
                            <th>Date & Time</th>
                            <th>Cashier</th>
                            <th>Items</th>
                            <th>Payment</th>
                            <th>Customer</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td>
                                    <span class="transaction-id">
                                        <?php echo htmlspecialchars($transaction['transaction_id']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date('M d, Y H:i', strtotime($transaction['sale_date'])); ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($transaction['cashier_name'] ?? 'N/A'); ?>
                                </td>
                                <td>
                                    <?php echo $transaction['item_count']; ?> items
                                </td>
                                <td>
                                    <?php
                                    $method = $transaction['payment_method'];
                                    $badgeClass = 'payment-' . $method;
                                    ?>
                                    <span class="payment-badge <?php echo $badgeClass; ?>">
                                        <?php echo strtoupper($method); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($transaction['customer_name'] ?? '-'); ?>
                                </td>
                                <td>
                                    <span class="amount">
                                        $<?php echo number_format($transaction['total'], 2); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Auto-refresh every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
