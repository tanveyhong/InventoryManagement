<?php
// Delete Store Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$db = getDB();
$store_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Get store data
$store = $db->fetch("SELECT * FROM stores WHERE id = ? AND active = 1", [$store_id]);

if (!$store) {
    addNotification('Store not found', 'error');
    header('Location: list.php');
    exit;
}

// Check if store has products
$product_count = $db->fetch("SELECT COUNT(*) as count FROM products WHERE store_id = ? AND active = 1", [$store_id])['count'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    try {
        $db->beginTransaction();
        
        if ($product_count > 0) {
            // Move products to default store or mark as inactive
            $db->query("UPDATE products SET store_id = NULL, updated_at = NOW() WHERE store_id = ?", [$store_id]);
        }
        
        // Soft delete the store
        $result = $db->query("UPDATE stores SET active = 0, updated_at = NOW() WHERE id = ?", [$store_id]);
        
        if ($result) {
            $db->commit();
            addNotification('Store deleted successfully!', 'success');
            header('Location: list.php');
            exit;
        } else {
            $db->rollback();
            addNotification('Failed to delete store. Please try again.', 'error');
        }
        
    } catch (Exception $e) {
        $db->rollback();
        addNotification('Error deleting store: ' . $e->getMessage(), 'error');
    }
}

$page_title = 'Delete Store - Inventory System';
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
            <h1>Delete Store</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="list.php">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="delete-confirmation">
                <h2>Delete Store</h2>
                
                <div class="alert alert-warning">
                    <h3>Are you sure you want to delete this store?</h3>
                    <p>This action will mark the store as inactive. It can be restored later if needed.</p>
                </div>
                
                <div class="store-details">
                    <h3>Store Information</h3>
                    <p><strong>Name:</strong> <?php echo htmlspecialchars($store['name']); ?></p>
                    <?php if (!empty($store['code'])): ?>
                        <p><strong>Code:</strong> <?php echo htmlspecialchars($store['code']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($store['address'])): ?>
                        <p><strong>Address:</strong> <?php echo htmlspecialchars($store['address']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($store['manager_name'])): ?>
                        <p><strong>Manager:</strong> <?php echo htmlspecialchars($store['manager_name']); ?></p>
                    <?php endif; ?>
                </div>
                
                <?php if ($product_count > 0): ?>
                    <div class="alert alert-info">
                        <p><strong>Warning:</strong> This store has <?php echo $product_count; ?> product(s). 
                           These products will be moved to "No Store" category.</p>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-actions">
                        <button type="submit" name="confirm_delete" class="btn btn-danger">
                            Yes, Delete Store
                        </button>
                        <a href="list.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
</body>
</html>