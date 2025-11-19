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
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            margin: 0 0 10px 0;
            color: #667eea;
            font-size: 28px;
        }
        
        .stats {
            display: flex;
            gap: 20px;
            margin-top: 15px;
        }
        
        .stat {
            background: #f8f9fa;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .stat strong {
            color: #667eea;
            font-size: 18px;
        }
        
        .controls {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            display: flex;
            gap: 15px;
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
            padding: 12px 40px 12px 15px;
            border: 2px solid #e1e4e8;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .search-box button {
            position: absolute;
            right: 5px;
            top: 50%;
            transform: translateY(-50%);
            background: #667eea;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .btn {
            padding: 12px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .stores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .store-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s;
        }
        
        .store-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }
        
        .store-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .store-name {
            font-size: 18px;
            font-weight: bold;
            color: #1f2937;
            margin: 0;
        }
        
        .store-code {
            background: #667eea;
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .store-info {
            margin: 12px 0;
            color: #6b7280;
            font-size: 14px;
        }
        
        .store-info i {
            width: 20px;
            color: #667eea;
        }
        
        .store-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 15px 0;
        }
        
        .stat-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
        }
        
        .stat-item .value {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
        }
        
        .stat-item .label {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .store-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }
        
        .store-actions a {
            flex: 1;
            text-align: center;
            padding: 8px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-view {
            background: #e0e7ff;
            color: #667eea;
        }
        
        .btn-view:hover {
            background: #667eea;
            color: white;
        }
        
        .btn-edit {
            background: #fef3c7;
            color: #f59e0b;
        }
        
        .btn-edit:hover {
            background: #f59e0b;
            color: white;
        }
        
        .pagination {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .pagination-info {
            color: #6b7280;
            font-size: 14px;
        }
        
        .pagination-links {
            display: flex;
            gap: 8px;
        }
        
        .pagination-links a,
        .pagination-links span {
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        
        .pagination-links a {
            background: #f3f4f6;
            color: #667eea;
        }
        
        .pagination-links a:hover {
            background: #667eea;
            color: white;
        }
        
        .pagination-links .current {
            background: #667eea;
            color: white;
        }
        
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .message.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        
        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        
        .no-stores {
            background: white;
            border-radius: 12px;
            padding: 60px 20px;
            text-align: center;
            color: #6b7280;
        }
        
        .no-stores i {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1><i class="fas fa-store"></i> Store Management</h1>
            <p>Manage your retail locations</p>
            <div class="stats">
                <div class="stat">
                    <div><strong><?php echo number_format($total_records); ?></strong></div>
                    <div>Total Stores</div>
                </div>
                <div class="stat">
                    <div><strong><?php echo number_format(array_sum(array_column($stores, 'product_count'))); ?></strong></div>
                    <div>Total Products</div>
                </div>
                <div class="stat">
                    <div><strong><?php echo number_format(array_sum(array_column($stores, 'total_stock'))); ?></strong></div>
                    <div>Total Stock</div>
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
            
            <?php if (currentUserHasPermission('can_add_stores')): ?>
            <a href="add.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add New Store
            </a>
            <?php endif; ?>
            
            <a href="list.php" class="btn" style="background: #f3f4f6; color: #6b7280;">
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
                    <div><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($store['address'] ?? 'N/A'); ?></div>
                    <div><i class="fas fa-city"></i> <?php echo htmlspecialchars($store['city'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($store['state'] ?? 'N/A'); ?></div>
                    <?php if (!empty($store['phone'])): ?>
                    <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($store['phone']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($store['manager_name'])): ?>
                    <div><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($store['manager_name']); ?></div>
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
                
                <div class="store-actions">
                    <a href="profile.php?id=<?php echo $store['id']; ?>" class="btn-view">
                        <i class="fas fa-eye"></i> View
                    </a>
                    <?php if (currentUserHasPermission('can_edit_stores')): ?>
                    <a href="edit.php?id=<?php echo $store['id']; ?>" class="btn-edit">
                        <i class="fas fa-edit"></i> Edit
                    </a>
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
    
    <!-- Offline Functionality for Stores List -->
    <script>
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
