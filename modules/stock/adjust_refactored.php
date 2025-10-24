<?php
/**
 * STOCK ADJUSTMENT WITH CASCADING UPDATES
 * ========================================
 * When adjusting store variant:
 * - Update store variant quantity
 * - Recalculate main product quantity (sum of all variants)
 * - Update both SQL and Firebase
 */

require_once '../../config.php';
require_once '../../functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_edit_inventory')) {
    $_SESSION['error'] = 'You do not have permission to adjust stock';
    header('Location: list.php');
    exit;
}

$db = getDB();
$sqlDb = getSQLDB();
$product_id = $_GET['id'] ?? null;

if (!$product_id) {
    $_SESSION['error'] = 'No product specified';
    header('Location: list.php');
    exit;
}

// Load product from SQL
$product = $sqlDb->fetch("SELECT * FROM products WHERE id = ?", [$product_id]);
if (!$product) {
    $_SESSION['error'] = 'Product not found';
    header('Location: list.php');
    exit;
}

$isStoreVariant = !empty($product['store_id']);
$baseSku = preg_replace('/-S\d+$/', '', $product['sku']);

// If store variant, find main product
$mainProduct = null;
if ($isStoreVariant) {
    $mainProduct = $sqlDb->fetch("SELECT * FROM products WHERE sku = ? AND store_id IS NULL", [$baseSku]);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adjustment_type = $_POST['type'] ?? 'add';
    $adjustment_qty = abs((int)($_POST['quantity'] ?? 0));
    $reason = trim($_POST['reason'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($adjustment_qty <= 0) {
        $errors[] = 'Please enter a valid adjustment quantity';
    }

    if ($adjustment_type === 'subtract' && $adjustment_qty > $product['quantity']) {
        $errors[] = 'Cannot subtract more than current quantity (' . $product['quantity'] . ')';
    }

    if (empty($errors)) {
        try {
            $sqlDb->beginTransaction();

            // Calculate new quantity for this product
            $oldQty = $product['quantity'];
            $newQty = ($adjustment_type === 'add') ? $oldQty + $adjustment_qty : $oldQty - $adjustment_qty;

            // Update this product (store variant or main)
            $sqlDb->execute(
                "UPDATE products SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                [$newQty, $product_id]
            );

            // Log stock movement for this product
            $movementType = ($adjustment_type === 'add') ? 'in' : 'out';
            $sqlDb->execute("
                INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
            ", [
                $product_id,
                $product['store_id'],
                $movementType,
                $adjustment_qty,
                $reason ?: 'Stock Adjustment',
                $notes ?: 'Manual stock adjustment',
                $_SESSION['user_id'] ?? null
            ]);

            // If this is a store variant, recalculate and update main product
            if ($isStoreVariant && $mainProduct) {
                // Get all variants for this main product
                $variants = $sqlDb->fetchAll(
                    "SELECT id, quantity FROM products WHERE sku LIKE ? AND store_id IS NOT NULL",
                    [$baseSku . '-S%']
                );

                // Calculate total quantity across all stores
                $totalQty = 0;
                foreach ($variants as $variant) {
                    $totalQty += (int)$variant['quantity'];
                }

                // Update main product quantity
                $oldMainQty = $mainProduct['quantity'];
                $sqlDb->execute(
                    "UPDATE products SET quantity = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                    [$totalQty, $mainProduct['id']]
                );

                // Log movement for main product
                $mainDiff = $totalQty - $oldMainQty;
                if ($mainDiff != 0) {
                    $mainMovementType = ($mainDiff > 0) ? 'in' : 'out';
                    $sqlDb->execute("
                        INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                        VALUES (?, NULL, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ", [
                        $mainProduct['id'],
                        $mainMovementType,
                        abs($mainDiff),
                        'Cascading Update',
                        "Auto-updated from store variant adjustment (Store variant SKU: {$product['sku']})",
                        $_SESSION['user_id'] ?? null
                    ]);
                }

                // Update main product in Firebase
                try {
                    $db->update('products', (string)$mainProduct['id'], [
                        'quantity' => $totalQty,
                        'updated_at' => date('c')
                    ]);
                } catch (Throwable $t) {
                    error_log('Firebase main product update failed: ' . $t->getMessage());
                }
            }

            $sqlDb->commit();

            // Update Firebase for this product
            try {
                $db->update('products', (string)$product_id, [
                    'quantity' => $newQty,
                    'updated_at' => date('c')
                ]);

                // Log movement in Firebase
                $db->create('stock_movements', [
                    'product_id'    => $product_id,
                    'store_id'      => $product['store_id'],
                    'movement_type' => $movementType,
                    'quantity'      => $adjustment_qty,
                    'reference'     => $reason ?: 'Stock Adjustment',
                    'notes'         => $notes ?: 'Manual stock adjustment',
                    'user_id'       => $_SESSION['user_id'] ?? null,
                    'created_at'    => date('c'),
                ]);
            } catch (Throwable $t) {
                error_log('Firebase sync failed: ' . $t->getMessage());
            }

            // Log audit
            try {
                log_stock_audit([
                    'action'         => 'adjust_stock',
                    'product_id'     => (string)$product_id,
                    'sku'            => $product['sku'],
                    'product_name'   => $product['name'],
                    'store_id'       => $product['store_id'],
                    'quantity_before' => $oldQty,
                    'quantity_after'  => $newQty,
                    'user_id'        => $_SESSION['user_id'] ?? null,
                    'username'       => $_SESSION['username'] ?? null,
                ]);
            } catch (Throwable $t) {
                error_log('Audit log failed: ' . $t->getMessage());
            }

            // Clear caches
            @unlink(__DIR__ . '/../../storage/cache/stock_list_data.cache');
            @unlink(__DIR__ . '/../../storage/cache/pos_products.cache');

            $_SESSION['success'] = 'Stock adjusted successfully' . ($isStoreVariant && $mainProduct ? ' (main product updated automatically)' : '');
            header('Location: list.php?refresh=1');
            exit;

        } catch (Throwable $t) {
            if ($sqlDb->inTransaction()) $sqlDb->rollBack();
            $errors[] = 'Error adjusting stock: ' . $t->getMessage();
        }
    }
}

$page_title = 'Adjust Stock - ' . $product['name'];
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
        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin-bottom: 20px;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">
        <main>
            <div class="page-header">
                <h1><i class="fas fa-sliders-h"></i> Adjust Stock</h1>
                <a href="list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>

            <div class="info-box">
                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                <p><strong>SKU:</strong> <?php echo htmlspecialchars($product['sku']); ?></p>
                <p><strong>Current Quantity:</strong> <?php echo number_format($product['quantity']); ?></p>
                <p><strong>Price:</strong> RM <?php echo number_format($product['price'], 2); ?></p>
                <?php if ($product['store_id']): ?>
                    <?php
                    $store = $sqlDb->fetch("SELECT name FROM stores WHERE id = ?", [$product['store_id']]);
                    ?>
                    <p><strong>Store:</strong> <?php echo htmlspecialchars($store['name'] ?? 'Unknown'); ?></p>
                <?php else: ?>
                    <p><strong>Type:</strong> <span style="color: #2196F3; font-weight: bold;">Main Product</span></p>
                <?php endif; ?>
            </div>

            <?php if ($isStoreVariant && $mainProduct): ?>
                <div class="warning-box">
                    <h4><i class="fas fa-info-circle"></i> Cascading Update</h4>
                    <p>This is a store variant. Adjusting its quantity will automatically update the main product's total quantity.</p>
                    <p><strong>Main Product:</strong> <?php echo htmlspecialchars($mainProduct['name']); ?> (<?php echo htmlspecialchars($mainProduct['sku']); ?>)</p>
                    <p><strong>Main Product Quantity:</strong> <?php echo number_format($mainProduct['quantity']); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" class="form-card">
                <div class="form-group">
                    <label><i class="fas fa-exchange-alt"></i> Adjustment Type *</label>
                    <div style="display: flex; gap: 20px;">
                        <label>
                            <input type="radio" name="type" value="add" checked>
                            Add Stock
                        </label>
                        <label>
                            <input type="radio" name="type" value="subtract">
                            Subtract Stock
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="quantity"><i class="fas fa-boxes"></i> Quantity *</label>
                    <input type="number" id="quantity" name="quantity" min="1" required>
                </div>

                <div class="form-group">
                    <label for="reason"><i class="fas fa-tag"></i> Reason</label>
                    <select id="reason" name="reason">
                        <option value="">-- Select Reason --</option>
                        <option value="Restock">Restock</option>
                        <option value="Damaged">Damaged</option>
                        <option value="Expired">Expired</option>
                        <option value="Lost">Lost</option>
                        <option value="Returned">Returned</option>
                        <option value="Correction">Inventory Correction</option>
                        <option value="Transfer">Transfer</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="notes"><i class="fas fa-comment"></i> Notes</label>
                    <textarea id="notes" name="notes" rows="3" placeholder="Additional notes..."></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check"></i> Adjust Stock
                    </button>
                    <a href="list.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </main>
    </div>
</body>
</html>
