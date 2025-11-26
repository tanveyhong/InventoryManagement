<?php
/**
 * Payment Data Report - User Transaction History
 * Shows payment details for the logged-in user (cashier)
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
$username = $_SESSION['username'] ?? $_SESSION['email'] ?? 'User';

// Date filter
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$payment_method_filter = $_GET['payment_method'] ?? 'all';

// Build query - check both cashier_id and user_id for backwards compatibility
$where_conditions = ["(s.cashier_id = ? OR (s.cashier_id IS NULL AND s.user_id = ?))"];
$params = [$userId, $userId];

if ($date_from) {
    $where_conditions[] = "DATE(s.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "DATE(s.created_at) <= ?";
    $params[] = $date_to;
}

if ($payment_method_filter !== 'all') {
    $where_conditions[] = "s.payment_method = ?";
    $params[] = $payment_method_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Get transactions
$transactions = [];
try {
    // Use STRING_AGG for PostgreSQL compatibility instead of GROUP_CONCAT
    $transactions = $db->fetchAll("
        SELECT 
            s.*,
            COUNT(si.id) as item_count,
            STRING_AGG(COALESCE(p.name, 'Unknown Item') || ' (x' || CAST(si.quantity AS TEXT) || ')', ', ') as item_details
        FROM sales s
        LEFT JOIN sale_items si ON s.id = si.sale_id
        LEFT JOIN products p ON si.product_id = p.id
        WHERE $where_clause
        GROUP BY s.id
        ORDER BY s.created_at DESC
    ", $params);
} catch (Exception $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}

// Calculate summary
$total_sales = 0;
$total_cash = 0;
$total_card = 0;
$total_ewallet = 0;
$transaction_count = 0;

foreach ($transactions as $txn) {
    $total_sales += $txn['total_amount'];
    $transaction_count++;
    
    switch ($txn['payment_method']) {
        case 'cash':
            $total_cash += $txn['total_amount'];
            break;
        case 'card':
            $total_card += $txn['total_amount'];
            break;
        case 'ewallet':
            $total_ewallet += $txn['total_amount'];
            break;
    }
}

$page_title = "My Payment Data";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            margin: 0;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .header h1 {
            margin: 0 0 10px 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .summary-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            border-left: 4px solid;
        }
        
        .summary-card.total { border-left-color: #3498db; }
        .summary-card.cash { border-left-color: #27ae60; }
        .summary-card.card { border-left-color: #9b59b6; }
        .summary-card.ewallet { border-left-color: #e67e22; }
        
        .summary-card h3 {
            margin: 0 0 8px 0;
            font-size: 14px;
            color: #7f8c8d;
            font-weight: 600;
        }
        
        .summary-card .amount {
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .summary-card .count {
            font-size: 14px;
            color: #95a5a6;
            margin-top: 8px;
        }
        
        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .filters form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)) auto;
            gap: 15px;
            align-items: end;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-group button {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .transactions-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #34495e;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge.cash { background: #d5f4e6; color: #27ae60; }
        .badge.card { background: #ebdef0; color: #9b59b6; }
        .badge.ewallet { background: #fdebd0; color: #e67e22; }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3498db;
            text-decoration: none;
            font-weight: 600;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="terminal.php?store_id=<?php echo $_SESSION['pos_store_id'] ?? ''; ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to POS Terminal
        </a>
        
        <div class="header">
            <h1><i class="fas fa-receipt"></i> My Payment Data</h1>
            <p style="margin: 0; color: #7f8c8d;">Cashier: <strong><?php echo htmlspecialchars($username); ?></strong></p>
        </div>
        
        <div class="summary-cards">
            <div class="summary-card total">
                <h3><i class="fas fa-chart-line"></i> Total Sales</h3>
                <div class="amount">RM <?php echo number_format($total_sales, 2); ?></div>
                <div class="count"><?php echo $transaction_count; ?> transactions</div>
            </div>
            
            <div class="summary-card cash">
                <h3><i class="fas fa-money-bill-wave"></i> Cash Payments</h3>
                <div class="amount">RM <?php echo number_format($total_cash, 2); ?></div>
            </div>
            
            <div class="summary-card card">
                <h3><i class="fas fa-credit-card"></i> Card Payments</h3>
                <div class="amount">RM <?php echo number_format($total_card, 2); ?></div>
            </div>
            
            <div class="summary-card ewallet">
                <h3><i class="fas fa-mobile-alt"></i> E-Wallet Payments</h3>
                <div class="amount">RM <?php echo number_format($total_ewallet, 2); ?></div>
            </div>
        </div>
        
        <div class="filters">
            <form method="GET">
                <div class="filter-group">
                    <label>From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                
                <div class="filter-group">
                    <label>To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                
                <div class="filter-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="all" <?php echo $payment_method_filter === 'all' ? 'selected' : ''; ?>>All Methods</option>
                        <option value="cash" <?php echo $payment_method_filter === 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="card" <?php echo $payment_method_filter === 'card' ? 'selected' : ''; ?>>Card</option>
                        <option value="ewallet" <?php echo $payment_method_filter === 'ewallet' ? 'selected' : ''; ?>>E-Wallet</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <button type="submit"><i class="fas fa-filter"></i> Apply Filters</button>
                </div>
            </form>
        </div>
        
        <div class="transactions-table">
            <?php if (empty($transactions)): ?>
                <div class="no-data">
                    <i class="fas fa-receipt" style="font-size: 64px; margin-bottom: 20px; opacity: 0.3;"></i>
                    <h3>No Transactions Found</h3>
                    <p>No sales data available for the selected period.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Sale #</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Payment Method</th>
                            <th>Reference</th>
                            <th>Amount</th>
                            <th>Change</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $txn): ?>
                            <tr>
                                <td><?php echo date('M d, Y h:i A', strtotime($txn['created_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($txn['sale_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($txn['customer_name'] ?? 'Walk-in'); ?></td>
                                <td>
                                    <div style="font-size: 14px; color: #2c3e50; max-width: 250px; line-height: 1.4;">
                                        <?php 
                                        $details = $txn['item_details'] ?? '';
                                        if (strlen($details) > 50) {
                                            echo htmlspecialchars(substr($details, 0, 50)) . '...';
                                            echo '<div style="font-size: 11px; color: #3498db; cursor: help;" title="' . htmlspecialchars($details) . '">Hover for full list</div>';
                                        } else {
                                            echo htmlspecialchars($details);
                                        }
                                        ?>
                                    </div>
                                    <div style="font-size: 11px; color: #95a5a6; margin-top: 2px;">
                                        <?php echo intval($txn['item_count']); ?> items total
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?php echo $txn['payment_method']; ?>">
                                        <?php 
                                        $icons = ['cash' => 'money-bill-wave', 'card' => 'credit-card', 'ewallet' => 'mobile-alt'];
                                        echo '<i class="fas fa-' . $icons[$txn['payment_method']] . '"></i> ';
                                        echo ucfirst($txn['payment_method']); 
                                        ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($txn['payment_reference'] ?? '-'); ?></td>
                                <td><strong>RM <?php echo number_format($txn['total_amount'], 2); ?></strong></td>
                                <td>
                                    <?php 
                                    if ($txn['payment_method'] === 'cash' && $txn['payment_change'] > 0) {
                                        echo 'RM ' . number_format($txn['payment_change'], 2);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
