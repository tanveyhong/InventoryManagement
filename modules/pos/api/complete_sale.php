<?php
/**
 * POS API - Complete Sale
 * Processes sale transaction and updates inventory
 */

require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

header('Content-Type: application/json');

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['items']) || empty($input['items'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid sale data']);
    exit;
}

$db = getSQLDB(); // Use SQL database for POS

try {
    $db->beginTransaction();
    
    $userId = $_SESSION['user_id'];
    $storeId = $input['store_id'] ?? null;
    $paymentMethod = $input['payment_method'] ?? 'cash';
    $customerName = $input['customer_name'] ?? null;
    $customerPhone = $input['customer_phone'] ?? null;
    
    // Calculate totals
    $subtotal = 0;
    $taxAmount = 0;
    $totalAmount = 0;
    
    foreach ($input['items'] as $item) {
        $itemTotal = (float)$item['price'] * (int)$item['quantity'];
        $subtotal += $itemTotal;
    }
    
    $taxAmount = $subtotal * 0.00; // 0% tax rate
    $totalAmount = $subtotal + $taxAmount;
    
    // Create sale record
    $transactionId = 'TXN-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    $db->execute("
        INSERT INTO sales (
            transaction_id, 
            user_id, 
            store_id, 
            subtotal, 
            tax, 
            total, 
            payment_method,
            customer_name,
            customer_phone,
            sale_date,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
    ", [
        $transactionId,
        $userId,
        $storeId,
        $subtotal,
        $taxAmount,
        $totalAmount,
        $paymentMethod,
        $customerName,
        $customerPhone
    ]);
    
    $saleId = $db->lastInsertId();
    
    // Insert sale items and update inventory
    foreach ($input['items'] as $item) {
        $itemSubtotal = (float)$item['price'] * (int)$item['quantity'];
        
        // Insert sale item
        $db->execute("
            INSERT INTO sale_items (
                sale_id, 
                product_id, 
                quantity, 
                price, 
                subtotal,
                created_at
            ) VALUES (?, ?, ?, ?, ?, datetime('now'))
        ", [
            $saleId,
            $item['product_id'],
            $item['quantity'],
            $item['price'],
            $itemSubtotal
        ]);
        
        // Update product stock
        $db->execute("
            UPDATE products 
            SET quantity = quantity - ?
            WHERE id = ? AND quantity >= ?
        ", [
            $item['quantity'],
            $item['product_id'],
            $item['quantity']
        ]);
        
        // Check if update was successful
        if ($db->rowCount() === 0) {
            throw new Exception("Insufficient stock for product ID: " . $item['product_id']);
        }
        
        // Log inventory change (if function exists)
        if (function_exists('logInventoryChange')) {
            logInventoryChange(
                $item['product_id'],
                -$item['quantity'],
                'sale',
                "POS Sale: " . $transactionId,
                $userId
            );
        }
    }
    
    // Log user activity (if function exists)
    if (function_exists('logUserActivity')) {
        logUserActivity(
            $userId,
            'pos_sale',
            "Completed POS sale: $transactionId, Amount: $" . number_format($totalAmount, 2)
        );
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Sale completed successfully',
        'transaction_id' => $transactionId,
        'sale_id' => $saleId,
        'total' => number_format($totalAmount, 2, '.', '')
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Error completing sale: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error completing sale: ' . $e->getMessage()
    ]);
}
