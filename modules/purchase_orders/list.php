<?php
// modules/purchase_orders/list.php
require_once '../../config.php';
session_start();
require_once '../../functions.php';
require_once '../../sql_db.php';
require_once '../../activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_manage_purchase_orders')) {
    header('Location: ../../index.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Fetch POs with supplier name
$sql = "
    SELECT po.*, s.name as supplier_name, st.name as store_name, u.username as created_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN stores st ON po.store_id = st.id
    LEFT JOIN users u ON po.created_by = u.id
    ORDER BY po.created_at DESC
    LIMIT ? OFFSET ?
";
$pos = $sqlDb->fetchAll($sql, [$per_page, $offset]);

// Count total
$countResult = $sqlDb->fetch("SELECT COUNT(*) as total FROM purchase_orders");
$total_records = $countResult['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Fetch Summary Stats
$statsSql = "
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status IN ('draft', 'ordered', 'partial') THEN 1 ELSE 0 END) as pending_count,
        SUM(total_amount) as total_value
    FROM purchase_orders
";
$stats = $sqlDb->fetch($statsSql);

$page_title = 'Purchase Orders';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Modern Dashboard Styles */
        body {
            background-color: #f4f6f9;
        }
        
        .po-dashboard {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            display: flex;
            align-items: center;
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid rgba(0,0,0,0.02);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.05);
        }
        
        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-right: 20px;
            flex-shrink: 0;
        }

        .stat-icon i {
            color: inherit;
        }
        
        .stat-info h3 {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1.2;
        }
        
        .stat-info p {
            margin: 4px 0 0;
            color: #7f8c8d;
            font-size: 14px;
            font-weight: 500;
        }
        
        .bg-blue { background: #e3f2fd; color: #1976d2 !important; }
        .bg-green { background: #e8f5e9; color: #2e7d32 !important; }
        .bg-orange { background: #fff3e0; color: #f57c00 !important; }
        .bg-purple { background: #f3e5f5; color: #7b1fa2 !important; }
        
        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.02);
        }
        
        .card-header {
            padding: 24px 30px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
        }
        
        .card-header h2 {
            margin: 0;
            font-size: 20px;
            color: #2c3e50;
            font-weight: 700;
        }
        
        .modern-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .modern-table th {
            background: #f8f9fa;
            padding: 18px 30px;
            text-align: left;
            font-weight: 600;
            color: #546e7a;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1px solid #eee;
        }
        
        .modern-table td {
            padding: 20px 30px;
            border-bottom: 1px solid #f5f5f5;
            color: #37474f;
            vertical-align: middle;
            font-size: 14px;
        }
        
        .modern-table tr:last-child td {
            border-bottom: none;
        }
        
        .modern-table tr:hover {
            background-color: #fafafa;
        }
        
        .status-badge {
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-badge::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }
        
        .status-draft { background: #eceff1; color: #546e7a; }
        .status-ordered { background: #e3f2fd; color: #1976d2; }
        .status-partial { background: #fff3e0; color: #f57c00; }
        .status-received { background: #e8f5e9; color: #2e7d32; }
        
        .po-number {
            font-family: 'Monaco', 'Consolas', monospace;
            font-weight: 600;
            color: #37474f;
            background: #f5f5f5;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .supplier-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .supplier-avatar {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(118, 75, 162, 0.2);
        }
        
        .btn-create {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(118, 75, 162, 0.3);
        }
        
        .btn-create:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(118, 75, 162, 0.4);
        }
        
        .action-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #546e7a;
            background: #f5f5f5;
            transition: all 0.2s;
            text-decoration: none;
            border: 1px solid transparent;
        }
        
        .action-btn:hover {
            background: #e3f2fd;
            color: #1976d2;
            border-color: #bbdefb;
            transform: translateY(-2px);
        }

        .amount-cell {
            font-weight: 600;
            color: #2c3e50;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #546e7a;
        }
        
        .user-info i {
            font-size: 12px;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>

    <div class="po-dashboard">
        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon bg-blue">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['total_count'] ?? 0); ?></h3>
                    <p>Total Orders</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-orange">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['pending_count'] ?? 0); ?></h3>
                    <p>Pending</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['completed_count'] ?? 0); ?></h3>
                    <p>Completed</p>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon bg-purple">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo number_format($stats['total_value'] ?? 0, 2); ?></h3>
                    <p>Total Value</p>
                </div>
            </div>
        </div>

        <div class="content-card">
            <div class="card-header">
                <h2>Recent Purchase Orders</h2>
                <a href="create.php" class="btn-create">
                    <i class="fas fa-plus"></i> New Order
                </a>
            </div>

            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>PO Number</th>
                            <th>Supplier</th>
                            <th>Store</th>
                            <th>Status</th>
                            <th>Total Amount</th>
                            <th>Created By</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pos)): ?>
                            <tr><td colspan="8" style="text-align:center; padding: 40px;">
                                <div style="color: #90a4ae;">
                                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
                                    <p>No purchase orders found</p>
                                </div>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($pos as $po): ?>
                                <tr>
                                    <td><span class="po-number"><?php echo htmlspecialchars($po['po_number']); ?></span></td>
                                    <td>
                                        <div class="supplier-info">
                                            <div class="supplier-avatar">
                                                <?php echo strtoupper(substr($po['supplier_name'] ?? '?', 0, 1)); ?>
                                            </div>
                                            <span><?php echo htmlspecialchars($po['supplier_name'] ?? '-'); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($po['store_name'] ?? 'Main Warehouse'); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $po['status']; ?>">
                                            <?php echo ucfirst($po['status']); ?>
                                        </span>
                                    </td>
                                    <td class="amount-cell"><?php echo number_format($po['total_amount'], 2); ?></td>
                                    <td>
                                        <div class="user-info">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($po['created_by_name'] ?? '-'); ?>
                                        </div>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($po['created_at'])); ?></td>
                                    <td>
                                        <a href="edit.php?id=<?php echo $po['id']; ?>" class="action-btn" title="View Details">
                                            <i class="fas fa-arrow-right"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
