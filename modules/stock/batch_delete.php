<?php
// Smart batch delete for products
require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_manage_inventory')) {
    $_SESSION['error'] = 'You do not have permission to delete products.';
    header('Location: ../../index.php');
    exit;
}

// Accept POST with array of product IDs to delete
$productIds = $_POST['product_ids'] ?? [];
if (!is_array($productIds) || empty($productIds)) {
    $_SESSION['error'] = 'No products selected for batch delete.';
    header('Location: list.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();
$deleted = [];
$failed = [];
$variantsDeleted = 0;

foreach ($productIds as $pid) {
    try {
        // 1. Fetch product details to determine if it's a main product or variant
        $product = $sqlDb->fetch("SELECT id, sku, store_id FROM products WHERE id = ?", [$pid]);
        
        if (!$product) {
            continue; // Product not found
        }

        $sku = $product['sku'];
        $storeId = $product['store_id'];
        
        // 2. Delete the selected product (Soft delete)
        $sqlDb->execute(
            "UPDATE products SET deleted_at = NOW(), active = FALSE WHERE id = ? AND deleted_at IS NULL",
            [$pid]
        );
        $deleted[] = $pid;

        // 3. Smart Delete Logic:
        // If it is a Main Product (store_id is NULL), delete all its variants.
        // A Main Product is defined as having no store_id.
        // Variants are identified by SKU starting with the Main Product's SKU + '-S'.
        
        if (empty($storeId) && !empty($sku)) {
            // It's a main product. Find and delete variants.
            // Pattern: SKU + '-S' + anything (e.g. "PROD-S1", "PROD-S2")
            $variantPattern = $sku . '-S%';
            
            // Count how many variants will be deleted (optional, for reporting)
            // We only count those that are not already deleted
            $variants = $sqlDb->fetchAll(
                "SELECT id FROM products WHERE sku LIKE ? AND deleted_at IS NULL", 
                [$variantPattern]
            );
            
            if (!empty($variants)) {
                $sqlDb->execute(
                    "UPDATE products SET deleted_at = NOW(), active = FALSE WHERE sku LIKE ? AND deleted_at IS NULL",
                    [$variantPattern]
                );
                $variantsDeleted += count($variants);
            }
        }
        // If it is a Variant (store_id is NOT NULL), we do nothing else.
        // The main product and other variants remain untouched.

    } catch (Exception $e) {
        $failed[] = $pid;
        error_log("Batch delete error for ID $pid: " . $e->getMessage());
    }
}

$msg = count($deleted) . " products deleted.";
if ($variantsDeleted > 0) {
    $msg .= " (Including $variantsDeleted sub-branch variants)";
}

$_SESSION['success'] = $msg;
if ($failed) {
    $_SESSION['error'] = "Failed to delete: " . implode(', ', $failed);
}
header('Location: list.php');
exit;
