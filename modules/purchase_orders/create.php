<?php
// modules/purchase_orders/create.php
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

$sqlDb = SQLDatabase::getInstance();
$suppliers = $sqlDb->fetchAll("SELECT id, name FROM suppliers WHERE active = TRUE ORDER BY name");
$stores = $sqlDb->fetchAll("SELECT id, name FROM stores WHERE active = TRUE ORDER BY name");

$pre_supplier_id = $_GET['supplier_id'] ?? '';
$pre_store_id = '';
$batch_product_ids = [];

if (!empty($_GET['product_id'])) {
    $prod = $sqlDb->fetch("SELECT store_id, supplier_id FROM products WHERE id = ?", [$_GET['product_id']]);
    if ($prod) {
        if ($prod['store_id']) $pre_store_id = $prod['store_id'];
        if (!$pre_supplier_id && $prod['supplier_id']) $pre_supplier_id = $prod['supplier_id'];
    }
} elseif (!empty($_GET['product_ids'])) {
    $ids = explode(',', $_GET['product_ids']);
    $batch_product_ids = array_filter($ids, 'is_numeric');
    
    if (!empty($batch_product_ids)) {
        // Try to find common supplier and store
        $placeholders = implode(',', array_fill(0, count($batch_product_ids), '?'));
        $prods = $sqlDb->fetchAll("SELECT store_id, supplier_id FROM products WHERE id IN ($placeholders)", $batch_product_ids);
        
        $common_store = null;
        $common_supplier = null;
        $first = true;
        
        foreach ($prods as $p) {
            if ($first) {
                $common_store = $p['store_id'];
                $common_supplier = $p['supplier_id'];
                $first = false;
            } else {
                if ($common_store != $p['store_id']) $common_store = false;
                if ($common_supplier != $p['supplier_id']) $common_supplier = false;
            }
        }
        
        if ($common_store !== false) $pre_store_id = $common_store;
        if ($common_supplier !== false && !$pre_supplier_id) $pre_supplier_id = $common_supplier;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_id = $_POST['supplier_id'];
    $store_id = !empty($_POST['store_id']) ? $_POST['store_id'] : null;
    $expected_date = !empty($_POST['expected_date']) ? $_POST['expected_date'] : date('Y-m-d', strtotime('+1 day'));
    $notes = $_POST['notes'];
    $batch_ids_str = $_POST['batch_product_ids'] ?? '';

    // Generate PO Number
    $po_number = 'PO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

    try {
        $sqlDb->execute(
            "INSERT INTO purchase_orders (po_number, supplier_id, store_id, expected_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)",
            [$po_number, $supplier_id, $store_id, $expected_date, $notes, $_SESSION['user_id']]
        );
        $po_id = $sqlDb->lastInsertId();
        
        // Handle Batch Items
        if (!empty($batch_ids_str)) {
            $b_ids = explode(',', $batch_ids_str);
            foreach ($b_ids as $pid) {
                if (!is_numeric($pid)) continue;
                // Get current price as default cost
                $p_info = $sqlDb->fetch("SELECT price FROM products WHERE id = ?", [$pid]);
                $cost = $p_info ? $p_info['price'] : 0;
                
                // Insert with qty 1
                $sqlDb->execute(
                    "INSERT INTO purchase_order_items (po_id, product_id, quantity, unit_cost, total_cost) VALUES (?, ?, 1, ?, ?)",
                    [$po_id, $pid, $cost, $cost]
                );
            }
            // Update Total
            $sqlDb->execute("UPDATE purchase_orders SET total_amount = (SELECT SUM(total_cost) FROM purchase_order_items WHERE po_id = ?) WHERE id = ?", [$po_id, $po_id]);
        }

        logActivity('po_created', "Created Purchase Order: $po_number", ['po_id' => $po_id, 'po_number' => $po_number]);

        $redirectUrl = "edit.php?id=$po_id";
        if (!empty($_GET['product_id'])) {
            $redirectUrl .= "&pre_product_id=" . urlencode($_GET['product_id']);
        }
        
        header("Location: $redirectUrl"); // Go to edit page to add items
        exit;
    } catch (Exception $e) {
        $error = "Error creating PO: " . $e->getMessage();
    }
}

// Auto-generate meaningful notes
$u_note = $sqlDb->fetch("SELECT username FROM users WHERE id = ?", [$_SESSION['user_id']]);
$current_user = $u_note['username'] ?? 'Staff';
$default_notes = "Restock request created by $current_user.";

if (!empty($_GET['product_id'])) {
    $p_note = $sqlDb->fetch("SELECT name, sku FROM products WHERE id = ?", [$_GET['product_id']]);
    if ($p_note) {
        $default_notes .= "\nItem: " . $p_note['name'] . " (" . $p_note['sku'] . ")";
    }
} elseif (!empty($batch_product_ids)) {
    $default_notes .= "\nBulk replenishment for " . count($batch_product_ids) . " items.";
}

if ($pre_store_id) {
    foreach ($stores as $s) {
        if ($s['id'] == $pre_store_id) {
            $default_notes .= "\nDestination: " . $s['name'];
            break;
        }
    }
}

$default_notes .= "\nPlease include packing slip with delivery.";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Purchase Order</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">
        <main>
            <div class="page-header">
                <div class="page-header-inner">
                    <div class="left">
                        <h1 class="title">Create Purchase Order</h1>
                        <p class="subtitle">Start a new purchase order.</p>
                    </div>
                    <div class="right">
                        <a href="list.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            </div>

            <div class="form-container">
                <form method="POST" class="product-form">
                    <?php if (!empty($batch_product_ids)): ?>
                        <input type="hidden" name="batch_product_ids" value="<?php echo implode(',', $batch_product_ids); ?>">
                        <div class="alert alert-info">
                            <strong>Batch Order:</strong> You are creating an order for <?php echo count($batch_product_ids); ?> selected items. They will be added to the order automatically.
                        </div>
                    <?php endif; ?>
                    <div class="form-sections">
                        <div class="form-section">
                            <h3>Order Details</h3>
                            
                            <div class="form-group required">
                                <label>Supplier</label>
                                <select name="supplier_id" required class="form-control">
                                    <option value="">-- Select Supplier --</option>
                                    <?php foreach ($suppliers as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo ($s['id'] == $pre_supplier_id) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($s['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Destination Store</label>
                                <select name="store_id" class="form-control">
                                    <option value="">Main Warehouse (Central Stock)</option>
                                    <?php foreach ($stores as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo ($s['id'] == $pre_store_id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Where should the stock be added upon receipt?</small>
                            </div>

                            <div class="form-group">
                                <label>Expected Date</label>
                                <input type="date" name="expected_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>">
                                <small style="color: #6c757d;">Auto-set to tomorrow</small>
                            </div>

                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($default_notes); ?></textarea>
                                <small style="color: #6c757d;">Auto-generated. You can edit this.</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Create Draft PO</button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
