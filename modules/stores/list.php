<?php
// Store List Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$db = getDB();
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$search = sanitizeInput($_GET['search'] ?? '');

// Build search condition
$where_clause = "WHERE s.active = 1";
$params = [];

if (!empty($search)) {
    $where_clause .= " AND (s.name LIKE ? OR s.address LIKE ? OR s.phone LIKE ?)";
    $search_param = "%{$search}%";
    $params = [$search_param, $search_param, $search_param];
}

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM stores s $where_clause";
$total_records = $db->fetch($count_sql, $params)['total'] ?? 0;

// Calculate pagination
$pagination = paginate($page, $per_page, $total_records);

// Get stores
$sql = "SELECT s.*, 
               COUNT(DISTINCT p.id) as product_count,
               COALESCE(SUM(p.quantity), 0) as total_stock
        FROM stores s
        LEFT JOIN products p ON s.id = p.store_id AND p.active = 1
        $where_clause
        GROUP BY s.id
        ORDER BY s.name
        LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";

$stores = $db->fetchAll($sql, $params);

$page_title = 'Store Management - Inventory System';
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
            <h1>Store Management</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="list.php" class="active">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="page-header">
                <h2>Stores (<?php echo number_format($total_records); ?>)</h2>
                <div class="page-actions">
                    <a href="add.php" class="btn btn-primary">Add New Store</a>
                </div>
            </div>

            <!-- Search Form -->
            <div class="search-section">
                <form method="GET" action="" class="search-form">
                    <input type="text" name="search" placeholder="Search stores..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-secondary">Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="list.php" class="btn btn-outline">Clear</a>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Stores Table -->
            <?php if (empty($stores)): ?>
                <div class="no-data">
                    <p>No stores found.</p>
                    <a href="add.php" class="btn btn-primary">Add Your First Store</a>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Store Name</th>
                                <th>Address</th>
                                <th>Phone</th>
                                <th>Manager</th>
                                <th>Products</th>
                                <th>Total Stock</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stores as $store): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($store['name']); ?></strong>
                                        <?php if (!empty($store['code'])): ?>
                                            <br><small>Code: <?php echo htmlspecialchars($store['code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($store['address'])): ?>
                                            <?php echo htmlspecialchars($store['address']); ?>
                                            <?php if (!empty($store['city'])): ?>
                                                <br><small><?php echo htmlspecialchars($store['city']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">Not provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($store['phone'] ?? 'Not provided'); ?></td>
                                    <td><?php echo htmlspecialchars($store['manager_name'] ?? 'Not assigned'); ?></td>
                                    <td>
                                        <span class="badge badge-info"><?php echo number_format($store['product_count']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge badge-primary"><?php echo number_format($store['total_stock']); ?></span>
                                    </td>
                                    <td>
                                        <span class="status <?php echo $store['active'] ? 'active' : 'inactive'; ?>">
                                            <?php echo $store['active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="../stock/list.php?store=<?php echo $store['id']; ?>" 
                                               class="btn btn-sm btn-info" title="View Products">Products</a>
                                            <a href="edit.php?id=<?php echo $store['id']; ?>" 
                                               class="btn btn-sm btn-primary" title="Edit Store">Edit</a>
                                            <a href="delete.php?id=<?php echo $store['id']; ?>" 
                                               class="btn btn-sm btn-danger" title="Delete Store"
                                               onclick="return confirm('Are you sure you want to delete this store?')">Delete</a>
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
                            <a href="?page=<?php echo $pagination['page'] - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                               class="btn btn-sm btn-outline">Previous</a>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $pagination['page']; ?> of <?php echo $pagination['total_pages']; ?>
                            (<?php echo number_format($total_records); ?> total stores)
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="?page=<?php echo $pagination['page'] + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                               class="btn btn-sm btn-outline">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
</body>
</html>