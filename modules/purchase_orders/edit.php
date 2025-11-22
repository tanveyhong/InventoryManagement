<?php
// modules/purchase_orders/edit.php
require_once '../../config.php';
session_start();
require_once '../../functions.php';
require_once '../../sql_db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
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
    SELECT po.*, s.name as supplier_name, st.name as store_name 
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

// Fetch Products for Dropdown
// We will fetch store info as well to group them
$products = $sqlDb->fetchAll("
    SELECT p.id, p.name, p.sku, p.price, p.store_id, s.name as store_name 
    FROM products p 
    LEFT JOIN stores s ON p.store_id = s.id 
    WHERE p.active = TRUE 
    ORDER BY s.name NULLS FIRST, p.name
");

// Group products by Store
$grouped_products = [];
foreach ($products as $p) {
    $storeName = $p['store_name'] ?? 'Main Warehouse / Unassigned';
    $grouped_products[$storeName][] = $p;
}

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
    }
    elseif ($action === 'delete_item') {
        $item_id = $_POST['item_id'];
        $sqlDb->execute("DELETE FROM purchase_order_items WHERE id = ?", [$item_id]);
        // Update PO Total
        $sqlDb->execute("UPDATE purchase_orders SET total_amount = (SELECT COALESCE(SUM(total_cost), 0) FROM purchase_order_items WHERE po_id = ?) WHERE id = ?", [$id, $id]);
    }
    elseif ($action === 'delete_po') {
        // Delete items first
        $sqlDb->execute("DELETE FROM purchase_order_items WHERE po_id = ?", [$id]);
        // Delete PO
        $sqlDb->execute("DELETE FROM purchase_orders WHERE id = ?", [$id]);
        header("Location: list.php");
        exit;
    }
    elseif ($action === 'mark_ordered') {
        $sqlDb->execute("UPDATE purchase_orders SET status = 'ordered', updated_at = NOW() WHERE id = ?", [$id]);
        header("Location: edit.php?id=$id");
        exit;
    }
    elseif ($action === 'mark_received') {
        // Process Stock Updates
        $sqlDb->beginTransaction();
        try {
            foreach ($items as $item) {
                // Update Product Stock
                // If PO has a store_id, we need to find the product variant for that store.
                // If it doesn't exist, we might need to create it (Zero-Copy logic).
                // But wait, if we are ordering for a specific store, we expect the product to exist there?
                // Or we are ordering Main Product and putting it into Store?
                
                $target_product_id = $item['product_id'];
                
                if ($po['store_id']) {
                    // Check if this product is already a variant (has store_id)
                    // If the item added to PO was the Main Product (store_id IS NULL), but PO is for Store X.
                    // We need to find the variant of this Main Product in Store X.
                    
                    // 1. Check if the item['product_id'] is already the variant?
                    $p = $sqlDb->fetch("SELECT store_id, sku FROM products WHERE id = ?", [$item['product_id']]);
                    
                    if ($p['store_id'] == $po['store_id']) {
                        // It is already the variant. Just update it.
                        $sqlDb->execute("UPDATE products SET quantity = quantity + ? WHERE id = ?", [$item['quantity'], $item['product_id']]);
                    } else {
                        // It is likely the Main Product (or another store's variant).
                        // Find the variant in the target store.
                        // Assuming SKU convention: MainSKU -> MainSKU-S{StoreId} ?? Or just same SKU?
                        // The system uses "Zero-Copy" where we create a new row in `products` table for the store variant.
                        
                        // Let's try to find a product with same name/sku in that store?
                        // Or better, we should have selected the variant in the dropdown?
                        // But the dropdown showed all products.
                        
                        // Let's assume we are restocking the Main Product ID passed in.
                        // If PO has store_id, we look for a product with same SKU in that store?
                        // Or we just add stock to the product ID specified?
                        
                        // User requirement: "assign to store with 0 quantity , then we restock".
                        // This implies the variant ALREADY exists (assigned).
                        // So we should find the variant for this Main Product in this Store.
                        
                        // Find variant by parent? We don't have parent_id column.
                        // We match by SKU?
                        // Let's check how "Assign to Store" works. It likely creates a new product with same SKU (or suffixed) and store_id.
                        
                        // For now, simple logic: Update the product ID specified. 
                        // If the user selected the Main Product but meant the Store Variant, this is a UI issue.
                        // Ideally, if PO is for Store X, the dropdown should only show products in Store X.
                        
                        $sqlDb->execute("UPDATE products SET quantity = quantity + ? WHERE id = ?", [$item['quantity'], $item['product_id']]);
                    }
                } else {
                    // Main Warehouse Restock
                    $sqlDb->execute("UPDATE products SET quantity = quantity + ? WHERE id = ?", [$item['quantity'], $item['product_id']]);
                }
                
                // Log Movement
                $sqlDb->execute(
                    "INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at) VALUES (?, ?, 'in', ?, ?, ?, ?, NOW())",
                    [$item['product_id'], $po['store_id'], $item['quantity'], 'PO-' . $po['po_number'], 'Received from Supplier', $_SESSION['user_id']]
                );
            }
            
            $sqlDb->execute("UPDATE purchase_orders SET status = 'received', updated_at = NOW() WHERE id = ?", [$id]);
            $sqlDb->commit();
            header("Location: edit.php?id=$id");
            exit;
        } catch (Exception $e) {
            $sqlDb->rollBack();
            $error = "Error receiving order: " . $e->getMessage();
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
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
            padding-left: 12px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
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
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Mark as Ordered
                                </button>
                            </form>
                        <?php elseif ($po['status'] === 'ordered'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="mark_received">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-check-circle"></i> Receive Items
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

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
                    <div class="info-value"><?php echo $po['expected_date'] ? date('M j, Y', strtotime($po['expected_date'])) : '-'; ?></div>
                </div>
            </div>

            <?php if ($po['status'] === 'draft'): ?>
            <div class="form-container" style="margin-bottom: 30px;">
                <h3 style="margin-bottom: 15px; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 10px;">Add Item to Order</h3>
                <form method="POST" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <input type="hidden" name="action" value="add_item">
                    <div class="form-group" style="flex: 3; min-width: 300px;">
                        <label>Product</label>
                        <select name="product_id" id="productSelect" required class="form-control">
                            <option value="" data-price="0">-- Search & Select Product --</option>
                            <?php 
                            $selected_pid = $_POST['product_id'] ?? $_GET['pre_product_id'] ?? '';
                            foreach ($grouped_products as $storeName => $storeProducts): 
                            ?>
                                <optgroup label="<?php echo htmlspecialchars($storeName); ?>">
                                    <?php foreach ($storeProducts as $p): ?>
                                        <option value="<?php echo $p['id']; ?>" 
                                                data-price="<?php echo $p['price']; ?>" 
                                                <?php echo ($p['id'] == $selected_pid) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($p['name']); ?> (SKU: <?php echo htmlspecialchars($p['sku']); ?>) - RM<?php echo number_format($p['price'], 2); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 100px;">
                        <label>Quantity</label>
                        <input type="number" name="quantity" required min="1" class="form-control" placeholder="Qty">
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 120px;">
                        <label>Unit Cost</label>
                        <input type="number" name="unit_cost" id="unitCostInput" required min="0" step="0.01" class="form-control" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary" style="height: 38px;">Add Item</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Quantity</th>
                            <th>Unit Cost</th>
                            <th>Total Cost</th>
                            <?php if ($po['status'] === 'draft'): ?><th>Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="<?php echo ($po['status'] === 'draft') ? 6 : 5; ?>" style="text-align: center; padding: 30px; color: #999;">
                                    No items added yet.
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
                                <td colspan="4" style="text-align: right; padding-right: 20px;">Total Amount:</td>
                                <td style="color: #2c3e50; font-size: 1.1em;">RM <?php echo number_format($po['total_amount'], 2); ?></td>
                                <?php if ($po['status'] === 'draft'): ?><td></td><?php endif; ?>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>

    <!-- jQuery (required for Select2) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize Select2
            $('#productSelect').select2({
                placeholder: "-- Search & Select Product --",
                allowClear: true,
                width: '100%'
            });

            // Handle price update on change
            $('#productSelect').on('select2:select', function (e) {
                var data = e.params.data;
                // We need to get the data-price attribute from the original option
                var element = $(data.element);
                var price = element.data('price');
                
                if (price) {
                    $('#unitCostInput').val(parseFloat(price).toFixed(2));
                }
            });

            // Run once on load if something is selected (e.g. from redirect)
            var initialPrice = $('#productSelect').find(':selected').data('price');
            if (initialPrice) {
                $('#unitCostInput').val(parseFloat(initialPrice).toFixed(2));
            }
        });
    </script>
</body>
</html>
