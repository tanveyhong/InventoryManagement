<?php
// Add New Product
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

// Require permission to manage inventory
if (!currentUserHasPermission('can_add_inventory')) {
    $_SESSION['error'] = 'You do not have permission to add products';
    header('Location: ../../index.php');
    exit;
}

$db = getDB();
$errors = [];
$success = false;

// Get categories for dropdowns
$categories = $db->fetchAll("SELECT id, name FROM categories ORDER BY name");

// Get suppliers for dropdowns (MUST use SQL DB, not Firestore)
$suppliers = [];
try {
    $sqlDb = getSQLDB(); // or SQLDatabase::getInstance()
    $suppliers = $sqlDb->fetchAll("SELECT id, name FROM suppliers WHERE active = TRUE ORDER BY name");
} catch (Throwable $e) {
    error_log('Load suppliers failed in add.php: ' . $e->getMessage());
    $suppliers = [];
}

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
    'Skincare',
    'Haircare',
    'Body Care',
    'Oral Care',
    'Health & Wellness',
    'General',
];

// Keep the user’s selection on postback (NO default)
$selectedCategory = isset($_POST['category'])
    ? trim((string)$_POST['category'])
    : '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- 1) Gather & normalize ------------------------------------------------
    $name            = sanitizeInput($_POST['name'] ?? '');
    $sku             = strtoupper(trim((string)($_POST['sku'] ?? '')));   // <-- canonicalize

    // Auto-generate SKU if empty
    if (empty($sku)) {
        $base = 'PRD';
        if (!empty($name)) {
            // Extract uppercase letters and numbers
            $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper($name));
            if (strlen($clean) >= 3) {
                // Take up to first 6 characters of the name
                $base = substr($clean, 0, 6);
            }
        }
        // Add random suffix for uniqueness
        $sku = $base . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    }

    $description     = sanitizeInput($_POST['description'] ?? '');
    $category = trim(sanitizeInput($_POST['category'] ?? ''));
    $store_id        = null; // Always NULL for main stock (central inventory)
    $supplier_id     = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $rawQty  = trim((string)($_POST['quantity'] ?? ''));
    $rawUnit = trim((string)($_POST['unit_price'] ?? ''));

    // Only cast AFTER validation passes
    $quantity   = ($rawQty === '')  ? null : (int)$rawQty;
    $unit_price = ($rawUnit === '') ? null : (float)$rawUnit;
    $cost_price      = (float)($_POST['cost_price'] ?? 0);
    $min_stock_level = (int)($_POST['min_stock_level'] ?? 0);

    $errors = [];

    // --- 2) Basic validation --------------------------------------------------
    if ($name === '') $errors[] = 'Product name is required';
    if ($rawQty === '') {
        $errors[] = 'Initial quantity is required';
    } elseif (!ctype_digit($rawQty)) {
        $errors[] = 'Initial quantity must be a whole number';
    }

    if ($rawUnit === '') {
        $errors[] = 'Unit price is required';
    } elseif (!is_numeric($rawUnit)) {
        $errors[] = 'Unit price must be a valid number';
    } elseif ((float)$rawUnit < 0) {
        $errors[] = 'Unit price cannot be negative';
    }
    if ($cost_price < 0) $errors[] = 'Cost price cannot be negative';
    if ($min_stock_level < 0) $errors[] = 'Minimum stock level cannot be negative';
    if ($category === '') $errors[] = 'Category is required. Please select a category.';

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
                        'cost_price'    => isset($row['cost_price']) ? (float)$row['cost_price'] : 0.0,
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
                    (name, sku, description, category, store_id, supplier_id, quantity, cost_price, price, selling_price, reorder_level, created_at, updated_at)
                VALUES
                    (?,    ?,   ?,           ?,        ?,        ?,           ?,        ?,          ?,     ?,             ?,             CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ";

            // We are using 'category' text column, not category_id relation in this schema version
            // But we still want to ensure the category exists in categories table for consistency if possible
            // For now, just insert the text name as that's what the table expects

            $sqlDb->execute($sql, [
                $name,
                $sku !== '' ? $sku : null,
                $description !== '' ? $description : null,
                $category, // Insert text directly
                $store_id > 0 ? $store_id : null,
                $supplier_id,
                $quantity,
                $cost_price,
                $unit_price,
                $unit_price, // selling_price
                $min_stock_level, // reorder_level
            ]);

            $product_id = $sqlDb->lastInsertId();

            logActivity('product_added', "Added new product: $name (SKU: $sku)", ['product_id' => $product_id, 'sku' => $sku]);

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
                    'cost_price'    => $cost_price,
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
                                    <div style="display: flex; gap: 10px;">
                                        <input type="text" id="sku" name="sku" value="<?php echo htmlspecialchars($_POST['sku'] ?? ''); ?>" placeholder="Auto-generated if empty">
                                        <button type="button" onclick="generateSKU()" class="btn btn-secondary" style="padding: 0 15px; white-space: nowrap;">Generate</button>
                                    </div>
                                    <small>Stock Keeping Unit (unique identifier)</small>
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
                                        <option value="" <?php echo ($selectedCategory === '') ? 'selected' : ''; ?>>
                                            -- Select Category --
                                        </option>

                                        <?php foreach ($CATEGORY_OPTIONS as $opt): ?>
                                            <option value="<?php echo htmlspecialchars($opt); ?>"
                                                <?php echo ($opt === $selectedCategory) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($opt); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <small>Product category</small>
                                </div>

                                <div class="form-group">
                                    <label for="supplier_id">Supplier:</label>
                                    <select id="supplier_id" name="supplier_id" class="form-control">
                                        <option value="">-- Select Supplier --</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?php echo $supplier['id']; ?>" <?php echo (isset($_POST['supplier_id']) && $_POST['supplier_id'] == $supplier['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($supplier['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small>Primary supplier for this product</small>
                                </div>
                            </div>
                        </div>

                        <!-- Stock Information -->
                        <div class="form-section">
                            <h3>Stock Information</h3>

                            <div class="form-row">
                                <div class="form-group required">
                                    <label for="quantity">Initial Quantity:</label>
                                    <input type="number" id="quantity" name="quantity" value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>" class="form-control" required min="0" step="1">
                                    <small>Starting inventory quantity</small>
                                </div>

                                <div class="form-group">
                                    <label for="min_stock_level">Minimum Stock Level:</label>
                                    <input type="number" id="min_stock_level" name="min_stock_level" value="<?php echo htmlspecialchars($_POST['min_stock_level'] ?? '5'); ?>" class="form-control" required min="0" step="1">
                                    <small>Alert threshold for low stock</small>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing Information -->
                        <div class="form-section">
                            <h3>Pricing Information</h3>

                            <div class="form-row">
                                <div class="form-group required">
                                    <label for="cost_price">Cost Price (RM):</label>
                                    <input type="number" id="cost_price" name="cost_price" value="<?php echo htmlspecialchars($_POST['cost_price'] ?? '0.00'); ?>" min="0" step="0.01">
                                    <small>Cost per unit from supplier</small>
                                </div>

                                <div class="form-group required">
                                    <label for="unit_price">Unit Price (RM):</label>
                                    <input type="number" id="unit_price" name="unit_price" value="<?php echo htmlspecialchars($_POST['unit_price'] ?? ''); ?>" class="form-control" required min="0" step="0.01">
                                    <small>Selling price per unit (Auto-calc: Cost / 0.7)</small>
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
        // Generate SKU based on Product Name
        function generateSKU() {
            const name = document.getElementById('name').value;
            let base = 'PRD';

            if (name) {
                // Get first letters of words or just the name
                const words = name.toUpperCase().replace(/[^A-Z0-9\s]/g, '').split(/\s+/).filter(w => w.length > 0);
                if (words.length > 1) {
                    // First 3 chars of first 2 words (e.g. "Super Widget" -> "SUPWID")
                    base = words[0].substring(0, 3) + words[1].substring(0, 3);
                } else if (words.length === 1) {
                    // First 6 chars of first word (e.g. "SuperWidget" -> "SUPERW")
                    base = words[0].substring(0, 6);
                }
            }

            // Add random suffix (e.g. "-X7Y8Z9")
            const random = Math.random().toString(36).substring(2, 8).toUpperCase();
            document.getElementById('sku').value = base + '-' + random;
        }

        // Calculate total value
        function updateTotalValue() {
            // Quantity is now always 0 initially
            const quantity = 0;
            const unitPrice = parseFloat(document.getElementById('unit_price').value) || 0;
            const totalValue = quantity * unitPrice;

            // Update display if there's a total value element
            const totalElement = document.getElementById('total_value_display');
            if (totalElement) {
                totalElement.textContent = '$' + totalValue.toFixed(2);
            }
        }

        // Auto-calculate Selling Price based on Cost
        document.getElementById('cost_price').addEventListener('input', function() {
            const cost = parseFloat(this.value) || 0;
            if (cost > 0) {
                // Formula: Cost = Price * 0.70  =>  Price = Cost / 0.70
                const price = cost / 0.70;
                document.getElementById('unit_price').value = price.toFixed(2);
                updateTotalValue();
            }
        });

        // document.getElementById('quantity').addEventListener('input', updateTotalValue);
        document.getElementById('unit_price').addEventListener('input', updateTotalValue);

        // Form validation
        document.querySelector('.product-form').addEventListener('submit', function(e) {
            const name = document.getElementById('name').value.trim();
            // const quantity = parseInt(document.getElementById('quantity').value);
            const unitPrice = parseFloat(document.getElementById('unit_price').value);

            if (!name) {
                alert('Product name is required');
                e.preventDefault();
                return false;
            }

            /*
            if (quantity <= 0) {
                alert('Quantity cannot be empty or negative');
                e.preventDefault();
                return false;
            }
            */

            if (unitPrice < 0) {
                alert('Unit price cannot be negative');
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