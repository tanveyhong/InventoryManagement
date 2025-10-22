<?php
// Enhanced Store Inventory Viewer with Real-time Updates
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

// Require permission to view store inventory
if (!currentUserHasPermission('can_view_stores') && !currentUserHasPermission('can_view_inventory')) {
    $_SESSION['error'] = 'You do not have permission to view store inventory';
    header('Location: ../../index.php');
    exit;
}

$sql_db = getSQLDB();
$store_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$store_id) {
    header('Location: list.php');
    exit;
}

// Get store information with enhanced details
$store = $sql_db->fetch("SELECT s.*, r.name as region_name, r.code as region_code,
                                sp.total_sales, sp.avg_rating, sp.last_updated as performance_updated
                         FROM stores s 
                         LEFT JOIN regions r ON s.region_id = r.id 
                         LEFT JOIN store_performance sp ON sp.store_id = s.id
                         WHERE s.id = ? AND s.active = 1", [$store_id]);

if (!$store) {
    addNotification('Store not found or inactive', 'error');
    header('Location: list.php');
    exit;
}

// Get filtering and pagination parameters
$category = sanitizeInput($_GET['category'] ?? '');
$status = sanitizeInput($_GET['status'] ?? '');
$search = sanitizeInput($_GET['search'] ?? '');
$sort = sanitizeInput($_GET['sort'] ?? 'name');
$order = sanitizeInput($_GET['order'] ?? 'asc');
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = intval($_GET['per_page'] ?? 25);

// Enhanced search and filtering
$where_conditions = ['p.store_id = ?', 'p.active = 1'];
$params = [$store_id];

if (!empty($category)) {
    $where_conditions[] = 'p.category = ?';
    $params[] = $category;
}

if (!empty($status)) {
    if ($status === 'low_stock') {
        $where_conditions[] = 'p.quantity <= p.reorder_level';
    } elseif ($status === 'out_of_stock') {
        $where_conditions[] = 'p.quantity = 0';
    } elseif ($status === 'expired') {
        $where_conditions[] = 'p.expiry_date <= CURRENT_DATE';
    } elseif ($status === 'expiring_soon') {
        $where_conditions[] = 'p.expiry_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY)';
    }
}

if (!empty($search)) {
    $where_conditions[] = '(p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ? OR p.description LIKE ?)';
    $search_param = '%' . $search . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

// Build the WHERE clause
$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM products p {$where_clause}";
$total_result = $sql_db->fetch($count_query, $params);
$total_products = $total_result['total'] ?? 0;

// Calculate pagination
$pagination = paginate($page, $per_page, $total_products);

// Build the main query with enhanced data
$valid_sorts = ['name', 'sku', 'category', 'quantity', 'price', 'created_at', 'updated_at', 'expiry_date'];
$sort = in_array($sort, $valid_sorts) ? $sort : 'name';
$order = in_array(strtolower($order), ['asc', 'desc']) ? strtolower($order) : 'asc';

$query = "SELECT p.*, 
                 CASE 
                     WHEN p.quantity = 0 THEN 'out_of_stock'
                     WHEN p.quantity <= p.reorder_level THEN 'low_stock'
                     WHEN p.expiry_date < CURRENT_DATE THEN 'expired'
                     WHEN p.expiry_date <= DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN 'expiring_soon'
                     ELSE 'in_stock'
                 END as stock_status,
                 DATEDIFF(p.expiry_date, CURRENT_DATE) as days_to_expiry
          FROM products p 
          {$where_clause}
          ORDER BY p.{$sort} {$order}
          LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";

$products = $sql_db->fetchAll($query, $params);

// Get categories for filtering
$categories = $sql_db->fetchAll("SELECT DISTINCT category FROM products WHERE store_id = ? AND active = 1 AND category IS NOT NULL ORDER BY category", [$store_id]);

// Get inventory summary statistics
$summary_stats = $sql_db->fetch("
    SELECT 
        COUNT(*) as total_products,
        SUM(quantity) as total_quantity,
        SUM(quantity * price) as total_value,
        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock_count,
        SUM(CASE WHEN quantity <= reorder_level THEN 1 ELSE 0 END) as low_stock_count,
        SUM(CASE WHEN expiry_date < CURRENT_DATE THEN 1 ELSE 0 END) as expired_count,
        SUM(CASE WHEN expiry_date BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_soon_count
    FROM products 
    WHERE store_id = ? AND active = 1
", [$store_id]);

$summary_stats = $summary_stats ?: [
    'total_products' => 0, 'total_quantity' => 0, 'total_value' => 0,
    'out_of_stock_count' => 0, 'low_stock_count' => 0, 'expired_count' => 0, 'expiring_soon_count' => 0
];

// Validate sort column
$allowed_sorts = ['name', 'sku', 'category', 'quantity', 'cost_price', 'selling_price', 'expiry_date', 'created_at'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'name';
}
$order = ($order === 'desc') ? 'desc' : 'asc';

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM products p $where_clause";
$total_records = $db->fetch($count_sql, $params)['total'] ?? 0;

// Calculate pagination
$pagination = paginate($page, $per_page, $total_records);

// Get products
$sql = "SELECT p.*, 
               CASE 
                   WHEN p.quantity = 0 THEN 'out_of_stock'
                   WHEN p.quantity <= p.reorder_level THEN 'low_stock'
                   WHEN p.expiry_date < CURDATE() THEN 'expired'
                   WHEN p.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring_soon'
                   ELSE 'in_stock'
               END as stock_status,
               (p.quantity * p.selling_price) as total_value
        FROM products p
        $where_clause
        ORDER BY p.$sort $order
        LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";

$products = $db->fetchAll($sql, $params);

// Get categories for filter
$categories_sql = "SELECT DISTINCT category FROM products WHERE store_id = ? AND active = 1 ORDER BY category";
$categories = $db->fetchAll($categories_sql, [$store_id]);

// Get inventory summary
$summary_sql = "SELECT 
                    COUNT(*) as total_products,
                    SUM(quantity) as total_quantity,
                    SUM(quantity * cost_price) as total_cost_value,
                    SUM(quantity * selling_price) as total_selling_value,
                    COUNT(DISTINCT category) as categories_count,
                    COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock,
                    COUNT(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 END) as low_stock,
                    COUNT(CASE WHEN expiry_date < CURDATE() THEN 1 END) as expired,
                    COUNT(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as expiring_soon
                FROM products 
                WHERE store_id = ? AND active = 1";

$summary = $db->fetch($summary_sql, [$store_id]);

$page_title = "Store Inventory - {$store['name']} - Inventory System";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .store-header {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .inventory-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .summary-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .summary-number {
            font-size: 2em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .summary-label {
            color: #666;
            font-size: 0.9em;
        }
        
        .status-in_stock { color: #28a745; }
        .status-low_stock { color: #ffc107; }
        .status-out_of_stock { color: #dc3545; }
        .status-expired { color: #dc3545; background: #f8d7da; }
        .status-expiring_soon { color: #fd7e14; }
        
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .filters-row {
            display: grid;
            grid-template-columns: 1fr 200px 200px 150px 100px;
            gap: 10px;
            align-items: end;
        }
        
        .product-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .product-table th,
        .product-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .product-table th {
            background: #f8f9fa;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .product-table tr:hover {
            background: #f8f9fa;
        }
        
        .sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
        }
        
        .sortable:hover {
            background: #e9ecef;
        }
        
        .sort-icon {
            margin-left: 5px;
            opacity: 0.5;
        }
        
        .sort-active {
            opacity: 1;
        }
        
        .stock-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .badge-in_stock {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-low_stock {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-out_of_stock {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-expired {
            background: #f5c6cb;
            color: #721c24;
        }
        
        .badge-expiring_soon {
            background: #ffeaa7;
            color: #b8860b;
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin: 20px 0;
        }
        
        .export-section {
            margin: 20px 0;
            padding: 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Store Inventory Viewer</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="list.php">Stores</a></li>
                    <li><a href="map.php">Store Map</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <!-- Store Information Header -->
            <div class="store-header">
                <div style="display: flex; justify-content: space-between; align-items: start;">
                    <div>
                        <h2><?php echo htmlspecialchars($store['name']); ?></h2>
                        <p><strong>Code:</strong> <?php echo htmlspecialchars($store['code']); ?></p>
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($store['address'] . ', ' . $store['city'] . ', ' . $store['state']); ?></p>
                        <p><strong>Region:</strong> <?php echo htmlspecialchars($store['region_name'] ?? 'N/A'); ?></p>
                        <p><strong>Type:</strong> <?php echo htmlspecialchars(ucfirst($store['store_type'])); ?></p>
                    </div>
                    <div class="page-actions">
                        <a href="profile.php?id=<?php echo $store_id; ?>" class="btn btn-secondary">Store Profile</a>
                        <a href="edit.php?id=<?php echo $store_id; ?>" class="btn btn-outline">Edit Store</a>
                    </div>
                </div>
            </div>

            <!-- Inventory Summary -->
            <div class="inventory-summary">
                <div class="summary-card">
                    <div class="summary-number status-in_stock"><?php echo number_format($summary['total_products'] ?? 0); ?></div>
                    <div class="summary-label">Total Products</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number"><?php echo number_format($summary['total_quantity'] ?? 0); ?></div>
                    <div class="summary-label">Total Quantity</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number">$<?php echo number_format($summary['total_cost_value'] ?? 0, 2); ?></div>
                    <div class="summary-label">Cost Value</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number">$<?php echo number_format($summary['total_selling_value'] ?? 0, 2); ?></div>
                    <div class="summary-label">Selling Value</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number status-low_stock"><?php echo number_format($summary['low_stock'] ?? 0); ?></div>
                    <div class="summary-label">Low Stock</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number status-out_of_stock"><?php echo number_format($summary['out_of_stock'] ?? 0); ?></div>
                    <div class="summary-label">Out of Stock</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number status-expired"><?php echo number_format($summary['expired'] ?? 0); ?></div>
                    <div class="summary-label">Expired</div>
                </div>
                <div class="summary-card">
                    <div class="summary-number status-expiring_soon"><?php echo number_format($summary['expiring_soon'] ?? 0); ?></div>
                    <div class="summary-label">Expiring Soon</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <a href="../stock/add.php?store_id=<?php echo $store_id; ?>" class="btn btn-primary">Add Product</a>
                <a href="bulk_update.php?store_id=<?php echo $store_id; ?>" class="btn btn-secondary">Bulk Update</a>
                <a href="stock_take.php?store_id=<?php echo $store_id; ?>" class="btn btn-outline">Stock Take</a>
                <button onclick="exportInventory()" class="btn btn-outline">Export Data</button>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <form method="GET" action="">
                    <input type="hidden" name="id" value="<?php echo $store_id; ?>">
                    <div class="filters-row">
                        <input type="text" name="search" placeholder="Search products..." 
                               value="<?php echo htmlspecialchars($search); ?>" class="search-input">
                        
                        <select name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['category']); ?>" 
                                        <?php echo ($category === $cat['category']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['category']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="in_stock" <?php echo ($status === 'in_stock') ? 'selected' : ''; ?>>In Stock</option>
                            <option value="low_stock" <?php echo ($status === 'low_stock') ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="out_of_stock" <?php echo ($status === 'out_of_stock') ? 'selected' : ''; ?>>Out of Stock</option>
                            <option value="expired" <?php echo ($status === 'expired') ? 'selected' : ''; ?>>Expired</option>
                            <option value="expiring_soon" <?php echo ($status === 'expiring_soon') ? 'selected' : ''; ?>>Expiring Soon</option>
                        </select>
                        
                        <select name="sort">
                            <option value="name" <?php echo ($sort === 'name') ? 'selected' : ''; ?>>Name</option>
                            <option value="sku" <?php echo ($sort === 'sku') ? 'selected' : ''; ?>>SKU</option>
                            <option value="category" <?php echo ($sort === 'category') ? 'selected' : ''; ?>>Category</option>
                            <option value="quantity" <?php echo ($sort === 'quantity') ? 'selected' : ''; ?>>Quantity</option>
                            <option value="selling_price" <?php echo ($sort === 'selling_price') ? 'selected' : ''; ?>>Price</option>
                            <option value="expiry_date" <?php echo ($sort === 'expiry_date') ? 'selected' : ''; ?>>Expiry</option>
                        </select>
                        
                        <select name="order">
                            <option value="asc" <?php echo ($order === 'asc') ? 'selected' : ''; ?>>↑ ASC</option>
                            <option value="desc" <?php echo ($order === 'desc') ? 'selected' : ''; ?>>↓ DESC</option>
                        </select>
                    </div>
                    
                    <div style="margin-top: 10px;">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="inventory_viewer.php?id=<?php echo $store_id; ?>" class="btn btn-outline">Clear Filters</a>
                        <span style="margin-left: 20px; color: #666;">
                            Showing <?php echo number_format($pagination['showing_start']); ?>-<?php echo number_format($pagination['showing_end']); ?> 
                            of <?php echo number_format($total_records); ?> products
                        </span>
                    </div>
                </form>
            </div>

            <!-- Products Table -->
            <?php if (empty($products)): ?>
                <div class="no-data">
                    <p>No products found with current filters.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="product-table">
                        <thead>
                            <tr>
                                <th>Product Details</th>
                                <th>SKU</th>
                                <th>Category</th>
                                <th>Quantity</th>
                                <th>Status</th>
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                                <th>Total Value</th>
                                <th>Expiry Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                        <?php if ($product['description']): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars(substr($product['description'], 0, 50)) . (strlen($product['description']) > 50 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                        <?php if ($product['barcode']): ?>
                                            <br><small style="color: #999;">Barcode: <?php echo htmlspecialchars($product['barcode']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['sku']); ?></td>
                                    <td><?php echo htmlspecialchars($product['category']); ?></td>
                                    <td>
                                        <strong class="status-<?php echo $product['stock_status']; ?>">
                                            <?php echo number_format($product['quantity']); ?>
                                        </strong>
                                        <?php if ($product['unit']): ?>
                                            <br><small><?php echo htmlspecialchars($product['unit']); ?></small>
                                        <?php endif; ?>
                                        <?php if ($product['reorder_level'] > 0): ?>
                                            <br><small style="color: #999;">Reorder: <?php echo number_format($product['reorder_level']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="stock-badge badge-<?php echo $product['stock_status']; ?>">
                                            <?php 
                                            switch($product['stock_status']) {
                                                case 'in_stock': echo 'In Stock'; break;
                                                case 'low_stock': echo 'Low Stock'; break;
                                                case 'out_of_stock': echo 'Out of Stock'; break;
                                                case 'expired': echo 'Expired'; break;
                                                case 'expiring_soon': echo 'Expiring Soon'; break;
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>$<?php echo number_format($product['cost_price'], 2); ?></td>
                                    <td>$<?php echo number_format($product['selling_price'], 2); ?></td>
                                    <td>$<?php echo number_format($product['total_value'], 2); ?></td>
                                    <td>
                                        <?php if ($product['expiry_date']): ?>
                                            <?php 
                                            $expiry_date = new DateTime($product['expiry_date']);
                                            $now = new DateTime();
                                            $diff = $now->diff($expiry_date);
                                            ?>
                                            <span class="status-<?php echo $product['stock_status']; ?>">
                                                <?php echo $expiry_date->format('M j, Y'); ?>
                                            </span>
                                            <br><small style="color: #666;">
                                                <?php
                                                if ($expiry_date < $now) {
                                                    echo 'Expired ' . $diff->days . ' days ago';
                                                } else {
                                                    echo 'Expires in ' . $diff->days . ' days';
                                                }
                                                ?>
                                            </small>
                                        <?php else: ?>
                                            <span style="color: #999;">No expiry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="../stock/view.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-outline">View</a>
                                        <a href="../stock/adjust.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-secondary">Adjust</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($pagination['current_page'] > 1): ?>
                            <a href="?id=<?php echo $store_id; ?>&page=<?php echo ($pagination['current_page'] - 1); ?>&category=<?php echo urlencode($category); ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&order=<?php echo urlencode($order); ?>" class="pagination-btn">← Previous</a>
                        <?php endif; ?>
                        
                        <span class="pagination-info">
                            Page <?php echo $pagination['current_page']; ?> of <?php echo $pagination['total_pages']; ?>
                        </span>
                        
                        <?php if ($pagination['current_page'] < $pagination['total_pages']): ?>
                            <a href="?id=<?php echo $store_id; ?>&page=<?php echo ($pagination['current_page'] + 1); ?>&category=<?php echo urlencode($category); ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>&sort=<?php echo urlencode($sort); ?>&order=<?php echo urlencode($order); ?>" class="pagination-btn">Next →</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Export Section -->
            <div class="export-section">
                <h3>Export Options</h3>
                <p>Export the current filtered inventory data:</p>
                <div style="margin-top: 10px;">
                    <button onclick="exportToCsv()" class="btn btn-secondary">Export to CSV</button>
                    <button onclick="exportToPdf()" class="btn btn-outline">Export to PDF</button>
                    <button onclick="printInventory()" class="btn btn-outline">Print Report</button>
                </div>
            </div>
        </main>
    </div>

    <script>
        function exportInventory() {
            exportToCsv();
        }
        
        function exportToCsv() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'csv');
            window.open('api/export_inventory.php?' + params.toString(), '_blank');
        }
        
        function exportToPdf() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', 'pdf');
            window.open('api/export_inventory.php?' + params.toString(), '_blank');
        }
        
        function printInventory() {
            window.print();
        }
        
        // Real-time inventory updates
        function refreshInventory() {
            location.reload();
        }
        
        // Auto-refresh every 5 minutes
        setInterval(refreshInventory, 300000);
        
        // Add visual feedback for expired items
        document.addEventListener('DOMContentLoaded', function() {
            const expiredRows = document.querySelectorAll('.status-expired');
            expiredRows.forEach(row => {
                if (row.closest('tr')) {
                    row.closest('tr').style.backgroundColor = '#fff5f5';
                }
            });
        });
    </script>
</body>
</html>