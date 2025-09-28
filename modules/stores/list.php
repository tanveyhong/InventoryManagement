<?php
// Store List Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$db = getDB();
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$search = sanitizeInput($_GET['search'] ?? '');

// Firebase-style: Get all stores and filter in PHP
$all_stores = $db->readAll('stores', [['active', '==', 1]]);
$stores = [];
if (!empty($search)) {
    $search_lower = mb_strtolower($search);
    foreach ($all_stores as $store) {
        $fields = [
            mb_strtolower($store['name'] ?? ''),
            mb_strtolower($store['address'] ?? ''),
            mb_strtolower($store['phone'] ?? '')
        ];
        $found = false;
        foreach ($fields as $field) {
            if (strpos($field, $search_lower) !== false) {
                $found = true;
                break;
            }
        }
        if ($found) $stores[] = $store;
    }
} else {
    $stores = $all_stores;
}

$total_records = count($stores);
$pagination = paginate($page, $per_page, $total_records);
$stores = array_slice($stores, $pagination['offset'], $pagination['per_page']);

// For each store, aggregate product_count and total_stock using Firebase
foreach ($stores as &$store) {
    $products = $db->readAll('products', [['store_id', '==', $store['id']], ['active', '==', 1]]);
    $store['product_count'] = count($products);
    $store['total_stock'] = 0;
    foreach ($products as $product) {
        $store['total_stock'] += isset($product['quantity']) ? (int)$product['quantity'] : 0;
    }
}
unset($store);

