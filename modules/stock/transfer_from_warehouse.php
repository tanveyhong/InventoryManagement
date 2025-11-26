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

if (!currentUserHasPermission('can_manage_stock_transfers') && !currentUserHasPermission('can_manage_purchase_orders')) {
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
        $storeProduct = $sqlDb->fetch("
            SELECT p.*, s.name as store_name 
            FROM products p 
            LEFT JOIN stores s ON p.store_id = s.id 
            WHERE p.id = ?
        ", [$product_id]);

        if (!$storeProduct) {
            throw new Exception("Store product not found");
        }
        
        if (empty($storeProduct['store_id'])) {
            throw new Exception("This is already a warehouse product");
        }

        $sku = $storeProduct['sku'];
        $store_id = $storeProduct['store_id'];
        $store_name = $storeProduct['store_name'];

        // Logic to derive Base SKU (copied from list.php)
        $baseSku = $sku;
        
        $sanitizedStoreName = $store_name ? strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $store_name)) : '';
        $meaningfulSuffix = $sanitizedStoreName ? '-' . $sanitizedStoreName : '';
        $posSuffix = $sanitizedStoreName ? '-POS-' . $sanitizedStoreName : '';

        if ($posSuffix && strlen($sku) > strlen($posSuffix) && substr($sku, -strlen($posSuffix)) === $posSuffix) {
             $baseSku = substr($sku, 0, -strlen($posSuffix));
        } elseif (preg_match('/^(.+)-S(\d+)$/', $sku, $matches)) {
             $baseSku = $matches[1];
        } elseif ($meaningfulSuffix && strlen($sku) > strlen($meaningfulSuffix) && substr($sku, -strlen($meaningfulSuffix)) === $meaningfulSuffix) {
             $baseSku = substr($sku, 0, -strlen($meaningfulSuffix));
        }

        // 2. Get the warehouse product
        $warehouseProduct = $sqlDb->fetch("SELECT * FROM products WHERE sku = ? AND store_id IS NULL", [$baseSku]);
        
        // Fallback: try exact SKU match if base SKU not found
        if (!$warehouseProduct) {
             $warehouseProduct = $sqlDb->fetch("SELECT * FROM products WHERE sku = ? AND store_id IS NULL", [$sku]);
        }
        
        if (!$warehouseProduct) {
            throw new Exception("Warehouse product not found for SKU: $sku");
        }

        // 3. Check warehouse stock
        if ($warehouseProduct['quantity'] < $quantity) {
            throw new Exception("Insufficient stock in warehouse. Available: " . $warehouseProduct['quantity']);
        }

        // 4. Update warehouse stock (Deduct immediately to reserve stock)
        $sqlDb->execute("UPDATE products SET quantity = quantity - ?, updated_at = NOW() WHERE id = ?", [$quantity, $warehouseProduct['id']]);

        // 5. Create Pending Transfer Record
        // We do NOT add to store stock yet. It must be confirmed upon arrival.
        $sqlDb->execute("INSERT INTO inventory_transfers 
            (source_product_id, dest_product_id, store_id, quantity, status, created_by, created_at) 
            VALUES (?, ?, ?, ?, 'pending', ?, NOW())", 
            [$warehouseProduct['id'], $product_id, $store_id, $quantity, $_SESSION['user_id']]
        );

        // 6. Log activity
        logActivity('stock_transfer_initiated', "Initiated transfer of $quantity units of $sku from Warehouse to Store #$store_id (Pending Arrival)", [
            'sku' => $sku,
            'quantity' => $quantity,
            'from_store' => 'Warehouse',
            'to_store' => $store_id,
            'warehouse_product_id' => $warehouseProduct['id'],
            'store_product_id' => $product_id
        ]);

        $sqlDb->commit();
        $_SESSION['success'] = "Transfer initiated! $quantity units deducted from Warehouse. Please confirm receipt when stock arrives.";

    } catch (Exception $e) {
        $sqlDb->rollBack();
        $_SESSION['error'] = 'Transfer failed: ' . $e->getMessage();
    }
}

header('Location: list.php');
exit;
