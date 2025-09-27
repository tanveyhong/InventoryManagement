<?php
require_once '../../config.php';
require_once '../../functions.php';
session_start();

// Auth check - redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

// Load products from SQL database and map to template fields
$all_products = [];
$stores = [];
$categories = [];
try {
    $sqlDb = getSQLDB();
    // Fetch products with store name if available
    $rows = $sqlDb->fetchAll("SELECT p.*, s.name as store_name FROM products p LEFT JOIN stores s ON p.store_id = s.id ORDER BY p.name");
    foreach ($rows as $r) {
        $prod = [
            'id' => $r['id'] ?? null,
            'name' => $r['name'] ?? '',
            'sku' => $r['sku'] ?? '',
            'description' => $r['description'] ?? '',
            'quantity' => isset($r['quantity']) ? intval($r['quantity']) : 0,
            // map reorder_level/min_stock_level
            'min_stock_level' => isset($r['reorder_level']) ? intval($r['reorder_level']) : (isset($r['min_stock_level']) ? intval($r['min_stock_level']) : 0),
            // map price/unit_price
            'unit_price' => isset($r['price']) ? floatval($r['price']) : (isset($r['unit_price']) ? floatval($r['unit_price']) : 0.0),
            'expiry_date' => $r['expiry_date'] ?? null,
            'created_at' => $r['created_at'] ?? null,
            'category_name' => $r['category'] ?? ($r['category_name'] ?? null),
            'store_id' => $r['store_id'] ?? null,
            'store_name' => $r['store_name'] ?? null,
        ];
        $all_products[] = $prod;
    }

    // Fetch stores for filter dropdown
    try {
        $stores = $sqlDb->fetchAll("SELECT id, name FROM stores WHERE active = 1 ORDER BY name");
    } catch (Exception $e) {
        $stores = [];
    }

    // Derive categories from products if categories table not present
    $catNames = [];
    foreach ($all_products as $p) {
        if (!empty($p['category_name'])) $catNames[$p['category_name']] = true;
    }
    $categories = array_map(fn($n) => ['name' => $n], array_keys($catNames));
} catch (Exception $e) {
    // If SQL DB not available, fall back to empty list
    $all_products = [];
    $stores = [];
    $categories = [];
}

$store_filter = $_GET['store'] ?? '';
$category_filter = $_GET['category'] ?? '';
$search_query = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$sort_order = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'DESC' : 'ASC';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filtering
$filtered_products = array_filter($all_products, function($p) use ($store_filter, $category_filter, $search_query, $status_filter) {
    if ($store_filter && (!isset($p['store_id']) || $p['store_id'] != $store_filter)) return false;
    if ($category_filter && (!isset($p['category_name']) || $p['category_name'] != $category_filter)) return false;
    if ($search_query) {
        $q = strtolower($search_query);
        $fields = [$p['name'] ?? '', $p['sku'] ?? '', $p['description'] ?? ''];
        if (!array_filter($fields, fn($f) => strpos(strtolower($f), $q) !== false)) return false;
    }
    if ($status_filter) {
        switch ($status_filter) {
            case 'low_stock':
                if (!isset($p['quantity'], $p['min_stock_level']) || $p['quantity'] > $p['min_stock_level']) return false;
                break;
            case 'out_of_stock':
                if (!isset($p['quantity']) || $p['quantity'] != 0) return false;
                break;
            case 'expiring':
                if (empty($p['expiry_date']) || strtotime($p['expiry_date']) > strtotime('+30 days')) return false;
                break;
            case 'expired':
                if (empty($p['expiry_date']) || strtotime($p['expiry_date']) >= time()) return false;
                break;
        }
    }
    return true;
});

