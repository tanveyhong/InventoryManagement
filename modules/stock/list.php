<?php
// Stock Management - List All Products
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';
require_once '../../redis.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$db = getDB();
$redis = new RedisConnection();

// Get filters
$store_filter = isset($_GET['store']) ? intval($_GET['store']) : null;
$category_filter = isset($_GET['category']) ? $_GET['category'] : null;
$status_filter = isset($_GET['status']) ? $_GET['status'] : null;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : null;
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$sort_order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = [];
$params = [];

if ($store_filter) {
    $where_conditions[] = 'p.store_id = ?';
    $params[] = $store_filter;
}

if ($category_filter) {
    $where_conditions[] = 'c.name = ?';
    $params[] = $category_filter;
}

if ($search_query) {
    $where_conditions[] = '(p.name ILIKE ? OR p.sku ILIKE ? OR p.description ILIKE ?)';
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($status_filter) {
    switch ($status_filter) {
        case 'low_stock':
            $where_conditions[] = 'p.quantity <= p.min_stock_level';
            break;
        case 'out_of_stock':
            $where_conditions[] = 'p.quantity = 0';
            break;
        case 'expiring':
            $where_conditions[] = 'p.expiry_date <= CURRENT_DATE + INTERVAL \'30 days\'';
            break;
        case 'expired':
            $where_conditions[] = 'p.expiry_date < CURRENT_DATE';
            break;
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Valid sort columns
$valid_sorts = ['name', 'sku', 'quantity', 'unit_price', 'expiry_date', 'created_at', 'category_name', 'store_name'];
if (!in_array($sort_by, $valid_sorts)) {
    $sort_by = 'name';
}

// Main query
$sql = "
    SELECT 
        p.id,
        p.name,
        p.sku,
        p.description,
        p.quantity,
        p.unit_price,
        p.min_stock_level,
        p.expiry_date,
        p.created_at,
        p.updated_at,
        c.name as category_name,
        s.name as store_name,
        CASE 
            WHEN p.quantity = 0 THEN 'out_of_stock'
            WHEN p.quantity <= p.min_stock_level THEN 'low_stock'
            WHEN p.expiry_date < CURRENT_DATE THEN 'expired'
            WHEN p.expiry_date <= CURRENT_DATE + INTERVAL '7 days' THEN 'expiring_soon'
            ELSE 'normal'
        END as status
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stores s ON p.store_id = s.id
    {$where_clause}
    ORDER BY {$sort_by} {$sort_order}
    LIMIT {$per_page} OFFSET {$offset}
";

$products = $db->fetchAll($sql, $params);

// Count query for pagination
$count_sql = "
    SELECT COUNT(*) as total
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stores s ON p.store_id = s.id
    {$where_clause}
";

$total_count = $db->fetchOne($count_sql, $params)['total'];
$pagination = paginate($page, $per_page, $total_count);

// Get stores and categories for filters
$stores = $db->fetchAll("SELECT id, name FROM stores ORDER BY name");
$categories = $db->fetchAll("SELECT DISTINCT name FROM categories ORDER BY name");

// Get summary statistics (cached)
$cache_key = 'stock_summary:' . md5($where_clause . serialize($params));
$summary_stats = $redis->get($cache_key);

if (!$summary_stats) {
    $stats_sql = "
        SELECT 
            COUNT(*) as total_products,
            SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock,
            SUM(CASE WHEN quantity <= min_stock_level THEN 1 ELSE 0 END) as low_stock,
            SUM(CASE WHEN expiry_date < CURRENT_DATE THEN 1 ELSE 0 END) as expired,
            SUM(quantity * unit_price) as total_value
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN stores s ON p.store_id = s.id
        {$where_clause}
    ";
    
    $summary_stats = $db->fetchOne($stats_sql, $params);
    $redis->setex($cache_key, 300, json_encode($summary_stats)); // Cache for 5 minutes
} else {
    $summary_stats = json_decode($summary_stats, true);
}

$page_title = 'Stock Management - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>Stock Management</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="list.php" class="active">Stock</a></li>
                    <li><a href="../stores/list.php">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="../alerts/low_stock.php">Alerts</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="page-header">
                <h2>Stock Inventory</h2>
                <div class="page-actions">
                    <a href="add.php" class="btn btn-primary">Add Product</a>
                    <a href="import.php" class="btn btn-secondary">Import CSV</a>
                    <a href="export.php" class="btn btn-outline">Export</a>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="stock-summary">
                <div class="summary-card">
                    <h3>Total Products</h3>
                    <p class="stat-number"><?php echo number_format($summary_stats['total_products']); ?></p>
                </div>
                <div class="summary-card warning">
                    <h3>Low Stock</h3>
                    <p class="stat-number"><?php echo number_format($summary_stats['low_stock']); ?></p>
                </div>
                <div class="summary-card danger">
                    <h3>Out of Stock</h3>
                    <p class="stat-number"><?php echo number_format($summary_stats['out_of_stock']); ?></p>
                </div>
                <div class="summary-card info">
                    <h3>Total Value</h3>
                    <p class="stat-number">$<?php echo number_format($summary_stats['total_value'], 2); ?></p>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-panel">
                <form method="GET" class="filters-form">
                    <div class="filter-group">
                        <label for="search">Search:</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_query ?? ''); ?>" placeholder="Product name, SKU, or description">
                    </div>

                    <div class="filter-group">
                        <label for="store">Store:</label>
                        <select id="store" name="store">
                            <option value="">All Stores</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" <?php echo $store_filter == $store['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="category">Category:</label>
                        <select id="category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category['name']); ?>" <?php echo $category_filter === $category['name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Status:</label>
                        <select id="status" name="status">
                            <option value="">All Status</option>
                            <option value="low_stock" <?php echo $status_filter === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="out_of_stock" <?php echo $status_filter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                            <option value="expiring" <?php echo $status_filter === 'expiring' ? 'selected' : ''; ?>>Expiring Soon</option>
                            <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort By:</label>
                        <select id="sort" name="sort">
                            <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="sku" <?php echo $sort_by === 'sku' ? 'selected' : ''; ?>>SKU</option>
                            <option value="quantity" <?php echo $sort_by === 'quantity' ? 'selected' : ''; ?>>Quantity</option>
                            <option value="unit_price" <?php echo $sort_by === 'unit_price' ? 'selected' : ''; ?>>Price</option>
                            <option value="expiry_date" <?php echo $sort_by === 'expiry_date' ? 'selected' : ''; ?>>Expiry Date</option>
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="order">Order:</label>
                        <select id="order" name="order">
                            <option value="asc" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="desc" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="list.php" class="btn btn-outline">Clear All</a>
                    </div>
                </form>
            </div>

            <!-- Products Table -->
            <?php if (empty($products)): ?>
                <div class="no-data">
                    <h3>No Products Found</h3>
                    <p>No products match your current filters.</p>
                    <a href="add.php" class="btn btn-primary">Add Your First Product</a>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product Details</th>
                                <th>Stock Level</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Expiry Date</th>
                                <th>Store/Category</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr class="product-row status-<?php echo $product['status']; ?>">
                                    <td>
                                        <div class="product-info">
                                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                            <?php if (!empty($product['sku'])): ?>
                                                <br><small class="sku">SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($product['description'])): ?>
                                                <br><small class="description"><?php echo htmlspecialchars(substr($product['description'], 0, 100)); ?><?php echo strlen($product['description']) > 100 ? '...' : ''; ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stock-info">
                                            <span class="current-stock status-<?php echo $product['status']; ?>">
                                                <?php echo number_format($product['quantity']); ?>
                                            </span>
                                            <br><small>Min: <?php echo number_format($product['min_stock_level']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="price">$<?php echo number_format($product['unit_price'], 2); ?></span>
                                        <br><small>Total: $<?php echo number_format($product['quantity'] * $product['unit_price'], 2); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $product['status']; ?>">
                                            <?php 
                                            switch($product['status']) {
                                                case 'out_of_stock': echo 'Out of Stock'; break;
                                                case 'low_stock': echo 'Low Stock'; break;
                                                case 'expired': echo 'Expired'; break;
                                                case 'expiring_soon': echo 'Expiring Soon'; break;
                                                default: echo 'Normal'; break;
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($product['expiry_date']): ?>
                                            <span class="expiry-date status-<?php echo $product['status']; ?>">
                                                <?php echo date('M j, Y', strtotime($product['expiry_date'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="no-expiry">No expiry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="location-info">
                                            <?php if ($product['store_name']): ?>
                                                <strong><?php echo htmlspecialchars($product['store_name']); ?></strong><br>
                                            <?php endif; ?>
                                            <?php if ($product['category_name']): ?>
                                                <small><?php echo htmlspecialchars($product['category_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="view.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-info" title="View Details">View</a>
                                            <a href="edit.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-primary" title="Edit Product">Edit</a>
                                            <a href="adjust.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-warning" title="Adjust Stock">Adjust</a>
                                            <a href="delete.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-danger" 
                                               onclick="return confirm('Are you sure you want to delete this product?')" title="Delete Product">Delete</a>
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
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['page'] - 1])); ?>" 
                               class="btn btn-sm btn-outline">Previous</a>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $pagination['page']; ?> of <?php echo $pagination['total_pages']; ?>
                            (<?php echo number_format($total_count); ?> total products)
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['page'] + 1])); ?>" 
                               class="btn btn-sm btn-outline">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Auto-submit filters on change
        document.addEventListener('DOMContentLoaded', function() {
            const filterInputs = document.querySelectorAll('.filters-form select:not(#sort):not(#order)');
            
            filterInputs.forEach(input => {
                input.addEventListener('change', function() {
                    // Add a small delay for better UX
                    setTimeout(() => {
                        this.form.submit();
                    }, 100);
                });
            });
            
            // Submit search on Enter key
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        this.form.submit();
                    }
                });
            }
        });
    </script>

    <style>
        .stock-summary {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            flex: 1;
            padding: 1.5rem;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #e0e0e0;
            text-align: center;
        }
        
        .summary-card.warning {
            border-left-color: #ffc107;
            background-color: #fff8e1;
        }
        
        .summary-card.danger {
            border-left-color: #dc3545;
            background-color: #ffebee;
        }
        
        .summary-card.info {
            border-left-color: #2196f3;
            background-color: #e3f2fd;
        }
        
        .filters-panel {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: end;
        }
        
        .filter-group {
            min-width: 150px;
        }
        
        .product-row.status-out_of_stock {
            background-color: rgba(220, 53, 69, 0.1);
        }
        
        .product-row.status-low_stock {
            background-color: rgba(255, 193, 7, 0.1);
        }
        
        .product-row.status-expired {
            background-color: rgba(244, 67, 54, 0.1);
        }
        
        .product-row.status-expiring_soon {
            background-color: rgba(255, 152, 0, 0.1);
        }
        
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-badge.status-normal {
            background-color: #4caf50;
            color: white;
        }
        
        .status-badge.status-low_stock {
            background-color: #ffc107;
            color: #212529;
        }
        
        .status-badge.status-out_of_stock {
            background-color: #dc3545;
            color: white;
        }
        
        .status-badge.status-expired {
            background-color: #f44336;
            color: white;
        }
        
        .status-badge.status-expiring_soon {
            background-color: #ff9800;
            color: white;
        }
        
        .current-stock.status-out_of_stock,
        .current-stock.status-low_stock {
            color: #dc3545;
            font-weight: bold;
        }
    </style>
</body>
</html>