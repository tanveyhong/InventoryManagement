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
    $_SESSION['error'] = 'You do not have permission to confirm transfers';
    header('Location: list.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $transfer_id = $_POST['transfer_id'] ?? '';
    $action = $_POST['action'] ?? ''; // confirm or cancel

    if (empty($transfer_id)) {
        $_SESSION['error'] = 'Invalid transfer ID';
        header('Location: list.php');
        exit;
    }

    try {
        $sqlDb = SQLDatabase::getInstance();
        
        // Start transaction
        $sqlDb->beginTransaction();

        // 1. Get the transfer record
        $transfer = $sqlDb->fetch("SELECT * FROM inventory_transfers WHERE id = ?", [$transfer_id]);
        if (!$transfer) {
            throw new Exception("Transfer record not found");
        }
        
        if ($transfer['status'] !== 'pending') {
            throw new Exception("Transfer is already " . $transfer['status']);
        }

        if ($action === 'confirm') {
            // 2. Update Store Product Stock
            $sqlDb->execute("UPDATE products SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?", [$transfer['quantity'], $transfer['dest_product_id']]);

            // 3. Update Transfer Status
            $sqlDb->execute("UPDATE inventory_transfers SET status = 'completed', received_at = NOW(), received_by = ? WHERE id = ?", [$_SESSION['user_id'], $transfer_id]);

            // 4. Log activity
            logActivity('stock_transfer_confirmed', "Confirmed receipt of {$transfer['quantity']} units (Transfer #$transfer_id)", [
                'transfer_id' => $transfer_id,
                'quantity' => $transfer['quantity'],
                'store_product_id' => $transfer['dest_product_id']
            ]);

            $_SESSION['success'] = "Stock transfer confirmed! Inventory updated.";

        } elseif ($action === 'cancel') {
            // Return stock to warehouse
            $sqlDb->execute("UPDATE products SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?", [$transfer['quantity'], $transfer['source_product_id']]);

            // Update Transfer Status
            $sqlDb->execute("UPDATE inventory_transfers SET status = 'cancelled', received_at = NOW(), received_by = ? WHERE id = ?", [$_SESSION['user_id'], $transfer_id]);

            logActivity('stock_transfer_cancelled', "Cancelled transfer #$transfer_id. Stock returned to warehouse.", [
                'transfer_id' => $transfer_id,
                'quantity' => $transfer['quantity']
            ]);

            $_SESSION['success'] = "Stock transfer cancelled. Stock returned to warehouse.";
        }

        $sqlDb->commit();

    } catch (Exception $e) {
        $sqlDb->rollBack();
        $_SESSION['error'] = 'Action failed: ' . $e->getMessage();
    }
}

header('Location: list.php');
exit;
?>