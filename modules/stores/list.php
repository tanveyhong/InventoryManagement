<?php
// Store List Page - Optimized PostgreSQL Version
ob_start('ob_gzhandler'); // Enable compression

require_once '../../config.php';
require_once '../../db.php';
require_once '../../sql_db.php';
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

// Require permission to view stores
if (!currentUserHasPermission('can_view_stores') && !currentUserHasPermission('can_add_stores') && !currentUserHasPermission('can_edit_stores')) {
    $_SESSION['error'] = 'You do not have permission to access stores';
    header('Location: ../../index.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();
$message = '';
$messageType = '';

// Get current user info
$userId = $_SESSION['user_id'] ?? null;
$currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
$isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
$isManager = (strtolower($currentUser['role'] ?? '') === 'manager');

// Pagination and search parameters
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$search = sanitizeInput($_GET['search'] ?? '');
$offset = ($page - 1) * $per_page;

// Build WHERE clause - admins/managers see all stores, others see only their assigned stores
if ($isAdmin || $isManager) {
    $where = "WHERE s.active = TRUE";
    $storeAccessJoin = "";
} else {
    // Regular users only see stores they have access to
    $where = "WHERE s.active = TRUE AND s.id IN (SELECT store_id FROM user_store_access WHERE user_id = ?)";
    $storeAccessJoin = "";
    $params[] = $currentUser['id'];
}

// Original params array for search
$baseParams = $params ?? [];
$params = $baseParams;

if (!empty($search)) {
    $where .= " AND (s.name ILIKE ? OR s.address ILIKE ? OR s.phone ILIKE ? OR s.city ILIKE ?)";
    $searchPattern = "%$search%";
    $params = array_merge($params, [$searchPattern, $searchPattern, $searchPattern, $searchPattern]);
}

try {
    // Get total count for pagination (fast query)
    $countSql = "SELECT COUNT(*) as total FROM stores s $where";
    $countResult = $sqlDb->fetch($countSql, $params);
    $total_records = $countResult['total'] ?? 0;
    
    // Get stores with product counts in a single optimized query
    // Uses LEFT JOIN with aggregation for O(1) performance
    $sql = "
        SELECT 
            s.*,
            COALESCE(COUNT(p.id), 0) as product_count,
            COALESCE(SUM(CASE WHEN p.active = TRUE THEN p.quantity ELSE 0 END), 0) as total_stock
        FROM stores s
        LEFT JOIN products p ON p.store_id = s.id AND p.active = TRUE
        $where
        GROUP BY s.id
        ORDER BY s.name ASC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $stores = $sqlDb->fetchAll($sql, $params);
    
    error_log("Stores query executed - Found: " . count($stores) . " stores in " . (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? 0)) . "s");
    
} catch (Exception $e) {
    error_log('PostgreSQL fetch failed for stores list: ' . $e->getMessage());
    $stores = [];
    $total_records = 0;
    $message = 'Failed to load stores. Please try again.';
    $messageType = 'error';
}

// Calculate pagination
$total_pages = ceil($total_records / $per_page);
$pagination = [
    'current_page' => $page,
    'per_page' => $per_page,
    'total_records' => $total_records,
    'total_pages' => $total_pages,
    'offset' => $offset,
    'has_prev' => $page > 1,
    'has_next' => $page < $total_pages
];

// HTTP caching headers
header('Cache-Control: private, max-age=60'); // Cache for 1 minute
header('Vary: Cookie');

$page_title = 'Store Management - Inventory System';
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
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --background: #f3f4f6;
            --surface: #ffffff;
            --text-main: #1f2937;
            --text-light: #6b7280;
            --border: #e5e7eb;
        }

        body {
            background-color: var(--background);
            min-height: 100vh;
            margin: 0;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            color: var(--text-main);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: var(--surface);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-content h1 {
            margin: 0 0 5px 0;
            color: var(--text-main);
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-content h1 i {
            color: var(--primary);
        }

        .header-content p {
            margin: 0;
            color: var(--text-light);
            font-size: 14px;
        }
        
        .stats-container {
            display: flex;
            gap: 15px;
        }
        
        .stat-card {
            background: #f8fafc;
            padding: 10px 15px;
            border-radius: 10px;
            border: 1px solid var(--border);
            min-width: 100px;
        }
        
        .stat-card .value {
            color: var(--primary);
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .stat-card .label {
            color: var(--text-light);
            font-size: 12px;
            font-weight: 500;
            margin-top: 2px;
        }
        
        .controls {
            background: var(--surface);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-box {
            flex: 1;
            min-width: 250px;
            position: relative;
        }
        
        .search-box input {
            width: 100%;
            padding: 8px 40px 8px 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
            background: #f9fafb;
        }

        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .search-box button {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            color: var(--text-light);
            border: none;
            padding: 6px;
            cursor: pointer;
            transition: color 0.2s;
        }

        .search-box button:hover {
            color: var(--primary);
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 8px -1px rgba(79, 70, 229, 0.3);
        }

        .btn-secondary {
            background: white;
            color: var(--text-main);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        
        .stores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .store-card {
            background: var(--surface);
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
            border: 1px solid transparent;
            display: flex;
            flex-direction: column;
        }
        
        .store-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border-color: var(--primary);
        }

        .store-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border);
        }
        
        .store-name {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0 0 4px 0;
            line-height: 1.3;
        }
        
        .store-code {
            background: #e0e7ff;
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .store-info {
            margin-bottom: 15px;
            flex-grow: 1;
        }
        
        .info-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            color: var(--text-light);
            font-size: 13px;
        }
        
        .info-row i {
            width: 16px;
            color: var(--primary);
            text-align: center;
        }
        
        .store-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
            background: #f8fafc;
            padding: 10px;
            border-radius: 8px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-item .value {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
        }
        
        .stat-item .label {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 2px;
        }
        
        .store-actions {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .btn-view {
            background: var(--primary);
            color: white;
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            display: block;
        }
        
        .btn-view:hover {
            background: var(--primary-dark);
        }

        .btn-delete {
            background: #fee2e2;
            color: #ef4444;
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            display: block;
            border: none;
            cursor: pointer;
            width: 100%;
        }
        
        .btn-delete:hover {
            background: #ef4444;
            color: white;
        }

        .btn-inventory {
            background: #d1fae5;
            color: #059669;
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
            display: block;
        }

        .btn-inventory:hover {
            background: #059669;
            color: white;
        }
        
        .store-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .store-actions.has-delete {
            grid-template-columns: 1fr 1fr 1fr;
        }
        
        .pagination {
            background: var(--surface);
            border-radius: 16px;
            padding: 20px 30px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .pagination-links {
            display: flex;
            gap: 6px;
        }
        
        .pagination-links a,
        .pagination-links span {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .pagination-links a {
            background: transparent;
            color: var(--text-light);
            border: 1px solid var(--border);
        }
        
        .pagination-links a:hover {
            background: #f3f4f6;
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .pagination-links .current {
            background: var(--primary);
            color: white;
            border: 1px solid var(--primary);
        }
        
        .no-stores {
            background: var(--surface);
            border-radius: 16px;
            padding: 80px 20px;
            text-align: center;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }

        /* List Layout Styles */
        .stores-grid.layout-list {
            grid-template-columns: 1fr;
            gap: 10px;
        }
        
        .stores-grid.layout-list .store-card {
            flex-direction: row;
            align-items: center;
            gap: 20px;
            padding: 15px 25px;
        }
        
        .stores-grid.layout-list .store-header {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
            width: 250px;
            flex-shrink: 0;
            flex-direction: column;
            align-items: flex-start;
            gap: 5px;
        }
        
        .stores-grid.layout-list .store-info {
            margin-bottom: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 30px;
            flex-grow: 1;
        }
        
        .stores-grid.layout-list .store-stats {
            margin-bottom: 0;
            width: 200px;
            flex-shrink: 0;
            background: transparent;
            padding: 0;
            border: none;
        }
        
        .stores-grid.layout-list .store-actions {
            width: 150px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .stores-grid.layout-list .store-actions.has-delete {
            grid-template-columns: 1fr;
        }

        .layout-toggles {
            display: flex;
            background: #f3f4f6;
            padding: 4px;
            border-radius: 8px;
            gap: 4px;
        }

        .layout-btn {
            padding: 6px 10px;
            border: none;
            background: transparent;
            border-radius: 6px;
            cursor: pointer;
            color: var(--text-light);
            transition: all 0.2s;
        }

        .layout-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        @media (max-width: 1024px) {
            .stores-grid.layout-list .store-card {
                flex-wrap: wrap;
            }
            .stores-grid.layout-list .store-info {
                width: 100%;
                order: 3;
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid var(--border);
            }
            .stores-grid.layout-list .store-stats {
                margin-left: auto;
            }
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-container {
                width: 100%;
                overflow-x: auto;
                padding-bottom: 10px;
            }
            
            .stores-grid {
                grid-template-columns: 1fr;
            }
            
            .pagination {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .layout-toggles {
                display: none; /* Hide layout toggle on mobile, force grid */
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <h1><i class="fas fa-store"></i> Store Management</h1>
                <p>Manage your retail locations</p>
            </div>
            <div class="stats-container">
                <div class="stat-card">
                    <div class="value"><?php echo number_format($total_records); ?></div>
                    <div class="label">Total Stores</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?php echo number_format(array_sum(array_column($stores, 'product_count'))); ?></div>
                    <div class="label">Total Products</div>
                </div>
                <div class="stat-card">
                    <div class="value"><?php echo number_format(array_sum(array_column($stores, 'total_stock'))); ?></div>
                    <div class="label">Total Stock</div>
                </div>
            </div>
        </div>
        
        <!-- Controls -->
        <div class="controls">
            <form class="search-box" method="get" action="">
                <input type="text" name="search" placeholder="Search stores by name, address, phone, or city..." 
                       value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit"><i class="fas fa-search"></i></button>
            </form>
            
            <div class="layout-toggles">
                <button class="layout-btn active" onclick="switchLayout('grid')" title="Grid View">
                    <i class="fas fa-th-large"></i>
                </button>
                <button class="layout-btn" onclick="switchLayout('list')" title="List View">
                    <i class="fas fa-list"></i>
                </button>
            </div>

            <?php if (currentUserHasPermission('can_add_stores')): ?>
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add New Store
            </a>
            <?php endif; ?>
            
            <a href="list.php" class="btn btn-secondary">
                <i class="fas fa-sync-alt"></i> Refresh
            </a>
        </div>
        
        <!-- Messages -->
        <?php if ($message): ?>
        <div class="message <?php echo $messageType; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <!-- Stores Grid -->
        <?php if (!empty($stores)): ?>
        <div class="stores-grid">
            <?php foreach ($stores as $store): ?>
            <div class="store-card">
                <div class="store-header">
                    <h3 class="store-name"><?php echo htmlspecialchars($store['name']); ?></h3>
                    <?php if (!empty($store['code'])): ?>
                    <span class="store-code"><?php echo htmlspecialchars($store['code']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="store-info">
                    <div class="info-row"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($store['address'] ?? 'N/A'); ?></div>
                    <div class="info-row"><i class="fas fa-city"></i> <?php echo htmlspecialchars($store['city'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($store['state'] ?? 'N/A'); ?></div>
                    <?php if (!empty($store['phone'])): ?>
                    <div class="info-row"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($store['phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($store['manager_name'])): ?>
                    <div class="info-row"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($store['manager_name']); ?></div>
                    <?php endif; ?>
                </div>
                
                <div class="store-stats">
                    <div class="stat-item">
                        <div class="value"><?php echo number_format($store['product_count']); ?></div>
                        <div class="label">Products</div>
                    </div>
                    <div class="stat-item">
                        <div class="value"><?php echo number_format($store['total_stock']); ?></div>
                        <div class="label">Total Stock</div>
                    </div>
                </div>
                
                <div class="store-actions <?php echo currentUserHasPermission('can_delete_stores') ? 'has-delete' : ''; ?>">
                    <a href="profile.php?id=<?php echo $store['id']; ?>" class="btn-view">
                        <i class="fas fa-eye"></i> View
                    </a>
                    <a href="../stock/list.php?store=<?php echo $store['id']; ?>" class="btn-inventory">
                        <i class="fas fa-boxes"></i> Stock
                    </a>
                    <?php if (currentUserHasPermission('can_delete_stores')): ?>
                    <button onclick="confirmDeleteStore(<?php echo $store['id']; ?>, '<?php echo htmlspecialchars(addslashes($store['name'])); ?>', <?php echo $store['product_count']; ?>)" class="btn-delete">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($pagination['total_pages'] > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                Showing <?php echo ($pagination['offset'] + 1); ?>-<?php echo min($pagination['offset'] + $pagination['per_page'], $pagination['total_records']); ?> 
                of <?php echo number_format($pagination['total_records']); ?> stores
            </div>
            
            <div class="pagination-links">
                <?php if ($pagination['has_prev']): ?>
                <a href="?page=<?php echo ($pagination['current_page'] - 1); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                    <i class="fas fa-chevron-left"></i> Previous
                </a>
                <?php endif; ?>
                
                <?php
                // Show page numbers
                $start_page = max(1, $pagination['current_page'] - 2);
                $end_page = min($pagination['total_pages'], $pagination['current_page'] + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <?php if ($i == $pagination['current_page']): ?>
                    <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($pagination['has_next']): ?>
                <a href="?page=<?php echo ($pagination['current_page'] + 1); ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">
                    Next <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="no-stores">
            <i class="fas fa-store-slash"></i>
            <h2>No Stores Found</h2>
            <p><?php echo $search ? 'No stores match your search criteria.' : 'Get started by adding your first store.'; ?></p>
            <?php if (currentUserHasPermission('can_add_stores')): ?>
            <br>
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add Your First Store
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div style="background: white; padding: 25px; border-radius: 12px; width: 90%; max-width: 400px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);">
            <h3 style="margin-top: 0; color: var(--text-main); font-size: 1.25rem;">Delete Store</h3>
            <p style="color: var(--text-light); margin-bottom: 20px;">
                Are you sure you want to delete <strong id="deleteStoreName"></strong>?
                <br><br>
                <span id="deleteStoreWarning" style="color: var(--danger); font-size: 0.9em;"></span>
            </p>
            
            <form id="deleteStoreForm" action="" method="POST" style="display: flex; gap: 10px; justify-content: flex-end;">
                <input type="hidden" name="confirm_delete" value="1">
                <button type="button" onclick="closeDeleteModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-delete" style="width: auto;">Delete Store</button>
            </form>
        </div>
    </div>

    <!-- Offline Functionality for Stores List -->
    <script>
        function confirmDeleteStore(id, name, productCount) {
            const modal = document.getElementById('deleteModal');
            const form = document.getElementById('deleteStoreForm');
            const nameSpan = document.getElementById('deleteStoreName');
            const warningSpan = document.getElementById('deleteStoreWarning');
            
            form.action = 'delete.php?id=' + id;
            nameSpan.textContent = name;
            
            if (productCount > 0) {
                warningSpan.textContent = `Warning: This store contains ${productCount} products. They will be unassigned from this store.`;
            } else {
                warningSpan.textContent = 'This action cannot be undone.';
            }
            
            modal.style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });

        function switchLayout(layout) {
            const grid = document.querySelector('.stores-grid');
            const btns = document.querySelectorAll('.layout-btn');
            
            if (layout === 'list') {
                grid.classList.add('layout-list');
                btns[0].classList.remove('active');
                btns[1].classList.add('active');
            } else {
                grid.classList.remove('layout-list');
                btns[0].classList.add('active');
                btns[1].classList.remove('active');
            }
            
            // Save preference
            localStorage.setItem('store_layout_preference', layout);
        }

        // Load preference on start
        document.addEventListener('DOMContentLoaded', function() {
            const savedLayout = localStorage.getItem('store_layout_preference');
            if (savedLayout === 'list') {
                switchLayout('list');
            }
        });

        document.addEventListener('DOMContentLoaded', async function() {
            if (window.offlineManager) {
                try {
                    // Cache all stores
                    const stores = <?php echo json_encode($stores ?? []); ?>;
                    
                    if (stores && stores.length > 0) {
                        await window.offlineManager.saveData('stores', stores);
                        console.log(`Cached ${stores.length} stores for offline use`);
                    }
                    
                    // Load from cache if offline
                    if (!window.offlineManager.isOnline) {
                        const cachedStores = await window.offlineManager.getAllData('stores');
                        if (cachedStores && cachedStores.length > 0) {
                            console.log(`Loaded ${cachedStores.length} stores from cache`);
                            window.offlineManager.showNotification(
                                `Viewing ${cachedStores.length} cached stores`, 
                                'info'
                            );
                        }
                    }
                } catch (error) {
                    console.error('Failed to cache stores:', error);
                }
            }
        });
    </script>
</body>
</html>
