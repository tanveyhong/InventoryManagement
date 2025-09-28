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

$db = getDB();
$errors = [];
$success = false;

// Get stores and categories for dropdowns
$stores = $db->fetchAll("SELECT id, name FROM stores WHERE is_active = 1 ORDER BY name");
$categories = $db->fetchAll("SELECT id, name FROM categories ORDER BY name");

$CATEGORY_OPTIONS = [
  'General',
  'Beverages',
  'Snacks',
  'Personal Care',
  'Household',
  'Electronics',
  'Stationery',
  'Fresh Produce',
  'Frozen',
  'Bakery',
];

// Keep the user’s selection on postback (default to General)
$selectedCategory = isset($_POST['category']) && $_POST['category'] !== ''
  ? (string)$_POST['category']
  : 'General';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $name = sanitizeInput($_POST['name'] ?? '');
    $sku = sanitizeInput($_POST['sku'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $category = sanitizeInput($_POST['category'] ?? '');
    $store_id = intval($_POST['store_id'] ?? 0);
    $quantity = intval($_POST['quantity'] ?? 0);
    $unit_price = floatval($_POST['unit_price'] ?? 0);
    $min_stock_level = intval($_POST['min_stock_level'] ?? 0);
    $expiry_date = $_POST['expiry_date'] ?? null;
    
    // Validation
    if (empty($name)) {
        $errors[] = 'Product name is required';
    }
    
    if (!empty($sku)) {
        // Check if SKU already exists using SQL DB (reliable for unique constraints)
        try {
            $sqlDb = getSQLDB();
            $existing = $sqlDb->fetch("SELECT id FROM products WHERE sku = ?", [$sku]);
            if ($existing) {
                $errors[] = 'SKU already exists';
            }
        } catch (Exception $e) {
            // If check fails, fall back to generic check (do not block flow)
            error_log('SKU uniqueness check failed: ' . $e->getMessage());
        }
    }
    
    if ($quantity <= 0) {
        $errors[] = 'Quantity cannot be negative';
    }
    
    if ($unit_price <= 0) {
        $errors[] = 'Unit price cannot be negative';
    }
    
    if ($min_stock_level < 0) {
        $errors[] = 'Minimum stock level cannot be negative';
    }
    
    if (!empty($expiry_date) && !strtotime($expiry_date)) {
        $errors[] = 'Invalid expiry date format';
    }
    
    // If no errors, insert product
    if (empty($errors)) {
        try {
            // Map to SQL schema used by SQLite (products table uses 'category' and 'price'/'reorder_level')
            // Resolve category name from the categories list
            $category_name = null;
            if ($category_id > 0 && is_array($categories)) {
                foreach ($categories as $cat) {
                    if (isset($cat['id']) && $cat['id'] == $category_id) {
                        $category_name = $cat['name'];
                        break;
                    }
                }
            }

            $sql = "
                INSERT INTO products (name, sku, description, category, store_id, quantity, price, reorder_level, expiry_date, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ";

            $params = [
                $name,
                !empty($sku) ? $sku : null,
                !empty($description) ? $description : null,
                $category,
                $store_id > 0 ? $store_id : null,
                $quantity,
                $unit_price,
                $min_stock_level,
                !empty($expiry_date) ? $expiry_date : null
            ];
            
            // Use SQL DB for write operations (legacy path)
            $sqlDb = getSQLDB();
            $sqlDb->execute($sql, $params);
            $product_id = $sqlDb->lastInsertId();
            
            // Log stock movement if initial quantity > 0 (SQL)
            if ($quantity > 0) {
                $movement_sql = "
                    INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at) 
                    VALUES (?, ?, 'in', ?, 'Initial stock', 'Product created with initial stock', ?, CURRENT_TIMESTAMP)
                ";
                $sqlDb->execute($movement_sql, [$product_id, $store_id > 0 ? $store_id : null, $quantity, $_SESSION['user_id']]);
            }

            // Sync to Firebase (best-effort, don't block user flow)
            try {
                $firebase = getDB();

                // Prepare product document for Firebase
                $productDoc = [
                    'name' => $name,
                    'sku' => !empty($sku) ? $sku : null,
                    'description' => !empty($description) ? $description : null,
                    'category' => $category,
                    'store_id' => $store_id > 0 ? $store_id : null,
                    'quantity' => $quantity,
                    'price' => $unit_price,
                    'reorder_level' => $min_stock_level,
                    'expiry_date' => !empty($expiry_date) ? $expiry_date : null,
                    'created_at' => date('c'),
                    'updated_at' => date('c')
                ];

                // Use SQL product_id as the Firebase document ID for mapping
                $firebase->create('products', $productDoc, (string)$product_id);

                // Create a stock movement record in Firebase as well
                if ($quantity > 0) {
                    $movementData = [
                        'product_id' => $product_id,
                        'store_id' => $store_id > 0 ? $store_id : null,
                        'movement_type' => 'in',
                        'quantity' => $quantity,
                        'reference' => 'Initial stock',
                        'notes' => 'Product created with initial stock',
                        'user_id' => $_SESSION['user_id'] ?? null,
                        'created_at' => date('c')
                    ];
                    $firebase->create('stock_movements', $movementData);
                }
            } catch (Exception $e) {
                // Log but don't block the user
                error_log('Firebase sync failed when adding product: ' . $e->getMessage());
            }
            
            $success = true;
            $success_message = "Product '{$name}' added successfully!";
            
            // Redirect after successful creation
            header("Location: list.php?success=" . urlencode($success_message));
            exit;
            
        } catch (Exception $e) {
            $msg = $e->getMessage();
            // Detect SQLite UNIQUE constraint violation on products.sku and show a friendly message
            if (stripos($msg, 'unique constraint failed') !== false || stripos($msg, 'UNIQUE constraint failed') !== false || stripos($msg, 'Integrity constraint violation') !== false) {
                if (stripos($msg, 'products.sku') !== false || stripos($msg, 'products."sku"') !== false) {
                    $errors[] = 'SKU already exists';
                } else {
                    $errors[] = 'Database integrity error: ' . $msg;
                }
            } else {
                $errors[] = "Error creating product: " . $msg;
            }
        }
    }
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
    <?php include '../../includes/dashboard_header2.php'; ?>
    <div class="container">

        <main>
            <div class="page-header">
                <h2>Add New Product</h2>
                <div class="page-actions">
                    <a href="list.php" class="btn btn-secondary">← Back to Stock List</a>
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
                                
                                <div class="form-group">
                                    <label for="expiry_date">Expiry Date:</label>
                                    <input type="date" id="expiry_date" name="expiry_date" value="<?php echo htmlspecialchars($_POST['expiry_date'] ?? ''); ?>">
                                    <small>Leave blank if product doesn't expire</small>
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
        // Auto-generate SKU based on product name
        document.getElementById('name').addEventListener('input', function() {
            const name = this.value.trim();
            const skuField = document.getElementById('sku');
            
            if (name && !skuField.value) {
                // Generate simple SKU from first letters of words
                const words = name.split(' ');
                let sku = words.map(word => word.charAt(0).toUpperCase()).join('');
                sku += '-001'; // Add numeric suffix
                skuField.value = sku;
            }
        });
        
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
                alert('Quantity cannot be negative');
                e.preventDefault();
                return false;
            }
            
            if (unitPrice <= 0) {
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
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
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
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
    </style>
</body>
</html>