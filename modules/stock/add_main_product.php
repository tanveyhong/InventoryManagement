<?php
/**
 * REFACTORED INVENTORY ARCHITECTURE
 * ==================================
 * 
 * Main Products (No store_id):
 * - Central product with total quantity across all stores
 * - SKU format: CATEGORY-PRODUCT (e.g., BEV-001, CARE-003)
 * 
 * Store Variants (With store_id):
 * - Sub-products created when assigning main product to stores
 * - SKU format: BASE-S{store_id} (e.g., BEV-001-S6, CARE-003-S7)
 * - Quantity represents stock in that specific store
 * 
 * Stock Flow:
 * - When store variant quantity changes, main product quantity updates automatically
 * - Main product quantity = SUM of all store variant quantities
 * - Stock adjustments, sales, etc. update both store variant AND main product
 */

require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_add_inventory')) {
    $_SESSION['error'] = 'You do not have permission to add products';
    header('Location: list.php');
    exit;
}

$db = getDB();
$errors = [];
$success = false;

// Get categories for dropdown
$categories = [
    'General',
    'Foods',
    'Beverages',
    'Snacks',
    'Personal Care',
    'Furniture',
    'Electronics',
    'Stationery',
    'Canned Foods',
    'Frozen',
];

$selectedCategory = isset($_POST['category']) && $_POST['category'] !== ''
    ? (string)$_POST['category']
    : 'General';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gather input
    $name            = sanitizeInput($_POST['name'] ?? '');
    $sku             = strtoupper(trim((string)($_POST['sku'] ?? '')));
    $description     = sanitizeInput($_POST['description'] ?? '');
    $category        = sanitizeInput($_POST['category'] ?? 'General');
    $quantity        = (int)($_POST['quantity'] ?? 0);
    $unit_price      = (float)($_POST['unit_price'] ?? 0);
    $cost_price      = (float)($_POST['cost_price'] ?? 0);
    $min_stock_level = (int)($_POST['min_stock_level'] ?? 0);
    $barcode         = trim($_POST['barcode'] ?? '');
    $unit            = trim($_POST['unit'] ?? 'pcs');

    $errors = [];

    // Validation
    if ($name === '') $errors[] = 'Product name is required';
    if ($sku === '') $errors[] = 'SKU is required';
    if ($quantity < 0) $errors[] = 'Quantity cannot be negative';
    if ($unit_price < 0) $errors[] = 'Unit price cannot be negative';
    if ($min_stock_level < 0) $errors[] = 'Minimum stock level cannot be negative';

    // Check if SKU contains store suffix (should not for main products)
    if (preg_match('/-S\d+$/', $sku)) {
        $errors[] = 'Main product SKU should not contain store suffix (-S#). Store variants will be created automatically when assigning to stores.';
    }

    // Check SKU uniqueness in SQL
    if (empty($errors) && $sku !== '') {
        try {
            $sqlDb = getSQLDB();
            $row = $sqlDb->fetch("SELECT id FROM products WHERE UPPER(sku) = ? AND store_id IS NULL", [strtoupper($sku)]);
            if ($row) $errors[] = 'SKU already exists for another main product';
        } catch (Throwable $t) {
            error_log('SQL SKU check failed: ' . $t->getMessage());
        }
    }

    // Check SKU uniqueness in Firebase
    if (empty($errors) && $sku !== '') {
        try {
            $existingProducts = $db->queryCollection('products', [
                ['field' => 'sku', 'operator' => '==', 'value' => $sku]
            ], null, 1);
            
            foreach ($existingProducts as $prod) {
                // Only error if it's a main product (no store_id)
                if (empty($prod['store_id'])) {
                    $errors[] = 'SKU already exists in Firebase';
                    break;
                }
            }
        } catch (Throwable $t) {
            error_log('Firebase SKU check failed: ' . $t->getMessage());
        }
    }

    // Create main product
    if (empty($errors)) {
        $sqlDb = getSQLDB();

        try {
            $sqlDb->beginTransaction();

            // Insert main product (NO store_id)
            $sql = "
                INSERT INTO products
                    (name, sku, barcode, description, category, unit, cost_price, price, quantity, reorder_level, store_id, active, created_at, updated_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ";
            $sqlDb->execute($sql, [
                $name,
                $sku,
                $barcode !== '' ? $barcode : null,
                $description !== '' ? $description : null,
                $category,
                $unit,
                $cost_price,
                $unit_price,
                $quantity,
                $min_stock_level,
            ]);

            $product_id = $sqlDb->lastInsertId();

            // Log initial stock movement
            if ($quantity > 0) {
                $sqlDb->execute("
                    INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                    VALUES (?, NULL, 'in', ?, 'Initial stock', 'Main product created with initial stock', ?, CURRENT_TIMESTAMP)
                ", [
                    $product_id,
                    $quantity,
                    $_SESSION['user_id'] ?? null
                ]);
            }

            $sqlDb->commit();

            // Sync to Firebase (best effort)
            try {
                $productDoc = [
                    'name'          => $name,
                    'sku'           => $sku,
                    'barcode'       => $barcode !== '' ? $barcode : null,
                    'description'   => $description !== '' ? $description : null,
                    'category'      => $category,
                    'unit'          => $unit,
                    'cost_price'    => $cost_price,
                    'selling_price' => $unit_price,
                    'price'         => $unit_price,
                    'quantity'      => $quantity,
                    'reorder_level' => $min_stock_level,
                    'min_stock_level' => $min_stock_level,
                    'store_id'      => null, // Main product has no store
                    'active'        => 1,
                    'created_at'    => date('c'),
                    'updated_at'    => date('c'),
                ];

                $db->create('products', $productDoc, (string)$product_id);

                // Sync stock movement
                if ($quantity > 0) {
                    $db->create('stock_movements', [
                        'product_id'    => $product_id,
                        'store_id'      => null,
                        'movement_type' => 'in',
                        'quantity'      => $quantity,
                        'reference'     => 'Initial stock',
                        'notes'         => 'Main product created with initial stock',
                        'user_id'       => $_SESSION['user_id'] ?? null,
                        'created_at'    => date('c'),
                    ]);
                }
            } catch (Throwable $t) {
                error_log('Firebase sync failed: ' . $t->getMessage());
            }

            // Log audit trail
            try {
                $changedBy   = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? null;
                $changedName = $_SESSION['username'] ?? $_SESSION['email'] ?? null;

                log_stock_audit([
                    'action'         => 'create_main_product',
                    'product_id'     => (string)$product_id,
                    'sku'            => $sku,
                    'product_name'   => $name,
                    'store_id'       => null,
                    'user_id'        => $changedBy,
                    'username'       => $changedName,
                    'changed_by'     => $changedBy,
                    'changed_name'   => $changedName,
                ]);
            } catch (Throwable $t) {
                error_log('Audit log failed: ' . $t->getMessage());
            }

            // Clear cache
            $cacheFile = __DIR__ . '/../../storage/cache/stock_list_data.cache';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }

            $_SESSION['success'] = "Main product '{$name}' created successfully! You can now assign it to stores.";
            header('Location: list.php?refresh=1');
            exit;

        } catch (Throwable $t) {
            if ($sqlDb->inTransaction()) $sqlDb->rollBack();
            $msg = $t->getMessage();
            if (stripos($msg, 'UNIQUE') !== false && stripos($msg, 'sku') !== false) {
                $errors[] = 'SKU already exists';
            } else {
                $errors[] = 'Error creating product: ' . $msg;
            }
        }
    }
}

