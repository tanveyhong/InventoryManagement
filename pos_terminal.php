<?php
require_once 'hybrid_config.php';
require_once 'hybrid_db.php';
require_once 'functions.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: modules/users/login.php');
    exit;
}

// Check permission
if (!currentUserHasPermission('can_use_pos')) {
    $_SESSION['error'] = 'You do not have permission to access the POS terminal';
    header('Location: index.php');
    exit;
}

// Initialize database
$db = getHybridDB();

// Ensure customer_accounts table exists (for demo)
try {
    // Try SQLite syntax first (most likely for POS terminal)
    $db->execute("CREATE TABLE IF NOT EXISTS customer_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        account_number VARCHAR(50) UNIQUE NOT NULL,
        account_name VARCHAR(100) NOT NULL,
        account_type VARCHAR(20) NOT NULL,
        balance DECIMAL(10, 2) DEFAULT 0.00,
        pin VARCHAR(255),
        email VARCHAR(100),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Check if demo data exists
    $check = $db->fetch("SELECT COUNT(*) as count FROM customer_accounts");
    $count = is_array($check) ? $check['count'] : $check; // Handle different fetch return types
    
    if ($count == 0) {
        $db->execute("INSERT INTO customer_accounts (account_number, account_name, account_type, balance, email) 
                   VALUES ('4000123456789010', 'John Doe Bank', 'bank', 5000.00, 'demo@example.com')");
        
        $pin = password_hash('123456', PASSWORD_DEFAULT);
        $db->execute("INSERT INTO customer_accounts (account_number, account_name, account_type, balance, pin) 
                   VALUES ('0123456789', 'Jane Doe Wallet', 'ewallet', 500.00, ?)", [$pin]);
    }
} catch (Exception $e) {
    // If SQLite syntax fails, it might be Postgres (though unlikely given datetime('now') usage elsewhere)
    // We could try Postgres syntax here if needed, but let's assume SQLite for POS local DB.
}

// Get sync status for header display
$syncStatus = $db->getSyncStatus();

// Handle AJAX requests
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'search_product':
            $query = $_POST['query'] ?? '';
            if (strlen($query) >= 2) {
                $products = $db->fetchAll("
                    SELECT p.*, c.name as category_name 
                    FROM products p 
                    LEFT JOIN categories c ON p.category_id = c.id 
                    WHERE (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?) 
                    AND p.is_active = 1 AND p.quantity > 0
                    LIMIT 10
                ", ["%$query%", "%$query%", "%$query%"]);
                
                echo json_encode(['success' => true, 'products' => $products]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Query too short']);
            }
            exit;

        case 'verify_payment':
            $type = $_POST['type']; // 'card' or 'ewallet'
            $account_number = $_POST['account_number'];
            $amount = floatval($_POST['amount']);
            
            // Check if account exists
            $account = $db->fetch("SELECT * FROM customer_accounts WHERE account_number = ?", [$account_number]);
            
            if (!$account) {
                echo json_encode(['success' => false, 'message' => 'Account not found']);
                exit;
            }
            
            if ($account['balance'] < $amount) {
                echo json_encode(['success' => false, 'message' => 'Insufficient balance. Available: $' . number_format($account['balance'], 2)]);
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
                // Simulate OTP
                $otp = rand(100000, 999999);
                $_SESSION['payment_otp'] = $otp;
                $_SESSION['payment_account'] = $account_number;
                
                // Try to send email
                $email_sent = false;
                if (!empty($account['email']) && file_exists('email_helper.php')) {
                    require_once 'email_helper.php';
                    // sendEmail($to, $subject, $body)
                    try {
                        sendEmail($account['email'], "Payment OTP", "Your OTP for payment of $" . number_format($amount, 2) . " is: <b>$otp</b>");
                        $email_sent = true;
                    } catch (Exception $e) {
                        // Ignore email error for demo
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'require_otp' => true, 
                    'message' => 'OTP sent to ' . $account['email'],
                    'debug_otp' => $otp // For demo convenience
                ]);
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
            
        case 'process_sale':
            try {
                $items = json_decode($_POST['items'], true);
                $customer = $_POST['customer_name'] ?? '';
                $payment_method = $_POST['payment_method'] ?? 'cash';
                $discount = floatval($_POST['discount'] ?? 0);
                
                if (empty($items)) {
                    throw new Exception('No items in cart');
                }
                
                $db->execute("BEGIN TRANSACTION");
                
                // Generate transaction number
                $transaction_number = 'POS-' . STORE_ID . '-' . date('Ymd') . '-' . uniqid();
                
                // Calculate totals
                $subtotal = 0;
                foreach ($items as $item) {
                    $subtotal += $item['quantity'] * $item['unit_price'];
                }
                
                $discount_amount = ($subtotal * $discount) / 100;
                $tax_amount = 0; // Tax removed
                $total_amount = $subtotal - $discount_amount + $tax_amount;
                
                // Deduct from customer account if applicable
                if ($payment_method === 'card' || $payment_method === 'mobile') {
                    $account_number = $_POST['account_number'] ?? '';
                    
                    // Verify again (security)
                    $account = $db->fetch("SELECT * FROM customer_accounts WHERE account_number = ?", [$account_number]);
                    if (!$account) {
                         throw new Exception('Payment failed: Account not found');
                    }
                    if ($account['balance'] < $total_amount) {
                        throw new Exception('Payment failed: Insufficient funds');
                    }
                    
                    // Deduct
                    $db->execute("UPDATE customer_accounts SET balance = balance - ? WHERE id = ?", [$total_amount, $account['id']]);
                }
                
                // Create transaction
                $db->execute("
                    INSERT INTO transactions 
                    (transaction_number, store_id, user_id, customer_name, total_amount, tax_amount, discount_amount, payment_method, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', datetime('now'))
                ", [
                    $transaction_number, STORE_ID, 1, $customer, 
                    $total_amount, $tax_amount, $discount_amount, $payment_method
                ]);
                
                $transaction_id = $db->getWriteConnection()->lastInsertId();
                
                // Add transaction items and update stock
                foreach ($items as $item) {
                    // Add transaction item
                    $line_total = $item['quantity'] * $item['unit_price'];
                    $db->execute("
                        INSERT INTO transaction_items 
                        (transaction_id, product_id, quantity, unit_price, line_total, created_at)
                        VALUES (?, ?, ?, ?, ?, datetime('now'))
                    ", [$transaction_id, $item['product_id'], $item['quantity'], $item['unit_price'], $line_total]);
                    
                    // Update product stock
                    $db->execute("
                        UPDATE products 
                        SET quantity = quantity - ?, updated_at = datetime('now')
                        WHERE id = ?
                    ", [$item['quantity'], $item['product_id']]);
                    
                    // Record stock movement
                    $db->execute("
                        INSERT INTO stock_movements 
                        (product_id, store_id, movement_type, quantity, unit_price, reference, user_id, created_at)
                        VALUES (?, ?, 'sale', ?, ?, ?, ?, datetime('now'))
                    ", [
                        $item['product_id'], STORE_ID, -$item['quantity'], 
                        $item['unit_price'], $transaction_number, 1
                    ]);
                }
                
                $db->execute("COMMIT");
                
                echo json_encode([
                    'success' => true,
                    'transaction_id' => $transaction_id,
                    'transaction_number' => $transaction_number,
                    'total' => $total_amount,
                    'message' => 'Sale completed successfully'
                ]);
                
            } catch (Exception $e) {
                $db->execute("ROLLBACK");
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
            
        case 'sync_now':
            $result = $db->forceSyncNow();
            echo json_encode($result);
            exit;
    }
}

// Get recent transactions for display
$recent_transactions = $db->fetchAll("
    SELECT t.*, COUNT(ti.id) as item_count
    FROM transactions t
    LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
    WHERE t.store_id = ?
    GROUP BY t.id
    ORDER BY t.created_at DESC
    LIMIT 10
", [STORE_ID]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Terminal - Store <?= STORE_ID ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            height: 100vh;
            overflow: hidden;
        }
        
        .pos-container {
            display: grid;
            grid-template-columns: 1fr 400px;
            grid-template-rows: 60px 1fr;
            height: 100vh;
            gap: 10px;
            padding: 10px;
        }
        
        .header {
            grid-column: 1 / -1;
            background: #2c3e50;
            color: white;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 8px;
        }
        
        .sync-status {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 14px;
        }
        
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .online { background: #27ae60; }
        .offline { background: #e74c3c; }
        .syncing { background: #f39c12; }
        
        .main-area {
            background: white;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        .search-area {
            margin-bottom: 20px;
        }
        
        .search-input {
            width: 100%;
            padding: 12px;
            font-size: 16px;
            border: 2px solid #ddd;
            border-radius: 6px;
            outline: none;
        }
        
        .search-input:focus {
            border-color: #3498db;
        }
        
        .product-results {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-top: 10px;
            display: none;
        }
        
        .product-item {
            padding: 12px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
        }
        
        .product-item:hover {
            background: #f8f9fa;
        }
        
        .product-info {
            flex: 1;
        }
        
        .product-name {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .product-details {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 4px;
        }
        
        .product-price {
            font-size: 18px;
            font-weight: bold;
            color: #27ae60;
        }
        
        .cart-area {
            background: white;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            flex-direction: column;
        }
        
        .cart-header {
            background: #3498db;
            color: white;
            padding: 15px;
            margin: -20px -20px 20px -20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
            font-size: 18px;
            font-weight: bold;
        }
        
        .cart-items {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        
        .cart-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        
        .item-info {
            flex: 1;
            margin-right: 10px;
        }
        
        .item-name {
            font-weight: bold;
            color: #2c3e50;
        }
        
        .item-price {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            margin: 0 10px;
        }
        
        .qty-btn {
            width: 30px;
            height: 30px;
            border: 1px solid #ddd;
            background: #f8f9fa;
            cursor: pointer;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .qty-btn:hover {
            background: #e9ecef;
        }
        
        .quantity {
            width: 50px;
            text-align: center;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .remove-btn {
            color: #e74c3c;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
        }
        
        .remove-btn:hover {
            background: #fadbd8;
        }
        
        .cart-totals {
            border-top: 2px solid #eee;
            padding-top: 15px;
        }
        
        .total-line {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }
        
        .total-line.grand-total {
            font-size: 20px;
            font-weight: bold;
            color: #2c3e50;
            border-top: 1px solid #ddd;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .checkout-area {
            margin-top: 20px;
        }
        
        .customer-input, .payment-select, .discount-input {
            width: 100%;
            padding: 10px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .checkout-btn {
            width: 100%;
            padding: 15px;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }
        
        .checkout-btn:hover {
            background: #219a52;
        }
        
        .checkout-btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }
        
        .transactions-section {
            margin-top: 20px;
            flex: 1;
            overflow: hidden;
        }
        
        .recent-transactions {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 15px;
            height: 100%;
            overflow-y: auto;
        }
        
        .transaction-item {
            background: white;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
            border-left: 4px solid #3498db;
        }
        
        .sync-btn {
            background: #f39c12;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .sync-btn:hover {
            background: #d68910;
        }
        
        .empty-cart {
            text-align: center;
            color: #95a5a6;
            padding: 40px 20px;
        }
    </style>
    <script src="modules/offline/init.js?v=10"></script>
</head>
<body>
    <div class="pos-container">
        <div class="header">
            <div>
                <h1>POS Terminal - Store <?= STORE_ID ?></h1>
                <small><?= CURRENT_MODE === 'hybrid' ? 'Offline Ready' : 'Online Only' ?></small>
            </div>
            <div class="sync-status">
                <span class="status-dot <?= $syncStatus['central_connected'] ? 'online' : 'offline' ?>"></span>
                <span><?= $syncStatus['central_connected'] ? 'Online' : 'Offline' ?></span>
                <span>Queue: <?= $syncStatus['queue_size'] ?></span>
                <button class="sync-btn" onclick="syncNow()">Sync Now</button>
            </div>
        </div>
        
        <div class="main-area">
            <div class="search-area">
                <input type="text" class="search-input" placeholder="Search products by name, SKU, or barcode..." 
                       id="productSearch" autocomplete="off">
                <div class="product-results" id="productResults"></div>
            </div>
            
            <div class="transactions-section">
                <h3>Recent Transactions</h3>
                <div class="recent-transactions">
                    <?php if (empty($recent_transactions)): ?>
                        <div class="empty-cart">
                            <p>No recent transactions</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_transactions as $transaction): ?>
                            <div class="transaction-item">
                                <div style="display: flex; justify-content: space-between;">
                                    <strong>#<?= htmlspecialchars($transaction['transaction_number']) ?></strong>
                                    <strong>$<?= number_format($transaction['total_amount'], 2) ?></strong>
                                </div>
                                <div style="font-size: 12px; color: #7f8c8d; margin-top: 5px;">
                                    <?= $transaction['customer_name'] ?: 'Walk-in Customer' ?> • 
                                    <?= $transaction['item_count'] ?> items • 
                                    <?= date('M j, Y g:i A', strtotime($transaction['created_at'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="cart-area">
            <div class="cart-header">Shopping Cart</div>
            
            <div class="cart-items" id="cartItems">
                <div class="empty-cart">
                    <p>Cart is empty</p>
                    <small>Search and add products to begin</small>
                </div>
            </div>
            
            <div class="cart-totals" id="cartTotals" style="display: none;">
                <div class="total-line">
                    <span>Subtotal:</span>
                    <span id="subtotal">$0.00</span>
                </div>
                <div class="total-line">
                    <span>Discount:</span>
                    <span id="discountAmount">$0.00</span>
                </div>
                <!-- Tax removed
                <div class="total-line">
                    <span>Tax (10%):</span>
                    <span id="taxAmount">$0.00</span>
                </div>
                -->
                <div class="total-line grand-total">
                    <span>Total:</span>
                    <span id="grandTotal">$0.00</span>
                </div>
            </div>
            
            <div class="checkout-area" id="checkoutArea" style="display: none;">
                <input type="text" class="customer-input" id="customerName" placeholder="Customer name (optional)">
                <select class="payment-select" id="paymentMethod" onchange="togglePaymentFields()">
                    <option value="cash">Cash</option>
                    <option value="card">Card</option>
                    <option value="mobile">Mobile Payment (E-Wallet)</option>
                </select>
                
                <!-- Payment Details Section -->
                <div id="paymentDetails" style="display: none; border: 1px solid #ddd; padding: 10px; margin: 10px 0; border-radius: 4px; background: #f9f9f9;">
                    <div id="cardFields" style="display: none;">
                        <input type="text" class="customer-input" id="cardNumber" placeholder="Card Number (Account #)">
                        <div id="otpSection" style="display: none;">
                            <p style="font-size: 12px; color: #666; margin-bottom: 5px;">OTP sent to email</p>
                            <input type="text" class="customer-input" id="otpInput" placeholder="Enter OTP">
                            <button class="sync-btn" onclick="verifyOtp()" style="width: 100%; margin-top: 5px; background: #2980b9;">Verify OTP</button>
                        </div>
                        <button class="sync-btn" id="sendOtpBtn" onclick="initiateCardPayment()" style="width: 100%; margin-top: 5px; background: #2980b9;">Send OTP</button>
                    </div>
                    
                    <div id="walletFields" style="display: none;">
                        <input type="text" class="customer-input" id="walletNumber" placeholder="Wallet ID (Account #)">
                        <input type="password" class="customer-input" id="walletPin" placeholder="6-Digit PIN" maxlength="6">
                        <button class="sync-btn" onclick="verifyWallet()" style="width: 100%; margin-top: 5px; background: #8e44ad;">Verify Wallet</button>
                    </div>
                    <div id="paymentStatus" style="margin-top: 10px; font-weight: bold; font-size: 14px;"></div>
                </div>

                <input type="number" class="discount-input" id="discountPercent" placeholder="Discount %" 
                       min="0" max="100" step="0.1" value="0">
                <button class="checkout-btn" id="checkoutBtn" onclick="processCheckout()">
                    Complete Sale
                </button>
            </div>
        </div>
    </div>

    <script>
        let cart = [];
        let searchTimeout;
        
        // Product search functionality
        document.getElementById('productSearch').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length >= 2) {
                searchTimeout = setTimeout(() => searchProducts(query), 300);
            } else {
                document.getElementById('productResults').style.display = 'none';
            }
        });
        
        function searchProducts(query) {
            const formData = new FormData();
            formData.append('action', 'search_product');
            formData.append('query', query);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayProductResults(data.products);
                }
            })
            .catch(error => console.error('Search error:', error));
        }
        
        function displayProductResults(products) {
            const resultsDiv = document.getElementById('productResults');
            
            if (products.length === 0) {
                resultsDiv.innerHTML = '<div style="padding: 15px; text-align: center; color: #95a5a6;">No products found</div>';
            } else {
                resultsDiv.innerHTML = products.map(product => `
                    <div class="product-item" onclick="addToCart(${product.id}, '${product.name.replace(/'/g, "\\'")}', ${product.unit_price}, '${product.sku || ''}')">
                        <div class="product-info">
                            <div class="product-name">${product.name}</div>
                            <div class="product-details">
                                SKU: ${product.sku || 'N/A'} | Stock: ${product.quantity} | 
                                Category: ${product.category_name || 'Uncategorized'}
                            </div>
                        </div>
                        <div class="product-price">$${parseFloat(product.unit_price).toFixed(2)}</div>
                    </div>
                `).join('');
            }
            
            resultsDiv.style.display = 'block';
        }
        
        function addToCart(productId, productName, unitPrice, sku) {
            // Check if item already in cart
            const existingItem = cart.find(item => item.product_id === productId);
            
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                cart.push({
                    product_id: productId,
                    name: productName,
                    unit_price: parseFloat(unitPrice),
                    sku: sku,
                    quantity: 1
                });
            }
            
            updateCartDisplay();
            document.getElementById('productSearch').value = '';
            document.getElementById('productResults').style.display = 'none';
        }
        
        function removeFromCart(productId) {
            cart = cart.filter(item => item.product_id !== productId);
            updateCartDisplay();
        }
        
        function updateQuantity(productId, change) {
            const item = cart.find(item => item.product_id === productId);
            if (item) {
                item.quantity += change;
                if (item.quantity <= 0) {
                    removeFromCart(productId);
                } else {
                    updateCartDisplay();
                }
            }
        }
        
        function updateCartDisplay() {
            const cartItemsDiv = document.getElementById('cartItems');
            const cartTotalsDiv = document.getElementById('cartTotals');
            const checkoutAreaDiv = document.getElementById('checkoutArea');
            
            if (cart.length === 0) {
                cartItemsDiv.innerHTML = `
                    <div class="empty-cart">
                        <p>Cart is empty</p>
                        <small>Search and add products to begin</small>
                    </div>
                `;
                cartTotalsDiv.style.display = 'none';
                checkoutAreaDiv.style.display = 'none';
            } else {
                cartItemsDiv.innerHTML = cart.map(item => `
                    <div class="cart-item">
                        <div class="item-info">
                            <div class="item-name">${item.name}</div>
                            <div class="item-price">$${item.unit_price.toFixed(2)} each</div>
                        </div>
                        <div class="quantity-controls">
                            <div class="qty-btn" onclick="updateQuantity(${item.product_id}, -1)">−</div>
                            <input type="number" class="quantity" value="${item.quantity}" 
                                   onchange="setQuantity(${item.product_id}, this.value)" min="1">
                            <div class="qty-btn" onclick="updateQuantity(${item.product_id}, 1)">+</div>
                        </div>
                        <div class="remove-btn" onclick="removeFromCart(${item.product_id})">✕</div>
                    </div>
                `).join('');
                
                updateTotals();
                cartTotalsDiv.style.display = 'block';
                checkoutAreaDiv.style.display = 'block';
            }
        }
        
        function setQuantity(productId, quantity) {
            const item = cart.find(item => item.product_id === productId);
            if (item) {
                item.quantity = parseInt(quantity) || 1;
                updateCartDisplay();
            }
        }
        
        function updateTotals() {
            const discount = parseFloat(document.getElementById('discountPercent').value) || 0;
            
            const subtotal = cart.reduce((sum, item) => sum + (item.quantity * item.unit_price), 0);
            const discountAmount = (subtotal * discount) / 100;
            const taxableAmount = subtotal - discountAmount;
            const taxAmount = 0; // Tax removed
            const grandTotal = taxableAmount + taxAmount;
            
            document.getElementById('subtotal').textContent = '$' + subtotal.toFixed(2);
            document.getElementById('discountAmount').textContent = '$' + discountAmount.toFixed(2);
            // document.getElementById('taxAmount').textContent = '$' + taxAmount.toFixed(2);
            document.getElementById('grandTotal').textContent = '$' + grandTotal.toFixed(2);
        }
        
        // Update totals when discount changes
        document.getElementById('discountPercent').addEventListener('input', updateTotals);
        
        let paymentVerified = false;
        let verifiedAccountNumber = '';

        function togglePaymentFields() {
            const method = document.getElementById('paymentMethod').value;
            const detailsDiv = document.getElementById('paymentDetails');
            const cardFields = document.getElementById('cardFields');
            const walletFields = document.getElementById('walletFields');
            const checkoutBtn = document.getElementById('checkoutBtn');
            
            document.getElementById('paymentStatus').textContent = '';
            document.getElementById('paymentStatus').className = '';
            paymentVerified = false;
            verifiedAccountNumber = '';
            
            if (method === 'cash') {
                detailsDiv.style.display = 'none';
                checkoutBtn.disabled = false;
            } else {
                detailsDiv.style.display = 'block';
                checkoutBtn.disabled = true; // Require verification
                
                if (method === 'card') {
                    cardFields.style.display = 'block';
                    walletFields.style.display = 'none';
                } else {
                    cardFields.style.display = 'none';
                    walletFields.style.display = 'block';
                }
            }
        }

        function initiateCardPayment() {
            const accountNum = document.getElementById('cardNumber').value;
            const amount = parseFloat(document.getElementById('grandTotal').textContent.replace('$', ''));
            
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
                        console.log('Debug OTP:', data.debug_otp);
                        alert('Demo OTP: ' + data.debug_otp);
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
                    document.getElementById('checkoutBtn').disabled = false;
                    document.getElementById('otpSection').style.display = 'none';
                } else {
                    alert(data.message);
                }
            });
        }

        function verifyWallet() {
            const accountNum = document.getElementById('walletNumber').value;
            const pin = document.getElementById('walletPin').value;
            const amount = parseFloat(document.getElementById('grandTotal').textContent.replace('$', ''));
            
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
                    document.getElementById('paymentStatus').textContent = 'Wallet Verified. Balance after: $' + data.balance_after.toFixed(2);
                    document.getElementById('paymentStatus').style.color = 'green';
                    document.getElementById('checkoutBtn').disabled = false;
                } else {
                    alert(data.message);
                }
            });
        }

        function processCheckout() {
            if (cart.length === 0) return;
            
            const method = document.getElementById('paymentMethod').value;
            if (method !== 'cash' && !paymentVerified) {
                alert('Please verify payment first');
                return;
            }
            
            const checkoutBtn = document.getElementById('checkoutBtn');
            checkoutBtn.disabled = true;
            checkoutBtn.textContent = 'Processing...';
            
            const formData = new FormData();
            formData.append('action', 'process_sale');
            formData.append('items', JSON.stringify(cart));
            formData.append('customer_name', document.getElementById('customerName').value);
            formData.append('payment_method', method);
            formData.append('discount', document.getElementById('discountPercent').value);
            if (method !== 'cash') {
                formData.append('account_number', verifiedAccountNumber);
            }
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Sale completed successfully!\nTransaction: ${data.transaction_number}\nTotal: $${data.total.toFixed(2)}`);
                    
                    // Reset cart and form
                    cart = [];
                    document.getElementById('customerName').value = '';
                    document.getElementById('discountPercent').value = '0';
                    updateCartDisplay();
                    
                    // Reload to show new transaction
                    setTimeout(() => location.reload(), 1000);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Checkout error:', error);
                alert('Error processing sale. Please try again.');
            })
            .finally(() => {
                checkoutBtn.disabled = false;
                checkoutBtn.textContent = 'Complete Sale';
            });
        }
        
        function syncNow() {
            const formData = new FormData();
            formData.append('action', 'sync_now');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Sync completed!\nSynced: ${data.synced || 0} records\nRemaining: ${data.remaining || 0}`);
                } else {
                    alert('Sync failed: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Sync error:', error);
                alert('Sync error occurred.');
            });
        }
        
        // Auto-focus search on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('productSearch').focus();
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // F1 for search focus
            if (e.key === 'F1') {
                e.preventDefault();
                document.getElementById('productSearch').focus();
            }
            
            // F2 for sync
            if (e.key === 'F2') {
                e.preventDefault();
                syncNow();
            }
            
            // Enter to checkout
            if (e.key === 'Enter' && e.ctrlKey && cart.length > 0) {
                e.preventDefault();
                processCheckout();
            }
        });
        
        // Hide search results when clicking elsewhere
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.search-area')) {
                document.getElementById('productResults').style.display = 'none';
            }
        });
    </script>
</body>
</html>