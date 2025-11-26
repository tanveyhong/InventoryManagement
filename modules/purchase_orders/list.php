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
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$supplier_filter = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : '';
$store_filter = isset($_GET['store_id']) ? (int)$_GET['store_id'] : '';
$user_filter = isset($_GET['user_id']) ? (int)$_GET['user_id'] : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
$order = isset($_GET['order']) ? strtoupper($_GET['order']) : 'DESC';

// Validate sort and order
$allowed_sorts = ['total_amount', 'created_at', 'po_number'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'created_at';
}
if (!in_array($order, ['ASC', 'DESC'])) {
    $order = 'DESC';
}

// Fetch Suppliers and Stores for filters
$suppliers = $sqlDb->fetchAll("SELECT id, name FROM suppliers ORDER BY name ASC");
$stores = $sqlDb->fetchAll("SELECT id, name FROM stores ORDER BY name ASC");
$users = [];

// Check user role for permission scope
$canViewAll = false;
try {
    // Use SQL directly to avoid potential issues with getUserInfo/Firebase
    $currentUser = $sqlDb->fetch("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
    
    if ($currentUser && is_array($currentUser) && isset($currentUser['role'])) {
        $userRole = strtolower($currentUser['role']);
        $canViewAll = in_array($userRole, ['admin', 'manager']);
    }

    if ($canViewAll) {
        $users = $sqlDb->fetchAll("SELECT id, username FROM users ORDER BY username ASC");
    }
} catch (Exception $e) {
    // If anything goes wrong, default to restricted view (safe fail)
    error_log("Error checking user role in PO list: " . $e->getMessage());
}

// Build Query Conditions
$where_clauses = [];
$params = [];

// Restrict non-admin/manager users to their own orders
if (!$canViewAll) {
    $where_clauses[] = "po.created_by = ?";
    $params[] = $_SESSION['user_id'];
} elseif ($user_filter) {
    // Only allow filtering by user if they can view all
    $where_clauses[] = "po.created_by = ?";
    $params[] = $user_filter;
}



if ($search) {
    $where_clauses[] = "(po.po_number LIKE ? OR s.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if ($status_filter) {
    $where_clauses[] = "po.status = ?";
    $params[] = $status_filter;
}

if ($supplier_filter) {
    $where_clauses[] = "po.supplier_id = ?";
    $params[] = $supplier_filter;
}

if ($store_filter) {
    $where_clauses[] = "po.store_id = ?";
    $params[] = $store_filter;
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// Count total records for pagination
$countSql = "
    SELECT COUNT(*) as total 
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    $where_sql
";
$countResult = $sqlDb->fetch($countSql, $params);
$total_records = $countResult['total'] ?? 0;
$total_pages = ceil($total_records / $per_page);

// Fetch POs with supplier name
$sql = "
    SELECT po.*, s.name as supplier_name, st.name as store_name, u.username as created_by_name
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN stores st ON po.store_id = st.id
    LEFT JOIN users u ON po.created_by = u.id
    $where_sql
    ORDER BY po.$sort $order
    LIMIT ? OFFSET ?
";

// Add pagination params
$params[] = $per_page;
$params[] = $offset;

$pos = $sqlDb->fetchAll($sql, $params);

// Handle AJAX Search
if (isset($_GET['ajax'])) {
    $html = '';
    if (empty($pos)) {
        $html .= '<tr><td colspan="8" style="text-align:center; padding: 40px;">
            <div style="color: #90a4ae;">
                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
                <p>No purchase orders found</p>
            </div>
        </td></tr>';
    } else {
        foreach ($pos as $po) {
            $html .= '<tr>';
            $html .= '<td><span class="po-number">' . htmlspecialchars($po['po_number']) . '</span></td>';
            $html .= '<td><div class="supplier-info"><div class="supplier-avatar">' . strtoupper(substr($po['supplier_name'] ?? '?', 0, 1)) . '</div><span>' . htmlspecialchars($po['supplier_name'] ?? '-') . '</span></div></td>';
            $html .= '<td>' . htmlspecialchars($po['store_name'] ?? 'Main Warehouse') . '</td>';
            $html .= '<td><span class="status-badge status-' . $po['status'] . '">' . ucfirst($po['status']) . '</span></td>';
            $html .= '<td class="amount-cell">' . number_format($po['total_amount'], 2) . '</td>';
            $html .= '<td><div class="user-info"><i class="fas fa-user"></i> ' . htmlspecialchars($po['created_by_name'] ?? '-') . '</div></td>';
            $html .= '<td>' . date('M d, Y', strtotime($po['created_at'])) . '</td>';
            $html .= '<td><a href="edit.php?id=' . $po['id'] . '" class="action-btn" title="View Details"><i class="fas fa-arrow-right"></i></a></td>';
            $html .= '</tr>';
        }
    }

    // Pagination HTML
    $pagination_html = '';
    if ($total_pages > 1) {
        $base_params = [
            'search' => $search,
            'status' => $status_filter,
            'supplier_id' => $supplier_filter,
            'store_id' => $store_filter,
            'user_id' => $user_filter,
            'sort' => $sort,
            'order' => $order
        ];

        $pagination_html .= '<div class="pagination">';
        if ($page > 1) {
            $params = array_merge($base_params, ['page' => $page - 1]);
            $pagination_html .= '<a href="?' . http_build_query($params) . '" class="page-link"><i class="fas fa-chevron-left"></i></a>';
        }
        
        $start_page = max(1, $page - 2);
        $end_page = min($total_pages, $page + 2);

        if ($start_page > 1) {
            $params = array_merge($base_params, ['page' => 1]);
            $pagination_html .= '<a href="?' . http_build_query($params) . '" class="page-link">1</a>';
            if ($start_page > 2) {
                $pagination_html .= '<span class="page-link" style="border:none;">...</span>';
            }
        }

        for ($i = $start_page; $i <= $end_page; $i++) {
            $active = $i === $page ? 'active' : '';
            $params = array_merge($base_params, ['page' => $i]);
            $pagination_html .= '<a href="?' . http_build_query($params) . '" class="page-link ' . $active . '">' . $i . '</a>';
        }

        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                $pagination_html .= '<span class="page-link" style="border:none;">...</span>';
            }
            $params = array_merge($base_params, ['page' => $total_pages]);
            $pagination_html .= '<a href="?' . http_build_query($params) . '" class="page-link">' . $total_pages . '</a>';
        }

        if ($page < $total_pages) {
            $params = array_merge($base_params, ['page' => $page + 1]);
            $pagination_html .= '<a href="?' . http_build_query($params) . '" class="page-link"><i class="fas fa-chevron-right"></i></a>';
        }
        $pagination_html .= '</div>';
    }

    header('Content-Type: application/json');
    echo json_encode(['html' => $html, 'pagination' => $pagination_html]);
    exit;
}