$page_title = 'Add Main Product - Inventory System';
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
            border-radius: 4px;
        }
        .info-box h4 {
            margin: 0 0 10px 0;
            color: #1976D2;
        }
        .info-box ul {
            margin: 5px 0 0 20px;
            color: #424242;
        }
        .info-box code {
            background: #fff;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>

<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">
        <main>
            <div class="page-header">
                <h1><i class="fas fa-plus-circle"></i> Add Main Product</h1>
                <a href="list.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Stock List
                </a>
            </div>

            <div class="info-box">
                <h4><i class="fas fa-info-circle"></i> New Product Architecture</h4>
                <ul>
                    <li><strong>Main Product:</strong> Create a central product without store assignment (e.g., <code>BEV-001</code>)</li>
                    <li><strong>Store Variants:</strong> Assign main product to stores later via "Assign to Store" action</li>
                    <li><strong>SKU Format:</strong> Use base SKU only (e.g., <code>CARE-003</code>). Store suffix like <code>-S6</code> will be added automatically</li>
                    <li><strong>Stock Tracking:</strong> Main product quantity = sum of all store variant quantities</li>
                </ul>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <h3>Please fix the following errors:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="add.php" class="form-card">
                <div class="form-section">
                    <h3>Basic Information</h3>
                    
                    <div class="form-group">
                        <label for="name"><i class="fas fa-tag"></i> Product Name *</label>
                        <input type="text" id="name" name="name" 
                               value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                               required placeholder="e.g., Coca-Cola 330ml">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="sku"><i class="fas fa-barcode"></i> SKU (Base) *</label>
                            <input type="text" id="sku" name="sku" 
                                   value="<?php echo htmlspecialchars($_POST['sku'] ?? ''); ?>" 
                                   required placeholder="e.g., BEV-001"
                                   pattern="[A-Z0-9\-]+"
                                   title="Use uppercase letters, numbers, and hyphens only. NO store suffix (-S#)">
                            <small>Base SKU without store suffix. Example: BEV-001, CARE-003</small>
                        </div>

                        <div class="form-group">
                            <label for="barcode"><i class="fas fa-qrcode"></i> Barcode</label>
                            <input type="text" id="barcode" name="barcode" 
                                   value="<?php echo htmlspecialchars($_POST['barcode'] ?? ''); ?>" 
                                   placeholder="Optional barcode">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="description"><i class="fas fa-align-left"></i> Description</label>
                        <textarea id="description" name="description" rows="3" 
                                  placeholder="Product description"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="category"><i class="fas fa-folder"></i> Category *</label>
                            <select id="category" name="category" required>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" 
                                            <?php echo $selectedCategory === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="unit"><i class="fas fa-cube"></i> Unit</label>
                            <input type="text" id="unit" name="unit" 
                                   value="<?php echo htmlspecialchars($_POST['unit'] ?? 'pcs'); ?>" 
                                   placeholder="e.g., pcs, kg, L">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Pricing & Stock</h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="cost_price"><i class="fas fa-dollar-sign"></i> Cost Price (RM)</label>
                            <input type="number" id="cost_price" name="cost_price" 
                                   value="<?php echo htmlspecialchars($_POST['cost_price'] ?? '0'); ?>" 
                                   min="0" step="0.01" placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label for="unit_price"><i class="fas fa-money-bill-wave"></i> Selling Price (RM) *</label>
                            <input type="number" id="unit_price" name="unit_price" 
                                   value="<?php echo htmlspecialchars($_POST['unit_price'] ?? '0'); ?>" 
                                   min="0" step="0.01" required placeholder="0.00">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="quantity"><i class="fas fa-boxes"></i> Initial Quantity</label>
                            <input type="number" id="quantity" name="quantity" 
                                   value="<?php echo htmlspecialchars($_POST['quantity'] ?? '0'); ?>" 
                                   min="0" placeholder="0">
                            <small>Total quantity before assigning to stores</small>
                        </div>

                        <div class="form-group">
                            <label for="min_stock_level"><i class="fas fa-exclamation-triangle"></i> Minimum Stock Level</label>
                            <input type="number" id="min_stock_level" name="min_stock_level" 
                                   value="<?php echo htmlspecialchars($_POST['min_stock_level'] ?? '0'); ?>" 
                                   min="0" placeholder="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="expiry_date"><i class="fas fa-calendar-alt"></i> Expiry Date</label>
                        <input type="date" id="expiry_date" name="expiry_date" 
                               value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Main Product
                    </button>
                    <a href="list.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
</body>
</html>
