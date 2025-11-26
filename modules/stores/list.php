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

// Handle AJAX request
if (isset($_GET['ajax'])) {
    // Prevent caching for AJAX
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    ob_start();
    include 'list_content.php';
    $html = ob_get_clean();
    
    header('Content-Type: application/json');
    echo json_encode([
        'html' => $html,
        'total_records' => $total_records,
        'total_products' => array_sum(array_column($stores, 'product_count')),
        'total_stock' => array_sum(array_column($stores, 'total_stock'))
    ]);
    exit;
}

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
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .btn-view, .btn-edit, .btn-inventory, .btn-delete {
            text-align: center;
            padding: 12px 10px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 100%;
            border: none;
            cursor: pointer;
            width: 100%;
        }

        .btn-view {
            background: var(--primary);
            color: white;
        }
        
        .btn-view:hover {
            background: var(--primary-dark);
        }

        .btn-delete {
            background: #fee2e2;
            color: #ef4444;
        }
        
        .btn-delete:hover {
            background: #ef4444;
            color: white;
        }

        .btn-inventory {
            background: #d1fae5;
            color: #059669;
        }

        .btn-inventory:hover {
            background: #059669;
            color: white;
        }

        .btn-edit {
            background: #ffedd5;
            color: #c2410c;
        }

        .btn-edit:hover {
            background: #c2410c;
            color: white;
        }
        
        .store-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(0, 1fr));
            gap: 10px;
        }

        /* .store-actions.has-delete removed as auto-fit handles it */
        
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

        /* List Layout Styles - REFACTORED FOR TABLE LOOK */
        /* List View Headers */
        .list-headers {
            display: none; /* Hidden in grid view */
            grid-template-columns: 1.5fr 2fr 1.5fr 1fr 1fr 0.8fr 1.2fr 260px;
            gap: 15px;
            padding: 15px 25px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            border-radius: 12px 12px 0 0;
            font-weight: 600;
            color: var(--text-light);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0;
        }

        .list-headers > div {
            display: flex;
            align-items: center;
        }

        /* List View Layout Overrides */
        .stores-grid.layout-list {
            display: flex;
            flex-direction: column;
            gap: 0;
            background: white;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-top: 0;
        }

        .stores-grid.layout-list .store-card {
            display: grid;
            grid-template-columns: 1.5fr 2fr 1.5fr 1fr 1fr 0.8fr 1.2fr 260px;
            gap: 15px;
            padding: 15px 25px;
            border-radius: 0;
            box-shadow: none;
            border: none;
            border-bottom: 1px solid var(--border);
            align-items: center;
            flex-direction: row; /* Reset flex direction */
        }

        .stores-grid.layout-list .store-card:last-child {
            border-bottom: none;
        }

        .stores-grid.layout-list .store-card:hover {
            transform: none;
            background-color: #f9fafb;
            border-color: var(--border);
        }

        /* Adjust inner elements for list view */
        .stores-grid.layout-list .store-header {
            border: none;
            margin: 0;
            padding: 0;
            width: auto;
            display: block;
            flex-shrink: 1;
        }
        
        .stores-grid.layout-list .store-name {
            font-size: 15px;
            margin-bottom: 2px;
        }
        
        .stores-grid.layout-list .store-code {
            font-size: 10px;
            padding: 2px 6px;
        }

        .stores-grid.layout-list .store-info {
            display: contents; /* Flatten children to grid */
        }
        
        .stores-grid.layout-list .info-row {
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .stores-grid.layout-list .info-row i {
            display: none; /* Hide icons in list view to save space */
        }
        
        .stores-grid.layout-list .empty-cell {
            color: #d1d5db;
        }

        .stores-grid.layout-list .store-stats {
            margin: 0;
            padding: 0;
            background: transparent;
            border: none;
            display: flex;
            gap: 15px;
            width: auto;
        }
        
        .stores-grid.layout-list .stat-item {
            text-align: left;
            display: flex;
            flex-direction: column;
        }
        
        .stores-grid.layout-list .stat-item .value {
            font-size: 14px;
        }
        
        .stores-grid.layout-list .stat-item .label {
            font-size: 10px;
        }

        .stores-grid.layout-list .store-actions {
            width: auto;
            display: flex;
            gap: 5px;
            grid-template-columns: none;
            justify-content: flex-end;
        }
        
        .stores-grid.layout-list .btn-view,
        .stores-grid.layout-list .btn-inventory,
        .stores-grid.layout-list .btn-edit,
        .stores-grid.layout-list .btn-delete {
            padding: 6px 10px;
            font-size: 12px;
            width: auto;
            display: inline-flex;
            flex-direction: row;
            gap: 5px;
            height: auto;
        }
        
        .stores-grid.layout-list .btn-delete {
            padding: 6px 10px; /* Match others */
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
                    <div class="value" id="total-stores-count"><?php echo number_format($total_records); ?></div>
                    <div class="label">Total Stores</div>
                </div>
                <div class="stat-card">
                    <div class="value" id="total-products-count"><?php echo number_format(array_sum(array_column($stores, 'product_count'))); ?></div>
                    <div class="label">Total Products</div>
                </div>
                <div class="stat-card">
                    <div class="value" id="total-stock-count"><?php echo number_format(array_sum(array_column($stores, 'total_stock'))); ?></div>
                    <div class="label">Total Stock</div>
                </div>
            </div>
        </div>
        
        <!-- Controls -->
        <div class="controls">
            <div class="search-box">
                <input type="text" id="store-search" name="search" placeholder="Search stores by name, address, phone, or city..." 
                       value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                <button type="button"><i class="fas fa-search"></i></button>
            </div>
            
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
        
        <!-- List Headers (Visible in List View) -->
        <div class="list-headers" id="list-headers">
            <div>Name / Code</div>
            <div>Address</div>
            <div>City / State</div>
            <div>Phone</div>
            <div>Manager</div>
            <div>POS</div>
            <div>Stats</div>
            <div>Actions</div>
        </div>

        <div id="stores-list-container" style="position: relative; min-height: 200px;">
            <?php include 'list_content.php'; ?>
        </div>
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
        // Real-time Search and AJAX Pagination
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('store-search');
            const resultsContainer = document.getElementById('stores-list-container');
            let searchTimeout;
            
            // Debounce function
            function debounce(func, wait) {
                let timeout;
                return function(...args) {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(this, args), wait);
                };
            }
            
            // Fetch stores function
            async function fetchStores(page = 1, search = '') {
                // Show loading state
                resultsContainer.style.opacity = '0.5';
                
                try {
                    const params = new URLSearchParams({
                        ajax: '1',
                        page: page,
                        search: search,
                        _t: new Date().getTime()
                    });
                    
                    const response = await fetch(`list.php?${params.toString()}`);
                    if (!response.ok) throw new Error('Network response was not ok');
                    
                    const data = await response.json();
                    
                    // Update content
                    resultsContainer.innerHTML = data.html;
                    resultsContainer.style.opacity = '1';
                    
                    // Update stats
                    document.getElementById('total-stores-count').textContent = new Intl.NumberFormat().format(data.total_records);
                    document.getElementById('total-products-count').textContent = new Intl.NumberFormat().format(data.total_products);
                    document.getElementById('total-stock-count').textContent = new Intl.NumberFormat().format(data.total_stock);
                    
                    // Update URL without reload
                    const url = new URL(window.location);
                    if (search) {
                        url.searchParams.set('search', search);
                    } else {
                        url.searchParams.delete('search');
                    }
                    if (page > 1) {
                        url.searchParams.set('page', page);
                    } else {
                        url.searchParams.delete('page');
                    }
                    window.history.pushState({}, '', url);
                    
                    // Re-apply layout preference
                    const savedLayout = localStorage.getItem('store_layout_preference');
                    if (savedLayout === 'list') {
                        switchLayout('list');
                    }
                    
                } catch (error) {
                    console.error('Error fetching stores:', error);
                    resultsContainer.style.opacity = '1';
                    // Optional: Show error message
                }
            }
            
            // Handle search input
            searchInput.addEventListener('input', debounce(function(e) {
                fetchStores(1, e.target.value);
            }, 300));
            
            // Handle pagination clicks
            resultsContainer.addEventListener('click', function(e) {
                const link = e.target.closest('.ajax-link');
                if (link) {
                    e.preventDefault();
                    const page = link.dataset.page;
                    const search = searchInput.value;
                    fetchStores(page, search);
                    
                    // Scroll to top of list
                    resultsContainer.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });

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
            const headers = document.getElementById('list-headers');
            
            if (layout === 'list') {
                grid.classList.add('layout-list');
                btns[0].classList.remove('active');
                btns[1].classList.add('active');
                if (headers) headers.style.display = 'grid';
            } else {
                grid.classList.remove('layout-list');
                btns[0].classList.add('active');
                btns[1].classList.remove('active');
                if (headers) headers.style.display = 'none';
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
