<?php
require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';
session_start();

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_edit_inventory')) {
    $_SESSION['error'] = 'You do not have permission to transfer stock';
    header('Location: list.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = $_POST['product_id'] ?? '';
    $quantity = intval($_POST['quantity'] ?? 0);

    if (empty($product_id) || $quantity <= 0) {
        $_SESSION['error'] = 'Invalid product or quantity';
        header('Location: list.php');
        exit;
    }

    try {
        $sqlDb = SQLDatabase::getInstance();
        
        // Start transaction
        $sqlDb->beginTransaction();

        // 1. Get the store product
        $storeProduct = $sqlDb->fetch("SELECT * FROM products WHERE id = ?", [$product_id]);
        if (!$storeProduct) {
            throw new Exception("Store product not found");
        }
        
        if (empty($storeProduct['store_id'])) {
            throw new Exception("This is already a warehouse product");
        }

        $sku = $storeProduct['sku'];
        $store_id = $storeProduct['store_id'];

        // 2. Get the warehouse product
        $warehouseProduct = $sqlDb->fetch("SELECT * FROM products WHERE sku = ? AND store_id IS NULL", [$sku]);
        
        if (!$warehouseProduct) {
            throw new Exception("Warehouse product not found for SKU: $sku");
        }

        // 3. Check warehouse stock
        if ($warehouseProduct['quantity'] < $quantity) {
            throw new Exception("Insufficient stock in warehouse. Available: " . $warehouseProduct['quantity']);
        }

        // 4. Update warehouse stock
        $sqlDb->execute("UPDATE products SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?", [$quantity, $warehouseProduct['id']]);

        // 5. Update store product stock
        $sqlDb->execute("UPDATE products SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?", [$quantity, $product_id]);

        // 6. Log activity
        logActivity('stock_transfer', "Transferred $quantity units of $sku from Warehouse to Store #$store_id", [
            'sku' => $sku,
            'quantity' => $quantity,
            'from_store' => 'Warehouse',
            'to_store' => $store_id,
            'warehouse_product_id' => $warehouseProduct['id'],
            'store_product_id' => $product_id
        ]);

        $sqlDb->commit();
        $_SESSION['success'] = "Successfully transferred $quantity units from Warehouse.";

    } catch (Exception $e) {
        $sqlDb->rollBack();
        $_SESSION['error'] = 'Transfer failed: ' . $e->getMessage();
    }
}

header('Location: list.php');
exit;