$page_title = 'Store Management - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .page-header h2 {
            margin: 0 0 10px 0;
            font-size: 2.2rem;
            font-weight: 700;
        }
        
        .page-header p {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .page-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .page-actions .btn {
            padding: 12px 24px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .btn-primary:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: white;
            border: 2px solid rgba(255,255,255,0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            color: white;
            border: 2px solid rgba(255,255,255,0.4);
        }
        
        .btn-outline:hover {
            background: rgba(255,255,255,0.1);
            transform: translateY(-2px);
        }
        
        .search-section {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-form input {
            flex: 1;
            min-width: 250px;
            padding: 15px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        .search-form input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-form .btn {
            padding: 15px 25px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .search-form .btn-secondary {
            background: #667eea;
            color: white;
            border: 2px solid #667eea;
        }
        
        .search-form .btn-secondary:hover {
            background: #5a6fd8;
            transform: translateY(-2px);
        }
        
        .search-form .btn-outline {
            background: transparent;
            color: #64748b;
            border: 2px solid #e2e8f0;
        }
        
        .search-form .btn-outline:hover {
            border-color: #667eea;
            color: #667eea;
        }
        
        .table-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .data-table th,
        .data-table td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .data-table th {
            font-weight: 600;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table tbody tr {
            transition: all 0.3s ease;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
            transform: scale(1.01);
        }
        
        .store-stats {
            display: flex;
            gap: 15px;
            font-size: 0.9rem;
        }
        
        .stat-badge {
            background: #e2e8f0;
            color: #475569;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .stat-badge.products {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .stat-badge.stock {
            background: #dcfce7;
            color: #166534;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-buttons .btn {
            padding: 8px 16px;
            font-size: 0.85rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-sm.btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-sm.btn-primary:hover {
            background: #5a6fd8;
            transform: translateY(-1px);
        }
        
        .btn-sm.btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-sm.btn-secondary:hover {
            background: #475569;
            transform: translateY(-1px);
        }
        
        .btn-sm.btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-sm.btn-warning:hover {
            background: #d97706;
            transform: translateY(-1px);
        }
        
        .no-data {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .no-data i {
            font-size: 4rem;
            color: #e2e8f0;
            margin-bottom: 20px;
        }
        
        .no-data p {
            font-size: 1.2rem;
            color: #64748b;
            margin-bottom: 30px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 30px 0;
        }
        
        .pagination a,
        .pagination span {
            padding: 12px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .pagination a {
            background: white;
            color: #667eea;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .pagination a:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }
        
        .pagination .current {
            background: #667eea;
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <?php 
        $header_title = "Store Management";
        $header_subtitle = "Manage your store locations and monitor performance";
        $header_icon = "fas fa-store";
        $show_compact_toggle = true;
        $header_stats = [
            [
                'value' => number_format($total_records),
                'label' => 'Total Stores',
                'icon' => 'fas fa-store',
                'type' => 'primary',
                'trend' => [
                    'type' => 'trend-up',
                    'icon' => 'arrow-up',
                    'text' => 'Active stores'
                ]
            ],
            [
                'value' => !empty($search) ? 'Filtered' : 'All',
                'label' => 'View Mode',
                'icon' => 'fas fa-filter',
                'type' => 'info',
                'trend' => [
                    'type' => 'trend-neutral',
                    'icon' => 'search',
                    'text' => !empty($search) ? "Search: '$search'" : 'Showing all stores'
                ]
            ]
        ];
        include '../../includes/dashboard_header.php'; 
        ?>

        <!-- Page header (rendered by the page since header include no longer prints it) -->
        <div class="page-header">
            <div class="header-left">
                <div class="header-icon">
                    <i class="<?php echo htmlspecialchars($header_icon ?? 'fas fa-store'); ?>"></i>
                </div>
                <div class="header-text">
                    <h1><?php echo htmlspecialchars($header_title ?? 'Store Management'); ?></h1>
                    <p><?php echo htmlspecialchars($header_subtitle ?? 'Manage your store locations and monitor performance'); ?></p>
                </div>
            </div>
            <?php if (!empty($show_compact_toggle)): ?>
            <div class="header-actions">
                <button class="btn-compact-toggle" onclick="toggleCompactView()">
                    <i class="fas fa-compress"></i>
                    <span>Compact View</span>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($header_stats)): ?>
        <div class="stats-grid">
            <?php foreach ($header_stats as $stat): ?>
            <div class="stat-card">
                <div class="stat-card-inner">
                    <div class="stat-icon-wrapper">
                        <div class="stat-icon <?php echo htmlspecialchars($stat['type'] ?? 'primary'); ?>">
                            <i class="<?php echo htmlspecialchars($stat['icon'] ?? 'fas fa-info'); ?>"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo htmlspecialchars($stat['value']); ?></div>
                        <div class="stat-label"><?php echo htmlspecialchars($stat['label']); ?></div>
                        <?php if (isset($stat['trend'])): ?>
                        <div class="stat-trend <?php echo htmlspecialchars($stat['trend']['type'] ?? 'neutral'); ?>">
                            <i class="fas fa-<?php echo htmlspecialchars($stat['trend']['icon'] ?? 'minus'); ?>"></i>
                            <span><?php echo htmlspecialchars($stat['trend']['text'] ?? ''); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <main>

            <!-- Search Form -->
            <div class="search-section">
                <form method="GET" action="" class="search-form">
                    <input type="text" name="search" placeholder="🔍 Search stores by name, address, or phone..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="list.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Stores Table -->
            <?php if (empty($stores)): ?>
                <div class="no-data">
                    <i class="fas fa-store-slash"></i>
                    <p>No stores found</p>
                    <a href="add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Your First Store</a>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><i class="fas fa-store"></i> Store Name</th>
                                <th><i class="fas fa-map-marker-alt"></i> Address</th>
                                <th><i class="fas fa-phone"></i> Phone</th>
                                <th><i class="fas fa-user-tie"></i> Manager</th>
                                <th><i class="fas fa-chart-bar"></i> Statistics</th>
                                <th><i class="fas fa-cogs"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stores as $store): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($store['name']); ?></strong>
                                        <?php if (!empty($store['code'])): ?>
                                            <br><small style="color: #64748b;">Code: <?php echo htmlspecialchars($store['code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($store['address'])): ?>
                                            <?php echo htmlspecialchars($store['address']); ?>
                                            <?php if (!empty($store['city'])): ?>
                                                <br><small style="color: #64748b;"><?php echo htmlspecialchars($store['city']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">Not provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($store['phone'] ?? 'Not provided'); ?></td>
                                    <td><?php echo htmlspecialchars($store['manager_name'] ?? 'Not assigned'); ?></td>
                                    <td>
                                        <div class="store-stats">
                                            <span class="stat-badge products">
                                                <i class="fas fa-box"></i> <?php echo number_format($store['product_count']); ?>
                                            </span>
                                            <span class="stat-badge stock">
                                                <i class="fas fa-cubes"></i> <?php echo number_format($store['total_stock']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="profile.php?id=<?php echo $store['id']; ?>" class="btn btn-sm btn-primary" title="View Store Profile">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="edit.php?id=<?php echo $store['id']; ?>" class="btn btn-sm btn-secondary" title="Edit Store">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="inventory_viewer.php?id=<?php echo $store['id']; ?>" class="btn btn-sm btn-warning" title="View Inventory">
                                                <i class="fas fa-boxes"></i> Inventory
                                            </a>
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
                            <a href="?page=<?php echo $pagination['page'] - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <span class="current">
                            <i class="fas fa-file-alt"></i>
                            Page <?php echo $pagination['page']; ?> of <?php echo $pagination['total_pages']; ?>
                            (<?php echo number_format($total_records); ?> stores)
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="?page=<?php echo $pagination['page'] + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
</body>
</html>