<?php
// modules/purchase_orders/edit.php
require_once '../../config.php';
session_start();
require_once '../../functions.php';
require_once '../../sql_db.php';
require_once '../../activity_logger.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_manage_purchase_orders')) {
    header('Location: ../../index.php');
    exit;
}

$id = $_GET['id'] ?? '';
if (empty($id)) {
    header('Location: list.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();

// Fetch PO details
$po = $sqlDb->fetch("
    SELECT 
        po.*, 
        s.name as supplier_name, 
        s.email as supplier_email, 
        s.address as supplier_address,
        s.phone as supplier_phone,
        s.contact_person as supplier_contact,
        st.name as store_name,
        st.address as store_address,
        st.city as store_city,
        st.state as store_state,
        st.zip_code as store_zip,
        st.phone as store_phone
    FROM purchase_orders po
    LEFT JOIN suppliers s ON po.supplier_id = s.id
    LEFT JOIN stores st ON po.store_id = st.id
    WHERE po.id = ?
", [$id]);

if (!$po) die('PO not found');

// Fetch PO Items
$items = $sqlDb->fetchAll("
    SELECT poi.*, p.name as product_name, p.sku 
    FROM purchase_order_items poi
    JOIN products p ON poi.product_id = p.id
    WHERE poi.po_id = ?
", [$id]);

// Fetch Products for Dropdown - REMOVED (Using AJAX now)
// $products = ...

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $product_id = $_POST['product_id'];
        $quantity = (int)$_POST['quantity'];
        $unit_cost = (float)$_POST['unit_cost'];
        $total_cost = $quantity * $unit_cost;

        $sqlDb->execute(
            "INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?)",
            [$id, $product_id, $quantity, $unit_cost, $total_cost]
        );
        
        // Update PO Total
        $sqlDb->execute("UPDATE purchase_orders SET total_amount = (SELECT SUM(total_cost) FROM purchase_order_items WHERE po_id = ?) WHERE id = ?", [$id, $id]);
        
        logActivity('po_item_added', "Added item to PO #$id", ['po_id' => $id, 'product_id' => $product_id]);
    }
    elseif ($action === 'delete_item') {
        $item_id = $_POST['item_id'];
        $sqlDb->execute("DELETE FROM purchase_order_items WHERE id = ?", [$item_id]);
        // Update PO Total
        $sqlDb->execute("UPDATE purchase_orders SET total_amount = (SELECT COALESCE(SUM(total_cost), 0) FROM purchase_order_items WHERE po_id = ?) WHERE id = ?", [$id, $id]);
        
        logActivity('po_item_deleted', "Deleted item from PO #$id", ['po_id' => $id, 'item_id' => $item_id]);
    }
    elseif ($action === 'update_notes') {
        $notes = $_POST['notes'];
        $sqlDb->execute("UPDATE purchase_orders SET notes = ? WHERE id = ?", [$notes, $id]);
        logActivity('po_updated', "Updated notes for PO #$id", ['po_id' => $id]);
        header("Location: edit.php?id=$id");
        exit;
    }
    elseif ($action === 'update_date') {
        $date = $_POST['expected_date'];
        $sqlDb->execute("UPDATE purchase_orders SET expected_date = ? WHERE id = ?", [$date ?: null, $id]);
        logActivity('po_updated', "Updated expected date for PO #$id to $date", ['po_id' => $id]);
        header("Location: edit.php?id=$id");
        exit;
    }
    elseif ($action === 'delete_po') {
        // Delete items first
        $sqlDb->execute("DELETE FROM purchase_order_items WHERE po_id = ?", [$id]);
        // Delete PO
        $sqlDb->execute("DELETE FROM purchase_orders WHERE id = ?", [$id]);
        
        logActivity('po_deleted', "Deleted PO #$id", ['po_id' => $id]);
        
        header("Location: list.php");
        exit;
    }
    elseif ($action === 'mark_ordered') {
        $sqlDb->execute("UPDATE purchase_orders SET status = 'ordered', updated_at = NOW() WHERE id = ?", [$id]);
        
        // Send Email to Supplier
        if (!empty($po['supplier_email'])) {
            require_once '../../email_helper.php';
            
            $subject = "Purchase Order #" . $po['po_number'] . " - " . $po['store_name'];
            
            // Format Addresses
            $supplierAddress = htmlspecialchars($po['supplier_address'] ?? '');
            // Supplier table doesn't have city/state/zip columns in current schema
            
            $shipToAddress = htmlspecialchars($po['store_address'] ?? '');
            if (!empty($po['store_city'])) $shipToAddress .= '<br>' . htmlspecialchars($po['store_city']);
            if (!empty($po['store_state'])) $shipToAddress .= ', ' . htmlspecialchars($po['store_state']);
            if (!empty($po['store_zip'])) $shipToAddress .= ' ' . htmlspecialchars($po['store_zip']);

            $itemsHtml = '<table style="width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px;">
                <thead>
                    <tr style="background-color: #f8f9fa; color: #495057;">
                        <th style="padding: 12px; border-bottom: 2px solid #dee2e6; text-align: left;">Product</th>
                        <th style="padding: 12px; border-bottom: 2px solid #dee2e6; text-align: center;">Quantity</th>
                        <th style="padding: 12px; border-bottom: 2px solid #dee2e6; text-align: right;">Unit Cost</th>
                        <th style="padding: 12px; border-bottom: 2px solid #dee2e6; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>';
            
            foreach ($items as $item) {
                $itemsHtml .= '<tr>
                    <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                        <strong>' . htmlspecialchars($item['product_name']) . '</strong><br>
                        <span style="color: #6c757d; font-size: 12px;">SKU: ' . htmlspecialchars($item['sku']) . '</span>
                    </td>
                    <td style="padding: 12px; border-bottom: 1px solid #dee2e6; text-align: center;">' . $item['quantity'] . '</td>
                    <td style="padding: 12px; border-bottom: 1px solid #dee2e6; text-align: right;">' . number_format($item['unit_cost'], 2) . '</td>
                    <td style="padding: 12px; border-bottom: 1px solid #dee2e6; text-align: right;">' . number_format($item['total_cost'], 2) . '</td>
                </tr>';
            }
            
            $itemsHtml .= '</tbody>
                <tfoot>
                    <tr style="font-weight: bold; background-color: #f8f9fa;">
                        <td colspan="3" style="padding: 12px; border-top: 2px solid #dee2e6; text-align: right;">Total Amount:</td>
                        <td style="padding: 12px; border-top: 2px solid #dee2e6; text-align: right;">' . number_format($po['total_amount'], 2) . '</td>
                    </tr>
                </tfoot>
            </table>';
            
            $body = '
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                    .container { max-width: 800px; margin: 20px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                    .header { background: #2c3e50; color: white; padding: 30px; text-align: center; }
                    .header h1 { margin: 0; font-size: 24px; font-weight: 600; }
                    .content { padding: 40px; }
                    .info-section { display: flex; justify-content: space-between; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
                    .info-box { flex: 1; min-width: 250px; }
                    .info-box h3 { color: #2c3e50; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; font-size: 16px; text-transform: uppercase; letter-spacing: 1px; }
                    .po-meta { background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 30px; display: flex; justify-content: space-between; flex-wrap: wrap; gap: 15px; }
                    .meta-item { display: flex; flex-direction: column; }
                    .meta-label { font-size: 12px; color: #6c757d; text-transform: uppercase; font-weight: 600; }
                    .meta-value { font-size: 16px; font-weight: 500; color: #2c3e50; }
                    .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #6c757d; border-top: 1px solid #dee2e6; }
                    .notes { background: #fff3cd; color: #856404; padding: 15px; border-radius: 6px; margin-top: 20px; border: 1px solid #ffeeba; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>Purchase Order</h1>
                        <div style="font-size: 14px; opacity: 0.8; margin-top: 5px;">#' . htmlspecialchars($po['po_number']) . '</div>
                    </div>
                    
                    <div class="content">
                        <!-- PO Meta Data Table -->
                        <table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #f8f9fa; border-radius: 6px; margin-bottom: 30px;">
                            <tr>
                                <td width="33%" valign="top" style="border-right: 1px solid #dee2e6;">
                                    <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; font-weight: 600; margin-bottom: 5px;">PO Number</div>
                                    <div style="font-size: 16px; font-weight: 600; color: #2c3e50;">' . htmlspecialchars($po['po_number']) . '</div>
                                </td>
                                <td width="33%" valign="top" style="border-right: 1px solid #dee2e6;">
                                    <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; font-weight: 600; margin-bottom: 5px;">Order Date</div>
                                    <div style="font-size: 16px; font-weight: 600; color: #2c3e50;">' . date('M d, Y') . '</div>
                                </td>
                                <td width="33%" valign="top">
                                    <div style="font-size: 11px; color: #6c757d; text-transform: uppercase; font-weight: 600; margin-bottom: 5px;">Expected Delivery</div>
                                    <div style="font-size: 16px; font-weight: 600; color: #2c3e50;">' . ($po['expected_date'] ? date('M d, Y', strtotime($po['expected_date'])) : 'TBD') . '</div>
                                </td>
                            </tr>
                        </table>

                        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 30px;">
                            <tr>
                                <td width="50%" valign="top" style="padding-right: 20px;">
                                    <h3 style="color: #2c3e50; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Vendor</h3>
                                    <div style="font-size: 14px; line-height: 1.5;">
                                        <strong>' . htmlspecialchars($po['supplier_name']) . '</strong><br>
                                        ' . $supplierAddress . '<br>
                                        ' . ($po['supplier_contact'] ? 'Attn: ' . htmlspecialchars($po['supplier_contact']) . '<br>' : '') . '
                                        ' . ($po['supplier_phone'] ? 'P: ' . htmlspecialchars($po['supplier_phone']) . '<br>' : '') . '
                                        ' . ($po['supplier_email'] ? 'E: ' . htmlspecialchars($po['supplier_email']) : '') . '
                                    </div>
                                </td>
                                <td width="50%" valign="top" style="padding-left: 20px;">
                                    <h3 style="color: #2c3e50; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 0; font-size: 14px; text-transform: uppercase; letter-spacing: 1px;">Ship To</h3>
                                    <div style="font-size: 14px; line-height: 1.5;">
                                        ' . (!empty($po['store_name']) ? '
                                            <strong>' . htmlspecialchars($po['store_name']) . '</strong><br>
                                            ' . $shipToAddress . '<br>
                                            ' . ($po['store_phone'] ? 'P: ' . htmlspecialchars($po['store_phone']) : '') . '
                                        ' : '
                                            <strong>Main Warehouse</strong><br>
                                            123 Inventory Lane<br>
                                            Logistics City, ST 12345<br>
                                            P: (555) 123-4567
                                        ') . '
                                    </div>
                                </td>
                            </tr>
                        </table>
                        
                        <p>Dear ' . htmlspecialchars($po['supplier_name']) . ',</p>
                        <p>Please accept this purchase order for the following items:</p>
                        
                        ' . $itemsHtml . '
                        
                        ' . (!empty($po['notes']) ? '<div class="notes"><strong>Notes:</strong> ' . htmlspecialchars($po['notes']) . '</div>' : '') . '
                        
                        <p style="margin-top: 30px;">Please confirm receipt of this order and provide an estimated delivery date if different from above.</p>
                    </div>
                    
                    <div class="footer">
                        <p>If you have any questions about this purchase order, please contact us immediately.</p>
                        <p>&copy; ' . date('Y') . ' Inventory Management System. All rights reserved.</p>
                    </div>
                </div>
            </body>
            </html>';
            
            sendEmail($po['supplier_email'], $subject, $body);
            logActivity('po_email_sent', "Sent PO #$id email to " . $po['supplier_email'], ['po_id' => $id]);
        }
        
        if (!currentUserHasPermission('can_send_purchase_orders')) {
            header("Location: edit.php?id=$id&error=" . urlencode("You do not have permission to send orders."));
            exit;
        }

        logActivity('po_status_updated', "Marked PO #$id as Ordered", ['po_id' => $id, 'status' => 'ordered']);
        
        header("Location: edit.php?id=$id");
        exit;
    }
    elseif ($action === 'receive_shipment') {
        if (!currentUserHasPermission('can_manage_stock_transfers')) {
            header("Location: edit.php?id=$id&error=" . urlencode("You do not have permission to receive shipments."));
            exit;
        }

        $received_items = $_POST['receive'] ?? [];
        $rejected_items = $_POST['rejected'] ?? [];
        $shipment_notes = $_POST['shipment_notes'] ?? '';
        $received_at = $_POST['received_at'] ?? date('Y-m-d H:i:s');
        
        if (empty($received_items) && empty($rejected_items)) {
            header("Location: edit.php?id=$id");
            exit;
        }

        // Validation: Check for negative quantities and over-receiving
        foreach ($received_items as $item_id => $qty_received) {
            $qty_received = (int)$qty_received;
            $qty_rejected = (int)($rejected_items[$item_id] ?? 0);
            
            if ($qty_received < 0 || $qty_rejected < 0) {
                header("Location: edit.php?id=$id&error=" . urlencode("Invalid negative quantity entered."));
                exit;
            }

            if ($qty_received == 0 && $qty_rejected == 0) continue;

            $item = $sqlDb->fetch("
                SELECT poi.*, p.name as product_name 
                FROM purchase_order_items poi 
                JOIN products p ON poi.product_id = p.id 
                WHERE poi.id = ? AND poi.po_id = ?
            ", [$item_id, $id]);
            
            if (!$item) continue;

            $ordered = (int)$item['quantity'];
            $prev_received = (int)($item['received_quantity'] ?? 0);
            $prev_rejected = (int)($item['rejected_quantity'] ?? 0);
            
            // REPLACEMENT LOGIC: We only care that we don't end up with more GOOD items than ordered.
            // Rejected items can accumulate (e.g. if they send 5 bad ones, then 5 good ones, total processed is 10, but we only keep 5).
            $total_good_items = $prev_received + $qty_received;
            
            if ($total_good_items > $ordered) {
                $remaining = $ordered - $prev_received;
                header("Location: edit.php?id=$id&error=" . urlencode("Cannot receive more items than ordered for {$item['product_name']}. Remaining: $remaining, Tried to receive: $qty_received"));
                exit;
            }
        }

        $sqlDb->beginTransaction();
        try {
            $total_received_now = 0;
            $total_rejected_now = 0;

            foreach ($received_items as $item_id => $qty_received) {
                $qty_received = (int)$qty_received;
                $qty_rejected = (int)($rejected_items[$item_id] ?? 0);
                
                if ($qty_received <= 0 && $qty_rejected <= 0) continue;

                // Verify item belongs to PO
                $item = $sqlDb->fetch("SELECT * FROM purchase_order_items WHERE id = ? AND po_id = ?", [$item_id, $id]);
                if (!$item) continue;

                // Update PO Item received quantity
                if ($qty_received > 0) {
                    $sqlDb->execute("UPDATE purchase_order_items SET received_quantity = COALESCE(received_quantity, 0) + ? WHERE id = ?", [$qty_received, $item_id]);

                    // Update Product Stock
                    // Logic: Update the product ID specified in the PO item.
                    $sqlDb->execute("UPDATE products SET quantity = COALESCE(quantity, 0) + ? WHERE id = ?", [$qty_received, $item['product_id']]);
                    
                    // Log Movement
                    $sqlDb->execute(
                        "INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at) VALUES (?, ?, 'in', ?, ?, ?, ?, ?)",
                        [$item['product_id'], $po['store_id'], $qty_received, 'PO-' . $po['po_number'], 'Received Shipment' . ($shipment_notes ? ": $shipment_notes" : ''), $_SESSION['user_id'], $received_at]
                    );
                    
                    $total_received_now += $qty_received;
                }

                // Update PO Item rejected quantity
                if ($qty_rejected > 0) {
                    $sqlDb->execute("UPDATE purchase_order_items SET rejected_quantity = COALESCE(rejected_quantity, 0) + ? WHERE id = ?", [$qty_rejected, $item_id]);
                    $total_rejected_now += $qty_rejected;
                }
            }

            if ($total_received_now > 0 || $total_rejected_now > 0) {
                // Check overall status
                $allItems = $sqlDb->fetchAll("SELECT quantity, received_quantity FROM purchase_order_items WHERE po_id = ?", [$id]);
                
                $allFullyReceived = true;
                $anyReceived = false;
                
                foreach ($allItems as $itm) {
                    $ord = (int)$itm['quantity'];
                    $rcv = (int)($itm['received_quantity'] ?? 0);
                    
                    if ($rcv > 0) $anyReceived = true;
                    if ($rcv < $ord) $allFullyReceived = false;
                }
                
                $newStatus = 'ordered';
                if ($allFullyReceived) {
                    $newStatus = 'received';
                } elseif ($anyReceived) {
                    $newStatus = 'partial';
                }
                
                // Update PO Status
                $sqlDb->execute("UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?", [$newStatus, $id]);
                
                logActivity('po_received_shipment', "Received shipment for PO #$id ($newStatus). Rejected: $total_rejected_now", ['po_id' => $id, 'status' => $newStatus]);

                // Send Rejection Email if needed
                if ($total_rejected_now > 0 && !empty($po['supplier_email'])) {
                    require_once '../../email_helper.php';
                    
                    $subject = "Rejected Items Report - PO #" . $po['po_number'];
                    
                    $rejectedHtml = '<table style="width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 14px;">
                        <thead>
                            <tr style="background-color: #ffebee; color: #c0392b;">
                                <th style="padding: 12px; border-bottom: 2px solid #e74c3c; text-align: left;">Product</th>
                                <th style="padding: 12px; border-bottom: 2px solid #e74c3c; text-align: center;">Qty Rejected</th>
                                <th style="padding: 12px; border-bottom: 2px solid #e74c3c; text-align: left;">Reason</th>
                            </tr>
                        </thead>
                        <tbody>';
                    
                    $attachments = [];
                    $rejection_reasons = $_POST['rejection_reason'] ?? [];
                    
                    foreach ($received_items as $item_id => $qty_received) {
                        $qty_rejected = (int)($rejected_items[$item_id] ?? 0);
                        if ($qty_rejected > 0) {
                            // Fetch item details again
                            $itemDetails = $sqlDb->fetch("
                                SELECT poi.*, p.name as product_name, p.sku 
                                FROM purchase_order_items poi
                                JOIN products p ON poi.product_id = p.id
                                WHERE poi.id = ?
                            ", [$item_id]);
                            
                            if ($itemDetails) {
                                $reason = $rejection_reasons[$item_id] ?? '-';
                                
                                // Handle File Upload
                                $proofText = '';
                                if (isset($_FILES['rejection_proof']['name'][$item_id])) {
                                    $files = $_FILES['rejection_proof'];
                                    // Check if it's an array (multiple files)
                                    if (is_array($files['name'][$item_id])) {
                                        $count = count($files['name'][$item_id]);
                                        for ($i = 0; $i < $count; $i++) {
                                            if ($files['error'][$item_id][$i] === UPLOAD_ERR_OK) {
                                                $tmp_name = $files['tmp_name'][$item_id][$i];
                                                $name = basename($files['name'][$item_id][$i]);
                                                $uploadDir = sys_get_temp_dir();
                                                $targetFile = $uploadDir . DIRECTORY_SEPARATOR . 'proof_' . $item_id . '_' . $i . '_' . $name;
                                                
                                                if (move_uploaded_file($tmp_name, $targetFile)) {
                                                    $attachments[] = $targetFile;
                                                    $proofText .= '<br><small style="color: #666;">(Image Attached: ' . htmlspecialchars($name) . ')</small>';
                                                }
                                            }
                                        }
                                    }
                                }

                                $rejectedHtml .= '<tr>
                                    <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">
                                        <strong>' . htmlspecialchars($itemDetails['product_name']) . '</strong><br>
                                        <span style="color: #6c757d; font-size: 12px;">SKU: ' . htmlspecialchars($itemDetails['sku']) . '</span>
                                    </td>
                                    <td style="padding: 12px; border-bottom: 1px solid #dee2e6; text-align: center; font-weight: bold; color: #c0392b;">' . $qty_rejected . '</td>
                                    <td style="padding: 12px; border-bottom: 1px solid #dee2e6;">' . htmlspecialchars($reason) . $proofText . '</td>
                                </tr>';
                            }
                        }
                    }
                    
                    $rejectedHtml .= '</tbody></table>';
                    
                    $body = '
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <style>
                            body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 20px auto; background: #ffffff; padding: 20px; border: 1px solid #eee; border-radius: 8px; }
                            .header { border-bottom: 2px solid #e74c3c; padding-bottom: 10px; margin-bottom: 20px; }
                            .header h2 { color: #c0392b; margin: 0; }
                        </style>
                    </head>
                    <body>
                        <div class="container">
                            <div class="header">
                                <h2>Rejected Items Report</h2>
                                <p>PO #' . htmlspecialchars($po['po_number']) . '</p>
                            </div>
                            
                            <p>Dear ' . htmlspecialchars($po['supplier_name']) . ',</p>
                            <p>We have received a shipment for the above Purchase Order. Unfortunately, the following items were rejected upon inspection:</p>
                            
                            ' . $rejectedHtml . '
                            
                            ' . (!empty($shipment_notes) ? '<p><strong>Notes:</strong> ' . htmlspecialchars($shipment_notes) . '</p>' : '') . '
                            
                            <p>Please contact us to arrange for a replacement or credit note.</p>
                            
                            <p>Regards,<br>' . htmlspecialchars($po['store_name'] ?? 'Inventory Management') . '</p>
                        </div>
                    </body>
                    </html>';
                    
                    sendEmail($po['supplier_email'], $subject, $body, '', $attachments);
                    
                    // Cleanup temp files
                    foreach ($attachments as $file) {
                        if (file_exists($file)) unlink($file);
                    }
                    
                    logActivity('po_rejection_email_sent', "Sent rejection email for PO #$id", ['po_id' => $id]);
                }
            }
            
            $sqlDb->commit();
            header("Location: edit.php?id=$id");
            exit;
        } catch (Exception $e) {
            $sqlDb->rollBack();
            // $error = "Error receiving shipment: " . $e->getMessage();
            // For now just redirect, maybe add error param
            header("Location: edit.php?id=$id&error=" . urlencode($e->getMessage()));
            exit;
        }
    }
    
    // Refresh items
    header("Location: edit.php?id=$id");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit PO <?php echo htmlspecialchars($po['po_number']); ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Select2 Customization to match theme */
        .select2-container .select2-selection--single {
            height: 42px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px;
            padding-left: 12px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 40px;
        }
        
        /* Custom Dropdown Styling */
        .product-result-item {
            display: flex;
            align-items: center;
            padding: 6px 10px;
        }
        .product-image {
            width: 36px;
            height: 36px;
            background-color: #f8f9fa;
            border-radius: 4px;
            margin-right: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            font-size: 16px;
            flex-shrink: 0;
            overflow: hidden;
            border: 1px solid #dee2e6;
        }
        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-info {
            flex-grow: 1;
            overflow: hidden;
        }
        .product-name {
            font-weight: 600;
            color: #333;
            font-size: 14px;
            margin-bottom: 3px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .product-meta {
            font-size: 12px;
            color: #6c757d;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .product-badge {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
            color: #495057;
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
            vertical-align: middle;
        }
        .stock-badge {
            color: #198754;
            font-weight: 600;
        }
        .stock-badge.low {
            color: #dc3545;
        }

        /* Highlighted State Fixes (When hovering/selecting) */
        .select2-results__option--highlighted .product-name {
            color: #fff;
        }
        .select2-results__option--highlighted .product-meta {
            color: rgba(255,255,255,0.9);
        }
        .select2-results__option--highlighted .product-badge {
            background: rgba(255,255,255,0.2);
            color: #fff;
        }
        .select2-results__option--highlighted .stock-badge {
            color: #fff !important;
        }
        .select2-results__option--highlighted .stock-badge.low {
            color: #ffc107 !important; /* Yellow for better visibility on blue */
        }

        .po-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .info-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        .info-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .info-value {
            font-weight: 600;
            color: #212529;
            font-size: 15px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">
        <main>
            <div class="page-header">
                <div class="page-header-inner">
                    <div class="left">
                        <h1 class="title">Edit Purchase Order</h1>
                        <p class="subtitle"><?php echo htmlspecialchars($po['po_number']); ?></p>
                    </div>
                    <div class="right">
                        <a href="list.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                        <?php if ($po['status'] === 'draft'): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this draft? This cannot be undone.');">
                                <input type="hidden" name="action" value="delete_po">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-trash"></i> Delete Draft
                                </button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="mark_ordered">
                                <?php if (currentUserHasPermission('can_send_purchase_orders')): ?>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Make Order
                                </button>
                                <?php endif; ?>
                            </form>
                        <?php elseif ($po['status'] === 'ordered' || $po['status'] === 'partial'): ?>
                            <?php if (currentUserHasPermission('can_manage_stock_transfers')): ?>
                            <button type="button" class="btn btn-success" onclick="openReceiveModal()">
                                <i class="fas fa-box-open"></i> Receive Shipment
                            </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger" style="background-color: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_GET['error']); ?>
                </div>
            <?php endif; ?>

            <div class="po-info-grid">
                <div class="info-box">
                    <div class="info-label">Supplier</div>
                    <div class="info-value"><?php echo htmlspecialchars($po['supplier_name']); ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Destination Store</div>
                    <div class="info-value"><?php echo htmlspecialchars($po['store_name'] ?? 'Main Warehouse'); ?></div>
                </div>
                <div class="info-box">
                    <div class="info-label">Status</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo $po['status']; ?>">
                            <?php echo ucfirst($po['status']); ?>
                        </span>
                    </div>
                </div>
                <div class="info-box">
                    <div class="info-label">Expected Date</div>
                    <div class="info-value" id="date-display">
                        <?php echo $po['expected_date'] ? date('M j, Y', strtotime($po['expected_date'])) : '-'; ?>
                        <?php if ($po['status'] !== 'received' && $po['status'] !== 'cancelled'): ?>
                            <a href="#" onclick="document.getElementById('date-display').style.display='none';document.getElementById('date-edit').style.display='block';return false;" style="font-size: 12px; margin-left: 5px;"><i class="fas fa-edit"></i></a>
                        <?php endif; ?>
                    </div>
                    <form id="date-edit" method="POST" style="display: none;">
                        <input type="hidden" name="action" value="update_date">
                        <input type="date" name="expected_date" value="<?php echo $po['expected_date']; ?>" class="form-control" style="padding: 2px 5px; height: auto; font-size: 14px; margin-bottom: 5px;">
                        <button type="submit" class="btn btn-sm btn-primary" style="padding: 2px 8px; font-size: 12px;">Save</button>
                        <button type="button" onclick="document.getElementById('date-edit').style.display='none';document.getElementById('date-display').style.display='block';" class="btn btn-sm btn-secondary" style="padding: 2px 8px; font-size: 12px;">Cancel</button>
                    </form>
                </div>
            </div>

            <!-- Notes Section -->
            <div style="background: #fff; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h4 style="margin: 0; font-size: 14px; color: #6c757d; text-transform: uppercase;">Order Notes</h4>
                    <?php if ($po['status'] === 'draft'): ?>
                    <button onclick="document.getElementById('notes-view').style.display='none';document.getElementById('notes-edit').style.display='block';" class="btn btn-sm btn-link" style="padding: 0;">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <?php endif; ?>
                </div>
                <div id="notes-view" style="font-size: 14px; color: #212529; white-space: pre-wrap;"><?php echo htmlspecialchars($po['notes'] ?? 'No notes added.'); ?></div>
                
                <?php if ($po['status'] === 'draft'): ?>
                <form id="notes-edit" method="POST" style="display: none;">
                    <input type="hidden" name="action" value="update_notes">
                    <textarea name="notes" class="form-control" rows="3" style="margin-bottom: 10px;"><?php echo htmlspecialchars($po['notes'] ?? ''); ?></textarea>
                    <div style="text-align: right;">
                        <button type="button" onclick="document.getElementById('notes-edit').style.display='none';document.getElementById('notes-view').style.display='block';" class="btn btn-sm btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-primary">Save Notes</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>

            <!-- Progress Tracker -->
            <?php
            $steps = [
                'draft' => ['label' => 'Draft & Items', 'icon' => 'fa-pencil-alt'],
                'ordered' => ['label' => 'Ordered', 'icon' => 'fa-paper-plane'],
                'partial' => ['label' => 'Partial', 'icon' => 'fa-box-open'],
                'received' => ['label' => 'Received', 'icon' => 'fa-check']
            ];
            
            $currentStatus = $po['status'];
            
            // Default flow: Draft -> Ordered -> Received
            $statusOrder = ['draft', 'ordered', 'received'];
            
            // Only inject 'partial' step if the order is currently in that state
            if ($currentStatus === 'partial') {
                $statusOrder = ['draft', 'ordered', 'partial', 'received'];
            }
            
            $currentIndex = array_search($currentStatus, $statusOrder);
            ?>
            
            <div class="progress-tracker">
                <?php foreach ($statusOrder as $index => $stepKey): ?>
                    <?php 
                        $step = $steps[$stepKey];
                        $isCompleted = $index < $currentIndex;
                        $isActive = $index === $currentIndex;
                        
                        $circleClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
                        $lineClass = $isCompleted ? 'completed' : '';
                    ?>
                    
                    <div class="step <?php echo $isActive ? 'active' : ''; ?>">
                        <div class="step-circle <?php echo $circleClass; ?>">
                            <?php if ($isCompleted): ?>
                                <i class="fas fa-check"></i>
                            <?php else: ?>
                                <i class="fas <?php echo $step['icon']; ?>"></i>
                            <?php endif; ?>
                        </div>
                        <div class="step-label"><?php echo $step['label']; ?></div>
                    </div>
                    
                    <?php if ($index < count($statusOrder) - 1): ?>
                        <div class="step-line <?php echo $lineClass; ?>"></div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <style>
                .progress-tracker {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    margin-bottom: 30px;
                    background: #fff;
                    padding: 30px 40px;
                    border-radius: 12px;
                    box-shadow: 0 2px 15px rgba(0,0,0,0.03);
                    position: relative;
                }
                .step {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    z-index: 2;
                    position: relative;
                }
                .step-circle {
                    width: 50px;
                    height: 50px;
                    border-radius: 50%;
                    background: #f1f5f9;
                    color: #94a3b8;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 18px;
                    margin-bottom: 10px;
                    transition: all 0.3s ease;
                    border: 2px solid #e2e8f0;
                }
                .step-circle.active {
                    background: #fff;
                    border-color: #3b82f6;
                    color: #3b82f6;
                    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
                }
                .step-circle.completed {
                    background: #10b981;
                    border-color: #10b981;
                    color: #fff;
                }
                .step-label {
                    font-size: 14px;
                    font-weight: 600;
                    color: #64748b;
                }
                .step.active .step-label {
                    color: #0f172a;
                }
                .step-line {
                    flex-grow: 1;
                    height: 3px;
                    background: #e2e8f0;
                    margin: 0 15px;
                    margin-bottom: 25px; /* Align with circle center approx */
                    position: relative;
                    top: -14px;
                    z-index: 1;
                }
                .step-line.completed {
                    background: #10b981;
                }
            </style>

            <?php if ($po['status'] === 'draft'): ?>

            <div class="form-container" style="margin-bottom: 30px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                    <h3 style="margin: 0; font-size: 16px;">Add Item to Order</h3>
                    <div style="font-size: 13px; color: #666;">
                        <i class="fas fa-info-circle"></i> Select products below to build your order
                    </div>
                </div>
                <form method="POST" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <input type="hidden" name="action" value="add_item">
                    <div class="form-group" style="flex: 3; min-width: 300px;">
                        <label>Product</label>
                        <select name="product_id" id="productSelect" required class="form-control">
                            <option value="" selected>-- Search Product by Name or SKU --</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 100px;">
                        <label>Quantity</label>
                        <input type="number" name="quantity" required min="1" class="form-control" placeholder="Qty">
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 120px; position: relative;">
                        <label>Unit Cost</label>
                        <input type="number" name="unit_cost" id="unitCostInput" required min="0" step="0.01" class="form-control" placeholder="0.00" readonly>
                        <small id="costHelper" class="form-text" style="display:none; position: absolute; top: 100%; left: 0; font-size: 11px; margin-top: 4px; line-height: 1.2; width: 200px;"></small>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="height: 38px;">Add Item</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($po['status'] === 'ordered' || $po['status'] === 'partial'): ?>
            <div style="background: #e3f2fd; border: 1px solid #bbdefb; color: #0d47a1; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px;">
                <div style="font-size: 24px;"><i class="fas fa-shipping-fast"></i></div>
                <div>
                    <h4 style="margin: 0 0 5px 0;">
                        <?php echo $po['status'] === 'partial' ? 'Partially Received' : 'Order Placed'; ?>
                    </h4>
                    <p style="margin: 0; font-size: 14px;">
                        <?php if ($po['status'] === 'partial'): ?>
                            Some items have been received. Click <strong>"Receive Shipment"</strong> to record more deliveries.
                        <?php else: ?>
                            This order has been sent to the supplier. When items arrive, click <strong>"Receive Shipment"</strong> to update stock.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Ordered</th>
                            <th>Received</th>
                            <th>Rejected</th>
                            <th>Unit Cost</th>
                            <th>Total Cost</th>
                            <?php if ($po['status'] === 'draft'): ?><th>Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="<?php echo ($po['status'] === 'draft') ? 8 : 7; ?>" style="text-align: center; padding: 40px; color: #6c757d;">
                                    <div style="margin-bottom: 15px;">
                                        <i class="fas fa-box-open" style="font-size: 48px; color: #dee2e6;"></i>
                                    </div>
                                    <h4 style="margin: 0 0 10px 0; color: #495057;">Your order is empty</h4>
                                    <p style="margin: 0; font-size: 14px;">Use the form above to add products to this purchase order.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                    </td>
                                    <td><span style="font-family: monospace; background: #f1f5f9; padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($item['sku']); ?></span></td>
                                    <td><?php echo $item['quantity']; ?></td>
                                    <td>
                                        <?php 
                                            $received = $item['received_quantity'] ?? 0;
                                            $ordered = $item['quantity'];
                                            $percent = ($ordered > 0) ? min(100, round(($received / $ordered) * 100)) : 0;
                                            $color = ($received >= $ordered) ? '#10b981' : (($received > 0) ? '#f59e0b' : '#94a3b8');
                                        ?>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <span style="font-weight: 600; color: <?php echo $color; ?>"><?php echo $received; ?></span>
                                            <?php if ($ordered > 0): ?>
                                            <div style="flex-grow: 1; height: 4px; background: #e2e8f0; border-radius: 2px; width: 50px;">
                                                <div style="height: 100%; background: <?php echo $color; ?>; width: <?php echo $percent; ?>%; border-radius: 2px;"></div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php 
                                            $rejected = $item['rejected_quantity'] ?? 0;
                                            if ($rejected > 0) {
                                                echo '<span style="color: #e74c3c; font-weight: 600;">' . $rejected . '</span>';
                                            } else {
                                                echo '<span style="color: #ccc;">-</span>';
                                            }
                                        ?>
                                    </td>
                                    <td>RM <?php echo number_format($item['unit_cost'], 2); ?></td>
                                    <td>RM <?php echo number_format($item['total_cost'], 2); ?></td>
                                    <?php if ($po['status'] === 'draft'): ?>
                                    <td>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="delete_item">
                                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" title="Remove Item"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <tr style="font-weight: bold; background: #f8f9fa; border-top: 2px solid #dee2e6;">
                                <td colspan="5" style="text-align: right; padding-right: 20px;">Total Amount:</td>
                                <td style="color: #2c3e50; font-size: 1.1em;">RM <?php echo number_format($po['total_amount'], 2); ?></td>
                                <?php if ($po['status'] === 'draft'): ?><td></td><?php endif; ?>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- Receive Shipment Modal -->
    <div id="receiveModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="modal-content" style="background: #fff; width: 90%; max-width: 800px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); overflow: hidden; display: flex; flex-direction: column; max-height: 90vh;">
            <div class="modal-header" style="padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa;">
                <h3 style="margin: 0; color: #2c3e50;">Receive Shipment</h3>
                <button type="button" onclick="closeReceiveModal()" style="background: none; border: none; font-size: 20px; color: #6c757d; cursor: pointer;">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
                <input type="hidden" name="action" value="receive_shipment">
                <div class="modal-body" style="padding: 20px; overflow-y: auto;">
                    <p style="margin-top: 0; color: #666; font-size: 14px; margin-bottom: 20px;">
                        Enter the quantity received for each item. You can receive partial amounts now and the rest later.
                    </p>
                    
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f8f9fa; text-align: left;">
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6;">Product</th>
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; width: 80px; text-align: center;">Ordered</th>
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; width: 80px; text-align: center;">Received</th>
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; width: 100px;">Receive Now</th>
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; width: 100px;">Rejected</th>
                                <th style="padding: 10px; border-bottom: 2px solid #dee2e6; width: 200px;">Rejection Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): 
                                $ordered = (int)$item['quantity'];
                                $received = (int)($item['received_quantity'] ?? 0);
                                $rejected_prev = (int)($item['rejected_quantity'] ?? 0);
                                // REPLACEMENT LOGIC: Remaining is based only on what we have successfully received.
                                // Rejected items are still "owed" to us by the supplier.
                                $remaining = max(0, $ordered - $received);
                                
                                // Skip fully received items
                                $isDone = $remaining === 0;
                            ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px 10px;">
                                    <div style="font-weight: 600;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    <div style="font-size: 12px; color: #6c757d;"><?php echo htmlspecialchars($item['sku']); ?></div>
                                </td>
                                <td style="padding: 12px 10px; text-align: center; color: #6c757d;">
                                    <?php echo $ordered; ?>
                                </td>
                                <td style="padding: 12px 10px; text-align: center; color: #10b981; font-weight: 600;">
                                    <?php 
                                        echo $received; 
                                        if ($rejected_prev > 0) {
                                            echo '<div style="font-size: 11px; color: #e74c3c;">(' . $rejected_prev . ' rej)</div>';
                                        }
                                    ?>
                                </td>
                                <?php if ($isDone): ?>
                                    <td colspan="3" style="padding: 12px 10px; text-align: center;">
                                        <span style="color: #10b981; font-size: 13px; font-weight: 600;"><i class="fas fa-check"></i> Complete</span>
                                    </td>
                                <?php else: ?>
                                    <td style="padding: 12px 10px;">
                                        <input type="number" name="receive[<?php echo $item['id']; ?>]" 
                                               value="<?php echo $remaining; ?>" 
                                               min="0" max="<?php echo $remaining; ?>" 
                                               class="form-control receive-input" 
                                               data-item-id="<?php echo $item['id']; ?>"
                                               style="width: 100px;"
                                               required>
                                    </td>
                                    <td style="padding: 12px 10px;">
                                        <input type="number" name="rejected[<?php echo $item['id']; ?>]" 
                                               value="0" 
                                               min="0" 
                                               class="form-control rejected-input" 
                                               data-item-id="<?php echo $item['id']; ?>"
                                               style="width: 80px; border-color: #e74c3c; color: #c0392b; background-color: #f8d7da;"
                                               placeholder="Qty"
                                               readonly>
                                    </td>
                                    <td style="padding: 12px 10px;">
                                        <div id="rejection-details-<?php echo $item['id']; ?>" style="display: none;">
                                            <input type="text" name="rejection_reason[<?php echo $item['id']; ?>]" 
                                                   class="form-control" placeholder="Reason (e.g. Broken)" 
                                                   style="font-size: 12px; margin-bottom: 5px;">
                                            
                                            <div class="file-upload-wrapper">
                                                <input type="file" name="rejection_proof[<?php echo $item['id']; ?>][]" 
                                                       id="file-input-<?php echo $item['id']; ?>"
                                                       class="rejection-file-input"
                                                       data-item-id="<?php echo $item['id']; ?>"
                                                       accept="image/*"
                                                       multiple
                                                       style="display: none;">
                                                
                                                <button type="button" class="btn btn-sm btn-outline-secondary" 
                                                        onclick="document.getElementById('file-input-<?php echo $item['id']; ?>').click()"
                                                        style="font-size: 11px; width: 100%;">
                                                    <i class="fas fa-camera"></i> Add Photos
                                                </button>
                                                
                                                <div id="preview-container-<?php echo $item['id']; ?>" class="preview-container" 
                                                     style="display: flex; flex-wrap: wrap; gap: 5px; margin-top: 5px;"></div>
                                            </div>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 20px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <label style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 5px;">Date Received</label>
                            <input type="datetime-local" name="received_at" class="form-control" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                        <div>
                            <label style="font-weight: 600; font-size: 14px; display: block; margin-bottom: 5px;">Shipment Notes (Optional)</label>
                            <input type="text" name="shipment_notes" class="form-control" placeholder="e.g., Delivered by DHL, Tracking #123...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 20px; border-top: 1px solid #eee; background: #f8f9fa; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="closeReceiveModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Received Items</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openReceiveModal() {
            document.getElementById('receiveModal').style.display = 'flex';
        }
        function closeReceiveModal() {
            document.getElementById('receiveModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        document.getElementById('receiveModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeReceiveModal();
            }
        });
    </script>

    <!-- jQuery (required for Select2) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            function formatProduct(product) {
                if (product.loading) {
                    return product.text;
                }

                var imageUrl = product.image ? product.image : '';
                var imageHtml = imageUrl 
                    ? '<img src="' + imageUrl + '" onerror="this.style.display=\'none\'">' 
                    : '<i class="fas fa-box"></i>';
                
                var stockClass = product.stock < 10 ? 'low' : '';
                
                var $container = $(
                    '<div class="product-result-item">' +
                        '<div class="product-image">' + imageHtml + '</div>' +
                        '<div class="product-info">' +
                            '<div class="product-name">' + product.text + '</div>' +
                            '<div class="product-meta">' +
                                '<span class="product-badge">' + product.sku + '</span>' +
                                '<span class="product-badge">' + product.store + '</span>' +
                                '<span class="stock-badge ' + stockClass + '"><i class="fas fa-cubes"></i> ' + product.stock + ' in stock</span>' +
                                '<span>Cost: <strong>RM' + parseFloat(product.cost).toFixed(2) + '</strong></span>' +
                                '<span style="color: #adb5bd;">(Sell: RM' + parseFloat(product.price).toFixed(2) + ')</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>'
                );

                return $container;
            }

            function formatProductSelection(product) {
                if (!product.id) {
                    return product.text;
                }
                // When selected, show Name (SKU) - Cost
                var cost = product.cost || $(product.element).data('cost') || 0;
                return product.text + ' (' + (product.sku || 'No SKU') + ')';
            }

            // Initialize Select2 with AJAX
            $('#productSelect').select2({
                placeholder: "-- Search Product by Name or SKU --",
                allowClear: true,
                width: '100%',
                ajax: {
                    url: 'search_products.php',
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            term: params.term,
                            page: params.page,
                            store_id: '<?php echo $po['store_id'] ?? 'MAIN'; ?>'
                        };
                    },
                    processResults: function (data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.results,
                            pagination: {
                                more: data.pagination.more
                            }
                        };
                    },
                    cache: true
                },
                minimumInputLength: 0, // Allow opening without typing to see initial list
                templateResult: formatProduct,
                templateSelection: formatProductSelection
            });

            // Handle price update on change
            $('#productSelect').on('select2:select', function (e) {
                var data = e.params.data;
                var cost = parseFloat(data.cost);
                var price = parseFloat(data.price);
                
                var $input = $('#unitCostInput');
                var $helper = $('#costHelper');
                
                // Reset helper
                $helper.hide().text('');

                // If cost is valid and greater than 0, use it and lock field
                if (!isNaN(cost) && cost > 0) {
                    $input.val(cost.toFixed(2));
                    $input.prop('readonly', true);
                    $input.css('background-color', '#e9ecef');
                } else {
                    // Cost is missing. Allow manual entry.
                    $input.prop('readonly', false);
                    $input.css('background-color', '#fff');
                    $input.val('0.00');
                    
                    // Focus for manual adjustment
                    setTimeout(() => $input.select(), 100);
                }
            });
        });
    </script>

    <script>
        // Validate Receive Form
        document.addEventListener('submit', function(e) {
            // Check if this is the receive shipment form
            const form = e.target;
            if (!form.matches('form')) return;
            
            const actionInput = form.querySelector('input[name="action"][value="receive_shipment"]');
            if (!actionInput) return;

            let isValid = true;
            let errorMsg = "";

            const rows = form.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const receiveInput = row.querySelector('input[name^="receive"]');
                const rejectedInput = row.querySelector('input[name^="rejected"]');
                
                if (receiveInput && rejectedInput) {
                    const receiveVal = receiveInput.value;
                    const receive = parseInt(receiveVal) || 0;
                    const rejected = parseInt(rejectedInput.value) || 0;
                    const max = parseInt(receiveInput.getAttribute('max')); 
                    
                    if (receiveVal === "" || receiveInput.value.trim() === "") {
                        isValid = false;
                        errorMsg = "Please enter a quantity for all items.";
                        receiveInput.style.borderColor = "red";
                    } else if (receive < 0 || rejected < 0) {
                        isValid = false;
                        errorMsg = "Quantities cannot be negative.";
                        receiveInput.style.borderColor = "red";
                    } else if (receive > max) {
                        isValid = false;
                        errorMsg = "Cannot receive more than remaining ordered quantity (" + max + ").";
                        receiveInput.style.borderColor = "red";
                    } else {
                        receiveInput.style.borderColor = "";
                    }

                    // Check Rejection Reason if rejected > 0
                    if (rejected > 0) {
                        const reasonInput = row.querySelector('input[name^="rejection_reason"]');
                        if (reasonInput && reasonInput.value.trim() === "") {
                            isValid = false;
                            errorMsg = "Please provide a reason for rejected items.";
                            reasonInput.style.borderColor = "red";
                        } else if (reasonInput) {
                            reasonInput.style.borderColor = "";
                        }
                    }
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert(errorMsg || "Please correct the errors before saving.");
            }
        });
    </script>

    <script>
        // Toggle rejection details
        document.addEventListener('DOMContentLoaded', function() {
            const rejectedInputs = document.querySelectorAll('.rejected-input');
            
            rejectedInputs.forEach(input => {
                input.addEventListener('input', function() {
                    const itemId = this.dataset.itemId;
                    const val = parseInt(this.value) || 0;
                    const detailsDiv = document.getElementById('rejection-details-' + itemId);
                    
                    if (detailsDiv) {
                        detailsDiv.style.display = val > 0 ? 'block' : 'none';
                        
                        // If hidden, clear values? Maybe not, in case they toggle back.
                        // But if they submit 0 rejected, we ignore the details anyway.
                    }
                });
            });
        });
    </script>

    <script>
        // Auto-calculate rejected quantity based on Receive input
        document.addEventListener('DOMContentLoaded', function() {
            const receiveInputs = document.querySelectorAll('.receive-input');
            
            receiveInputs.forEach(input => {
                // Handle empty input on blur -> set to 0
                input.addEventListener('blur', function() {
                    if (this.value.trim() === '') {
                        this.value = 0;
                        this.dispatchEvent(new Event('input'));
                    }
                });

                input.addEventListener('input', function() {
                    const itemId = this.dataset.itemId;
                    const max = parseInt(this.getAttribute('max')) || 0;
                    let receive = parseInt(this.value);
                    
                    if (isNaN(receive)) receive = 0;
                    
                    // Auto-calculate rejected: Anything not received is considered rejected
                    let rejected = max - receive;
                    if (rejected < 0) rejected = 0;
                    
                    const rejectedInput = document.querySelector(`.rejected-input[data-item-id="${itemId}"]`);
                    if (rejectedInput) {
                        rejectedInput.value = rejected;
                        // Trigger input event to update details visibility
                        rejectedInput.dispatchEvent(new Event('input'));
                    }
                });
                
                // Initialize
                input.dispatchEvent(new Event('input'));
            });
        });
    </script>

    <script>
        // Handle File Upload Previews
        document.addEventListener('DOMContentLoaded', function() {
            const fileInputs = document.querySelectorAll('.rejection-file-input');
            
            fileInputs.forEach(input => {
                // Initialize DataTransfer for this input to support adding/removing
                input.dt = new DataTransfer();
                
                input.addEventListener('change', function(e) {
                    const itemId = this.dataset.itemId;
                    const container = document.getElementById('preview-container-' + itemId);
                    const newFiles = Array.from(this.files);
                    
                    // Add new files to our DataTransfer object
                    newFiles.forEach(file => {
                        // Check for duplicates based on name and size
                        let exists = false;
                        for (let i = 0; i < this.dt.items.length; i++) {
                            if (this.dt.items[i].getAsFile().name === file.name && 
                                this.dt.items[i].getAsFile().size === file.size) {
                                exists = true;
                                break;
                            }
                        }
                        
                        if (!exists) {
                            this.dt.items.add(file);
                            
                            // Create Preview UI
                            const div = document.createElement('div');
                            div.className = 'preview-item';
                            div.style.cssText = 'position: relative; width: 40px; height: 40px; border: 1px solid #ddd; border-radius: 4px; overflow: hidden; background: #f8f9fa;';
                            
                            const img = document.createElement('img');
                            img.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';
                            
                            const reader = new FileReader();
                            reader.onload = function(e) {
                                img.src = e.target.result;
                            };
                            reader.readAsDataURL(file);
                            
                            const btn = document.createElement('button');
                            btn.innerHTML = '&times;';
                            btn.type = 'button';
                            btn.style.cssText = 'position: absolute; top: 0; right: 0; background: rgba(220, 53, 69, 0.8); color: white; border: none; width: 14px; height: 14px; font-size: 10px; line-height: 1; cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center;';
                            btn.onclick = function() {
                                // Remove file from DataTransfer
                                const newDt = new DataTransfer();
                                for (let i = 0; i < input.dt.items.length; i++) {
                                    const f = input.dt.items[i].getAsFile();
                                    if (f !== file) {
                                        newDt.items.add(f);
                                    }
                                }
                                input.dt = newDt;
                                input.files = input.dt.files;
                                div.remove();
                            };
                            
                            div.appendChild(img);
                            div.appendChild(btn);
                            container.appendChild(div);
                        }
                    });
                    
                    // Update the input's files to match our accumulated list
                    this.files = this.dt.files;
                });
            });
        });
    </script>
</body>
</html>
