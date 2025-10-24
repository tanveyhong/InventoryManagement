<?php
/**
 * ASSIGN MAIN PRODUCT TO STORE
 * =============================
 * Creates a store variant from a main product
 * - Main product: SKU without store suffix (e.g., BEV-001)
 * - Store variant: SKU with store suffix (e.g., BEV-001-S6)
 * - Stock is moved from main product to store variant
 */

require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_add_inventory') && !currentUserHasPermission('can_edit_inventory')) {
    $_SESSION['error'] = 'You do not have permission to assign products';
    header('Location: ../../index.php');
    exit;
}

$db = getDB();
$sqlDb = getSQLDB();
$errors = [];

// Get main product ID from URL
$main_product_id = $_GET['id'] ?? null;
if (!$main_product_id) {
    $_SESSION['error'] = 'No product specified';
    header('Location: list.php');
    exit;
}

// Load main product from SQL
$mainProduct = $sqlDb->fetch("SELECT * FROM products WHERE id = ? AND store_id IS NULL", [$main_product_id]);
if (!$mainProduct) {
    $_SESSION['error'] = 'Main product not found';
    header('Location: list.php');
    exit;
}

// Get all stores
$stores = $sqlDb->fetchAll("SELECT * FROM stores WHERE active = 1 ORDER BY name");

// Get already assigned stores
$assignedStores = $sqlDb->fetchAll(
    "SELECT store_id FROM products WHERE sku LIKE ? AND store_id IS NOT NULL",
    [$mainProduct['sku'] . '-S%']
);
$assignedStoreIds = array_column($assignedStores, 'store_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $store_id = (int)($_POST['store_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);

    $errors = [];

    // Validation
    if ($store_id <= 0) $errors[] = 'Please select a store';
    if ($quantity < 0) $errors[] = 'Quantity cannot be negative';
    if ($quantity > $mainProduct['quantity']) {
        $errors[] = 'Cannot assign more than available quantity (' . $mainProduct['quantity'] . ')';
    }

    // Check if already assigned
    if (in_array($store_id, $assignedStoreIds)) {
        $errors[] = 'Product already assigned to this store';
    }

    if (empty($errors)) {
        try {
            $sqlDb->beginTransaction();

            // Get store info
            $store = $sqlDb->fetch("SELECT * FROM stores WHERE id = ?", [$store_id]);
            if (!$store) throw new Exception('Store not found');

            // Create store variant SKU
            $variantSku = $mainProduct['sku'] . '-S' . $store_id;

            // Create store variant
            $sql = "
                INSERT INTO products
                    (name, sku, barcode, description, category, unit, cost_price, price, quantity, reorder_level, expiry_date, store_id, active, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ";
            $sqlDb->execute($sql, [
                $mainProduct['name'],
                $variantSku,
                $mainProduct['barcode'],
                $mainProduct['description'],
                $mainProduct['category'],
                $mainProduct['unit'],
                $mainProduct['cost_price'],
                $mainProduct['price'],
                $quantity,
                $mainProduct['reorder_level'],
                $mainProduct['expiry_date'],
                $store_id
            ]);

            $variant_id = $sqlDb->lastInsertId();

            // Update main product quantity (subtract assigned quantity)
            $newMainQty = $mainProduct['quantity'] - $quantity;
            $sqlDb->execute(
                "UPDATE products SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$newMainQty, $main_product_id]
            );

            // Log stock movements
            // 1. Movement OUT from main product
            $sqlDb->execute("
                INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                VALUES (?, NULL, 'out', ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ", [
                $main_product_id,
                $quantity,
                'Store Assignment',
                "Assigned to {$store['name']} (Store variant created: {$variantSku})",
                $_SESSION['user_id'] ?? null
            ]);

            // 2. Movement IN to store variant
            $sqlDb->execute("
                INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                VALUES (?, ?, 'in', ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ", [
                $variant_id,
                $store_id,
                $quantity,
                'Store Assignment',
                "Assigned from main product (ID: {$main_product_id})",
                $_SESSION['user_id'] ?? null
            ]);

            $sqlDb->commit();

            // Sync to Firebase (best effort)
            try {
                // Create store variant in Firebase
                $variantDoc = [
                    'name'          => $mainProduct['name'],
                    'sku'           => $variantSku,
                    'barcode'       => $mainProduct['barcode'],
                    'description'   => $mainProduct['description'],
                    'category'      => $mainProduct['category'],
                    'unit'          => $mainProduct['unit'],
                    'cost_price'    => floatval($mainProduct['cost_price'] ?? 0),
                    'selling_price' => floatval($mainProduct['price'] ?? 0),
                    'price'         => floatval($mainProduct['price'] ?? 0),
                    'quantity'      => $quantity,
                    'reorder_level' => intval($mainProduct['reorder_level'] ?? 0),
                    'min_stock_level' => intval($mainProduct['reorder_level'] ?? 0),
                    'expiry_date'   => $mainProduct['expiry_date'],
                    'store_id'      => $store_id,
                    'active'        => 1,
                    'created_at'    => date('c'),
                    'updated_at'    => date('c'),
                ];
                $db->create('products', $variantDoc, (string)$variant_id);

                // Update main product in Firebase
                $db->update('products', (string)$main_product_id, [
                    'quantity' => $newMainQty,
                    'updated_at' => date('c')
                ]);

                // Log stock movements in Firebase
                $db->create('stock_movements', [
                    'product_id'    => $main_product_id,
                    'store_id'      => null,
                    'movement_type' => 'out',
                    'quantity'      => $quantity,
                    'reference'     => 'Store Assignment',
                    'notes'         => "Assigned to {$store['name']}",
                    'user_id'       => $_SESSION['user_id'] ?? null,
                    'created_at'    => date('c'),
                ]);

                $db->create('stock_movements', [
                    'product_id'    => $variant_id,
                    'store_id'      => $store_id,
                    'movement_type' => 'in',
                    'quantity'      => $quantity,
                    'reference'     => 'Store Assignment',
                    'notes'         => "Assigned from main product",
                    'user_id'       => $_SESSION['user_id'] ?? null,
                    'created_at'    => date('c'),
                ]);
            } catch (Throwable $t) {
                error_log('Firebase sync failed: ' . $t->getMessage());
            }

            // Log audit
            try {
                log_stock_audit([
                    'action'         => 'assign_to_store',
                    'product_id'     => (string)$main_product_id,
                    'sku'            => $mainProduct['sku'],
                    'product_name'   => $mainProduct['name'],
                    'store_id'       => $store_id,
                    'user_id'        => $_SESSION['user_id'] ?? null,
                    'username'       => $_SESSION['username'] ?? null,
                ]);
            } catch (Throwable $t) {
                error_log('Audit log failed: ' . $t->getMessage());
            }

            // Clear caches
            @unlink(__DIR__ . '/../../storage/cache/stock_list_data.cache');
            @unlink(__DIR__ . '/../../storage/cache/pos_products.cache');

            $_SESSION['success'] = "Product assigned to {$store['name']} successfully! Store variant {$variantSku} created.";
            header('Location: list.php?refresh=1');
            exit;

        } catch (Throwable $t) {
            if ($sqlDb->inTransaction()) $sqlDb->rollBack();
            $errors[] = 'Error assigning product: ' . $t->getMessage();
        }
    }
}

