<?php
/**
 * Full Retail POS - For Traditional Retail Stores
 * Optimized for: Clothing, electronics, general merchandise
 * Features: Advanced search, discounts, customer management, detailed receipts
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

// Check if Firebase ID is passed (from store list POS button)
$firebaseStoreId = $_GET['store_firebase_id'] ?? null;
$selectedStoreId = null;

// If Firebase ID is provided, find the corresponding SQL store ID
if ($firebaseStoreId) {
    try {
        $sqlStore = $db->fetch("SELECT id, name FROM stores WHERE firebase_id = ?", [$firebaseStoreId]);
        if ($sqlStore) {
            $selectedStoreId = $sqlStore['id'];
            $_SESSION['pos_store_id'] = $selectedStoreId;
        }
    } catch (Exception $e) {
        error_log("Error finding store by Firebase ID: " . $e->getMessage());
    }
}

// If no Firebase ID or not found, try regular store parameter or session
if (!$selectedStoreId) {
    $selectedStoreId = $_GET['store'] ?? $_SESSION['pos_store_id'] ?? null;
}

// Get list of stores user has access to
$userStores = [];
try {
    $userStores = $db->fetchAll("
        SELECT s.* FROM stores s
        LEFT JOIN user_stores us ON s.id = us.store_id
        WHERE (s.active = 1 OR s.active IS NULL)
        AND (us.user_id = ? OR ? IN (SELECT id FROM users WHERE role = 'admin'))
        ORDER BY s.name
    ", [$userId, $userId]);
} catch (Exception $e) {
    // Fallback: get all stores if user_stores table doesn't exist
    try {
        $userStores = $db->fetchAll("SELECT * FROM stores WHERE active = 1 OR active IS NULL ORDER BY name");
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

// Set store name for page title
$storeName = $currentStore ? $currentStore['name'] : 'Select Store';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Full Retail POS - <?php echo htmlspecialchars($storeName); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #ecf0f1;
            overflow: hidden;
        }
        
        .pos-container {
            display: grid;
            grid-template-columns: 1fr 450px;
            grid-template-rows: 60px 1fr;
            height: 100vh;
            gap: 0;
        }
        
        /* Top Header */
        .pos-header {
            grid-column: 1 / -1;
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 0 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 15px rgba(0,0,0,0.2);
            z-index: 100;
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .header-left h1 {
            font-size: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .store-badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .time-display {
            font-size: 14px;
            background: rgba(255,255,255,0.1);
            padding: 8px 15px;
            border-radius: 8px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        
        .btn-home {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-home:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Left Panel - Products */
        .products-panel {
            background: #ffffff;
            display: flex;
            flex-direction: column;
            border-right: 2px solid #bdc3c7;
        }
        
        .search-section {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 2px solid #e1e8ed;
        }
        
        .search-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .search-input-wrapper {
            position: relative;
        }
        
        .search-input {
            width: 100%;
            padding: 14px 50px;
            border: 2px solid #bdc3c7;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .search-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #7f8c8d;
            font-size: 18px;
        }
        
        .barcode-btn {
            padding: 14px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            white-space: nowrap;
        }
        
        .barcode-btn:hover {
            background: #2980b9;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .filter-select {
            padding: 10px 12px;
            border: 2px solid #bdc3c7;
            border-radius: 8px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .products-list {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
        }
        
        .product-item {
            background: white;
            border: 2px solid #ecf0f1;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: grid;
            grid-template-columns: 60px 1fr auto;
            gap: 15px;
            align-items: center;
        }
        
        .product-item:hover {
            border-color: #3498db;
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.15);
        }
        
        .product-thumbnail {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #3498db, #2980b9);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .product-details {
            flex: 1;
        }
        
        .product-name {
            font-weight: 600;
            font-size: 15px;
            color: #2c3e50;
            margin-bottom: 4px;
        }
        
        .product-sku {
            font-size: 12px;
            color: #7f8c8d;
            margin-bottom: 4px;
        }
        
        .product-stock-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .stock-good {
            background: #d4edda;
            color: #155724;
        }
        
        .stock-low {
            background: #fff3cd;
            color: #856404;
        }
        
        .stock-out {
            background: #f8d7da;
            color: #721c24;
        }
        
        .product-price-tag {
            font-size: 20px;
            font-weight: 700;
            color: #27ae60;
        }
        
        /* Right Panel - Cart */
        .cart-panel {
            background: #f8f9fa;
            display: flex;
            flex-direction: column;
        }
        
        .cart-header {
            background: #34495e;
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
            background: #e74c3c;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
        }
        
        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
        }
        
        .cart-item {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .cart-item-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }
        
        .cart-item-name {
            font-weight: 600;
            font-size: 14px;
            color: #2c3e50;
            flex: 1;
            margin-right: 10px;
        }
        
        .cart-item-remove {
            background: #e74c3c;
            border: none;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        
        .cart-item-remove:hover {
            background: #c0392b;
            transform: rotate(90deg);
        }
        
        .cart-item-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .qty-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #ecf0f1;
            padding: 5px 10px;
            border-radius: 8px;
        }
        
        .qty-btn {
            width: 28px;
            height: 28px;
            border: none;
            background: #3498db;
            color: white;
            border-radius: 50%;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .qty-btn:hover {
            background: #2980b9;
            transform: scale(1.1);
        }
        
        .qty-display {
            min-width: 40px;
            text-align: center;
            font-weight: 700;
            font-size: 16px;
        }
        
        .cart-item-total {
            font-weight: 700;
            font-size: 16px;
            color: #27ae60;
        }
        
        .discount-section {
            background: white;
            padding: 15px 20px;
            border-top: 2px solid #e1e8ed;
            border-bottom: 2px solid #e1e8ed;
        }
        
        .discount-row {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: center;
        }
        
        .discount-input {
            padding: 10px;
            border: 2px solid #bdc3c7;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .discount-btn {
            padding: 10px 20px;
            background: #f39c12;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .discount-btn:hover {
            background: #e67e22;
        }
        
        .cart-summary {
            background: white;
            padding: 20px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 15px;
        }
        
        .summary-row.discount {
            color: #e74c3c;
            font-weight: 600;
        }
        
        .summary-row.total {
            font-size: 24px;
            font-weight: 700;
            color: #27ae60;
            padding-top: 15px;
            border-top: 2px solid #ecf0f1;
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
            background: #95a5a6;
            color: white;
        }
        
        .btn-clear:hover {
            background: #7f8c8d;
        }
        
        .btn-hold {
            background: #f39c12;
            color: white;
        }
        
        .btn-hold:hover {
            background: #e67e22;
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
        
        .cart-empty {
            text-align: center;
            padding: 80px 20px;
            color: #7f8c8d;
        }
        
        .cart-empty i {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.75);
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
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .modal-header h2 {
            font-size: 24px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            color: #7f8c8d;
            cursor: pointer;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        
        .close-modal:hover {
            background: #ecf0f1;
            color: #2c3e50;
        }
        
        .form-section {
            margin-bottom: 25px;
        }
        
        .form-section-title {
            font-weight: 600;
            font-size: 16px;
            color: #34495e;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #bdc3c7;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        
        .payment-method {
            padding: 18px;
            border: 2px solid #bdc3c7;
            border-radius: 10px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }
        
        .payment-method:hover {
            border-color: #3498db;
        }
        
        .payment-method.active {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            border-color: #3498db;
        }
        
        .payment-method i {
            font-size: 28px;
            margin-bottom: 8px;
            display: block;
        }
        
        .payment-method-name {
            font-weight: 600;
            font-size: 13px;
        }
        
        .receipt-preview {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            border: 2px dashed #bdc3c7;
        }
        
        .receipt-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px dashed #bdc3c7;
        }
        
        .receipt-items {
            margin-bottom: 15px;
        }
        
        .receipt-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        
        .receipt-total {
            border-top: 2px solid #2c3e50;
            padding-top: 10px;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            font-size: 18px;
            font-weight: 700;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: #ecf0f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #95a5a6;
            border-radius: 5px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #7f8c8d;
        }
    </style>
</head>
<body>
    <?php if (!$selectedStoreId): ?>
    <!-- Store Selection Modal -->
    <div id="storeSelector" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.8); z-index: 9999; display: flex; align-items: center; justify-content: center;">
        <div style="background: white; padding: 40px; border-radius: 16px; max-width: 600px; width: 90%;">
            <h2 style="margin: 0 0 10px 0; color: #2c3e50; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-store"></i> Select Store
            </h2>
            <p style="color: #7f8c8d; margin: 0 0 30px 0;">Choose which store you want to operate the POS for</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px;">
                <?php foreach ($userStores as $store): ?>
                <a href="?store=<?php echo $store['id']; ?>" style="text-decoration: none; color: inherit;">
                    <div style="padding: 20px; border: 2px solid #e1e8ed; border-radius: 12px; transition: all 0.3s; cursor: pointer; <?php echo ($selectedStoreId == $store['id']) ? 'background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%); color: white; border-color: #2c3e50;' : ''; ?>">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                            <div style="width: 50px; height: 50px; background: <?php echo ($selectedStoreId == $store['id']) ? 'rgba(255,255,255,0.2)' : 'linear-gradient(135deg, #2c3e50 0%, #34495e 100%)'; ?>; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-store" style="font-size: 24px; color: white;"></i>
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
                <button onclick="document.getElementById('storeSelector').style.display='none'" style="padding: 12px 30px; background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%); color: white; border: none; border-radius: 8px; font-size: 16px; cursor: pointer;">
                    Continue with <?php echo htmlspecialchars($currentStore['name'] ?? 'Selected Store'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="pos-container">
        <!-- Top Header -->
        <div class="pos-header">
            <div class="header-left">
                <h1><i class="fas fa-cash-register"></i> Retail POS System</h1>
                <div class="store-badge">
                    <i class="fas fa-store"></i>
                    <?php echo htmlspecialchars($currentStore['name'] ?? 'No Store Selected'); ?>
                </div>
            </div>
            <div class="header-right">
                <div class="time-display" id="timeDisplay">
                    <i class="fas fa-clock"></i> <span id="currentTime"></span>
                </div>
                <div class="user-info">
                    <i class="fas fa-user-circle"></i>
                    <?php echo htmlspecialchars($userName); ?>
                </div>
                <?php if (count($userStores) > 1): ?>
                <button class="btn-home" onclick="document.getElementById('storeSelector').style.display='flex'" title="Switch Store" style="margin-right: 10px;">
                    <i class="fas fa-exchange-alt"></i>
                </button>
                <?php endif; ?>
                <button class="btn-home" onclick="window.location.href='../../index.php'">
                    <i class="fas fa-home"></i> Dashboard
                </button>
            </div>
        </div>
        
        <!-- Left Panel - Products -->
        <div class="products-panel">
            <div class="search-section">
                <div class="search-row">
                    <div class="search-input-wrapper">
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" id="searchInput" class="search-input" placeholder="Search by name, SKU, or barcode...">
                    </div>
                    <button class="barcode-btn" onclick="focusSearch()">
                        <i class="fas fa-barcode"></i> Scan
                    </button>
                </div>
                <div class="filter-row">
                    <select class="filter-select" id="categoryFilter" onchange="filterProducts()">
                        <option value="">All Categories</option>
                    </select>
                    <select class="filter-select" id="stockFilter" onchange="filterProducts()">
                        <option value="">All Stock Levels</option>
                        <option value="in-stock">In Stock</option>
                        <option value="low-stock">Low Stock</option>
                        <option value="out-of-stock">Out of Stock</option>
                    </select>
                </div>
            </div>
            
            <div class="products-list" id="productsList">
                <!-- Products will be loaded here -->
            </div>
        </div>
        
        <!-- Right Panel - Cart -->
        <div class="cart-panel">
            <div class="cart-header">
                <h2><i class="fas fa-shopping-cart"></i> Shopping Cart</h2>
                <span class="cart-count" id="cartCount">0</span>
            </div>
            
            <div class="cart-items" id="cartItems">
                <div class="cart-empty">
                    <i class="fas fa-shopping-cart"></i>
                    <p><strong>Cart is Empty</strong><br>Scan or select products to add</p>
                </div>
            </div>
            
            <div class="discount-section">
                <div class="discount-row">
                    <input type="number" class="discount-input" id="discountInput" placeholder="Discount %" min="0" max="100">
                    <button class="discount-btn" onclick="applyDiscount()">
                        <i class="fas fa-percent"></i> Apply
                    </button>
                </div>
            </div>
            
            <div class="cart-summary">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span id="subtotal">$0.00</span>
                </div>
                <div class="summary-row discount" id="discountRow" style="display: none;">
                    <span>Discount (<span id="discountPercent">0</span>%):</span>
                    <span id="discountAmount">-$0.00</span>
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
                    <button class="btn btn-hold" onclick="holdTransaction()">
                        <i class="fas fa-pause-circle"></i> Hold
                    </button>
                    <button class="btn btn-checkout" id="checkoutBtn" onclick="openCheckoutModal()" disabled>
                        <i class="fas fa-check-circle"></i> Proceed to Payment
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Checkout Modal -->
    <div class="modal" id="checkoutModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-cash-register"></i> Complete Transaction</h2>
                <button class="close-modal" onclick="closeCheckoutModal()">×</button>
            </div>
            
            <div class="form-section">
                <div class="form-section-title">
                    <i class="fas fa-credit-card"></i> Payment Method
                </div>
                <div class="payment-methods">
                    <div class="payment-method active" data-method="cash">
                        <i class="fas fa-money-bill-wave"></i>
                        <div class="payment-method-name">Cash</div>
                    </div>
                    <div class="payment-method" data-method="card">
                        <i class="fas fa-credit-card"></i>
                        <div class="payment-method-name">Credit/Debit Card</div>
                    </div>
                    <div class="payment-method" data-method="digital">
                        <i class="fas fa-mobile-alt"></i>
                        <div class="payment-method-name">Digital Wallet</div>
                    </div>
                    <div class="payment-method" data-method="check">
                        <i class="fas fa-money-check"></i>
                        <div class="payment-method-name">Check</div>
                    </div>
                </div>
            </div>
            
            <div class="form-section">
                <div class="form-section-title">
                    <i class="fas fa-user"></i> Customer Information (Optional)
                </div>
                <div class="form-group">
                    <label class="form-label">Customer Name</label>
                    <input type="text" id="customerName" class="form-input" placeholder="Enter customer name">
                </div>
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" id="customerPhone" class="form-input" placeholder="Enter phone number">
                </div>
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" id="customerEmail" class="form-input" placeholder="Enter email address">
                </div>
            </div>
            
            <div class="receipt-preview">
                <div class="receipt-header">
                    <h3>Transaction Summary</h3>
                    <div style="font-size: 12px; color: #7f8c8d;">Transaction will be recorded</div>
                </div>
                <div class="receipt-items" id="receiptItems"></div>
                <div class="receipt-total">
                    <span>Amount to Charge:</span>
                    <span id="modalTotal">$0.00</span>
                </div>
            </div>
            
            <button class="btn btn-checkout" onclick="completeSale()" style="width: 100%; font-size: 16px; padding: 18px;">
                <i class="fas fa-check-circle"></i> Complete & Print Receipt
            </button>
        </div>
    </div>

    <script>
        // Global state
        let cart = [];
        let allProducts = [];
        let selectedPaymentMethod = 'cash';
        let discountPercent = 0;
        const TAX_RATE = 0.00;
        
        // Update time display
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString();
            const dateString = now.toLocaleDateString();
            document.getElementById('currentTime').textContent = `${dateString} ${timeString}`;
        }
        setInterval(updateTime, 1000);
        updateTime();
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadProducts();
            
            // Search
            document.getElementById('searchInput').addEventListener('input', function(e) {
                filterProducts();
            });
            
            // Payment method selection
            document.querySelectorAll('.payment-method').forEach(method => {
                method.addEventListener('click', function() {
                    document.querySelectorAll('.payment-method').forEach(m => m.classList.remove('active'));
                    this.classList.add('active');
                    selectedPaymentMethod = this.dataset.method;
                });
            });
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.key === 'F2') {
                    e.preventDefault();
                    focusSearch();
                }
                if (e.key === 'F9' && cart.length > 0) {
                    e.preventDefault();
                    openCheckoutModal();
                }
                if (e.key === 'Escape') {
                    closeCheckoutModal();
                }
            });
        });
        
        function focusSearch() {
            document.getElementById('searchInput').focus();
            document.getElementById('searchInput').select();
        }
        
        // Load products
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
                    populateCategoryFilter();
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
        
        function populateCategoryFilter() {
            const categories = [...new Set(allProducts.map(p => p.category).filter(Boolean))];
            const select = document.getElementById('categoryFilter');
            categories.forEach(cat => {
                const option = document.createElement('option');
                option.value = cat;
                option.textContent = cat;
                select.appendChild(option);
            });
        }
        
        function filterProducts() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            const category = document.getElementById('categoryFilter').value;
            const stockLevel = document.getElementById('stockFilter').value;
            
            let filtered = allProducts.filter(product => {
                // Search filter
                if (searchTerm) {
                    const searchString = `${product.name} ${product.sku} ${product.barcode || ''}`.toLowerCase();
                    if (!searchString.includes(searchTerm)) return false;
                }
                
                // Category filter
                if (category && product.category !== category) return false;
                
                // Stock level filter
                if (stockLevel) {
                    if (stockLevel === 'in-stock' && product.quantity <= product.reorder_level) return false;
                    if (stockLevel === 'low-stock' && (product.quantity > product.reorder_level || product.quantity === 0)) return false;
                    if (stockLevel === 'out-of-stock' && product.quantity > 0) return false;
                }
                
                return true;
            });
            
            renderProducts(filtered);
        }
        
        function renderProducts(products) {
            const list = document.getElementById('productsList');
            
            if (products.length === 0) {
                list.innerHTML = '<div style="text-align: center; padding: 40px; color: #7f8c8d;"><i class="fas fa-box-open" style="font-size: 48px; margin-bottom: 10px; opacity: 0.3;"></i><br>No products found</div>';
                return;
            }
            
            list.innerHTML = products.map(product => {
                const stock = parseInt(product.quantity);
                let stockClass, stockText;
                
                if (stock === 0) {
                    stockClass = 'stock-out';
                    stockText = 'Out of Stock';
                } else if (stock <= product.reorder_level) {
                    stockClass = 'stock-low';
                    stockText = `Low: ${stock} left`;
                } else {
                    stockClass = 'stock-good';
                    stockText = `${stock} in stock`;
                }
                
                return `
                    <div class="product-item" onclick='addToCart(${JSON.stringify(product).replace(/'/g, "&apos;")})'>
                        <div class="product-thumbnail">
                            <i class="fas fa-box"></i>
                        </div>
                        <div class="product-details">
                            <div class="product-name">${product.name}</div>
                            <div class="product-sku">SKU: ${product.sku}</div>
                            <span class="product-stock-badge ${stockClass}">${stockText}</span>
                        </div>
                        <div class="product-price-tag">$${parseFloat(product.price).toFixed(2)}</div>
                    </div>
                `;
            }).join('');
        }
        
        function addToCart(product) {
            const existing = cart.find(item => item.id === product.id);
            
            if (existing) {
                if (existing.quantity < product.quantity) {
                    existing.quantity++;
                } else {
                    alert('Cannot add more. Stock limit reached.');
                    return;
                }
            } else {
                if (product.quantity > 0) {
                    cart.push({ ...product, cartQuantity: 1 });
                } else {
                    alert('Product out of stock');
                    return;
                }
            }
            
            renderCart();
            updateCartSummary();
        }
        
        function updateCartQuantity(productId, change) {
            const item = cart.find(i => i.id === productId);
            if (!item) return;
            
            const newQty = item.cartQuantity + change;
            
            if (newQty <= 0) {
                removeFromCart(productId);
            } else if (newQty <= item.quantity) {
                item.cartQuantity = newQty;
            } else {
                alert('Cannot add more. Stock limit reached.');
                return;
            }
            
            renderCart();
            updateCartSummary();
        }
        
        function removeFromCart(productId) {
            cart = cart.filter(item => item.id !== productId);
            renderCart();
            updateCartSummary();
        }
        
        function clearCart() {
            if (cart.length === 0) return;
            if (confirm('Clear all items from cart?')) {
                cart = [];
                discountPercent = 0;
                document.getElementById('discountInput').value = '';
                renderCart();
                updateCartSummary();
            }
        }
        
        function renderCart() {
            const container = document.getElementById('cartItems');
            const count = document.getElementById('cartCount');
            
            const totalItems = cart.reduce((sum, item) => sum + item.cartQuantity, 0);
            count.textContent = totalItems;
            
            if (cart.length === 0) {
                container.innerHTML = `
                    <div class="cart-empty">
                        <i class="fas fa-shopping-cart"></i>
                        <p><strong>Cart is Empty</strong><br>Scan or select products to add</p>
                    </div>
                `;
                return;
            }
            
            container.innerHTML = cart.map(item => `
                <div class="cart-item">
                    <div class="cart-item-header">
                        <div class="cart-item-name">${item.name}</div>
                        <button class="cart-item-remove" onclick="removeFromCart(${item.id})">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="cart-item-controls">
                        <div class="qty-controls">
                            <button class="qty-btn" onclick="updateCartQuantity(${item.id}, -1)">
                                <i class="fas fa-minus"></i>
                            </button>
                            <div class="qty-display">${item.cartQuantity}</div>
                            <button class="qty-btn" onclick="updateCartQuantity(${item.id}, 1)">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div class="cart-item-total">$${(item.price * item.cartQuantity).toFixed(2)}</div>
                    </div>
                </div>
            `).join('');
        }
        
        function applyDiscount() {
            const input = document.getElementById('discountInput');
            const value = parseFloat(input.value) || 0;
            
            if (value < 0 || value > 100) {
                alert('Discount must be between 0 and 100%');
                return;
            }
            
            discountPercent = value;
            updateCartSummary();
        }
        
        function updateCartSummary() {
            const subtotal = cart.reduce((sum, item) => sum + (item.price * item.cartQuantity), 0);
            const discountAmount = subtotal * (discountPercent / 100);
            const afterDiscount = subtotal - discountAmount;
            const tax = afterDiscount * TAX_RATE;
            const total = afterDiscount + tax;
            
            document.getElementById('subtotal').textContent = `$${subtotal.toFixed(2)}`;
            document.getElementById('tax').textContent = `$${tax.toFixed(2)}`;
            document.getElementById('total').textContent = `$${total.toFixed(2)}`;
            document.getElementById('modalTotal').textContent = `$${total.toFixed(2)}`;
            
            const discountRow = document.getElementById('discountRow');
            if (discountPercent > 0) {
                discountRow.style.display = 'flex';
                document.getElementById('discountPercent').textContent = discountPercent;
                document.getElementById('discountAmount').textContent = `-$${discountAmount.toFixed(2)}`;
            } else {
                discountRow.style.display = 'none';
            }
            
            document.getElementById('checkoutBtn').disabled = cart.length === 0;
            
            // Update receipt preview
            updateReceiptPreview();
        }
        
        function updateReceiptPreview() {
            const container = document.getElementById('receiptItems');
            container.innerHTML = cart.map(item => `
                <div class="receipt-item">
                    <span>${item.name} x${item.cartQuantity}</span>
                    <span>$${(item.price * item.cartQuantity).toFixed(2)}</span>
                </div>
            `).join('');
        }
        
        function holdTransaction() {
            if (cart.length === 0) return;
            alert('Transaction held. (Feature to be implemented)');
        }
        
        function openCheckoutModal() {
            if (cart.length === 0) return;
            updateReceiptPreview();
            document.getElementById('checkoutModal').classList.add('active');
        }
        
        function closeCheckoutModal() {
            document.getElementById('checkoutModal').classList.remove('active');
        }
        
        async function completeSale() {
            if (cart.length === 0) return;
            
            // Get store ID from URL
            const urlParams = new URLSearchParams(window.location.search);
            const storeId = urlParams.get('store');
            
            if (!storeId) {
                alert('Please select a store first!');
                return;
            }
            
            const saleData = {
                items: cart.map(item => ({
                    product_id: item.id,
                    quantity: item.cartQuantity,
                    price: item.price
                })),
                payment_method: selectedPaymentMethod,
                customer_name: document.getElementById('customerName').value || null,
                customer_phone: document.getElementById('customerPhone').value || null,
                customer_email: document.getElementById('customerEmail').value || null,
                discount_percent: discountPercent,
                store_id: storeId,
                user_id: <?php echo json_encode($userId); ?>
            };
            
            try {
                const response = await fetch('api/complete_sale.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(saleData)
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`✅ Sale Completed!\n\nTransaction ID: ${result.transaction_id}\nTotal: $${result.total}`);
                    
                    // Reset
                    cart = [];
                    discountPercent = 0;
                    document.getElementById('discountInput').value = '';
                    document.getElementById('customerName').value = '';
                    document.getElementById('customerPhone').value = '';
                    document.getElementById('customerEmail').value = '';
                    
                    renderCart();
                    updateCartSummary();
                    closeCheckoutModal();
                    loadProducts();
                } else {
                    alert('❌ Error: ' + result.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Error completing sale');
            }
        }
    </script>
</body>
</html>