// Sorting
$valid_sorts = ['name', 'sku', 'quantity', 'unit_price', 'expiry_date', 'created_at', 'category_name', 'store_name'];
if (!in_array($sort_by, $valid_sorts)) {
    $sort_by = 'name';
}
$sort_func = function($a, $b) use ($sort_by, $sort_order) {
    $av = $a[$sort_by] ?? '';
    $bv = $b[$sort_by] ?? '';
    if ($av == $bv) return 0;
    if ($sort_order === 'ASC') return ($av < $bv) ? -1 : 1;
    return ($av > $bv) ? -1 : 1;
};
usort($filtered_products, $sort_func);

// Pagination
$total_count = count($filtered_products);
$page_title = 'Stock Management - Inventory System';

$pagination = paginate($page, $per_page, $total_count);
$products = array_slice($filtered_products, $offset, $per_page);

// Compute status for products so templates can render status-based classes safely
foreach ($products as &$prod) {
    $qty = isset($prod['quantity']) ? intval($prod['quantity']) : 0;
    $min = isset($prod['min_stock_level']) ? intval($prod['min_stock_level']) : 0;
    $status = 'normal';
    if ($qty === 0) {
        $status = 'out_of_stock';
    } elseif ($min > 0 && $qty <= $min) {
        $status = 'low_stock';
    }
    if (!empty($prod['expiry_date'])) {
        $exp = strtotime($prod['expiry_date']);
        if ($exp !== false) {
            if ($exp < time()) {
                $status = 'expired';
            } elseif ($exp <= strtotime('+30 days')) {
                // only mark expiring_soon if not already expired
                if ($status !== 'expired') $status = 'expiring_soon';
            }
        }
    }
    $prod['status'] = $status;
}
unset($prod);

// Fallback mock stores and categories for filters (only if none loaded from DB)
if (empty($stores)) {
    $stores = [
        ['id' => 'S1', 'name' => 'Main Store'],
        ['id' => 'S2', 'name' => 'Branch Store'],
    ];
}
if (empty($categories)) {
    $categories = [
        ['name' => 'Fruits'],
        ['name' => 'Dairy'],
    ];
}

// Summary statistics
$summary_stats = [
    'total_products' => $total_count,
    'out_of_stock' => 0,
    'low_stock' => 0,
    'expired' => 0,
    'total_value' => 0
];
foreach ($filtered_products as $p) {
    if ($p['quantity'] == 0) $summary_stats['out_of_stock']++;
    if ($p['quantity'] <= $p['min_stock_level']) $summary_stats['low_stock']++;
    if (!empty($p['expiry_date']) && strtotime($p['expiry_date']) < time()) $summary_stats['expired']++;
    $summary_stats['total_value'] += $p['quantity'] * $p['unit_price'];
}

$page_title = 'Stock Management - Inventory System';
?>
<?php
// Prepare dashboard header variables
$header_title = 'Stock Inventory';
$header_subtitle = 'Manage products, stock levels, and expiries';
$header_icon = 'fas fa-boxes';
$show_compact_toggle = true;
$header_stats = [
    [
        'value' => number_format($summary_stats['total_products']),
        'label' => 'Total Products',
        'icon' => 'fas fa-boxes',
        'type' => 'primary',
    ],
    [
        'value' => number_format($summary_stats['low_stock']),
        'label' => 'Low Stock',
        'icon' => 'fas fa-exclamation-triangle',
        'type' => 'warning',
    ],
    [
        'value' => number_format($summary_stats['out_of_stock']),
        'label' => 'Out of Stock',
        'icon' => 'fas fa-times-circle',
        'type' => 'alert',
    ],
    [
        'value' => '$' . number_format($summary_stats['total_value'], 2),
        'label' => 'Total Value',
        'icon' => 'fas fa-dollar-sign',
        'type' => 'success',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">

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
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] - 1])); ?>" 
                               class="btn btn-sm btn-outline">Previous</a>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $pagination['current_page']; ?> of <?php echo $pagination['total_pages']; ?>
                            (<?php echo number_format($total_count); ?> total products)
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] + 1])); ?>" 
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