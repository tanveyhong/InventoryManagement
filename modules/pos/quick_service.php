<?php
/**
 * Quick Service POS - For Fast-Moving Retail
 * Optimized for: Convenience stores, cafes, quick service restaurants
 * Features: Fast checkout, barcode scanning, quick product selection
 */

// Prevent caching - force fresh data every time
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

$db = getSQLDB(); // Use SQL database for POS
$userId = $_SESSION['user_id'];
$userName = $_SESSION['username'] ?? 'Cashier';

// Get selected store from session or URL parameter
$selectedStoreId = $_GET['store'] ?? $_SESSION['pos_store_id'] ?? null;

// Get list of stores user has access to
$userStores = [];
try {
    $userStores = $db->fetchAll("
        SELECT s.* FROM stores s
        LEFT JOIN user_stores us ON s.id = us.store_id
        WHERE (s.deleted_at IS NULL OR s.deleted_at = '')
        AND (us.user_id = ? OR ? IN (SELECT id FROM users WHERE role = 'admin'))
        ORDER BY s.name
    ", [$userId, $userId]);
} catch (Exception $e) {
    // Fallback: get all stores if user_stores table doesn't exist
    try {
        $userStores = $db->fetchAll("SELECT * FROM stores WHERE deleted_at IS NULL OR deleted_at = '' ORDER BY name");
    } catch (Exception $e2) {
        $userStores = $db->fetchAll("SELECT * FROM stores ORDER BY name");
    }
}

// If store is selected, save to session
if ($selectedStoreId) {
    $_SESSION['pos_store_id'] = $selectedStoreId;
}

// Auto-select if user has only one store
if (!$selectedStoreId && count($userStores) === 1) {
    $selectedStoreId = $userStores[0]['id'];
    $_SESSION['pos_store_id'] = $selectedStoreId;
}

// Get current store details
$currentStore = null;
if ($selectedStoreId) {
    foreach ($userStores as $store) {
        if ($store['id'] == $selectedStoreId) {
            $currentStore = $store;
            break;
        }
    }
}

// Get popular products for quick access (filtered by store if selected)
$popularProducts = [];
try {
    $query = "
        SELECT p.*, COALESCE(SUM(si.quantity), 0) as total_sold 
        FROM products p
        LEFT JOIN sale_items si ON p.id = si.product_id
        WHERE (p.deleted_at IS NULL OR p.deleted_at = '')
    ";
    
    $params = [];
    if ($selectedStoreId) {
        $query .= " AND p.store_id = ?";
        $params[] = $selectedStoreId;
    }
    
    $query .= " GROUP BY p.id ORDER BY total_sold DESC LIMIT 12";
    
    $popularProducts = $db->fetchAll($query, $params);
} catch (Exception $e) {
    error_log("Error fetching popular products: " . $e->getMessage());
}

// Get product categories
$categories = [];
try {
    $allProducts = $db->fetchAll("SELECT DISTINCT category FROM products WHERE (deleted_at IS NULL OR deleted_at = '') AND category IS NOT NULL AND category != '' ORDER BY category");
    $categories = array_column($allProducts, 'category');
} catch (Exception $e) {
    error_log("Error fetching categories: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Service POS - <?php echo htmlspecialchars($storeName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            overflow: hidden;
        }
        
        .pos-container {
            display: grid;
            grid-template-columns: 1fr 400px;
            height: 100vh;
            gap: 0;
        }
        
        /* Left Panel - Products */
        .products-panel {
            background: #ffffff;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #e1e8ed;
        }
        
        .pos-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .pos-header h1 {
            font-size: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .store-info {
            font-size: 13px;
            opacity: 0.9;
        }
        
        .search-bar {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e1e8ed;
        }
        
        .search-input-wrapper {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 12px 45px 12px 45px;
            border: 2px solid #e1e8ed;
            border-radius: 25px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #657786;
            font-size: 18px;
        }
        
        .barcode-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .barcode-icon:hover {
            transform: translateY(-50%) scale(1.2);
        }
        
        .category-tabs {
            display: flex;
            gap: 10px;
            padding: 15px 20px;
            overflow-x: auto;
            background: #f8f9fa;
            border-bottom: 1px solid #e1e8ed;
        }
        
        .category-tab {
            padding: 8px 20px;
            background: white;
            border: 2px solid #e1e8ed;
            border-radius: 20px;
            cursor: pointer;
            white-space: nowrap;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #657786;
        }
        
        .category-tab:hover {
            border-color: #667eea;
            color: #667eea;
        }
        
        .category-tab.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }
        
        .products-grid {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
            align-content: start;
        }
        
        .product-card {
            background: white;
            border: 2px solid #e1e8ed;
            border-radius: 12px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            position: relative;
        }
        
        .product-card:hover {
            border-color: #667eea;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.2);
        }
        
        .product-card:active {
            transform: translateY(-2px);
        }
        
        .product-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            color: white;
            font-size: 24px;
        }
        
        .product-name {
            font-weight: 600;
            font-size: 13px;
            color: #14171a;
            margin-bottom: 5px;
            min-height: 32px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .product-price {
            color: #667eea;
            font-weight: 700;
            font-size: 16px;
        }
        
        .product-stock {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #27ae60;
            color: white;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .product-stock.low {
            background: #f39c12;
        }
        
        .product-stock.out {
            background: #e74c3c;
        }
        
        /* Right Panel - Cart */
        .cart-panel {
            background: #f8f9fa;
            display: flex;
            flex-direction: column;
        }
        
        .cart-header {
            background: #14171a;
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .cart-header h2 {
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .cart-count {
            background: #667eea;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 14px;
        }
        
        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
        }
        
        .cart-item {
            background: white;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .cart-item-info {
            flex: 1;
        }
        
        .cart-item-name {
            font-weight: 600;
            font-size: 14px;
            color: #14171a;
            margin-bottom: 5px;
        }
        
        .cart-item-price {
            color: #657786;
            font-size: 13px;
        }
        
        .cart-item-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .qty-btn {
            width: 30px;
            height: 30px;
            border: none;
            background: #667eea;
            color: white;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .qty-btn:hover {
            background: #764ba2;
            transform: scale(1.1);
        }
        
        .qty-display {
            min-width: 40px;
            text-align: center;
            font-weight: 700;
            font-size: 16px;
        }
        
        .remove-btn {
            background: #e74c3c;
            width: 30px;
            height: 30px;
            border: none;
            color: white;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .remove-btn:hover {
            background: #c0392b;
            transform: scale(1.1);
        }
        
        .cart-empty {
            text-align: center;
            padding: 60px 20px;
            color: #657786;
        }
        
        .cart-empty i {
            font-size: 60px;
            margin-bottom: 15px;
            opacity: 0.3;
        }
        
        .cart-summary {
            background: white;
            padding: 20px;
            border-top: 2px solid #e1e8ed;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .summary-row.total {
            font-size: 20px;
            font-weight: 700;
            color: #667eea;
            padding-top: 10px;
            border-top: 2px solid #e1e8ed;
            margin-top: 10px;
        }
        
        .cart-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            padding: 15px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .btn-clear {
            background: #f8f9fa;
            color: #657786;
            border: 2px solid #e1e8ed;
        }
        
        .btn-clear:hover {
            background: #e1e8ed;
        }
        
        .btn-checkout {
            background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
            color: white;
            grid-column: span 2;
        }
        
        .btn-checkout:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
        }
        
        .btn-checkout:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-header h2 {
            font-size: 22px;
            color: #14171a;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: #657786;
            cursor: pointer;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .close-modal:hover {
            background: #f8f9fa;
            color: #14171a;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #14171a;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .payment-method {
            padding: 15px;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .payment-method:hover {
            border-color: #667eea;
        }
        
        .payment-method.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-color: #667eea;
        }
        
        .payment-method i {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .product-card {
            animation: slideUp 0.3s ease;
        }
        
        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>
<body>
    <?php if (!$selectedStoreId || count($userStores) > 1): ?>
    <!-- Store Selection Modal/Banner -->
    <div id="storeSelector" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 9999; display: flex; align-items: center; justify-content: center;">
        <div style="background: white; padding: 40px; border-radius: 16px; max-width: 600px; width: 90%;">
            <h2 style="margin: 0 0 10px 0; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-store"></i> Select Store
            </h2>
            <p style="color: #7f8c8d; margin: 0 0 30px 0;">Choose which store you want to operate the POS for</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px;">
                <?php foreach ($userStores as $store): ?>
                <a href="?store=<?php echo $store['id']; ?>" style="text-decoration: none; color: inherit;">
                    <div style="padding: 20px; border: 2px solid #e1e8ed; border-radius: 12px; transition: all 0.3s; cursor: pointer; <?php echo ($selectedStoreId == $store['id']) ? 'background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-color: #667eea;' : ''; ?>">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                            <div style="width: 50px; height: 50px; background: <?php echo ($selectedStoreId == $store['id']) ? 'rgba(255,255,255,0.2)' : 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'; ?>; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-store" style="font-size: 24px; color: <?php echo ($selectedStoreId == $store['id']) ? 'white' : '#fff'; ?>;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 5px 0; font-size: 18px;"><?php echo htmlspecialchars($store['name']); ?></h3>
                                <?php if (!empty($store['code'])): ?>
                                <p style="margin: 0; font-size: 13px; opacity: 0.8;">Code: <?php echo htmlspecialchars($store['code']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if (!empty($store['address'])): ?>
                        <p style="margin: 0; font-size: 13px; opacity: 0.7; display: flex; align-items: center; gap: 5px;">
                            <i class="fas fa-map-marker-alt"></i>
                            <?php echo htmlspecialchars($store['city'] ?? $store['address']); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <?php if ($selectedStoreId): ?>
            <div style="margin-top: 20px; text-align: center;">
                <button onclick="document.getElementById('storeSelector').style.display='none'" style="padding: 12px 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer;">
                    Continue with <?php echo htmlspecialchars($currentStore['name'] ?? 'Selected Store'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="pos-container">
        <!-- Left Panel - Products -->
        <div class="products-panel">
            <div class="pos-header">
                <div>
                    <h1><i class="fas fa-bolt"></i> Quick Service POS</h1>
                    <div class="store-info">
                        <i class="fas fa-store"></i> <?php echo htmlspecialchars($currentStore['name'] ?? 'No Store Selected'); ?>
                        <?php if ($currentStore): ?>
                        <span style="opacity: 0.7;"> | </span>
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($userName); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <?php if (count($userStores) > 1): ?>
                    <button class="btn btn-clear" onclick="document.getElementById('storeSelector').style.display='flex'" style="background: rgba(255,255,255,0.2); color: white; border: none;" title="Switch Store">
                        <i class="fas fa-exchange-alt"></i>
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-clear" onclick="window.location.href='../../index.php'" style="background: rgba(255,255,255,0.2); color: white; border: none;">
                        <i class="fas fa-home"></i>
                    </button>
                </div>
            </div>
            
            <div class="search-bar">
                <div class="search-input-wrapper">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" class="search-input" placeholder="Search products or scan barcode...">
                    <i class="fas fa-barcode barcode-icon" title="Barcode Scanner"></i>
                </div>
            </div>
            
            <div class="category-tabs" id="categoryTabs">
                <div class="category-tab active" data-category="all">All Products</div>
                <?php foreach ($categories as $category): ?>
                    <div class="category-tab" data-category="<?php echo htmlspecialchars($category); ?>">
                        <?php echo htmlspecialchars($category); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="products-grid" id="productsGrid">
                <!-- Products will be loaded here -->
            </div>
        </div>
        
        <!-- Right Panel - Cart -->
        <div class="cart-panel">
            <div class="cart-header">
                <h2><i class="fas fa-shopping-cart"></i> Current Sale</h2>
                <span class="cart-count" id="cartCount">0</span>
            </div>
            
            <div class="cart-items" id="cartItems">
                <div class="cart-empty">
                    <i class="fas fa-shopping-cart"></i>
                    <p>Cart is empty<br>Add products to get started</p>
                </div>
            </div>
            
            <div class="cart-summary">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span id="subtotal">$0.00</span>
                </div>
                <div class="summary-row">
                    <span>Tax (0%):</span>
                    <span id="tax">$0.00</span>
                </div>
                <div class="summary-row total">
                    <span>TOTAL:</span>
                    <span id="total">$0.00</span>
                </div>
                
                <div class="cart-actions">
                    <button class="btn btn-clear" onclick="clearCart()">
                        <i class="fas fa-trash"></i> Clear
                    </button>
                    <button class="btn btn-checkout" id="checkoutBtn" onclick="openCheckoutModal()" disabled>
                        <i class="fas fa-check-circle"></i> Checkout
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Checkout Modal -->
    <div class="modal" id="checkoutModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-cash-register"></i> Complete Sale</h2>
                <button class="close-modal" onclick="closeCheckoutModal()">×</button>
            </div>
            
            <div class="form-group">
                <label class="form-label">Payment Method</label>
                <div class="payment-methods">
                    <div class="payment-method active" data-method="cash">
                        <i class="fas fa-money-bill-wave"></i>
                        <div>Cash</div>
                    </div>
                    <div class="payment-method" data-method="card">
                        <i class="fas fa-credit-card"></i>
                        <div>Card</div>
                    </div>
                    <div class="payment-method" data-method="digital">
                        <i class="fas fa-mobile-alt"></i>
                        <div>Digital</div>
                    </div>
                    <div class="payment-method" data-method="other">
                        <i class="fas fa-ellipsis-h"></i>
                        <div>Other</div>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Name (Optional)</label>
                <input type="text" id="customerName" class="form-input" placeholder="Enter customer name">
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Phone (Optional)</label>
                <input type="tel" id="customerPhone" class="form-input" placeholder="Enter phone number">
            </div>
            
            <div class="summary-row total" style="margin: 20px 0;">
                <span>Amount to Pay:</span>
                <span id="modalTotal">$0.00</span>
            </div>
            
            <button class="btn btn-checkout" onclick="completeSale()" style="width: 100%;">
                <i class="fas fa-check-circle"></i> Complete Sale
            </button>
        </div>
    </div>

    <script>
        // Cart state
        let cart = [];
        let allProducts = [];
        let selectedPaymentMethod = 'cash';
        const TAX_RATE = 0.00; // 0% tax
        
        // Load products on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadProducts();
            
            // Search functionality
            document.getElementById('searchInput').addEventListener('input', function(e) {
                filterProducts(e.target.value);
            });
            
            // Category tabs
            document.querySelectorAll('.category-tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    filterByCategory(this.dataset.category);
                });
            });
            
            // Payment method selection
            document.querySelectorAll('.payment-method').forEach(method => {
                method.addEventListener('click', function() {
                    document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('active'));
                    this.classList.add('active');
                    selectedPaymentMethod = this.dataset.method;
                });
            });
            
            // Keyboard shortcut for search
            document.addEventListener('keydown', function(e) {
                if (e.key === 'F2') {
                    e.preventDefault();
                    document.getElementById('searchInput').focus();
                }
                if (e.key === 'F9' && cart.length > 0) {
                    e.preventDefault();
                    openCheckoutModal();
                }
            });
        });
        
        // Load products from API
        async function loadProducts() {
            try {
                // Get store ID from URL parameter
                const urlParams = new URLSearchParams(window.location.search);
                const storeId = urlParams.get('store');
                
                const url = storeId ? `api/get_products.php?store_id=${storeId}` : 'api/get_products.php';
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    allProducts = data.products;
                    renderProducts(allProducts);
                    console.log(`Loaded ${data.count} products` + (data.store_id ? ` for store ${data.store_id}` : ''));
                } else {
                    console.error('Failed to load products:', data.message);
                    alert('Failed to load products. Please refresh the page.');
                }
            } catch (error) {
                console.error('Error loading products:', error);
                alert('Error loading products. Please check your connection.');
            }
        }
        
        // Render products grid
        function renderProducts(products) {
            const grid = document.getElementById('productsGrid');
            
            if (products.length === 0) {
                grid.innerHTML = '<div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #657786;">No products found</div>';
                return;
            }
            
            grid.innerHTML = products.map(product => {
                const stock = parseInt(product.quantity || 0);
                let stockClass = '';
                let stockText = `${stock} in stock`;
                
                if (stock === 0) {
                    stockClass = 'out';
                    stockText = 'Out';
                } else if (stock < 10) {
                    stockClass = 'low';
                }
                
                return `
                    <div class="product-card" onclick='addToCart(${JSON.stringify(product)})'>
                        <div class="product-stock ${stockClass}">${stockText}</div>
                        <div class="product-icon">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="product-name">${product.name}</div>
                        <div class="product-price">$${parseFloat(product.price).toFixed(2)}</div>
                    </div>
                `;
            }).join('');
        }
        
        // Filter products
        function filterProducts(searchTerm) {
            const filtered = allProducts.filter(product => {
                const searchString = `${product.name} ${product.sku} ${product.barcode || ''}`.toLowerCase();
                return searchString.includes(searchTerm.toLowerCase());
            });
            renderProducts(filtered);
        }
        
        // Filter by category
        function filterByCategory(category) {
            if (category === 'all') {
                renderProducts(allProducts);
            } else {
                const filtered = allProducts.filter(p => p.category === category);
                renderProducts(filtered);
            }
        }
        
        // Add product to cart
        function addToCart(product) {
            const existingItem = cart.find(item => item.id === product.id);
            
            if (existingItem) {
                if (existingItem.quantity < product.quantity) {
                    existingItem.quantity++;
                } else {
                    alert('Cannot add more. Stock limit reached.');
                    return;
                }
            } else {
                if (product.quantity > 0) {
                    cart.push({
                        ...product,
                        quantity: 1
                    });
                } else {
                    alert('Product out of stock');
                    return;
                }
            }
            
            renderCart();
            updateCartSummary();
        }
        
        // Update cart item quantity
        function updateQuantity(productId, change) {
            const item = cart.find(i => i.id === productId);
            if (!item) return;
            
            const newQuantity = item.quantity + change;
            
            if (newQuantity <= 0) {
                removeFromCart(productId);
            } else if (newQuantity <= item.quantity) {
                item.quantity = newQuantity;
            } else {
                alert('Cannot add more. Stock limit reached.');
                return;
            }
            
            renderCart();
            updateCartSummary();
        }
        
        // Remove item from cart
        function removeFromCart(productId) {
            cart = cart.filter(item => item.id !== productId);
            renderCart();
            updateCartSummary();
        }
        
        // Clear entire cart
        function clearCart() {
            if (cart.length === 0) return;
            
            if (confirm('Clear all items from cart?')) {
                cart = [];
                renderCart();
                updateCartSummary();
            }
        }
        
        // Render cart items
        function renderCart() {
            const cartContainer = document.getElementById('cartItems');
            const cartCount = document.getElementById('cartCount');
            
            cartCount.textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
            
            if (cart.length === 0) {
                cartContainer.innerHTML = `
                    <div class="cart-empty">
                        <i class="fas fa-shopping-cart"></i>
                        <p>Cart is empty<br>Add products to get started</p>
                    </div>
                `;
                return;
            }
            
            cartContainer.innerHTML = cart.map(item => `
                <div class="cart-item">
                    <div class="cart-item-info">
                        <div class="cart-item-name">${item.name}</div>
                        <div class="cart-item-price">$${parseFloat(item.price).toFixed(2)} each</div>
                    </div>
                    <div class="cart-item-controls">
                        <button class="qty-btn" onclick="updateQuantity(${item.id}, -1)">
                            <i class="fas fa-minus"></i>
                        </button>
                        <div class="qty-display">${item.quantity}</div>
                        <button class="qty-btn" onclick="updateQuantity(${item.id}, 1)">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button class="remove-btn" onclick="removeFromCart(${item.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `).join('');
        }
        
        // Update cart summary
        function updateCartSummary() {
            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
            const tax = subtotal * TAX_RATE;
            const total = subtotal + tax;
            
            document.getElementById('subtotal').textContent = `$${subtotal.toFixed(2)}`;
            document.getElementById('tax').textContent = `$${tax.toFixed(2)}`;
            document.getElementById('total').textContent = `$${total.toFixed(2)}`;
            document.getElementById('modalTotal').textContent = `$${total.toFixed(2)}`;
            
            document.getElementById('checkoutBtn').disabled = cart.length === 0;
        }
        
        // Open checkout modal
        function openCheckoutModal() {
            if (cart.length === 0) return;
            document.getElementById('checkoutModal').classList.add('active');
            document.getElementById('customerName').focus();
        }
        
        // Close checkout modal
        function closeCheckoutModal() {
            document.getElementById('checkoutModal').classList.remove('active');
        }
        
        // Complete sale
        async function completeSale() {
            if (cart.length === 0) return;
            
            // Get store ID from URL
            const urlParams = new URLSearchParams(window.location.search);
            const storeId = urlParams.get('store');
            
            if (!storeId) {
                alert('Please select a store first!');
                return;
            }
            
            const customerName = document.getElementById('customerName').value || null;
            const customerPhone = document.getElementById('customerPhone').value || null;
            
            const saleData = {
                items: cart.map(item => ({
                    product_id: item.id,
                    quantity: item.quantity,
                    price: item.price
                })),
                payment_method: selectedPaymentMethod,
                customer_name: customerName,
                customer_phone: customerPhone,
                store_id: storeId,
                user_id: <?php echo $userId; ?>
            };
            
            try {
                const response = await fetch('api/complete_sale.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(saleData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`Sale completed! Transaction ID: ${result.transaction_id}`);
                    
                    // Clear cart
                    cart = [];
                    renderCart();
                    updateCartSummary();
                    closeCheckoutModal();
                    
                    // Reset form
                    document.getElementById('customerName').value = '';
                    document.getElementById('customerPhone').value = '';
                    
                    // Reload products to update stock
                    loadProducts();
                } else {
                    alert('Error completing sale: ' + result.message);
                }
            } catch (error) {
                console.error('Error completing sale:', error);
                alert('Error completing sale. Please try again.');
            }
        }
    </script>
</body>
</html>
