<?php
/**
 * POS Terminal - PostgreSQL Version
 * Shows only products assigned to the selected store
 */
require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

if (!currentUserHasPermission('can_use_pos')) {
    $_SESSION['error'] = 'You do not have permission to access the POS terminal';
    header('Location: ../../index.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();
$errors = [];
$success_message = '';

// Get store ID from URL parameter or session
$user_store_id = $_GET['store_id'] ?? $_SESSION['pos_store_id'] ?? null;

if (!$user_store_id) {
    die("Error: No store selected. Please select a store from the <a href='stock_pos_integration.php'>POS Integration</a> page.");
}

// Store in session for future requests
$_SESSION['pos_store_id'] = $user_store_id;

// Get store information
$store_info = $sqlDb->fetch("SELECT * FROM stores WHERE id = ?", [$user_store_id]);

if (!$store_info) {
    die("Error: Store not found.");
}

// Check if POS is enabled for this store
if (empty($store_info['has_pos']) || $store_info['has_pos'] != true) {
    $_SESSION['error'] = 'POS is not enabled for this store. Please contact administrator.';
    header('Location: stock_pos_integration.php');
    exit;
}

error_log("=== POS TERMINAL INITIALIZED ===");
error_log("Store ID: $user_store_id");
error_log("Store Name: {$store_info['name']}");
error_log("User ID: " . $_SESSION['user_id']);


// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'verify_payment':
                $type = $_POST['type']; // 'card' or 'ewallet'
                $account_number = $_POST['account_number'];
                $amount = floatval($_POST['amount']);
                
                // Ensure table exists (Lazy init)
                $idType = "SERIAL PRIMARY KEY";
                if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') {
                    $idType = "INTEGER PRIMARY KEY AUTOINCREMENT";
                }
                
                $sqlDb->execute("CREATE TABLE IF NOT EXISTS customer_accounts (
                    id $idType,
                    account_number VARCHAR(50) UNIQUE NOT NULL,
                    account_name VARCHAR(100) NOT NULL,
                    account_type VARCHAR(20) NOT NULL,
                    balance DECIMAL(10, 2) DEFAULT 0.00,
                    pin VARCHAR(255),
                    email VARCHAR(100),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
                
                // Check/Insert demo data
                $check = $sqlDb->fetch("SELECT COUNT(*) as count FROM customer_accounts");
                $count = is_array($check) ? $check['count'] : $check;
                
                if ($count == 0) {
                    $sqlDb->execute("INSERT INTO customer_accounts (account_number, account_name, account_type, balance, email) 
                               VALUES ('4000123456789010', 'John Doe Bank', 'bank', 5000.00, 'demo@example.com')");
                    
                    $pin = password_hash('123456', PASSWORD_DEFAULT);
                    $sqlDb->execute("INSERT INTO customer_accounts (account_number, account_name, account_type, balance, pin) 
                               VALUES ('0123456789', 'Jane Doe Wallet', 'ewallet', 500.00, ?)", [$pin]);
                }
                
                // Check if account exists
                $account = $sqlDb->fetch("SELECT * FROM customer_accounts WHERE account_number = ?", [$account_number]);
                
                if (!$account) {
                    echo json_encode(['success' => false, 'message' => 'Account not found']);
                    exit;
                }
                
                if ($account['balance'] < $amount) {
                    echo json_encode(['success' => false, 'message' => 'Insufficient balance. Available: RM ' . number_format($account['balance'], 2)]);
                    exit;
                }
                
                if ($type === 'ewallet') {
                    $pin = $_POST['pin'];
                    if (!password_verify($pin, $account['pin'])) {
                        echo json_encode(['success' => false, 'message' => 'Invalid PIN']);
                        exit;
                    }
                    echo json_encode(['success' => true, 'message' => 'Verified', 'balance_after' => $account['balance'] - $amount]);
                } elseif ($type === 'card') {
                    // Generate OTP
                    $otp = rand(100000, 999999);
                    $_SESSION['payment_otp'] = $otp;
                    $_SESSION['payment_account'] = $account_number;
                    
                    // Send email
                    $email_sent = false;
                    $email_error = '';
                    
                    if (!empty($account['email'])) {
                        if (file_exists('../../email_helper.php')) {
                            require_once '../../email_helper.php';
                            try {
                                $subject = "Payment OTP Verification";
                                $body = "
                                    <h2>Payment Verification</h2>
                                    <p>You are attempting to make a payment of <strong>RM " . number_format($amount, 2) . "</strong>.</p>
                                    <p>Your OTP code is:</p>
                                    <h1 style='color: #3498db; letter-spacing: 5px;'>$otp</h1>
                                    <p>If you did not request this, please contact your bank immediately.</p>
                                ";
                                
                                if (sendEmail($account['email'], $subject, $body)) {
                                    $email_sent = true;
                                } else {
                                    $email_error = "Failed to send email.";
                                }
                            } catch (Exception $e) {
                                $email_error = $e->getMessage();
                            }
                        } else {
                            $email_error = "Email system not available.";
                        }
                    } else {
                        $email_error = "No email linked to this account.";
                    }
                    
                    if ($email_sent) {
                        echo json_encode([
                            'success' => true, 
                            'require_otp' => true, 
                            'message' => 'OTP sent to ' . $account['email']
                        ]);
                    } else {
                        // Fallback for demo if email fails (or just show error)
                        echo json_encode([
                            'success' => true, // Still success to allow demo to proceed if email fails
                            'require_otp' => true,
                            'message' => 'OTP sent to ' . $account['email'] . ' (Simulated: Email failed)',
                            'debug_otp' => $otp // Show OTP if email fails so user isn't stuck
                        ]);
                    }
                }
                exit;

            case 'verify_otp':
                $otp = $_POST['otp'];
                if (isset($_SESSION['payment_otp']) && $_SESSION['payment_otp'] == $otp) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
                }
                exit;

            case 'load_products':
                error_log("=== LOADING PRODUCTS FOR STORE $user_store_id ===");
                
                // Load products assigned to THIS store only
                // Removed quantity > 0 check to allow seeing out-of-stock items
                // Removed active = true check to allow seeing all assigned items (user can manage status in backend)
                $sql = "SELECT id, name, sku, barcode, category, price, selling_price, quantity, store_id, active
                        FROM products 
                        WHERE store_id = ?
                        AND deleted_at IS NULL
                        ORDER BY name 
                        LIMIT 500";
                
                $productDocs = $sqlDb->fetchAll($sql, [$user_store_id]);
                
                error_log("Found " . count($productDocs) . " products for store $user_store_id");
                
                $products = [];
                foreach ($productDocs as $p) {
                    $price = isset($p['selling_price']) && $p['selling_price'] > 0 
                           ? floatval($p['selling_price']) 
                           : floatval($p['price'] ?? 0);
                    
                    if ($price <= 0) {
                        continue; // Skip products with 0 price
                    }
                    
                    $products[] = [
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'sku' => $p['sku'] ?? '',
                        'price' => $price,
                        'quantity' => intval($p['quantity'] ?? 0),
                        'category' => $p['category'] ?? 'Uncategorized',
                        'barcode' => $p['barcode'] ?? '',
                        'store_id' => $p['store_id'],
                        'active' => (bool)($p['active'] ?? false)
                    ];
                }
                
                echo json_encode([
                    'success' => true, 
                    'products' => $products,
                    'debug' => [
                        'store_id' => $user_store_id,
                        'count' => count($products),
                        'sql' => $sql
                    ]
                ]);
                exit;
                
            case 'process_sale':
                error_log("============================================");
                error_log("=== PROCESS SALE STARTED ===");
                error_log("============================================");
                
                $items = json_decode($_POST['items'] ?? '[]', true);
                $customer_name = trim($_POST['customer_name'] ?? 'Walk-in Customer');
                $payment_method = $_POST['payment_method'] ?? 'cash';
                $amount_paid = floatval($_POST['amount_paid'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                
                error_log("Items count: " . count($items));
                error_log("Customer: $customer_name");
                error_log("Payment method: $payment_method");
                error_log("Amount paid: $amount_paid");
                error_log("Items JSON: " . json_encode($items));
                
                if (empty($items)) {
                    error_log("✗ ERROR: No items in cart");
                    throw new Exception('No items in cart');
                }
                
                // Calculate totals
                $subtotal = 0;
                $productUpdates = [];
                
                // Validate all items first - check SQL database first for accuracy
                $sqlDb = getSQLDB();
                error_log("SQL Database instance: " . ($sqlDb ? "OK" : "NULL"));
                
                foreach ($items as $item) {
                    $product_id = $item['product_id'] ?? '';
                    $quantity = intval($item['quantity'] ?? 0);
                    $price = floatval($item['price'] ?? 0);
                    
                    error_log("Processing item - Product ID: $product_id, Qty: $quantity, Price: $price");
                    
                    if (empty($product_id) || $quantity <= 0) {
                        throw new Exception('Invalid item in cart');
                    }
                    
                    // Try SQL first for most accurate stock levels
                    $product = null;
                    if ($sqlDb) {
                        try {
                            $sql = "SELECT * FROM products WHERE id = ?";
                            $product = $sqlDb->fetchAll($sql, [$product_id]);
                            $product = !empty($product) ? $product[0] : null;
                        } catch (Exception $e) {
                            error_log("SQL product fetch failed: " . $e->getMessage());
                        }
                    }
                    
                    // Fallback to Firebase
                    if (!$product) {
                        try {
                            $product = $db->read('products', $product_id);
                        } catch (Exception $e) {
                            throw new Exception("Product not found: {$product_id}");
                        }
                    }
                    
                    if (!$product) {
                        throw new Exception("Product not found: {$product_id}");
                    }
                    
                    $current_qty = intval($product['quantity'] ?? 0);
                    if ($current_qty < $quantity) {
                        throw new Exception("Insufficient stock for: {$product['name']}. Available: {$current_qty}");
                    }
                    
                    $subtotal += $quantity * $price;
                    
                    $productUpdates[] = [
                        'id' => $product_id,
                        'name' => $product['name'],
                        'old_qty' => $current_qty,
                        'sold_qty' => $quantity,
                        'new_qty' => $current_qty - $quantity,
                        'price' => $price,
                        'reorder_level' => intval($product['reorder_level'] ?? $product['min_stock_level'] ?? 0)
                    ];
                }
                
                $tax = 0; // Tax removed
                $total = $subtotal + $tax;
                $change = $amount_paid - $total;
                
                if ($amount_paid < $total) {
                    throw new Exception('Insufficient payment. Total: RM ' . number_format($total, 2));
                }
                
                // Deduct from customer account if applicable
                if ($payment_method === 'card' || $payment_method === 'ewallet') {
                    $account_number = $_POST['account_number'] ?? '';
                    
                    if (!empty($account_number)) {
                        // Verify again (security)
                        $account = $sqlDb->fetch("SELECT * FROM customer_accounts WHERE account_number = ?", [$account_number]);
                        if ($account) {
                            if ($account['balance'] < $total) {
                                throw new Exception('Payment failed: Insufficient funds in account');
                            }
                            // Deduct
                            $sqlDb->execute("UPDATE customer_accounts SET balance = balance - ? WHERE id = ?", [$total, $account['id']]);
                        }
                    }
                }
                
                // Create sale record
                $sale_id = uniqid('SALE_', true);
                $sale_number = 'POS-' . date('Ymd') . '-' . substr($sale_id, -8);
                
                $saleData = [
                    'sale_number' => $sale_number,
                    'store_id' => $user_store_id ?? 'main',
                    'user_id' => $_SESSION['user_id'],
                    'cashier_name' => $_SESSION['username'] ?? $_SESSION['email'] ?? 'Unknown',
                    'customer_name' => $customer_name,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total_amount' => $total,
                    'payment_method' => $payment_method,
                    'amount_paid' => $amount_paid,
                    'change' => $change,
                    'items_count' => count($items),
                    'notes' => $notes,
                    'status' => 'completed',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'sale_date' => date('Y-m-d'),
                    'items' => json_encode($items) // JSON string for SQL
                ];
                
                // DUAL-SAVE: Save to SQL first (primary database)
                error_log("=== SAVING SALE TO DATABASE ===");
                if ($sqlDb) {
                    error_log("SQL Database available: YES");
                    try {
                        // FIX: Determine actual store ID (not 'main')
                        $actual_store_id = null;
                        error_log("Determining store ID...");
                        error_log("user_store_id from session: " . ($user_store_id ?? 'NULL'));
                        
                        // Try to get from user session
                        if ($user_store_id && is_numeric($user_store_id)) {
                            $actual_store_id = intval($user_store_id);
                        }
                        
                        // If still no store, try to detect from products sold (check first product's store_id)
                        if (!$actual_store_id && !empty($productUpdates)) {
                            foreach ($productUpdates as $update) {
                                // Check SQL for product's store_id
                                $productCheck = $sqlDb->fetch("SELECT store_id FROM products WHERE id = ? LIMIT 1", [$update['id']]);
                                if ($productCheck && !empty($productCheck['store_id']) && is_numeric($productCheck['store_id'])) {
                                    $actual_store_id = intval($productCheck['store_id']);
                                    error_log("Detected store ID from product: $actual_store_id");
                                    break;
                                }
                            }
                        }
                        
                        // If still no store, use the first POS-enabled store as fallback
                        if (!$actual_store_id) {
                            $firstPosStore = $sqlDb->fetch("SELECT id FROM stores WHERE pos_enabled = true ORDER BY id ASC LIMIT 1");
                            if ($firstPosStore) {
                                $actual_store_id = intval($firstPosStore['id']);
                                error_log("Using first POS store as fallback: $actual_store_id");
                            }
                        }
                        
                        // Last resort: use store ID 1
                        if (!$actual_store_id) {
                            $actual_store_id = 1;
                            error_log("WARNING: No valid store found, using ID 1");
                        }
                        
                        error_log("Final store ID for sale: $actual_store_id");
                        error_log("Sale number: $sale_number");
                        error_log("User ID from session: " . ($_SESSION['user_id'] ?? 'NULL'));
                        
                        // FIX: Handle Firebase user ID (string) vs PostgreSQL user ID (integer)
                        $user_id_for_sale = null;
                        if (isset($_SESSION['user_id'])) {
                            // Check if it's a Firebase ID (20+ char alphanumeric string)
                            if (is_string($_SESSION['user_id']) && preg_match('/^[a-zA-Z0-9]{20,}$/', $_SESSION['user_id'])) {
                                error_log("⚠️ Firebase user ID detected: {$_SESSION['user_id']} - setting user_id to NULL");
                                $user_id_for_sale = null; // Will be NULL in database
                            } else {
                                // It's a valid integer or can be converted
                                $user_id_for_sale = intval($_SESSION['user_id']);
                                error_log("✓ Using integer user ID: $user_id_for_sale");
                            }
                        }
                        
                        error_log("Attempting to insert sale record...");
                        
                        // Get payment details from request
                        $amount_paid = $_POST['amount_paid'] ?? $total;
                        $payment_details_json = $_POST['payment_details'] ?? '{}';
                        $payment_details = json_decode($payment_details_json, true);
                        
                        // Extract cashier info
                        $cashier_id = $_SESSION['user_id'];
                        $cashier_name = $_SESSION['username'] ?? $_SESSION['email'] ?? 'Unknown';
                        
                        // Prepare payment fields
                        $payment_change = 0;
                        $payment_reference = null;
                        
                        if ($payment_method === 'cash' && isset($payment_details['change'])) {
                            $payment_change = $payment_details['change'];
                        } elseif ($payment_method === 'card' && isset($payment_details['cardLast4'])) {
                            $payment_reference = $payment_details['cardType'] . ' ****' . $payment_details['cardLast4'];
                        } elseif ($payment_method === 'ewallet' && isset($payment_details['provider'])) {
                            $payment_reference = $payment_details['provider'] . ' - ' . ($payment_details['reference'] ?? 'N/A');
                        }
                        
                        // Insert sale record with payment details
                        $sqlDb->execute(
                            "INSERT INTO sales (sale_number, store_id, user_id, customer_name, 
                                               subtotal, tax_amount, total_amount, payment_method, 
                                               payment_status, notes, amount_paid, payment_change, 
                                               payment_reference, payment_details, cashier_id, cashier_name,
                                               created_at, updated_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $sale_number,
                                $actual_store_id,
                                $user_id_for_sale,
                                $customer_name,
                                $subtotal,
                                $tax,
                                $total,
                                $payment_method,
                                'completed',
                                $notes,
                                $amount_paid,
                                $payment_change,
                                $payment_reference,
                                $payment_details_json,
                                $cashier_id,
                                $cashier_name,
                                date('Y-m-d H:i:s'),
                                date('Y-m-d H:i:s')
                            ]
                        );
                        
                        error_log("✓ Sale INSERT executed successfully");
                        
                        // Get the inserted sale ID
                        $sale_id_sql = $sqlDb->fetch("SELECT id FROM sales WHERE sale_number = ?", [$sale_number]);
                        $sale_id_db = $sale_id_sql ? $sale_id_sql['id'] : null;
                        
                        error_log("Sale ID retrieved: " . ($sale_id_db ?? 'NULL'));
                        
                        // Insert individual sale items if sale was created successfully
                        if ($sale_id_db) {
                            error_log("📦 INSERTING SALE ITEMS: " . count($items) . " items for sale ID $sale_id_db");
                            $item_insert_success = 0;
                            $item_insert_fail = 0;
                            
                            foreach ($items as $index => $item) {
                                try {
                                    error_log("Item #$index: {$item['name']} - Product ID: {$item['product_id']}, Qty: {$item['quantity']}, Price: {$item['price']}");
                                    
                                    $sqlDb->execute(
                                        "INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal, created_at) 
                                         VALUES (?, ?, ?, ?, ?, ?)",
                                        [
                                            $sale_id_db,
                                            $item['product_id'],
                                            $item['quantity'],
                                            $item['price'],
                                            $item['quantity'] * $item['price'],
                                            date('Y-m-d H:i:s')
                                        ]
                                    );
                                    $item_insert_success++;
                                    error_log("✓ Item #$index saved successfully");
                                } catch (Exception $e) {
                                    $item_insert_fail++;
                                    error_log("✗ Item #$index FAILED: " . $e->getMessage());
                                }
                            }
                            error_log("✓ Sale items result: $item_insert_success success, $item_insert_fail failed");
                        } else {
                            error_log("✗ WARNING: Could not retrieve sale ID after insert");
                        }
                        
                        error_log("✓ Sale saved to SQL: $sale_number (Store ID: $actual_store_id)");
                    } catch (Exception $e) {
                        $errorMsg = "✗ SQL sale save failed: " . $e->getMessage();
                        error_log($errorMsg);
                        error_log("Stack trace: " . $e->getTraceAsString());
                        // Store error to return to frontend
                        $sqlError = $errorMsg;
                        // Don't throw - continue to Firebase
                    }
                } else {
                    error_log("✗ SQL Database not available!");
                    $sqlError = "SQL Database not available";
                }
                
                // DUAL-SAVE: Also save to Firebase (secondary/sync database)
                $firebaseSaleData = [
                    'sale_number' => $sale_number,
                    'store_id' => isset($actual_store_id) ? strval($actual_store_id) : ($user_store_id ?? 'main'),
                    'user_id' => $_SESSION['user_id'],
                    'cashier_name' => $_SESSION['username'] ?? $_SESSION['email'] ?? 'Unknown',
                    'customer_name' => $customer_name,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'payment_method' => $payment_method,
                    'amount_paid' => $amount_paid,
                    'change' => $change,
                    'items_count' => count($items),
                    'notes' => $notes,
                    'status' => 'completed',
                    'created_at' => date('c'),
                    'sale_date' => date('Y-m-d'),
                    'items' => $items
                ];
                
                $saleResult = $db->create('sales', $firebaseSaleData, $sale_id);
                
                if (!$saleResult) {
                    error_log("✗ Failed to create Firebase sale record");
                    // Don't throw if SQL succeeded
                }
                
                // Update product quantities in both databases
                foreach ($productUpdates as $update) {
                    // Update SQL database first
                    if ($sqlDb) {
                        try {
                            $updateSql = "UPDATE products SET quantity = ?, updated_at = ? WHERE id = ?";
                            $sqlDb->execute($updateSql, [$update['new_qty'], date('Y-m-d H:i:s'), $update['id']]);
                            error_log("SQL: Deducted {$update['sold_qty']} from product {$update['id']}");
                            
                            // Record stock movement in PostgreSQL
                            try {
                                $sqlDb->execute(
                                    "INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, 
                                                                  reference, notes, user_id, created_at) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                                    [
                                        $update['id'],
                                        $actual_store_id ?? $user_store_id,
                                        'sale',
                                        -$update['sold_qty'],  // Negative for deduction
                                        $sale_number,
                                        "POS Sale - {$update['name']} x{$update['sold_qty']} @ RM{$update['price']} (Stock: {$update['old_qty']} → {$update['new_qty']})",
                                        $user_id_for_sale,  // Use sanitized user ID
                                        date('Y-m-d H:i:s')
                                    ]
                                );
                                error_log("✓ Stock movement recorded for product {$update['id']}");
                            } catch (Exception $e) {
                                error_log("✗ Stock movement logging failed: " . $e->getMessage());
                            }
                            
                            // CASCADING UPDATE: If this is a store variant, update main product total
                            $productInfo = $sqlDb->fetch("SELECT sku, store_id FROM products WHERE id = ?", [$update['id']]);
                            if ($productInfo && !empty($productInfo['store_id'])) {
                                // This is a variant, get base SKU
                                $variantSku = $productInfo['sku'];
                                // Extract base SKU (remove -S# suffix)
                                if (preg_match('/^(.+)-S\d+$/', $variantSku, $matches)) {
                                    $baseSku = $matches[1];
                                    
                                    // Calculate new main product quantity (sum of all variants)
                                    $totalVariants = $sqlDb->fetch(
                                        "SELECT SUM(quantity) as total FROM products WHERE sku LIKE ? AND store_id IS NOT NULL",
                                        [$baseSku . '-%']
                                    );
                                    
                                    $newMainQty = intval($totalVariants['total'] ?? 0);
                                    
                                    // Update main product
                                    $sqlDb->execute(
                                        "UPDATE products SET quantity = ?, updated_at = ? WHERE sku = ? AND store_id IS NULL",
                                        [$newMainQty, date('Y-m-d H:i:s'), $baseSku]
                                    );
                                    
                                    error_log("Cascading: Updated main product $baseSku to quantity $newMainQty");
                                }
                            }
                        } catch (Exception $e) {
                            error_log("SQL stock update failed: " . $e->getMessage());
                        }
                    }
                    
                    // Update Firebase
                    try {
                        $updateResult = $db->update('products', $update['id'], [
                            'quantity' => $update['new_qty'],
                            'updated_at' => date('c')
                        ]);
                        
                        if (!$updateResult) {
                            error_log("Firebase: Failed to update stock for product: {$update['id']}");
                        }
                    } catch (Exception $e) {
                        error_log("Firebase stock update failed: " . $e->getMessage());
                    }
                    
                    // Log stock audit
                    try {
                        log_stock_audit([
                            'action' => 'pos_sale',
                            'product_id' => $update['id'],
                            'product_name' => $update['name'],
                            'store_id' => $user_store_id ?? 'main',
                            'before' => ['quantity' => $update['old_qty']],
                            'after' => ['quantity' => $update['new_qty']],
                            'reference' => $sale_number,
                            'user_id' => $_SESSION['user_id'],
                            'username' => $_SESSION['username'] ?? $_SESSION['email'] ?? 'Unknown',
                            'changed_by' => $_SESSION['user_id'],
                            'changed_name' => $_SESSION['username'] ?? $_SESSION['email'] ?? 'Unknown'
                        ]);
                    } catch (Exception $e) {
                        error_log("Stock audit failed: " . $e->getMessage());
                    }
                    
                    // Check if low stock alert needed
                    if ($update['reorder_level'] > 0 && $update['new_qty'] <= $update['reorder_level']) {
                        try {
                            $alert_id = 'LOW_' . $update['id'];
                            $alertData = [
                                'product_id' => $update['id'],
                                'product_name' => $update['name'],
                                'alert_type' => 'LOW_STOCK',
                                'status' => 'PENDING',
                                'current_quantity' => $update['new_qty'],
                                'reorder_level' => $update['reorder_level'],
                                'created_at' => date('c'),
                                'updated_at' => date('c')
                            ];
                            
                            // Try to create alert, if exists then update
                            try {
                                $db->create('alerts', $alertData, $alert_id);
                            } catch (Exception $e) {
                                // Alert might already exist, try update
                                $db->update('alerts', $alert_id, $alertData);
                            }
                        } catch (Exception $e) {
                            error_log("Low stock alert failed: " . $e->getMessage());
                        }
                    }
                }
                
                // Clear caches to force refresh
                $cacheFiles = [
                    __DIR__ . '/../../storage/cache/pos_products.cache',
                    __DIR__ . '/../../storage/cache/stock_list_data.cache'
                ];
                
                foreach ($cacheFiles as $cacheFile) {
                    if (file_exists($cacheFile)) {
                        @unlink($cacheFile);
                    }
                }
                
                $response = [
                    'success' => true,
                    'sale_number' => $sale_number,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'change' => $change,
                    'items_sold' => count($items)
                ];
                
                // Add SQL error if there was one
                if (isset($sqlError)) {
                    $response['sql_error'] = $sqlError;
                    $response['warning'] = 'Sale saved to Firebase but SQL save failed';
                }
                
                echo json_encode($response);
                exit;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$page_title = 'POS Terminal - Inventory System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .pos-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            height: 100vh;
            gap: 0;
        }
        
        .products-panel {
            background: white;
            overflow-y: auto;
            padding: 20px;
        }
        
        .cart-panel {
            background: #2c3e50;
            color: white;
            display: flex;
            flex-direction: column;
            border-left: 3px solid #34495e;
        }
        
        .pos-header {
            background: #34495e;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .pos-header h1 {
            margin: 0;
            font-size: 24px;
            color: white;
        }
        
        .store-info {
            font-size: 14px;
            color: #ecf0f1;
        }
        
        .search-bar {
            margin: 20px 0;
            position: relative;
        }
        
        .search-bar input {
            width: 100%;
            padding: 15px 50px 15px 20px;
            font-size: 16px;
            border: 2px solid #ddd;
            border-radius: 8px;
            outline: none;
        }
        
        .search-bar input:focus {
            border-color: #3498db;
        }
        
        .search-icon {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: #95a5a6;
            font-size: 20px;
        }
        
        .category-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .category-tab {
            padding: 10px 20px;
            background: #ecf0f1;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .category-tab.active {
            background: #3498db;
            color: white;
        }
        
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
        }
        
        .product-card {
            background: white;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
            min-height: 140px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-color: #3498db;
        }
        
        .product-card:active {
            transform: scale(0.98);
            border-color: #2980b9;
        }
        
        .product-card.out-of-stock {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .product-name {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 5px;
            min-height: 40px;
        }
        
        .product-price {
            color: #27ae60;
            font-size: 16px;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .product-stock {
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .cart-header {
            padding: 20px;
            background: #34495e;
        }
        
        .cart-header h2 {
            margin: 0 0 10px 0;
            font-size: 20px;
        }
        
        .customer-input {
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 5px;
            margin-top: 10px;
        }
        
        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }
        
        .cart-item {
            background: #34495e;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 10px;
            position: relative;
        }
        
        .cart-item-name {
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .cart-item-details {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }
        
        .qty-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .qty-btn {
            background: #3498db;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .qty-btn:hover {
            background: #2980b9;
        }
        
        .qty-btn:active {
            transform: scale(0.95);
        }
        
        .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #e74c3c;
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .remove-btn:active {
            transform: scale(0.9);
        }
        
        .cart-summary {
            padding: 20px;
            background: #34495e;
            border-top: 2px solid #2c3e50;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .summary-row.total {
            font-size: 24px;
            font-weight: bold;
            padding-top: 10px;
            border-top: 2px solid #7f8c8d;
            color: #2ecc71;
        }
        
        .payment-section {
            margin-top: 15px;
        }
        
        .payment-methods {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .payment-method {
            padding: 10px;
            background: #2c3e50;
            border: 2px solid #7f8c8d;
            border-radius: 5px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s;
        }
        
        .payment-method.active {
            background: #3498db;
            border-color: #3498db;
        }
        
        .checkout-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
        }
        
        .checkout-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #2980b9 0%, #21618c 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4);
        }
        
        .checkout-btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            box-shadow: none;
        }
        
        .checkout-btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }
        
        .clear-cart-btn {
            width: 100%;
            padding: 12px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 10px;
            transition: all 0.3s;
        }
        
        .clear-cart-btn:hover {
            background: #c0392b;
            transform: translateY(-1px);
        }
        
        .empty-cart {
            text-align: center;
            padding: 40px 20px;
            color: #95a5a6;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #95a5a6;
        }
        
        .back-btn {
            background: #95a5a6;
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
            display: inline-block;
        }
        
        .back-btn:hover {
            background: #7f8c8d;
        }
        
        .add-products-btn {
            background: #3498db;
            color: white;
            padding: 12px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 16px;
            display: inline-block;
            border: 2px solid transparent;
        }
        
        .add-products-btn:hover {
            background: #2980b9;
            border-color: #fff;
        }
        
        .no-products-message {
            text-align: center;
            padding: 60px 20px;
            color: #7f8c8d;
        }
        
        .no-products-message i {
            font-size: 64px;
            color: #bdc3c7;
            margin-bottom: 20px;
            display: block;
        }
        
        .no-products-message h3 {
            color: #7f8c8d;
            margin-bottom: 10px;
        }
        
        .no-products-message p {
            color: #95a5a6;
            margin-bottom: 20px;
        }
        
        .no-products-message a {
            background: #3498db;
            color: white;
            padding: 15px 30px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            font-size: 18px;
        }
        
        .no-products-message a:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
    </style>
</head>
<body>
    <div class="pos-container">
        <!-- Products Panel -->
        <div class="products-panel">
            <div class="pos-header">
                <div>
                    <h1>🛒 POS Terminal</h1>
                    <?php if ($store_info): ?>
                        <div class="store-info">
                            📍 <?php echo htmlspecialchars($store_info['name']); ?>
                            <?php if (!empty($store_info['pos_terminal_id'])): ?>
                                | Terminal: <?php echo htmlspecialchars($store_info['pos_terminal_id']); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <a href="my_payments.php" class="add-products-btn" style="background: #9b59b6;" title="View your payment history and sales data">
                        <i class="fas fa-receipt"></i> My Payments
                    </a>
                    <a href="stock_pos_integration.php" class="add-products-btn" title="Go back to POS Stock Management">
                        <i class="fas fa-arrow-left"></i> POS Stock Management
                    </a>
                    <a href="../../index.php" class="back-btn"><i class="fas fa-home"></i> Dashboard</a>
                </div>
            </div>
            
            <div class="search-bar">
                <input type="text" id="searchInput" placeholder="Search products by name, SKU, or barcode...">
                <span class="search-icon">🔍</span>
            </div>
            
            <div class="category-tabs" id="categoryTabs">
                <button class="category-tab active" data-category="all">All Products</button>
            </div>
            
            <div class="products-grid" id="productsGrid">
                <div class="loading">Loading products...</div>
            </div>
        </div>
        
        <!-- Cart Panel -->
        <div class="cart-panel">
            <div class="cart-header">
                <h2>🛍️ Current Sale</h2>
                <input type="text" id="customerName" class="customer-input" placeholder="Customer name (optional)">
            </div>
            
            <div class="cart-items" id="cartItems">
                <div class="empty-cart">
                    <h3>Cart is empty</h3>
                    <p>Add products to start a sale</p>
                </div>
            </div>
            
            <div class="cart-summary">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span id="subtotal">RM 0.00</span>
                </div>
                <!-- Tax removed
                <div class="summary-row">
                    <span>Tax (6%):</span>
                    <span id="tax">RM 0.00</span>
                </div>
                -->
                <div class="summary-row total">
                    <span>TOTAL:</span>
                    <span id="total">RM 0.00</span>
                </div>
                
                <div class="payment-section">
                    <div class="payment-methods">
                        <div class="payment-method active" data-method="cash">
                            <i class="fas fa-money-bill-wave"></i> Cash
                        </div>
                        <div class="payment-method" data-method="card">
                            <i class="fas fa-credit-card"></i> Card
                        </div>
                        <div class="payment-method" data-method="ewallet">
                            <i class="fas fa-mobile-alt"></i> E-Wallet
                        </div>
                    </div>
                    
                    <button class="checkout-btn" id="checkoutBtn" disabled>
                        <i class="fas fa-credit-card"></i> Make Payment
                    </button>
                    <button class="clear-cart-btn" id="clearCartBtn">
                        <i class="fas fa-trash-alt"></i> Clear Cart
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // State
        let products = [];
        let cart = [];
        let selectedPaymentMethod = 'cash';
        let currentCategory = 'all';
        
        // Load products from server
        async function loadProducts() {
            try {
                console.log('=== LOADING PRODUCTS ===');
                console.log('Store ID: <?php echo $user_store_id; ?>');
                
                const formData = new FormData();
                formData.append('action', 'load_products');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                console.log('Products loaded:', data);
                
                if (data.debug) {
                    console.log('=== DEBUG INFO ===');
                    console.log('Store ID:', data.debug.store_id);
                    console.log('Products count:', data.debug.count);
                    console.log('SQL:', data.debug.sql);
                }
                
                if (data.success) {
                    products = data.products;
                    console.log('Total products:', products.length);
                    if (products.length > 0) {
                        console.log('First product:', products[0]);
                        console.log('Sample store_ids:', products.slice(0, 5).map(p => p.store_id));
                    }
                    renderCategories();
                    renderProducts();
                } else {
                    alert('Failed to load products: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error loading products:', error);
                alert('Failed to load products');
            }
        }
        
        // Render category tabs
        function renderCategories() {
            const categories = [...new Set(products.map(p => p.category))];
            const tabsHtml = '<button class="category-tab active" data-category="all">All Products</button>' +
                categories.map(cat => `<button class="category-tab" data-category="${cat}">${cat}</button>`).join('');
            
            document.getElementById('categoryTabs').innerHTML = tabsHtml;
            
            // Add click handlers
            document.querySelectorAll('.category-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    currentCategory = tab.dataset.category;
                    renderProducts();
                });
            });
        }
        
        // Render products grid
        function renderProducts(searchQuery = '') {
            let filteredProducts = products;
            
            // Filter by category
            if (currentCategory !== 'all') {
                filteredProducts = filteredProducts.filter(p => p.category === currentCategory);
            }
            
            // Filter by search
            if (searchQuery) {
                const query = searchQuery.toLowerCase();
                filteredProducts = filteredProducts.filter(p => 
                    p.name.toLowerCase().includes(query) ||
                    p.sku.toLowerCase().includes(query) ||
                    (p.barcode && p.barcode.toLowerCase().includes(query))
                );
            }
            
            const gridHtml = filteredProducts.length > 0 
                ? filteredProducts.map(product => `
                    <div class="product-card ${product.quantity <= 0 ? 'out-of-stock' : ''}" 
                         onclick="addToCart('${product.id}')" 
                         style="${!product.active ? 'opacity: 0.7; border: 1px dashed #ccc;' : ''}">
                        <div class="product-name">
                            ${escapeHtml(product.name)}
                            ${!product.active ? '<small style="color: #e74c3c;">(Inactive)</small>' : ''}
                        </div>
                        <div class="product-price">RM ${product.price.toFixed(2)}</div>
                        <div class="product-stock" style="${product.quantity <= 0 ? 'color: #e74c3c;' : ''}">
                            Stock: ${product.quantity}
                        </div>
                        ${product.sku ? `<div style="font-size: 11px; color: #95a5a6;">SKU: ${escapeHtml(product.sku)}</div>` : ''}
                    </div>
                `).join('')
                : (products.length === 0 
                    ? `<div class="no-products-message">
                        <i class="fas fa-box-open"></i>
                        <h3>No Products Available</h3>
                        <p>This POS terminal has no products yet.<br>Add products from your inventory to start selling.</p>
                        <a href="stock_pos_integration.php">
                            <i class="fas fa-plus-circle"></i> Add Products from Inventory
                        </a>
                    </div>`
                    : '<div class="loading">No products match your search</div>');
            
            document.getElementById('productsGrid').innerHTML = gridHtml;
        }
        
        // Add product to cart
        function addToCart(productId) {
            console.log('=== ADD TO CART ===');
            console.log('Product ID:', productId, 'Type:', typeof productId);
            console.log('Available products:', products.length);
            
            // Convert to number for comparison (since onclick passes string)
            const numericId = typeof productId === 'string' ? parseInt(productId) : productId;
            console.log('Numeric ID:', numericId);
            
            const product = products.find(p => p.id == productId); // Use == for type coercion
            console.log('Found product:', product);
            
            if (!product) {
                console.error('Product not found!');
                console.log('Available product IDs:', products.map(p => p.id));
                alert('Product not found');
                return;
            }
            
            if (product.quantity <= 0) {
                console.error('No stock available');
                alert('No stock available');
                return;
            }
            
            const existingItem = cart.find(item => item.product_id == productId); // Use == for type coercion
            
            if (existingItem) {
                if (existingItem.quantity < product.quantity) {
                    existingItem.quantity++;
                    console.log('Increased quantity to:', existingItem.quantity);
                } else {
                    alert('Cannot add more than available stock');
                    return;
                }
            } else {
                const newItem = {
                    product_id: numericId,
                    name: product.name,
                    price: product.price,
                    quantity: 1,
                    max_quantity: product.quantity
                };
                cart.push(newItem);
                console.log('Added new item to cart:', newItem);
            }
            
            console.log('Cart now has', cart.length, 'items');
            renderCart();
        }
        
        // Update cart item quantity
        function updateQuantity(productId, delta) {
            // Convert to number if needed for comparison
            const id = typeof productId === 'string' ? parseInt(productId) : productId;
            const item = cart.find(i => i.product_id == id);
            
            if (!item) {
                console.error('Item not found in cart:', id);
                return;
            }
            
            const newQty = item.quantity + delta;
            
            if (newQty <= 0) {
                removeFromCart(id);
            } else if (newQty <= item.max_quantity) {
                item.quantity = newQty;
                renderCart();
            } else {
                alert('Cannot add more than available stock');
            }
        }
        
        // Remove item from cart
        function removeFromCart(productId) {
            const id = typeof productId === 'string' ? parseInt(productId) : productId;
            cart = cart.filter(item => item.product_id != id);
            renderCart();
        }
        
        // Set specific quantity directly
        function setQuantity(productId, value) {
            const id = typeof productId === 'string' ? parseInt(productId) : productId;
            const item = cart.find(i => i.product_id == id);
            
            if (!item) return;
            
            let newQty = parseInt(value);
            
            if (isNaN(newQty) || newQty < 1) {
                newQty = 1;
            }
            
            if (newQty <= item.max_quantity) {
                item.quantity = newQty;
                renderCart();
            } else {
                alert('Cannot add more than available stock (' + item.max_quantity + ')');
                item.quantity = item.max_quantity;
                renderCart();
            }
        }
        
        // Render cart
        function renderCart() {
            const cartContainer = document.getElementById('cartItems');
            
            if (cart.length === 0) {
                cartContainer.innerHTML = `
                    <div class="empty-cart">
                        <h3>Cart is empty</h3>
                        <p>Add products to start a sale</p>
                    </div>
                `;
                document.getElementById('checkoutBtn').disabled = true;
            } else {
                const cartHtml = cart.map(item => `
                    <div class="cart-item">
                        <button class="remove-btn" onclick="removeFromCart('${item.product_id}')">×</button>
                        <div class="cart-item-name">${escapeHtml(item.name)}</div>
                        <div class="cart-item-details">
                            <div class="qty-controls">
                                <button class="qty-btn" onclick="updateQuantity('${item.product_id}', -1)">-</button>
                                <input type="number" value="${item.quantity}" 
                                       onchange="setQuantity('${item.product_id}', this.value)"
                                       onclick="this.select()"
                                       style="width: 60px; text-align: center; border: 1px solid #ddd; border-radius: 4px; padding: 8px; font-size: 16px; -moz-appearance: textfield;">
                                <button class="qty-btn" onclick="updateQuantity('${item.product_id}', 1)">+</button>
                            </div>
                            <div>
                                <div>RM ${item.price.toFixed(2)} each</div>
                                <div style="font-weight: bold;">RM ${(item.price * item.quantity).toFixed(2)}</div>
                            </div>
                        </div>
                    </div>
                `).join('');
                
                cartContainer.innerHTML = cartHtml;
                document.getElementById('checkoutBtn').disabled = false;
            }
            
            updateTotals();
        }
        
        // Update totals
        function updateTotals() {
            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const tax = 0; // Tax removed
            const total = subtotal + tax;
            
            document.getElementById('subtotal').textContent = 'RM ' + subtotal.toFixed(2);
            // document.getElementById('tax').textContent = 'RM ' + tax.toFixed(2);
            document.getElementById('total').textContent = 'RM ' + total.toFixed(2);
        }
        
        // Show payment modal
        let paymentVerified = false;
        let verifiedAccountNumber = '';

        function showPaymentModal() {
            if (cart.length === 0) return;
            
            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const tax = 0; // Tax removed
            const total = subtotal + tax;
            
            paymentVerified = false;
            verifiedAccountNumber = '';
            
            let modalContent = '';
            
            if (selectedPaymentMethod === 'cash') {
                modalContent = `
                    <h3 style="margin-top: 0; color: #2c3e50;"><i class="fas fa-money-bill-wave"></i> Cash Payment</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <div style="font-size: 14px; color: #7f8c8d; margin-bottom: 5px;">Total Amount</div>
                        <div style="font-size: 32px; font-weight: bold; color: #27ae60;">RM ${total.toFixed(2)}</div>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">Amount Received</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="number" id="cashAmount" value="${total.toFixed(2)}" step="0.01" min="${total.toFixed(2)}" 
                                   style="flex: 1; padding: 12px; font-size: 24px; border: 2px solid #e0e0e0; border-radius: 8px; text-align: right;" 
                                   placeholder="0.00" autofocus>
                            <button onclick="document.getElementById('cashAmount').value = ''; document.getElementById('cashAmount').focus();" 
                                    style="padding: 0 15px; background: #e74c3c; color: white; border: none; border-radius: 8px; font-size: 18px;">
                                <i class="fas fa-backspace"></i>
                            </button>
                        </div>
                        
                        <!-- Numpad for Touchscreen -->
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 10px;">
                            <button onclick="appendNumpad('1')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">1</button>
                            <button onclick="appendNumpad('2')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">2</button>
                            <button onclick="appendNumpad('3')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">3</button>
                            <button onclick="appendNumpad('4')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">4</button>
                            <button onclick="appendNumpad('5')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">5</button>
                            <button onclick="appendNumpad('6')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">6</button>
                            <button onclick="appendNumpad('7')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">7</button>
                            <button onclick="appendNumpad('8')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">8</button>
                            <button onclick="appendNumpad('9')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">9</button>
                            <button onclick="appendNumpad('.')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">.</button>
                            <button onclick="appendNumpad('0')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">0</button>
                            <button onclick="appendNumpad('00')" style="padding: 15px; font-size: 20px; background: #fff; border: 1px solid #ddd; border-radius: 6px;">00</button>
                        </div>
                    </div>
                    <div id="changeDisplay" style="background: #e8f5e9; padding: 12px; border-radius: 8px; margin-bottom: 20px; display: none;">
                        <div style="color: #2e7d32; font-weight: 600; font-size: 18px;">Change: <span id="changeAmount">RM 0.00</span></div>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px;">
                        <button onclick="document.getElementById('cashAmount').value = ${(total + 10).toFixed(2)}; calculateChange()" style="padding: 12px; background: #ecf0f1; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">+RM 10</button>
                        <button onclick="document.getElementById('cashAmount').value = ${(total + 20).toFixed(2)}; calculateChange()" style="padding: 12px; background: #ecf0f1; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">+RM 20</button>
                        <button onclick="document.getElementById('cashAmount').value = ${(total + 50).toFixed(2)}; calculateChange()" style="padding: 12px; background: #ecf0f1; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">+RM 50</button>
                    </div>`;
            } else if (selectedPaymentMethod === 'card') {
                modalContent = `
                    <h3 style="margin-top: 0; color: #2c3e50;"><i class="fas fa-credit-card"></i> Card Payment</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <div style="font-size: 14px; color: #7f8c8d; margin-bottom: 5px;">Total Amount</div>
                        <div style="font-size: 32px; font-weight: bold; color: #3498db;">RM ${total.toFixed(2)}</div>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">Card Number (Account #)</label>
                        <input type="text" id="cardNumber" 
                               style="width: 100%; padding: 12px; font-size: 16px; border: 2px solid #e0e0e0; border-radius: 8px;" 
                               placeholder="Enter card number">
                    </div>
                    
                    <div id="otpSection" style="display: none; margin-bottom: 15px; background: #e8f4f8; padding: 10px; border-radius: 8px;">
                        <p style="font-size: 12px; color: #666; margin-bottom: 5px;">OTP sent to email</p>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="otpInput" placeholder="Enter OTP" style="flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                            <button onclick="verifyOtp()" style="padding: 10px 15px; background: #2980b9; color: white; border: none; border-radius: 4px; cursor: pointer;">Verify</button>
                        </div>
                    </div>
                    
                    <button id="sendOtpBtn" onclick="initiateCardPayment(${total})" style="width: 100%; padding: 12px; background: #3498db; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-bottom: 15px;">
                        Send OTP
                    </button>
                    
                    <div id="paymentStatus" style="margin-bottom: 15px; font-weight: bold; text-align: center;"></div>
                    `;
            } else if (selectedPaymentMethod === 'ewallet') {
                modalContent = `
                    <h3 style="margin-top: 0; color: #2c3e50;"><i class="fas fa-mobile-alt"></i> E-Wallet Payment</h3>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <div style="font-size: 14px; color: #7f8c8d; margin-bottom: 5px;">Total Amount</div>
                        <div style="font-size: 32px; font-weight: bold; color: #9b59b6;">RM ${total.toFixed(2)}</div>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">Wallet ID (Account #)</label>
                        <input type="text" id="walletNumber" 
                               style="width: 100%; padding: 12px; font-size: 16px; border: 2px solid #e0e0e0; border-radius: 8px;" 
                               placeholder="Enter wallet ID">
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #2c3e50;">6-Digit PIN</label>
                        <div style="position: relative;">
                            <input type="password" id="walletPin" maxlength="6"
                                   style="width: 100%; padding: 12px; padding-right: 40px; font-size: 16px; border: 2px solid #e0e0e0; border-radius: 8px;" 
                                   placeholder="Enter PIN">
                            <i class="fas fa-eye" id="togglePinBtn" onclick="togglePinVisibility()" 
                               style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #7f8c8d;"></i>
                        </div>
                    </div>
                    
                    <button onclick="verifyWallet(${total})" style="width: 100%; padding: 12px; background: #9b59b6; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-bottom: 15px;">
                        Verify Wallet
                    </button>
                    
                    <div id="paymentStatus" style="margin-bottom: 15px; font-weight: bold; text-align: center;"></div>
                    `;
            }
            
            const modal = document.createElement('div');
            modal.id = 'paymentModal';
            modal.innerHTML = `
                <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 10000;">
                    <div style="background: white; padding: 30px; border-radius: 16px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto;">
                        ${modalContent}
                        <div style="display: flex; gap: 12px; margin-top: 20px;">
                            <button onclick="closePaymentModal()" style="flex: 1; padding: 14px; background: #95a5a6; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer;">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button id="completeSaleBtn" onclick="confirmAndCompleteSale()" style="flex: 2; padding: 14px; background: #27ae60; color: white; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer;" ${selectedPaymentMethod !== 'cash' ? 'disabled' : ''}>
                                <i class="fas fa-check-circle"></i> Complete Sale
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Setup cash amount change calculator
            if (selectedPaymentMethod === 'cash') {
                document.getElementById('cashAmount').addEventListener('input', calculateChange);
                calculateChange();
            }
        }
        
        function initiateCardPayment(amount) {
            const accountNum = document.getElementById('cardNumber').value;
            if (!accountNum) {
                alert('Please enter card number');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'verify_payment');
            formData.append('type', 'card');
            formData.append('account_number', accountNum);
            formData.append('amount', amount);
            
            fetch('', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('otpSection').style.display = 'block';
                    document.getElementById('sendOtpBtn').style.display = 'none';
                    document.getElementById('paymentStatus').textContent = data.message;
                    document.getElementById('paymentStatus').style.color = 'blue';
                    if (data.debug_otp) {
                        // Only show alert if email failed and we fell back to debug mode
                        alert('Email failed. Demo OTP: ' + data.debug_otp);
                    } else {
                        alert('OTP has been sent to your email.');
                    }
                } else {
                    alert(data.message);
                }
            });
        }

        function verifyOtp() {
            const otp = document.getElementById('otpInput').value;
            
            const formData = new FormData();
            formData.append('action', 'verify_otp');
            formData.append('otp', otp);
            
            fetch('', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    paymentVerified = true;
                    verifiedAccountNumber = document.getElementById('cardNumber').value;
                    document.getElementById('paymentStatus').textContent = 'Payment Verified';
                    document.getElementById('paymentStatus').style.color = 'green';
                    document.getElementById('completeSaleBtn').disabled = false;
                    document.getElementById('otpSection').style.display = 'none';
                } else {
                    alert(data.message);
                }
            });
        }

        function verifyWallet(amount) {
            const accountNum = document.getElementById('walletNumber').value;
            const pin = document.getElementById('walletPin').value;
            
            if (!accountNum || !pin) {
                alert('Please enter wallet ID and PIN');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'verify_payment');
            formData.append('type', 'ewallet');
            formData.append('account_number', accountNum);
            formData.append('pin', pin);
            formData.append('amount', amount);
            
            fetch('', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    paymentVerified = true;
                    verifiedAccountNumber = accountNum;
                    document.getElementById('paymentStatus').textContent = 'Wallet Verified. Balance after: RM ' + data.balance_after.toFixed(2);
                    document.getElementById('paymentStatus').style.color = 'green';
                    document.getElementById('completeSaleBtn').disabled = false;
                } else {
                    alert(data.message);
                }
            });
        }
        
        function togglePinVisibility() {
            const pinInput = document.getElementById('walletPin');
            const icon = document.getElementById('togglePinBtn');
            
            if (pinInput.type === 'password') {
                pinInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
           
            } else {
                pinInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function calculateChange() {
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0); // Tax removed
            const amountPaid = parseFloat(document.getElementById('cashAmount').value) || 0;
            const change = amountPaid - total;
            
            const changeDisplay = document.getElementById('changeDisplay');
            if (change >= 0) {
                changeDisplay.style.display = 'block';
                document.getElementById('changeAmount').textContent = 'RM ' + change.toFixed(2);
            } else {
                changeDisplay.style.display = 'none';
            }
        }
        
        function closePaymentModal() {
            const modal = document.getElementById('paymentModal');
            if (modal) modal.remove();
        }
        
        // Confirm and complete sale with validation
        async function confirmAndCompleteSale() {
            console.log('=== PAYMENT VALIDATION & SALE COMPLETION ===');
            
            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const tax = 0; // Tax removed
            const total = subtotal + tax;
            
            let amountPaid = total;
            let paymentDetails = {};
            let validationErrors = [];
            
            // Validate and collect payment details based on method
            if (selectedPaymentMethod === 'cash') {
                const cashInput = document.getElementById('cashAmount').value;
                amountPaid = parseFloat(cashInput);
                
                if (!cashInput || cashInput.trim() === '') {
                    validationErrors.push('Please enter the amount received');
                } else if (isNaN(amountPaid)) {
                    validationErrors.push('Invalid amount entered');
                } else if (amountPaid < total) {
                    validationErrors.push(`Insufficient payment. Need RM ${(total - amountPaid).toFixed(2)} more`);
                }
                
                if (validationErrors.length === 0) {
                    paymentDetails = {
                        amountReceived: amountPaid.toFixed(2),
                        change: (amountPaid - total).toFixed(2),
                        method: 'Cash Payment'
                    };
                }
            } else if (selectedPaymentMethod === 'card') {
                if (!paymentVerified) {
                    validationErrors.push('Please verify payment first');
                }
                
                if (validationErrors.length === 0) {
                    paymentDetails = {
                        cardLast4: verifiedAccountNumber.slice(-4),
                        cardType: 'CARD',
                        approvalCode: 'VERIFIED',
                        method: `Card Payment`
                    };
                    // For card, amount paid is exact
                    amountPaid = total;
                }
            } else if (selectedPaymentMethod === 'ewallet') {
                if (!paymentVerified) {
                    validationErrors.push('Please verify payment first');
                }
                
                if (validationErrors.length === 0) {
                    paymentDetails = {
                        provider: 'E-Wallet',
                        reference: 'VERIFIED',
                        method: `E-Wallet Payment`
                    };
                    // For e-wallet, amount paid is exact
                    amountPaid = total;
                }
            }
            
            // Show validation errors if any
            if (validationErrors.length > 0) {
                alert('Please fix the following:\n\n• ' + validationErrors.join('\n• '));
                return;
            }
            
            // Show confirmation summary
            let confirmMessage = '=== SALE CONFIRMATION ===\n\n';
            confirmMessage += `Items: ${cart.length}\n`;
            confirmMessage += `Subtotal: RM ${subtotal.toFixed(2)}\n`;
            // confirmMessage += `Tax (6%): RM ${tax.toFixed(2)}\n`;
            confirmMessage += `Total: RM ${total.toFixed(2)}\n\n`;
            confirmMessage += `Payment: ${paymentDetails.method}\n`;
            
            if (selectedPaymentMethod === 'cash') {
                confirmMessage += `Received: RM ${paymentDetails.amountReceived}\n`;
                confirmMessage += `Change: RM ${paymentDetails.change}\n`;
            } else if (selectedPaymentMethod === 'card') {
                confirmMessage += `Card: ****${paymentDetails.cardLast4}\n`;
                confirmMessage += `Status: Verified\n`;
            } else if (selectedPaymentMethod === 'ewallet') {
                confirmMessage += `Wallet: ${verifiedAccountNumber}\n`;
                confirmMessage += `Status: Verified\n`;
            }
            
            confirmMessage += '\n\nProceed with this sale?';
            
            if (!confirm(confirmMessage)) {
                console.log('Sale cancelled by user');
                return;
            }
            
            closePaymentModal();
            
            const customerName = document.getElementById('customerName').value || 'Walk-in Customer';
            console.log('Customer:', customerName);
            console.log('Payment Details:', paymentDetails);
            
            try {
                document.getElementById('checkoutBtn').disabled = true;
                document.getElementById('checkoutBtn').textContent = 'Processing...';
                
                const formData = new FormData();
                formData.append('action', 'process_sale');
                formData.append('items', JSON.stringify(cart));
                formData.append('customer_name', customerName);
                formData.append('payment_method', selectedPaymentMethod);
                formData.append('amount_paid', amountPaid);
                formData.append('payment_details', JSON.stringify(paymentDetails));
                if (selectedPaymentMethod !== 'cash') {
                    formData.append('account_number', verifiedAccountNumber);
                }
                
                console.log('Sending sale data...');
                console.log('Cart items:', cart);
                console.log('Payment details:', {
                    customer: customerName,
                    method: selectedPaymentMethod,
                    amountPaid: amountPaid,
                    total: total
                });
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                console.log('Response status:', response.status);
                console.log('Response OK:', response.ok);
                
                const responseText = await response.text();
                console.log('Raw response:', responseText);
                
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    console.error('Failed to parse JSON response:', e);
                    console.error('Response was:', responseText);
                    alert('Error: Invalid response from server');
                    return;
                }
                
                console.log('Parsed sale response:', data);
                
                if (data.success) {
                    const change = data.change;
                    alert(`Sale completed successfully!\n\nSale #: ${data.sale_number}\nTotal: RM ${data.total.toFixed(2)}\nPaid: RM ${amountPaid.toFixed(2)}\nChange: RM ${change.toFixed(2)}`);
                    
                    // Clear cart and reset
                    cart = [];
                    document.getElementById('customerName').value = '';
                    renderCart();
                    
                    // Reload products to update stock quantities
                    await loadProducts();
                } else {
                    alert('Sale failed: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Checkout error:', error);
                alert('Failed to process sale');
            } finally {
                document.getElementById('checkoutBtn').disabled = false;
                document.getElementById('checkoutBtn').textContent = 'Complete Sale';
            }
        }
        
        function appendNumpad(val) {
            const input = document.getElementById('cashAmount');
            if (!input) return;
            
            // If value is exactly the total (default), clear it first
            const total = parseFloat(document.getElementById('total').textContent.replace('RM ', ''));
            if (parseFloat(input.value) === total && input.value === total.toFixed(2)) {
                input.value = '';
            }
            
            input.value += val;
            calculateChange();
        }
        
        // Utility function
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Event listeners
        document.getElementById('searchInput').addEventListener('input', (e) => {
            renderProducts(e.target.value);
        });
        
        document.querySelectorAll('.payment-method').forEach(method => {
            method.addEventListener('click', () => {
                document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('active'));
                method.classList.add('active');
                selectedPaymentMethod = method.dataset.method;
            });
        });
        
        document.getElementById('checkoutBtn').addEventListener('click', showPaymentModal);
        
        document.getElementById('clearCartBtn').addEventListener('click', () => {
            if (cart.length > 0 && confirm('Clear all items from cart?')) {
                cart = [];
                renderCart();
            }
        });
        
        // Initialize
        loadProducts();
    </script>
</body>
</html>
