<?php
/**
 * POS Terminal - Firebase Integration
 * Uses the same products collection as Stock Management
 * Sales automatically deduct from stock quantities
 */
require_once '../../config.php';
require_once '../../functions.php';
require_once '../../getDB.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

// Check POS permission
if (!currentUserHasPermission('can_use_pos')) {
    $_SESSION['error'] = 'You do not have permission to use POS';
    header('Location: ../../index.php');
    exit;
}

$db = getDB();
$errors = [];
$success_message = '';

// Get user's store (if assigned)
$user_store_id = $_SESSION['store_id'] ?? null;
$store_info = null;

if ($user_store_id) {
    try {
        $store_info = $db->read('stores', $user_store_id);
        
        // Check if POS is enabled for this store
        if (empty($store_info['has_pos']) || $store_info['has_pos'] != 1) {
            $_SESSION['error'] = 'POS is not enabled for your store. Please contact administrator.';
            header('Location: ../../index.php');
            exit;
        }
    } catch (Exception $e) {
        error_log("Error fetching store info: " . $e->getMessage());
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'load_products':
                // Load products with caching (5 minutes)
                $cacheFile = __DIR__ . '/../../storage/cache/pos_products.cache';
                $cacheMaxAge = 300; // 5 minutes
                
                $useCache = file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheMaxAge);
                
                if ($useCache) {
                    $products = unserialize(file_get_contents($cacheFile));
                } else {
                    $products = [];
                    
                    // FIXED: Try SQL database first (more reliable and where batch-added products are)
                    $sqlDb = getSQLDB();
                    if ($sqlDb) {
                        try {
                            $sql = "SELECT id, name, sku, barcode, category, price, selling_price, quantity 
                                    FROM products 
                                    WHERE active = 1 
                                    AND quantity > 0";
                            
                            $params = [];
                            
                            // CRITICAL: Filter by store - must have store_id
                            if ($user_store_id) {
                                $sql .= " AND store_id = ?";
                                $params[] = $user_store_id;
                                error_log("POS filtering products for store ID: $user_store_id");
                            } else {
                                // If no store assigned, don't show any products
                                error_log("POS WARNING: No store_id found for user, showing no products");
                                $products = [];
                            }
                            
                            if ($user_store_id) {
                                $sql .= " ORDER BY name LIMIT 500";
                                
                                $productDocs = $sqlDb->fetchAll($sql, $params);
                                
                                foreach ($productDocs as $p) {
                                    $sku = $p['sku'] ?? '';
                                    $qty = intval($p['quantity'] ?? 0);
                                    
                                    // Skip products with invalid/Firebase-generated SKUs (contains random IDs)
                                    if (strlen($sku) > 50 || preg_match('/[a-zA-Z0-9]{20,}/', $sku)) {
                                        error_log("Skipping product with invalid SKU: $sku");
                                        continue;
                                    }
                                    
                                    // Skip products with 0 price (incomplete data)
                                    $price = isset($p['selling_price']) && $p['selling_price'] > 0 
                                           ? floatval($p['selling_price']) 
                                           : floatval($p['price'] ?? 0);
                                    
                                    if ($price <= 0) {
                                        error_log("Skipping product with 0 price: {$p['name']}");
                                        continue;
                                    }
                                    
                                    $products[] = [
                                        'id' => $p['id'] ?? '',
                                        'name' => $p['name'] ?? '',
                                        'sku' => $sku,
                                        'price' => $price,
                                        'quantity' => $qty,
                                        'category' => $p['category'] ?? 'Uncategorized',
                                        'barcode' => $p['barcode'] ?? '',
                                        'image' => $p['image'] ?? null
                                    ];
                                }
                            }
                            
                            error_log("POS loaded " . count($products) . " products from SQL for store: $user_store_id");
                        } catch (Exception $e) {
                            error_log("SQL product load failed: " . $e->getMessage());
                        }
                    }
                    
                    // Fallback to Firebase if SQL failed or no products
                    if (empty($products)) {
                        try {
                            $filters = [['active', '==', 1]];
                            if ($user_store_id) {
                                $filters[] = ['store_id', '==', $user_store_id];
                            }
                            
                            $productDocs = $db->readAll('products', $filters, null, 500);
                            
                            foreach ($productDocs as $p) {
                                $products[] = [
                                    'id' => $p['id'] ?? '',
                                    'name' => $p['name'] ?? '',
                                    'sku' => $p['sku'] ?? '',
                                    'price' => isset($p['price']) ? floatval($p['price']) : 0,
                                    'quantity' => intval($p['quantity'] ?? 0),
                                    'category' => $p['category'] ?? 'Uncategorized',
                                    'barcode' => $p['barcode'] ?? '',
                                    'image' => $p['image'] ?? null
                                ];
                            }
                            
                            error_log("POS loaded " . count($products) . " products from Firebase for store: $user_store_id");
                        } catch (Exception $e) {
                            error_log("Firebase product load failed: " . $e->getMessage());
                        }
                    }
                    
                    // Cache for next requests
                    if (!is_dir(dirname($cacheFile))) {
                        mkdir(dirname($cacheFile), 0755, true);
                    }
                    file_put_contents($cacheFile, serialize($products));
                }
                
                echo json_encode(['success' => true, 'products' => $products]);
                exit;
                
            case 'process_sale':
                $items = json_decode($_POST['items'] ?? '[]', true);
                $customer_name = trim($_POST['customer_name'] ?? 'Walk-in Customer');
                $payment_method = $_POST['payment_method'] ?? 'cash';
                $amount_paid = floatval($_POST['amount_paid'] ?? 0);
                $notes = trim($_POST['notes'] ?? '');
                
                if (empty($items)) {
                    throw new Exception('No items in cart');
                }
                
                // Calculate totals
                $subtotal = 0;
                $productUpdates = [];
                
                // Validate all items first - check SQL database first for accuracy
                $sqlDb = getSQLDB();
                
                foreach ($items as $item) {
                    $product_id = $item['product_id'] ?? '';
                    $quantity = intval($item['quantity'] ?? 0);
                    $price = floatval($item['price'] ?? 0);
                    
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
                
                $tax = $subtotal * 0.06; // 6% tax
                $total = $subtotal + $tax;
                $change = $amount_paid - $total;
                
                if ($amount_paid < $total) {
                    throw new Exception('Insufficient payment. Total: RM ' . number_format($total, 2));
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
                if ($sqlDb) {
                    try {
                        // FIX: Determine actual store ID (not 'main')
                        $actual_store_id = null;
                        
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
                            $firstPosStore = $sqlDb->fetch("SELECT id FROM stores WHERE has_pos = 1 ORDER BY id ASC LIMIT 1");
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
                        
                        // Match existing sales table structure
                        $sqlDb->execute(
                            "INSERT INTO sales (sale_number, store_id, user_id, customer_name, 
                                               subtotal, tax_amount, total_amount, payment_method, 
                                               payment_status, notes, created_at, updated_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [
                                $sale_number,
                                $actual_store_id,  // Use actual numeric store ID
                                $_SESSION['user_id'],
                                $customer_name,
                                $subtotal,
                                $tax,
                                $total,
                                $payment_method,
                                'completed',
                                $notes . "\n\nItems: " . json_encode($items, JSON_PRETTY_PRINT),
                                date('Y-m-d H:i:s'),
                                date('Y-m-d H:i:s')
                            ]
                        );
                        error_log("✓ Sale saved to SQL: $sale_number");
                    } catch (Exception $e) {
                        error_log("✗ SQL sale save failed: " . $e->getMessage());
                        // Don't throw - continue to Firebase
                    }
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
                
                echo json_encode([
                    'success' => true,
                    'sale_number' => $sale_number,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'change' => $change,
                    'items_sold' => count($items)
                ]);
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
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
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
        }
        
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-color: #3498db;
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
            width: 30px;
            height: 30px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 18px;
        }
        
        .qty-btn:hover {
            background: #2980b9;
        }
        
        .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #e74c3c;
            color: white;
            border: none;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 12px;
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
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }
        
        .checkout-btn:hover {
            background: #229954;
        }
        
        .checkout-btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }
        
        .clear-cart-btn {
            width: 100%;
            padding: 10px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
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
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 14px;
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
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
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
                    <a href="stock_pos_integration.php" class="add-products-btn" title="Manage products - Add products from inventory to this POS store">
                        ➕ Manage Products
                    </a>
                    <a href="../../index.php" class="back-btn">← Dashboard</a>
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
                <div class="summary-row">
                    <span>Tax (6%):</span>
                    <span id="tax">RM 0.00</span>
                </div>
                <div class="summary-row total">
                    <span>TOTAL:</span>
                    <span id="total">RM 0.00</span>
                </div>
                
                <div class="payment-section">
                    <div class="payment-methods">
                        <div class="payment-method active" data-method="cash">💵 Cash</div>
                        <div class="payment-method" data-method="card">💳 Card</div>
                        <div class="payment-method" data-method="ewallet">📱 E-Wallet</div>
                        <div class="payment-method" data-method="other">🔄 Other</div>
                    </div>
                    
                    <button class="checkout-btn" id="checkoutBtn" disabled>Complete Sale</button>
                    <button class="clear-cart-btn" id="clearCartBtn">Clear Cart</button>
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
                const formData = new FormData();
                formData.append('action', 'load_products');
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    products = data.products;
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
                         onclick="addToCart('${product.id}')">
                        <div class="product-name">${escapeHtml(product.name)}</div>
                        <div class="product-price">RM ${product.price.toFixed(2)}</div>
                        <div class="product-stock">Stock: ${product.quantity}</div>
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
            const product = products.find(p => p.id === productId);
            if (!product || product.quantity <= 0) return;
            
            const existingItem = cart.find(item => item.product_id === productId);
            
            if (existingItem) {
                if (existingItem.quantity < product.quantity) {
                    existingItem.quantity++;
                } else {
                    alert('Cannot add more than available stock');
                    return;
                }
            } else {
                cart.push({
                    product_id: productId,
                    name: product.name,
                    price: product.price,
                    quantity: 1,
                    max_quantity: product.quantity
                });
            }
            
            renderCart();
        }
        
        // Update cart item quantity
        function updateQuantity(productId, delta) {
            const item = cart.find(i => i.product_id === productId);
            if (!item) return;
            
            const newQty = item.quantity + delta;
            
            if (newQty <= 0) {
                removeFromCart(productId);
            } else if (newQty <= item.max_quantity) {
                item.quantity = newQty;
                renderCart();
            } else {
                alert('Cannot add more than available stock');
            }
        }
        
        // Remove item from cart
        function removeFromCart(productId) {
            cart = cart.filter(item => item.product_id !== productId);
            renderCart();
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
                                <span>${item.quantity}</span>
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
            const tax = subtotal * 0.06; // 6% tax
            const total = subtotal + tax;
            
            document.getElementById('subtotal').textContent = 'RM ' + subtotal.toFixed(2);
            document.getElementById('tax').textContent = 'RM ' + tax.toFixed(2);
            document.getElementById('total').textContent = 'RM ' + total.toFixed(2);
        }
        
        // Process checkout
        async function checkout() {
            if (cart.length === 0) return;
            
            const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0) * 1.06;
            const amountPaid = parseFloat(prompt('Enter amount paid (Total: RM ' + total.toFixed(2) + '):', total.toFixed(2)));
            
            if (isNaN(amountPaid) || amountPaid < total) {
                alert('Invalid payment amount');
                return;
            }
            
            const customerName = document.getElementById('customerName').value || 'Walk-in Customer';
            
            try {
                document.getElementById('checkoutBtn').disabled = true;
                document.getElementById('checkoutBtn').textContent = 'Processing...';
                
                const formData = new FormData();
                formData.append('action', 'process_sale');
                formData.append('items', JSON.stringify(cart));
                formData.append('customer_name', customerName);
                formData.append('payment_method', selectedPaymentMethod);
                formData.append('amount_paid', amountPaid);
                
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
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
        
        document.getElementById('checkoutBtn').addEventListener('click', checkout);
        
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
