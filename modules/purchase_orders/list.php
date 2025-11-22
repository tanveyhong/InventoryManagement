<?php
// modules/purchase_orders/list.php
require_once '../../config.php';
session_start();
require_once '../../functions.php';
require_once '../../sql_db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
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

$page_title = 'Purchase Orders';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>

    <div class="container">
        <div class="page-header">
            <h2>Purchase Orders</h2>
            <a href="create.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create PO</a>
        </div>

        <div class="table-container">
            <table class="data-table">
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
                        <tr><td colspan="8" style="text-align:center;">No purchase orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pos as $po): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($po['po_number']); ?></td>
                                <td><?php echo htmlspecialchars($po['supplier_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($po['store_name'] ?? 'Main Warehouse'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $po['status']; ?>">
                                        <?php echo ucfirst($po['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($po['total_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($po['created_by_name'] ?? '-'); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($po['created_at'])); ?></td>
                                <td>
                                    <a href="edit.php?id=<?php echo $po['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-eye"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
