<?php
// Add New Product
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

// Require permission to manage inventory
if (!currentUserHasPermission('can_add_inventory') && !currentUserHasPermission('can_edit_inventory')) {
    $_SESSION['error'] = 'You do not have permission to add products';
    header('Location: ../../index.php');
    exit;
}

$db = getDB();
$errors = [];
$success = false;

// Get stores and categories for dropdowns
$stores = $db->fetchAll("SELECT id, name FROM stores WHERE is_active = 1 ORDER BY name");
$categories = $db->fetchAll("SELECT id, name FROM categories ORDER BY name");

function alert_resolve_low_stock_if_recovered(PDO $db, string $pid, ?string $who = 'admin'): void
{
    // read current qty & level
    $s = $db->prepare("SELECT quantity, reorder_level FROM products WHERE id=? LIMIT 1");
    $s->execute([$pid]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    $qty = (int)$row['quantity'];
    $lvl = (int)$row['reorder_level'];

    // Only resolve if we actually recovered above the threshold
    if ($qty > $lvl) {
        $u = $db->prepare(
            "UPDATE alerts 
           SET status='RESOLVED', resolved_at=NOW(), resolved_by=?, resolution_note='User added stock'
           WHERE product_id=? AND alert_type='LOW_STOCK' AND status='PENDING'"
        );
        $u->execute([$who ?? 'admin', $pid]);
    }
}


$CATEGORY_OPTIONS = [
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

// Keep the user’s selection on postback (default to General)
$selectedCategory = isset($_POST['category']) && $_POST['category'] !== ''
    ? (string)$_POST['category']
    : 'General';

// --- BATCH INSERT LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_insert_personal_care'])) {
    try {
        $sqlDb = getSQLDB();
        
        // Get the first active store
        $store = $sqlDb->fetch("SELECT id, name FROM stores WHERE is_active = 1 LIMIT 1");
        if (!$store) {
            throw new Exception("No active store found. Please create a store first.");
        }
        $storeId = $store['id'];
        // Create a simple suffix from store name (first 3 chars, uppercase)
        $storeSuffix = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $store['name']), 0, 3));

        $personalCareProducts = [
            ['name' => 'Shampoo (Aloe Vera)', 'price' => 15.50, 'qty' => 50, 'min' => 10],
            ['name' => 'Body Wash (Lavender)', 'price' => 12.90, 'qty' => 40, 'min' => 10],
            ['name' => 'Toothpaste (Mint)', 'price' => 8.50, 'qty' => 100, 'min' => 20],
            ['name' => 'Hand Soap (Lemon)', 'price' => 5.50, 'qty' => 60, 'min' => 15],
            ['name' => 'Face Wash (Charcoal)', 'price' => 18.90, 'qty' => 30, 'min' => 5],
        ];

        $count = 0;
        foreach ($personalCareProducts as $prod) {
            // Generate a unique SKU: PC-{RAND}-{STORE}
            $sku = 'PC-' . rand(1000, 9999) . '-' . $storeSuffix;
            
            // Check if SKU exists (simple check)
            $exists = $sqlDb->fetch("SELECT id FROM products WHERE sku = ?", [$sku]);
            if ($exists) {
                $sku = 'PC-' . rand(1000, 9999) . '-' . $storeSuffix . 'X'; // Retry once with suffix
            }

            $sql = "
                INSERT INTO products
                    (name, sku, description, category, store_id, quantity, price, reorder_level, created_at, updated_at)
                VALUES
                    (?,    ?,   ?,           ?,        ?,        ?,        ?,     ?,             CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ";
            $sqlDb->execute($sql, [
                $prod['name'],
                $sku,
                'Auto-generated Personal Care Product',
                'Personal Care',
                $storeId,
                $prod['qty'],
                $prod['price'],
                $prod['min'],
            ]);
            $count++;
        }

        $_SESSION['success'] = "Successfully added $count Personal Care products.";
        header('Location: list.php');
        exit;

    } catch (Exception $e) {
        $errors[] = "Batch insert failed: " . $e->getMessage();
    }
}
// --------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['batch_insert_personal_care'])) {
    // --- 1) Gather & normalize ------------------------------------------------
    $name            = sanitizeInput($_POST['name'] ?? '');
    $sku             = strtoupper(trim((string)($_POST['sku'] ?? '')));   // <-- canonicalize
    $description     = sanitizeInput($_POST['description'] ?? '');
    $category        = sanitizeInput($_POST['category'] ?? 'General');    // string, not category_id
    $store_id        = (int)($_POST['store_id'] ?? 0);
    $quantity        = (int)($_POST['quantity'] ?? 0);
    $unit_price      = (float)($_POST['unit_price'] ?? 0);
    $min_stock_level = (int)($_POST['min_stock_level'] ?? 0);

    $errors = [];

    // --- 2) Basic validation --------------------------------------------------
    if ($name === '') $errors[] = 'Product name is required';
    if ($quantity < 0) $errors[] = 'Quantity cannot be negative';
    if ($unit_price < 0) $errors[] = 'Unit price cannot be negative';
    if ($min_stock_level < 0) $errors[] = 'Minimum stock level cannot be negative';

    // --- 3) Firestore-first SKU uniqueness -----------------------------------
    // Use helper to find existing product by sku or doc id
    $existingFs = null;
    if ($sku !== '') {
        try {
            $sqlDb = getSQLDB();

            // Case-insensitive check (works in SQLite and most DBs)
            $row = $sqlDb->fetch("SELECT * FROM products WHERE UPPER(sku) = ?", [$sku]);

            if ($row) {
                // SKU is already in SQL; make sure Firestore has it too (auto-repair)
                try {
                    $fs = getDB();

                    // Build the Firestore doc from SQL row
                    $product_id   = (string)$row['id'];
                    $productDoc   = [
                        'name'          => $row['name']          ?? '',
                        'sku'           => $row['sku']           ?? null,
                        'description'   => $row['description']   ?? null,
                        'category'      => $row['category']      ?? 'General',
                        'store_id'      => isset($row['store_id']) ? (int)$row['store_id'] : null,
                        'quantity'      => isset($row['quantity']) ? (int)$row['quantity'] : 0,
                        'price'         => isset($row['price']) ? (float)$row['price'] : 0.0,
                        'reorder_level' => isset($row['reorder_level']) ? (int)$row['reorder_level'] : 0,
                        'created_at'    => date('c'),
                        'updated_at'    => date('c'),
                    ];

                    // Upsert using SQL id as Firestore doc id
                    $fs->create('products', $productDoc, $product_id);
                } catch (Throwable $t) {
                    error_log('Auto-repair Firestore sync failed for existing SKU ' . $sku . ': ' . $t->getMessage());
                }

                // Tell the user SKU is taken (now it will appear in list due to the repair)
                $errors[] = 'SKU already exists';
            }
        } catch (Throwable $t) {
            error_log('SQL SKU check failed: ' . $t->getMessage());
            // Don't block submission on check failure
        }
    }


    // --- 4) SQL uniqueness as a safety net -----------------------------------
    if (empty($errors) && $sku !== '') {
        try {
            $sqlDb = getSQLDB();
            $row = $sqlDb->fetch("SELECT id FROM products WHERE UPPER(sku) = ?", [strtoupper($sku)]);
            if ($row) $errors[] = 'SKU already exists (database)';
        } catch (Throwable $t) {
            error_log('SQL SKU check failed: ' . $t->getMessage());
        }
    }

    // --- 5) Create product if valid ------------------------------------------
    if (empty($errors)) {
        $sqlDb = getSQLDB();

        try {
            $sqlDb->beginTransaction();

            $sql = "
                INSERT INTO products
                    (name, sku, description, category, store_id, quantity, price, reorder_level, created_at, updated_at)
                VALUES
                    (?,    ?,   ?,           ?,        ?,        ?,        ?,     ?,             CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ";
            $sqlDb->execute($sql, [
                $name,
                $sku !== '' ? $sku : null,
                $description !== '' ? $description : null,
                $category,
                $store_id > 0 ? $store_id : null,
                $quantity,
                $unit_price,
                $min_stock_level,
            ]);

            $product_id = $sqlDb->lastInsertId();

            // Optional initial stock movement
            if ($quantity > 0) {
                $sqlDb->execute("
                    INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                    VALUES (?, ?, 'in', ?, 'Initial stock', 'Product created with initial stock', ?, CURRENT_TIMESTAMP)
                ", [
                    $product_id,
                    $store_id > 0 ? $store_id : null,
                    $quantity,
                    $_SESSION['user_id'] ?? null
                ]);
            }

            $sqlDb->commit();

            // --- 6) Firestore upsert (best-effort; do not break UX) -----------
            try {
                $db = getDB();
                $productDoc = [
                    'name'          => $name,
                    'sku'           => $sku !== '' ? $sku : null,
                    'description'   => $description !== '' ? $description : null,
                    'category'      => $category,
                    'store_id'      => $store_id > 0 ? $store_id : null,
                    'quantity'      => $quantity,
                    'price'         => $unit_price,
                    'reorder_level' => $min_stock_level,
                    'created_at'    => date('c'),
                    'updated_at'    => date('c'),
                ];

                if (is_array($existingFs) && !empty($existingFs) && !empty($existingFs['doc_id'])) {
                    // REUSE the orphan/matching Firestore doc for this SKU
                    $db->update('products', (string)$existingFs['doc_id'], $productDoc);
                } else {
                    // Create a new Firestore doc using SQL id as the doc id
                    $db->create('products', $productDoc, (string)$product_id);
                }

                // Also mirror stock movement if any
                if ($quantity > 0) {
                    $db->create('stock_movements', [
                        'product_id'  => $product_id,
                        'store_id'    => $store_id > 0 ? $store_id : null,
                        'movement_type' => 'in',
                        'quantity'    => $quantity,
                        'reference'   => 'Initial stock',
                        'notes'       => 'Product created with initial stock',
                        'user_id'     => $_SESSION['user_id'] ?? null,
                        'created_at'  => date('c'),
                    ]);
                }
            } catch (Throwable $t) {
                error_log('Firestore upsert failed: ' . $t->getMessage());
            }
            try {
                // pull identity from session (adjust keys if your app uses different ones)
                $changedBy   = $_SESSION['user_id'] ?? $_SESSION['uid'] ?? ($_SESSION['user']['id'] ?? null);
                $changedName = $_SESSION['username'] ?? $_SESSION['email'] ?? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? null);

                log_stock_audit([
                    'action'         => 'create',
                    'product_id'     => (string)$product_id,               // SQL id you just inserted
                    'sku'            => $sku !== '' ? $sku : null,
                    'product_name'   => $name,
                    'store_id'       => $store_id > 0 ? $store_id : null,

                    // do NOT include 'before'/'after' so Qty shows "–" in the audit table

                    // also include who created it
                    'user_id'        => $changedBy,
                    'username'       => $changedName,
                    'changed_by'     => $changedBy,    // for pages that read these exact keys
                    'changed_name'   => $changedName,
                ]);
            } catch (Throwable $t) {
                error_log('create audit failed: ' . $t->getMessage());
            }

            // --- 7) Clear cache and redirect to refresh data -----------------------
            $cacheFile = __DIR__ . '/../../storage/cache/stock_list_data.cache';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }
            header('Location: list.php?refresh=1&success=' . rawurlencode("Product '{$name}' added successfully!"));
            exit;
        } catch (Throwable $t) {
            if ($sqlDb->inTransaction()) $sqlDb->rollBack();
            $msg = $t->getMessage();
            if (stripos($msg, 'UNIQUE') !== false && stripos($msg, 'sku') !== false) {
                $errors[] = 'SKU already exists (database unique index)';
            } else {
                $errors[] = 'Error creating product: ' . $msg;
            }
        }
    }



    // If here, $errors (if any) will be rendered by your form template.
}



