<?php
// modules/stock/delete.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../sql_db.php';
require_once __DIR__ . '/../../getDB.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_delete_inventory')) {
    $_SESSION['error'] = 'You do not have permission to delete products.';
    header('Location: list.php');
    exit;
}

$id = $_GET['id'] ?? '';
if (empty($id)) {
    $_SESSION['error'] = 'No product ID specified.';
    header('Location: list.php');
    exit;
}

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // 1. Fetch product details
    $product = $sqlDb->fetch("SELECT id, sku, store_id, name FROM products WHERE id = ?", [$id]);
    
    if (!$product) {
        $_SESSION['error'] = 'Product not found.';
        header('Location: list.php');
        exit;
    }

    $sku = $product['sku'];
    $storeId = $product['store_id'];
    $name = $product['name'];
    
    // 2. Soft delete the product
    $sqlDb->execute(
        "UPDATE products SET deleted_at = NOW(), active = FALSE WHERE id = ?",
        [$id]
    );
    
    $msg = "Product '$name' deleted.";
    $variantsDeleted = 0;

    // 3. Smart Delete Logic (Same as batch_delete.php)
    if (empty($storeId) && !empty($sku)) {
        // Main product -> delete variants
        $variantPattern = $sku . '-S%';
        
        $variants = $sqlDb->fetchAll(
            "SELECT id FROM products WHERE sku LIKE ? AND deleted_at IS NULL", 
            [$variantPattern]
        );
        
        if (!empty($variants)) {
            $sqlDb->execute(
                "UPDATE products SET deleted_at = NOW(), active = FALSE WHERE sku LIKE ? AND deleted_at IS NULL",
                [$variantPattern]
            );
            $variantsDeleted = count($variants);
            $msg .= " (Including $variantsDeleted sub-branch variants)";
        }
    }

    // 4. Audit Log
    try {
        log_stock_audit([
            'action'       => 'delete_product',
            'product_id'   => (string)$id,
            'sku'          => $sku,
            'product_name' => $name,
            'store_id'     => $storeId,
            'user_id'      => $_SESSION['user_id'],
            'username'     => $_SESSION['username'] ?? 'User',
            'changed_by'   => $_SESSION['user_id'],
            'changed_name' => $_SESSION['username'] ?? 'User',
            'notes'        => "Smart delete: $variantsDeleted variants removed."
        ]);
    } catch (Throwable $t) {
        error_log('Delete audit failed: ' . $t->getMessage());
    }

    // 5. Clear Cache
    $cacheFile = __DIR__ . '/../../storage/cache/stock_list_data.cache';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }

    $_SESSION['success'] = $msg;

} catch (Exception $e) {
    error_log("Delete error: " . $e->getMessage());
    $_SESSION['error'] = "Failed to delete product: " . $e->getMessage();
}

header('Location: list.php');
exit;