// Fetch Summary Stats
$statsParams = [];
$statsWhere = "";

if (!$canViewAll) {
    $statsWhere = "WHERE created_by = ?";
    $statsParams[] = $_SESSION['user_id'];
}

$statsSql = "
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as completed_count,
        SUM(CASE WHEN status IN ('draft', 'ordered', 'partial') THEN 1 ELSE 0 END) as pending_count,
        SUM(total_amount) as total_value
    FROM purchase_orders
    $statsWhere
";
$stats = $sqlDb->fetch($statsSql, $statsParams);

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
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(118, 75, 162, 0.3);
            font-size: 14px;
            white-space: nowrap;
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

        .search-box {
            display: flex;
            gap: 10px;
            background: #f8f9fa;
            padding: 8px;
            border-radius: 10px;
            border: 1px solid #eee;
        }

        .search-input {
            border: none;
            background: transparent;
            padding: 8px;
            outline: none;
            font-size: 14px;
            width: 250px;
        }

        .filter-select {
            border: none;
            background: transparent;
            padding: 8px;
            outline: none;
            font-size: 14px;
            color: #546e7a;
            border-left: 1px solid #eee;
            cursor: pointer;
        }

        .filter-select:first-of-type {
            border-left: none;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
            padding-bottom: 20px;
        }

        .page-link {
            padding: 8px 12px;
            border-radius: 8px;
            background: white;
            border: 1px solid #eee;
            color: #546e7a;
            text-decoration: none;
            transition: all 0.2s;
        }

        .page-link:hover, .page-link.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .sortable-header {
            cursor: pointer;
            user-select: none;
        }

        .header-content {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .sortable-header:hover {
            color: #2c3e50;
        }
        
        .sort-icon {
            font-size: 10px;
            color: #b0bec5;
        }
        
        .sort-icon.active {
            color: #546e7a;
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
                <div style="display: flex; align-items: center; gap: 20px;">
                    <h2>Recent Purchase Orders</h2>
                    <form method="GET" class="search-box">
                        <input type="text" name="search" class="search-input" placeholder="Search PO..." value="<?php echo htmlspecialchars($search); ?>">
                        
                        <select name="status" class="filter-select">
                            <option value="">All Status</option>
                            <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="ordered" <?php echo $status_filter === 'ordered' ? 'selected' : ''; ?>>Ordered</option>
                            <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                            <option value="received" <?php echo $status_filter === 'received' ? 'selected' : ''; ?>>Received</option>
                        </select>

                        <select name="supplier_id" class="filter-select">
                            <option value="">All Suppliers</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?php echo $supplier['id']; ?>" <?php echo $supplier_filter == $supplier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($supplier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="store_id" class="filter-select">
                            <option value="">All Stores</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" <?php echo $store_filter == $store['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if ($canViewAll && !empty($users)): ?>
                        <select name="user_id" class="filter-select">
                            <option value="">All Users</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($user['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>

                        <button type="submit" class="action-btn" style="width: 32px; height: 32px; border: none; background: transparent;">
                            <i class="fas fa-search"></i>
                        </button>
                        <button type="button" id="clear-filters" class="action-btn" style="width: 32px; height: 32px; display: none; align-items: center; justify-content: center; color: #e74c3c;" title="Clear All Filters">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                </div>
                <a href="create.php" class="btn-create">
                    <i class="fas fa-plus"></i> New Order
                </a>
            </div>

            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th class="sortable-header" data-sort="po_number">
                                <div class="header-content">
                                    PO Number
                                    <span class="sort-icon" id="sort-po_number">
                                        <i class="fas fa-sort"></i>
                                    </span>
                                </div>
                            </th>
                            <th>
                                Supplier
                            </th>
                            <th>
                                Store
                            </th>
                            <th>
                                Status
                            </th>
                            <th class="sortable-header" data-sort="total_amount">
                                <div class="header-content">
                                    Total Amount
                                    <span class="sort-icon" id="sort-total_amount">
                                        <i class="fas fa-sort"></i>
                                    </span>
                                </div>
                            </th>
                            <th>
                                Created By
                            </th>
                            <th class="sortable-header" data-sort="created_at">
                                <div class="header-content">
                                    Date
                                    <span class="sort-icon" id="sort-created_at">
                                        <i class="fas fa-sort"></i>
                                    </span>
                                </div>
                            </th>
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

            <!-- Pagination -->
            <div id="pagination-container">
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="page-link">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                <?php endif; ?>

                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);

                if ($start_page > 1) {
                    echo '<a href="?page=1&search=' . urlencode($search) . '" class="page-link">1</a>';
                    if ($start_page > 2) {
                        echo '<span class="page-link" style="border:none;">...</span>';
                    }
                }

                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" 
                       class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php
                if ($end_page < $total_pages) {
                    if ($end_page < $total_pages - 1) {
                        echo '<span class="page-link" style="border:none;">...</span>';
                    }
                    echo '<a href="?page=' . $total_pages . '&search=' . urlencode($search) . '" class="page-link">' . $total_pages . '</a>';
                }
                ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="page-link">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.querySelector('input[name="search"]');
        const statusSelect = document.querySelector('select[name="status"]');
        const supplierSelect = document.querySelector('select[name="supplier_id"]');
        const storeSelect = document.querySelector('select[name="store_id"]');
        const userSelect = document.querySelector('select[name="user_id"]');
        const clearButton = document.getElementById('clear-filters');
        const tableBody = document.querySelector('tbody');
        const paginationContainer = document.getElementById('pagination-container');
        
        let currentSort = '<?php echo $sort; ?>';
        let currentOrder = '<?php echo $order; ?>';
        let debounceTimer;

        function checkFilters() {
            const hasFilters = searchInput.value || statusSelect.value || supplierSelect.value || storeSelect.value || (userSelect && userSelect.value);
            clearButton.style.display = hasFilters ? 'flex' : 'none';
        }

        function getFilters() {
            return {
                search: searchInput.value,
                status: statusSelect.value,
                supplier_id: supplierSelect.value,
                store_id: storeSelect.value,
                user_id: userSelect ? userSelect.value : ''
            };
        }

        function updateResults(page = 1) {
            checkFilters();
            const filters = getFilters();
            const params = new URLSearchParams({
                ajax: 1,
                page: page,
                sort: currentSort,
                order: currentOrder,
                ...filters
            });

            // Update URL
            const url = new URL(window.location);
            Object.keys(filters).forEach(key => {
                if (filters[key]) {
                    url.searchParams.set(key, filters[key]);
                } else {
                    url.searchParams.delete(key);
                }
            });
            url.searchParams.set('page', page);
            url.searchParams.set('sort', currentSort);
            url.searchParams.set('order', currentOrder);
            window.history.pushState({}, '', url);
            
            // Update sort icons
            document.querySelectorAll('.sort-icon').forEach(icon => {
                icon.className = 'sort-icon';
                icon.innerHTML = '<i class="fas fa-sort"></i>';
            });
            
            const activeIcon = document.getElementById('sort-' + currentSort);
            if (activeIcon) {
                activeIcon.className = 'sort-icon active';
                activeIcon.innerHTML = currentOrder === 'ASC' ? 
                    '<i class="fas fa-sort-up"></i>' : 
                    '<i class="fas fa-sort-down"></i>';
            }

            fetch(`list.php?${params.toString()}`)
                .then(response => response.json())
                .then(data => {
                    tableBody.innerHTML = data.html;
                    paginationContainer.innerHTML = data.pagination;
                    attachPaginationListeners();
                })
                .catch(error => console.error('Error:', error));
        }

        // Event Listeners
        searchInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => updateResults(1), 300);
        });

        const selects = [statusSelect, supplierSelect, storeSelect];
        if (userSelect) selects.push(userSelect);
        
        selects.forEach(select => {
            select.addEventListener('change', () => updateResults(1));
        });

        // Sorting listeners
        document.querySelectorAll('.sortable-header').forEach(header => {
            header.addEventListener('click', function() {
                const sort = this.dataset.sort;
                if (currentSort === sort) {
                    currentOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    currentSort = sort;
                    currentOrder = 'DESC'; // Default to DESC for new sort
                }
                updateResults(1);
            });
        });

        clearButton.addEventListener('click', function() {
            searchInput.value = '';
            statusSelect.value = '';
            supplierSelect.value = '';
            storeSelect.value = '';
            if (userSelect) userSelect.value = '';
            
            // Reset sort
            currentSort = 'created_at';
            currentOrder = 'DESC';
            
            updateResults(1);
        });

        function attachPaginationListeners() {
            document.querySelectorAll('.page-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const href = this.getAttribute('href');
                    if (!href) return;
                    
                    const url = new URL(href, window.location.origin + window.location.pathname);
                    const page = url.searchParams.get('page');
                    updateResults(page);
                    window.scrollTo(0, 0);
                });
            });
        }
        
        // Initial attachment
        attachPaginationListeners();
        checkFilters();
        
        // Initialize sort icons
        const activeIcon = document.getElementById('sort-' + currentSort);
        if (activeIcon) {
            activeIcon.className = 'sort-icon active';
            activeIcon.innerHTML = currentOrder === 'ASC' ? 
                '<i class="fas fa-sort-up"></i>' : 
                '<i class="fas fa-sort-down"></i>';
        }
        
        // Handle browser back/forward
        window.addEventListener('popstate', function() {
            const url = new URL(window.location);
            searchInput.value = url.searchParams.get('search') || '';
            statusSelect.value = url.searchParams.get('status') || '';
            supplierSelect.value = url.searchParams.get('supplier_id') || '';
            storeSelect.value = url.searchParams.get('store_id') || '';
            if (userSelect) userSelect.value = url.searchParams.get('user_id') || '';
            
            currentSort = url.searchParams.get('sort') || 'created_at';
            currentOrder = url.searchParams.get('order') || 'DESC';
            
            const page = url.searchParams.get('page') || 1;
            updateResults(page);
        });
    });
    </script>
</body>
</html>