$page_title = 'Add Product - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    <div class="container">

        <main>
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-header-inner">
                    <div class="left">
                        <h1 class="title">Add New Product</h1>
                        <p class="subtitle">Fill in the details below to create a new product record.</p>
                    </div>
                    <div class="right">
                        <form method="POST" style="display: inline; margin-right: 10px;">
                            <input type="hidden" name="batch_insert_personal_care" value="1">
                            <button type="submit" class="btn btn-secondary" onclick="return confirm('This will add 5 sample Personal Care products. Continue?');">
                                <i class="fas fa-magic"></i> Batch Insert Personal Care
                            </button>
                        </form>
                        <a href="list.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back to Stock List
                        </a>
                    </div>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <h3>Please fix the following errors:</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <form method="POST" class="product-form">
                    <div class="form-sections">
                        <!-- Basic Information -->
                        <div class="form-section">
                            <h3>Basic Information</h3>

                            <div class="form-row">
                                <div class="form-group required">
                                    <label for="name">Product Name:</label>
                                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                                    <small>Enter the full product name</small>
                                </div>

                                <div class="form-group">
                                    <label for="sku">SKU:</label>
                                    <input type="text" id="sku" name="sku" value="<?php echo htmlspecialchars($_POST['sku'] ?? ''); ?>" placeholder="e.g., WH-001">
                                    <small>Stock Keeping Unit (optional, must be unique)</small>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="description">Description:</label>
                                <textarea id="description" name="description" rows="3" placeholder="Product description..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                                <small>Detailed product description</small>
                            </div>
                        </div>

                        <!-- Classification -->
                        <div class="form-section">
                            <h3>Classification</h3>

                            <div class="form-row">
                                <div class="form-group">
                                    <label for="category_id">Category:</label>
                                    <select id="category" name="category" class="form-control" required>
                                        <?php foreach ($CATEGORY_OPTIONS as $opt): ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>"
                                                <?php echo ($opt === $selectedCategory) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small>Product category for organization</small>
                                </div>

                                <div class="form-group">
                                    <label for="store_id">Store:</label>
                                    <select id="store_id" name="store_id">
                                        <option value="">Select Store</option>
                                        <?php foreach ($stores as $store): ?>
                                            <option value="<?php echo $store['id']; ?>"
                                                <?php echo (isset($_POST['store_id']) && $_POST['store_id'] == $store['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($store['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small>Store location for this product</small>
                                </div>
                            </div>
                        </div>

                        <!-- Stock Information -->
                        <div class="form-section">
                            <h3>Stock Information</h3>

                            <div class="form-row">
                                <div class="form-group required">
                                    <label for="quantity">Initial Quantity:</label>
                                    <input type="number" id="quantity" name="quantity" value="<?php echo htmlspecialchars($_POST['quantity'] ?? '0'); ?>" min="0" step="1">
                                    <small>Starting inventory quantity</small>
                                </div>

                                <div class="form-group">
                                    <label for="min_stock_level">Minimum Stock Level:</label>
                                    <input type="number" id="min_stock_level" name="min_stock_level" value="<?php echo htmlspecialchars($_POST['min_stock_level'] ?? '5'); ?>" min="0" step="1">
                                    <small>Alert threshold for low stock</small>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing Information -->
                        <div class="form-section">
                            <h3>Pricing Information</h3>

                            <div class="form-row">
                                <div class="form-group required">
                                    <label for="unit_price">Unit Price ($):</label>
                                    <input type="number" id="unit_price" name="unit_price" value="<?php echo htmlspecialchars($_POST['unit_price'] ?? '0.00'); ?>" min="0" step="0.01">
                                    <small>Selling price per unit</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Add Product</button>
                        <a href="list.php" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Calculate total value
        function updateTotalValue() {
            const quantity = parseInt(document.getElementById('quantity').value) || 0;
            const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
            const totalValue = quantity * unitPrice;

            // Update display if there's a total value element
            const totalElement = document.getElementById('total_value_display');
            if (totalElement) {
                totalElement.textContent = '$' + totalValue.toFixed(2);
            }
        }

        document.getElementById('quantity').addEventListener('input', updateTotalValue);
        document.getElementById('unit_price').addEventListener('input', updateTotalValue);

        // Form validation
        document.querySelector('.product-form').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            const quantity = parseInt(document.getElementById('quantity').value);
            const unitPrice = parseFloat(document.getElementById('unit_price').value);

            if (!name) {
                alert('Product name is required');
                e.preventDefault();
                return false;
            }

            if (quantity <= 0) {
                alert('Quantity cannot be empty or negative');
                e.preventDefault();
                return false;
            }

            if (unitPrice <= 0) {
                alert('Unit price cannot be empty or negative');
                e.preventDefault();
                return false;
            }

            return true;
        });
    </script>

    <style>
        .form-container {
            max-width: 900px;
            margin: 0 auto;
        }

        .product-form {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .form-sections {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .form-section {
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 2rem;
        }

        .form-section:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .form-section h3 {
            margin-bottom: 1rem;
            color: #333;
            font-size: 1.2rem;
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group.required label::after {
            content: ' *';
            color: #dc3545;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
        }

        .form-group small {
            display: block;
            margin-top: 0.25rem;
            color: #666;
            font-size: 0.875rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding-top: 2rem;
            border-top: 1px solid #e0e0e0;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0;
            }

            .form-actions {
                flex-direction: column;
            }
        }

        .page-header {
            background: #fff;
            border: 1px solid #e5eaf1;
            border-radius: 14px;
            padding: 18px 28px;
            margin: 25px auto 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.03);
            max-width: 1100px;
        }

        .page-header-inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .page-header .title {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e293b;
            margin: 0;
        }

        .page-header .subtitle {
            font-size: 0.9rem;
            color: #64748b;
            margin-top: 2px;
        }

        .page-header .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #2563eb;
            color: #fff;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 600;
            transition: background 0.25s ease, transform 0.2s ease;
        }

        .page-header .btn-back:hover {
            background: #1e40af;
            transform: translateY(-1px);
        }
    </style>
</body>

</html>