$page_title = 'Assign to Store - ' . $mainProduct['name'];
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">
        <main>
            <div class="page-header">
                <h1><i class="fas fa-store"></i> Assign Product to Store</h1>
                <a href="list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Stock List
                </a>
            </div>

            <div class="info-card">
                <h3><i class="fas fa-box"></i> Main Product</h3>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($mainProduct['name']); ?></p>
                <p><strong>SKU:</strong> <?php echo htmlspecialchars($mainProduct['sku']); ?></p>
                <p><strong>Available Quantity:</strong> <?php echo number_format($mainProduct['quantity']); ?></p>
                <p><strong>Price:</strong> RM <?php echo number_format($mainProduct['price'], 2); ?></p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (empty($stores)): ?>
                <div class="alert alert-warning">
                    <p>No active stores found. Please add stores first.</p>
                </div>
            <?php else: ?>
                <form method="POST" class="form-card">
                    <div class="form-group">
                        <label for="store_id"><i class="fas fa-store"></i> Select Store *</label>
                        <select id="store_id" name="store_id" required>
                            <option value="">-- Select Store --</option>
                            <?php foreach ($stores as $store): ?>
                                <?php if (!in_array($store['id'], $assignedStoreIds)): ?>
                                    <option value="<?php echo $store['id']; ?>">
                                        <?php echo htmlspecialchars($store['name']); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="quantity"><i class="fas fa-boxes"></i> Quantity to Assign *</label>
                        <input type="number" id="quantity" name="quantity" 
                               min="0" max="<?php echo $mainProduct['quantity']; ?>" 
                               value="<?php echo $mainProduct['quantity']; ?>" required>
                        <small>Maximum available: <?php echo number_format($mainProduct['quantity']); ?></small>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check"></i> Assign to Store
                        </button>
                        <a href="list.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (!empty($assignedStoreIds)): ?>
                <div class="info-card" style="margin-top: 30px;">
                    <h3><i class="fas fa-info-circle"></i> Already Assigned to Stores</h3>
                    <ul>
                        <?php
                        $assigned = $sqlDb->fetchAll(
                            "SELECT p.*, s.name as store_name 
                             FROM products p 
                             JOIN stores s ON p.store_id = s.id 
                             WHERE p.sku LIKE ? AND p.store_id IS NOT NULL",
                            [$mainProduct['sku'] . '-S%']
                        );
                        foreach ($assigned as $variant):
                        ?>
                            <li>
                                <strong><?php echo htmlspecialchars($variant['store_name']); ?></strong>: 
                                <?php echo number_format($variant['quantity']); ?> units 
                                (SKU: <?php echo htmlspecialchars($variant['sku']); ?>)
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
