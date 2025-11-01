<?php
/**
 * Stock-POS Integration Dashboard
 * Shows real-time synchronization between stock and POS systems
 * Manage POS enable/disable per store
 * OPTIMIZED: PostgreSQL-first with fast queries
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../sql_db.php';
require_once __DIR__ . '/../../functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

// Check permission
if (!currentUserHasPermission('can_view_inventory') && !currentUserHasPermission('can_manage_stores')) {
    $_SESSION['error'] = 'You do not have permission to access this page';
    header('Location: ../../index.php');
    exit;
}

$messages = [];

// Handle batch add products to store (NEW ARCHITECTURE: Assign main products to stores)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_add_products'])) {
    $target_store_id = $_POST['target_store_id'] ?? '';
    $product_ids = $_POST['product_ids'] ?? [];
    $quantities = $_POST['quantities'] ?? []; // NEW: Get quantities for each product
    
    if (empty($target_store_id) || empty($product_ids)) {
        $messages[] = ['type' => 'error', 'text' => 'Please select a store and at least one product'];
    } else {
        try {
            $added_count = 0;
            $skipped_count = 0;
            $skip_reasons = [];
            $sqlDb = SQLDatabase::getInstance();
            
            error_log("===== BATCH ASSIGN PRODUCTS TO STORE =====");
            error_log("Target Store ID: $target_store_id");
            error_log("Product IDs to assign: " . json_encode($product_ids));
            
            foreach ($product_ids as $idx => $product_id) {
                error_log("\n--- Processing Main Product ID: $product_id ---");
                $mainProduct = null;
                $assignQty = isset($quantities[$idx]) ? (int)$quantities[$idx] : 0;
                
                // Load MAIN product (store_id = NULL)
                try {
                    $mainProduct = $sqlDb->fetch("SELECT * FROM products WHERE id = ? AND store_id IS NULL LIMIT 1", [$product_id]);
                    if ($mainProduct) {
                        error_log("✓ Found main product: {$mainProduct['name']} (SKU: {$mainProduct['sku']}, Qty: {$mainProduct['quantity']})");
                    } else {
                        error_log("✗ Product ID $product_id not found or is not a main product");
                    }
                } catch (Exception $e) {
                    error_log("✗ SQL product fetch error: " . $e->getMessage());
                }
                
                if (!$mainProduct) {
                    $skipped_count++;
                    $skip_reasons[] = "Product ID '$product_id': Not found or not a main product";
                    continue;
                }
                
                $sku = $mainProduct['sku'] ?? '';
                $productName = $mainProduct['name'] ?? 'Unknown';
                $mainQty = (int)($mainProduct['quantity'] ?? 0);
                
                if (empty($sku)) {
                    $skipped_count++;
                    $skip_reasons[] = "Product '$productName': Missing SKU";
                    continue;
                }
                
                // Validate quantity
                if ($assignQty <= 0) {
                    $assignQty = $mainQty; // Assign all if not specified
                }
                
                if ($assignQty > $mainQty) {
                    $skipped_count++;
                    $skip_reasons[] = "Product '$productName': Cannot assign $assignQty units (only $mainQty available)";
                    continue;
                }
                
                // Create store variant SKU
                $variantSku = $sku . '-S' . $target_store_id;
                error_log("Creating store variant with SKU: $variantSku");
                
                // Check if variant already exists
                $existing = $sqlDb->fetch("SELECT id FROM products WHERE sku = ? AND active = TRUE LIMIT 1", [$variantSku]);
                if ($existing) {
                    error_log("✗ Store variant already exists: $variantSku");
                    $skipped_count++;
                    $skip_reasons[] = "Product '$productName': Already assigned to this store";
                    continue;
                }
                
                // NEW ARCHITECTURE: Create store variant and update main product
                try {
                    $sqlDb->execute("BEGIN TRANSACTION");
                        
                        // 1. Create store variant
                        $query = "INSERT INTO products (
                            name, sku, barcode, description, category, unit,
                            cost_price, selling_price, price, quantity, reorder_level,
                            store_id, active, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))";
                        
                        $params = [
                            $mainProduct['name'],
                            $variantSku,
                            $mainProduct['barcode'],
                            $mainProduct['description'],
                            $mainProduct['category'],
                            $mainProduct['unit'] ?? 'pcs',
                            $mainProduct['cost_price'] ?? 0,
                            $mainProduct['price'],
                            $mainProduct['price'],
                            $assignQty,
                            $mainProduct['reorder_level'] ?? 10,
                            $target_store_id,
                            1
                        ];
                        
                        $sqlDb->execute($query, $params);
                        $variant_id = $sqlDb->lastInsertId();
                        error_log("✓ Store variant created with ID: $variant_id");
                        
                        // 2. Update main product quantity (subtract assigned quantity)
                        $newMainQty = $mainQty - $assignQty;
                        $sqlDb->execute(
                            "UPDATE products SET quantity = ?, updated_at = datetime('now') WHERE id = ?",
                            [$newMainQty, $product_id]
                        );
                        error_log("✓ Main product quantity updated: $mainQty → $newMainQty");
                        
                        // 3. Log stock movements
                        // Movement OUT from main product
                        $sqlDb->execute("
                            INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                            VALUES (?, NULL, 'out', ?, 'Store Assignment', ?, ?, datetime('now'))
                        ", [
                            $product_id,
                            $assignQty,
                            "Assigned to store (Variant: $variantSku)",
                            $_SESSION['user_id'] ?? null
                        ]);
                        
                        // Movement IN to store variant
                        $sqlDb->execute("
                            INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                            VALUES (?, ?, 'in', ?, 'Store Assignment', ?, ?, datetime('now'))
                        ", [
                            $variant_id,
                            $target_store_id,
                            $assignQty,
                            "Assigned from main product (ID: $product_id)",
                            $_SESSION['user_id'] ?? null
                        ]);
                        
                        $sqlDb->execute("COMMIT");
                        error_log("✓ Transaction committed successfully");
                        
                        // 4. Sync to Firebase (best effort)
                        try {
                            // Create store variant in Firebase
                            $variantDoc = [
                                'name' => $mainProduct['name'],
                                'sku' => $variantSku,
                                'barcode' => $mainProduct['barcode'],
                                'description' => $mainProduct['description'],
                                'category' => $mainProduct['category'],
                                'unit' => $mainProduct['unit'] ?? 'pcs',
                                'cost_price' => floatval($mainProduct['cost_price'] ?? 0),
                                'selling_price' => floatval($mainProduct['price'] ?? 0),
                                'price' => floatval($mainProduct['price'] ?? 0),
                                'quantity' => $assignQty,
                                'reorder_level' => intval($mainProduct['reorder_level'] ?? 0),
                                'store_id' => $target_store_id,
                                'active' => 1,
                                'created_at' => date('c'),
                                'updated_at' => date('c'),
                            ];
                            $db->create('products', $variantDoc, (string)$variant_id);
                            
                            // Update main product in Firebase
                            $db->update('products', (string)$product_id, [
                                'quantity' => $newMainQty,
                                'updated_at' => date('c')
                            ]);
                            
                            error_log("✓ Firebase sync successful");
                        } catch (Exception $e) {
                            error_log("⚠️ Firebase sync failed: " . $e->getMessage());
                        }
                        
                        $added_count++;
                        error_log("✅ SUCCESS: $productName assigned to store ($assignQty units)");
                        
                    } catch (Exception $e) {
                        $sqlDb->execute("ROLLBACK");
                        $skipped_count++;
                        $skip_reasons[] = "Product '$productName': Database error - " . $e->getMessage();
                        error_log("❌ FAILED: " . $e->getMessage());
                    }
            }
            
            error_log("\n===== BATCH ASSIGN COMPLETE =====");
            error_log("Total Assigned: $added_count");
            error_log("Total Skipped: $skipped_count");
            
            // Clear cache
            $cacheFile = __DIR__ . '/../../storage/cache/stock_list_data.cache';
            if (file_exists($cacheFile)) {
                @unlink($cacheFile);
            }
            
            if ($added_count > 0) {
                $detailMsg = "Successfully assigned {$added_count} product(s) to store.";
                if ($skipped_count > 0) {
                    $detailMsg .= " {$skipped_count} skipped:<br>" . implode('<br>', array_map(function($r) {
                        return "• " . htmlspecialchars($r);
                    }, $skip_reasons));
                }
                $messages[] = ['type' => 'success', 'text' => $detailMsg];
            } else {
                $detailMsg = "No products were assigned. {$skipped_count} skipped:<br>" . 
                            implode('<br>', array_map(function($r) {
                                return "• " . htmlspecialchars($r);
                            }, $skip_reasons));
                $messages[] = ['type' => 'error', 'text' => $detailMsg];
            }
        } catch (Exception $e) {
            $messages[] = ['type' => 'error', 'text' => 'Error assigning products: ' . $e->getMessage()];
        }
    }
}

// Handle POS toggle request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_pos'])) {
    $store_id = $_POST['store_id'] ?? '';
    $enable_pos = isset($_POST['enable_pos']) && $_POST['enable_pos'] == '1';
    
    try {
        $sqlDb = SQLDatabase::getInstance();
        $updateData = [
            'has_pos' => $enable_pos ? 1 : 0,
            'pos_enabled_at' => $enable_pos ? 'NOW()' : null,
            'updated_at' => 'NOW()'
        ];
        
        $sql = "UPDATE stores SET has_pos = ?, updated_at = NOW()";
        $params = [$enable_pos ? 1 : 0];
        
        if ($enable_pos) {
            $sql .= ", pos_enabled_at = NOW()";
            if (!empty($_POST['pos_terminal_id'])) {
                $sql .= ", pos_terminal_id = ?";
                $params[] = trim($_POST['pos_terminal_id']);
            }
        } else {
            $sql .= ", pos_enabled_at = NULL";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $store_id;
        
        $result = $sqlDb->execute($sql, $params);
        
        if ($result) {
            $messages[] = [
                'type' => 'success',
                'text' => $enable_pos ? 'POS enabled successfully!' : 'POS disabled successfully!'
            ];
        } else {
            $messages[] = ['type' => 'error', 'text' => 'Failed to update POS status'];
        }
    } catch (Exception $e) {
        $messages[] = ['type' => 'error', 'text' => 'Error: ' . $e->getMessage()];
    }
}

// OPTIMIZED: Get all stores from PostgreSQL (fast query)
$allStores = [];
try {
    $sqlDb = SQLDatabase::getInstance();
    $storeRows = $sqlDb->fetchAll("SELECT * FROM stores WHERE active = TRUE ORDER BY name ASC");
    foreach ($storeRows as $s) {
        $allStores[] = [
            'id' => $s['id'] ?? '',
            'name' => $s['name'] ?? '',
            'has_pos' => isset($s['has_pos']) ? intval($s['has_pos']) : 0,
            'pos_terminal_id' => $s['pos_terminal_id'] ?? '',
            'pos_type' => $s['pos_type'] ?? 'quick_service',
            'pos_enabled_at' => $s['pos_enabled_at'] ?? null
        ];
    }
} catch (Exception $e) {
    error_log("SQL store load failed: " . $e->getMessage());
    $messages[] = ['type' => 'error', 'text' => 'Failed to load stores: ' . $e->getMessage()];
}

// OPTIMIZED: Get POS-enabled stores with stats from PostgreSQL (ONE query per stat type)
$posStores = [];
$posStoreIds = array_filter(array_map(function($s) {
    return $s['has_pos'] == 1 ? $s['id'] : null;
}, $allStores));

if (!empty($posStoreIds)) {
    try {
        $sqlDb = SQLDatabase::getInstance();
        // Get product counts per store in ONE query
        $productStatsQuery = "SELECT store_id, COUNT(*) as product_count, SUM(quantity) as total_stock 
                             FROM products 
                             WHERE active = TRUE AND store_id IN (" . implode(',', array_map('intval', $posStoreIds)) . ") 
                             GROUP BY store_id";
        $productStats = $sqlDb->fetchAll($productStatsQuery);
        $productStatsByStore = [];
        foreach ($productStats as $stat) {
            $productStatsByStore[$stat['store_id']] = $stat;
        }
        
        // Get sales counts per store in ONE query (now saved to SQL by POS terminal)
        $salesStatsQuery = "SELECT store_id, COUNT(*) as total_sales 
                           FROM sales 
                           WHERE store_id IN (" . implode(',', array_map('intval', $posStoreIds)) . ") 
                           GROUP BY store_id";
        $salesStats = $sqlDb->fetchAll($salesStatsQuery);
        $salesStatsByStore = [];
        foreach ($salesStats as $stat) {
            $salesStatsByStore[$stat['store_id']] = $stat;
        }
        
        // Attach stats to stores
        foreach ($allStores as $store) {
            if ($store['has_pos'] == 1) {
                $storeId = $store['id'];
                $store['product_count'] = isset($productStatsByStore[$storeId]) ? intval($productStatsByStore[$storeId]['product_count']) : 0;
                $store['total_stock'] = isset($productStatsByStore[$storeId]) ? intval($productStatsByStore[$storeId]['total_stock']) : 0;
                $store['total_sales'] = isset($salesStatsByStore[$storeId]) ? intval($salesStatsByStore[$storeId]['total_sales']) : 0;
                $posStores[] = $store;
            }
        }
    } catch (Exception $e) {
        error_log("Error loading store stats: " . $e->getMessage());
    }
}

// OPTIMIZED: Get recent sales from PostgreSQL (POS now saves to PostgreSQL)
$recentSales = [];
try {
    $sqlDb = SQLDatabase::getInstance();
    $recentSales = $sqlDb->fetchAll(
        "SELECT s.*, st.name as store_name 
         FROM sales s 
         LEFT JOIN stores st ON s.store_id = st.id 
         ORDER BY s.created_at DESC 
         LIMIT 10"
    );
    
    // Handle items - JSON string in SQL
    foreach ($recentSales as &$sale) {
        $items = $sale['items'] ?? '[]';
        if (is_string($items)) {
            $items = json_decode($items, true) ?? [];
        }
        $sale['item_count'] = is_array($items) ? count($items) : 0;
    }
    unset($sale);
} catch (Exception $e) {
    error_log("Error loading sales: " . $e->getMessage());
}

// Get all available MAIN products for batch add feature (store assignment)
$allAvailableProducts = [];
try {
    // NEW ARCHITECTURE: Load ONLY main products (store_id = NULL)
    // These are the central inventory products that can be assigned to stores
    $sqlDb = SQLDatabase::getInstance();
    $sqlProducts = $sqlDb->fetchAll("
        SELECT 
            id, name, sku, category, quantity,
            price, selling_price, store_id
        FROM products 
        WHERE active = TRUE 
          AND store_id IS NULL
        ORDER BY name 
        LIMIT 500
    ");
    
    foreach ($sqlProducts as $p) {
        $sku = $p['sku'] ?? '';
        
        // FILTER: Skip products with Firebase-generated random IDs in SKU
        if (preg_match('/-S[a-zA-Z0-9]{20,}/', $sku)) {
            error_log("Filtering out Firebase duplicate SKU: $sku");
            continue;
        }
        
        // FILTER: Skip products with excessively long SKUs (likely corrupted)
        if (strlen($sku) > 50) {
            error_log("Filtering out long SKU: $sku");
            continue;
        }
        
        // FILTER: Skip if base SKU ends with -S# pattern (store variants)
        if (preg_match('/-S\d+$/', $sku)) {
            error_log("Filtering out store variant SKU: $sku");
            continue;
        }
        
        // FILTER: Skip UNIQ-* products that have store suffix in middle (malformed)
        // Example: UNIQ-BUT-S9-302 should be filtered (S9 in the middle)
        if (preg_match('/^UNIQ-[A-Z]+-S\d+/', $sku)) {
            error_log("Filtering out UNIQ store variant: $sku");
            continue;
        }
        
        $allAvailableProducts[] = [
            'id' => $p['id'] ?? '',
            'name' => $p['name'] ?? '',
            'sku' => $sku,
            'quantity' => $p['quantity'] ?? 0,
            'price' => $p['price'] ?? $p['selling_price'] ?? 0,
            'category' => $p['category'] ?? 'Uncategorized',
            'store_id' => null // Always null for main products
        ];
    }
    
    // Sort by name
    usort($allAvailableProducts, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
} catch (Exception $e) {
    error_log("Error loading available products: " . $e->getMessage());
    $messages[] = ['type' => 'warning', 'text' => 'Could not load products. Please ensure products exist in your inventory.'];
}

// OPTIMIZED: Get low stock and out of stock products from SQL (ONE query instead of per-store)
$lowStockProducts = [];
$outOfStockProducts = [];

if (!empty($posStoreIds)) {
    try {
        $sqlDb = SQLDatabase::getInstance();
        // Get all low/out of stock in ONE query
        $alertProducts = $sqlDb->fetchAll(
            "SELECT p.*, s.name as store_name 
             FROM products p
             LEFT JOIN stores s ON p.store_id = s.id
             WHERE p.active = TRUE 
             AND p.store_id IN (" . implode(',', array_map('intval', $posStoreIds)) . ")
             AND (p.quantity = 0 OR p.quantity <= p.reorder_level)
             AND p.reorder_level > 0
             ORDER BY p.quantity ASC
             LIMIT 50"
        );
        
        foreach ($alertProducts as $product) {
            $qty = intval($product['quantity'] ?? 0);
            
            if ($qty == 0) {
                $outOfStockProducts[] = $product;
            } else {
                $lowStockProducts[] = $product;
            }
        }
        
        // Limit results
        $lowStockProducts = array_slice($lowStockProducts, 0, 20);
        $outOfStockProducts = array_slice($outOfStockProducts, 0, 20);
        
    } catch (Exception $e) {
        error_log("Error checking stock levels: " . $e->getMessage());
    }
}

usort($outOfStockProducts, function($a, $b) {
    return strcmp($a['name'] ?? '', $b['name'] ?? '');
});
$outOfStockProducts = array_slice($outOfStockProducts, 0, 20);

// Debug: Log data counts for troubleshooting
if (isset($_GET['debug'])) {
    echo "<pre style='background: #f0f0f0; padding: 20px; margin: 20px;'>";
    echo "=== DEBUG INFO ===\n\n";
    echo "All Stores: " . count($allStores) . "\n";
    echo "POS Stores: " . count($posStores) . "\n";
    echo "Available Products: " . count($allAvailableProducts) . "\n";
    echo "Recent Sales: " . count($recentSales) . "\n";
    echo "Low Stock: " . count($lowStockProducts) . "\n";
    echo "Out of Stock: " . count($outOfStockProducts) . "\n\n";
    
    if (!empty($allAvailableProducts)) {
        echo "First 3 Products:\n";
        foreach (array_slice($allAvailableProducts, 0, 3) as $p) {
            print_r($p);
        }
    } else {
        echo "⚠️ NO PRODUCTS FOUND!\n";
        echo "This means no products in SQL database OR Firebase.\n";
        echo "Please add products to your inventory first.\n";
    }
    
    if (!empty($allStores)) {
        echo "\nFirst Store Data:\n";
        print_r($allStores[0]);
    }
    echo "</pre>";
}

$page_title = 'Stock-POS Integration - Inventory System';
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
        /* Main content spacing after navigation */
        .main-content {
            margin-top: 80px;
            padding: 20px 0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .integration-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .integration-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #4f46e5;
        }
        
        .integration-card.warning {
            border-left-color: #f59e0b;
        }
        
        .integration-card.danger {
            border-left-color: #ef4444;
        }
        
        .integration-card.success {
            border-left-color: #10b981;
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .card-icon.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .card-icon.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
        .card-icon.danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
        .card-icon.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2937;
        }
        
        .sync-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
        }
        
        .sync-status.active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .sync-status.syncing {
            background: #fef3c7;
            color: #92400e;
        }
        
        .product-mini-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .product-mini-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .product-mini-item:last-child {
            border-bottom: none;
        }
        
        .product-mini-item:hover {
            background: #f9fafb;
        }
        
        .product-name {
            font-weight: 500;
            color: #1f2937;
            margin-bottom: 4px;
        }
        
        .product-store {
            font-size: 12px;
            color: #6b7280;
        }
        
        .quantity-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .quantity-badge.low {
            background: #fef3c7;
            color: #92400e;
        }
        
        .quantity-badge.out {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .quick-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .quick-actions .btn {
            flex: 1;
            text-align: center;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <?php foreach ($messages as $msg): ?>
                <div class="alert alert-<?php echo $msg['type']; ?>" style="margin-bottom: 20px; padding: 15px; border-radius: 8px; background: <?php echo $msg['type'] === 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $msg['type'] === 'success' ? '#065f46' : '#991b1b'; ?>;">
                    <?php echo htmlspecialchars($msg['text']); ?>
                </div>
            <?php endforeach; ?>
            
            <div class="page-header">
            <div class="header-left">
                <div class="header-icon"><i class="fas fa-sync-alt"></i></div>
                <div class="header-text">
                    <h1>Stock-POS Integration</h1>
                    <p>Real-time synchronization between inventory and point of sale</p>
                </div>
            </div>
            <div class="header-actions">
                <span class="sync-status active">
                    <i class="fas fa-check-circle"></i>
                    Synced with Firebase
                </span>
            </div>
        </div>

        <!-- POS Management Section -->
        <?php if (currentUserHasPermission('can_manage_stores')): ?>
        <div style="margin-bottom: 30px;">
            <h2 style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-cog"></i> POS System Management
            </h2>
            <div class="integration-card" style="background: #f9fafb; border-left-color: #6366f1;">
                <p style="margin-bottom: 15px; color: #4b5563;">
                    💡 <strong>Note:</strong> POS systems require hardware (cash registers, barcode scanners, receipt printers). 
                    Not all stores have POS equipment. Enable POS only for stores with the necessary hardware.
                </p>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                    <?php foreach ($allStores as $store): ?>
                        <div class="integration-card" style="margin: 0; border: 2px solid <?php echo $store['has_pos'] ? '#10b981' : '#e5e7eb'; ?>;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                                <div>
                                    <h3 style="margin: 0 0 5px 0; font-size: 16px;"><?php echo htmlspecialchars($store['name']); ?></h3>
                                    <span style="font-size: 12px; padding: 4px 8px; border-radius: 4px; background: <?php echo $store['has_pos'] ? '#d1fae5' : '#f3f4f6'; ?>; color: <?php echo $store['has_pos'] ? '#065f46' : '#6b7280'; ?>;">
                                        <?php echo $store['has_pos'] ? '✓ POS Enabled' : '○ POS Disabled'; ?>
                                    </span>
                                </div>
                                <button onclick="togglePOSModal('<?php echo $store['id']; ?>', '<?php echo htmlspecialchars($store['name']); ?>', <?php echo $store['has_pos']; ?>, '<?php echo htmlspecialchars($store['pos_terminal_id']); ?>')" 
                                        class="btn btn-sm" style="background: #6366f1; color: white; padding: 6px 12px;">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </div>
                            <?php if ($store['has_pos']): ?>
                                <div style="font-size: 13px; color: #6b7280; margin-top: 10px;">
                                    <?php if ($store['pos_terminal_id']): ?>
                                        <div>Terminal ID: <strong><?php echo htmlspecialchars($store['pos_terminal_id']); ?></strong></div>
                                    <?php endif; ?>
                                    <?php if ($store['pos_enabled_at']): ?>
                                        <div>Enabled: <?php echo date('M j, Y', strtotime($store['pos_enabled_at'])); ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Batch Add Products Section -->
        <?php if (!empty($posStores)): ?>
        <div style="margin-bottom: 30px;">
            <h2 style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-layer-group"></i> Batch Add Products to Store
            </h2>
            
            <?php if (empty($allAvailableProducts)): ?>
                <div class="integration-card" style="background: #fef3c7; border-left-color: #f59e0b; text-align: center; padding: 40px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f59e0b; margin-bottom: 15px;"></i>
                    <h3 style="color: #92400e;">No Products Available</h3>
                    <p style="color: #78350f; margin-bottom: 20px;">
                        No products found in your inventory. Please add products to your stock first before assigning them to POS stores.
                    </p>
                    <div style="display: flex; gap: 10px; justify-content: center;">
                        <a href="../stock/add.php" class="btn btn-primary" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                            <i class="fas fa-plus-circle"></i> Add Products to Stock
                        </a>
                        <a href="?debug=1" class="btn btn-secondary">
                            <i class="fas fa-bug"></i> Debug Info
                        </a>
                    </div>
                </div>
            <?php else: ?>
            <div class="integration-card" style="background: #f0f9ff; border-left-color: #3b82f6;">
                <p style="margin-bottom: 15px; color: #1e40af;">
                    <strong>Add products from your inventory to POS-enabled stores.</strong> Select products below and choose the target store. 
                    Products will be copied with 0 initial quantity - you'll need to stock them afterwards.
                </p>
                
                <form method="POST" action="" id="batchAddForm">
                    <input type="hidden" name="batch_add_products" value="1">
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #1f2937;">
                            Select Target Store:
                        </label>
                        <select name="target_store_id" required 
                                style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                            <option value="">-- Choose a POS-enabled store --</option>
                            <?php foreach ($posStores as $store): ?>
                                <option value="<?php echo htmlspecialchars($store['id']); ?>">
                                    <?php echo htmlspecialchars($store['name']); ?> 
                                    (<?php echo $store['product_count']; ?> products)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
                        <label style="font-weight: 600; color: #1f2937;">
                            Select Products to Add:
                        </label>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" onclick="selectAllProducts()" class="btn btn-sm" style="background: #3b82f6; color: white; padding: 6px 12px;">
                                Select All
                            </button>
                            <button type="button" onclick="deselectAllProducts()" class="btn btn-sm btn-secondary" style="padding: 6px 12px;">
                                Deselect All
                            </button>
                        </div>
                    </div>
                    
                    <div style="max-height: 400px; overflow-y: auto; border: 2px solid #e5e7eb; border-radius: 8px; padding: 10px; background: white;">
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
                            <?php foreach ($allAvailableProducts as $product): ?>
                                <label style="display: flex; align-items: start; gap: 10px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; transition: all 0.2s;" 
                                       onmouseover="this.style.background='#f9fafb'; this.style.borderColor='#3b82f6'" 
                                       onmouseout="this.style.background='white'; this.style.borderColor='#e5e7eb'">
                                    <input type="checkbox" name="product_ids[]" value="<?php echo htmlspecialchars($product['id']); ?>" 
                                           class="product-checkbox"
                                           style="margin-top: 4px; width: 18px; height: 18px;">
                                    <div style="flex: 1;">
                                        <div style="font-weight: 600; font-size: 14px; color: #1f2937; margin-bottom: 4px;">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #6b7280; margin-bottom: 2px;">
                                            SKU: <?php echo htmlspecialchars($product['sku']); ?>
                                        </div>
                                        <div style="display: flex; gap: 10px; align-items: center; margin-top: 4px;">
                                            <div style="font-size: 13px; color: #10b981; font-weight: 600;">
                                                RM <?php echo number_format($product['price'], 2); ?>
                                            </div>
                                            <div style="font-size: 12px; color: #3b82f6; font-weight: 600;">
                                                Qty: <?php echo number_format($product['quantity']); ?>
                                            </div>
                                        </div>
                                        <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">
                                            📦 <?php echo htmlspecialchars($product['category']); ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px; align-items: center; justify-content: space-between;">
                        <div style="color: #6b7280; font-size: 14px;">
                            <span id="selectedCount">0</span> product(s) selected
                        </div>
                        <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%); padding: 12px 30px;">
                            <i class="fas fa-plus-circle"></i> Add Selected Products to Store
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- POS Stores Overview -->
        <h2 style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-store"></i> Active POS Stores (<?php echo count($posStores); ?>)
        </h2>
        <?php if (empty($posStores)): ?>
            <div class="integration-card" style="text-align: center; padding: 40px;">
                <i class="fas fa-info-circle" style="font-size: 48px; color: #9ca3af; margin-bottom: 15px;"></i>
                <h3 style="color: #6b7280;">No POS-enabled stores</h3>
                <p style="color: #9ca3af; margin-bottom: 20px;">Enable POS for stores in the management section above</p>
            </div>
        <?php else: ?>
        <div class="integration-grid">
            <?php foreach ($posStores as $store): ?>
            <div class="integration-card success">
                <div class="card-header">
                    <div class="card-icon success">
                        <i class="fas fa-store"></i>
                    </div>
                    <div>
                        <div class="card-title"><?php echo htmlspecialchars($store['name']); ?></div>
                        <small style="color: #6b7280;">POS Enabled</small>
                    </div>
                </div>
                <div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); gap: 10px;">
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #4f46e5;">
                            <?php echo number_format((int)($store['product_count'] ?? 0)); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">Products</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #10b981;">
                            <?php echo number_format((int)($store['total_stock'] ?? 0)); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">Stock</div>
                    </div>
                    <div style="text-align: center;">
                        <div style="font-size: 24px; font-weight: 700; color: #f59e0b;">
                            <?php echo number_format((int)($store['total_sales'] ?? 0)); ?>
                        </div>
                        <div style="font-size: 12px; color: #6b7280;">Sales</div>
                    </div>
                </div>
                <div class="quick-actions">
                    <a href="terminal.php?store_id=<?php echo htmlspecialchars($store['id']); ?>" class="btn btn-sm" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                        <i class="fas fa-cash-register"></i> Open POS
                    </a>
                    <a href="../stock/list.php?store=<?php echo htmlspecialchars($store['id']); ?>" class="btn btn-sm btn-secondary">
                        <i class="fas fa-boxes"></i> View Stock
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Alerts Grid -->
        <div class="integration-grid">
            <!-- Low Stock Alert -->
            <div class="integration-card warning">
                <div class="card-header">
                    <div class="card-icon warning">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div>
                        <div class="card-title">Low Stock Alert</div>
                        <small style="color: #6b7280;"><?php echo count($lowStockProducts); ?> items need reordering</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($lowStockProducts)): ?>
                        <div style="text-align: center; padding: 20px; color: #6b7280;">
                            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 10px;"></i>
                            <p>All products are well stocked!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($lowStockProducts as $product): ?>
                        <div class="product-mini-item">
                            <div>
                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="product-store"><?php echo htmlspecialchars($product['store_name']); ?></div>
                            </div>
                            <span class="quantity-badge low"><?php echo $product['quantity']; ?> left</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Out of Stock Alert -->
            <div class="integration-card danger">
                <div class="card-header">
                    <div class="card-icon danger">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div>
                        <div class="card-title">Out of Stock</div>
                        <small style="color: #6b7280;"><?php echo count($outOfStockProducts); ?> items unavailable</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($outOfStockProducts)): ?>
                        <div style="text-align: center; padding: 20px; color: #6b7280;">
                            <i class="fas fa-check-circle" style="font-size: 48px; color: #10b981; margin-bottom: 10px;"></i>
                            <p>No out of stock items!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($outOfStockProducts as $product): ?>
                        <div class="product-mini-item">
                            <div>
                                <div class="product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                <div class="product-store"><?php echo htmlspecialchars($product['store_name']); ?></div>
                            </div>
                            <span class="quantity-badge out">0 stock</span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Sales -->
            <div class="integration-card primary">
                <div class="card-header">
                    <div class="card-icon primary">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div>
                        <div class="card-title">Recent POS Sales</div>
                        <small style="color: #6b7280;">Last <?php echo count($recentSales); ?> transactions</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($recentSales)): ?>
                        <div style="text-align: center; padding: 20px; color: #6b7280;">
                            <i class="fas fa-info-circle" style="font-size: 48px; color: #6b7280; margin-bottom: 10px;"></i>
                            <p>No sales recorded yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recentSales as $sale): ?>
                        <div class="product-mini-item">
                            <div>
                                <div class="product-name">#<?php echo htmlspecialchars($sale['sale_number'] ?? $sale['id']); ?></div>
                                <div class="product-store">
                                    <?php echo htmlspecialchars($sale['store_name'] ?? 'Unknown'); ?> • 
                                    <?php echo $sale['item_count']; ?> items
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight: 600; color: #10b981;">
                                    $<?php echo number_format($sale['total_amount'], 2); ?>
                                </div>
                                <div style="font-size: 11px; color: #6b7280;">
                                    <?php echo date('M j, g:i A', strtotime($sale['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div style="display: flex; gap: 15px; margin-top: 30px;">
            <a href="../stock/list.php" class="btn btn-primary" style="flex: 1;">
                <i class="fas fa-boxes"></i> View All Stock
            </a>
            <a href="../stores/list.php" class="btn btn-secondary" style="flex: 1;">
                <i class="fas fa-store"></i> Manage Stores
            </a>
            <a href="terminal.php" class="btn btn-secondary" style="flex: 1; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                <i class="fas fa-cash-register"></i> Open POS Terminal
            </a>
        </div>
        </div>
    </div>

    <!-- POS Toggle Modal -->
    <div id="posModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: white; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%;">
            <h2 style="margin: 0 0 20px 0;">
                <i class="fas fa-cog"></i> Configure POS System
            </h2>
            
            <form method="POST" action="">
                <input type="hidden" name="toggle_pos" value="1">
                <input type="hidden" name="store_id" id="modal_store_id">
                
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">Store Name:</label>
                    <div id="modal_store_name" style="padding: 10px; background: #f3f4f6; border-radius: 6px; color: #4b5563;"></div>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 15px; background: #f9fafb; border-radius: 8px; border: 2px solid #e5e7eb;">
                        <input type="checkbox" name="enable_pos" value="1" id="modal_enable_pos" style="width: 20px; height: 20px;">
                        <div>
                            <strong>Enable POS System</strong>
                            <div style="font-size: 13px; color: #6b7280; margin-top: 4px;">
                                Allow this store to use the POS terminal for sales
                            </div>
                        </div>
                    </label>
                </div>
                
                <div id="pos_settings" style="display: none; margin-bottom: 20px; padding: 15px; background: #f0fdf4; border-radius: 8px; border: 1px solid #86efac;">
                    <label style="display: block; margin-bottom: 5px; font-weight: 600;">
                        Terminal ID (Optional):
                    </label>
                    <input type="text" name="pos_terminal_id" id="modal_terminal_id" 
                           placeholder="e.g., POS-001, CASH-REG-A" 
                           style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px;">
                    <small style="color: #6b7280; display: block; margin-top: 5px;">
                        Identifier for the physical POS hardware
                    </small>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="button" onclick="closePOSModal()" class="btn btn-secondary" style="flex: 1;">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Batch Add Products Functions
        function selectAllProducts() {
            document.querySelectorAll('.product-checkbox').forEach(cb => {
                cb.checked = true;
            });
            updateSelectedCount();
        }
        
        function deselectAllProducts() {
            document.querySelectorAll('.product-checkbox').forEach(cb => {
                cb.checked = false;
            });
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const count = document.querySelectorAll('.product-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = count;
        }
        
        // Update count when checkboxes change
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.product-checkbox').forEach(cb => {
                cb.addEventListener('change', updateSelectedCount);
            });
            updateSelectedCount();
            
            // Validate form submission
            document.getElementById('batchAddForm')?.addEventListener('submit', function(e) {
                const selectedCount = document.querySelectorAll('.product-checkbox:checked').length;
                if (selectedCount === 0) {
                    e.preventDefault();
                    alert('Please select at least one product to add');
                    return false;
                }
                
                const storeSelect = this.querySelector('[name="target_store_id"]');
                if (!storeSelect.value) {
                    e.preventDefault();
                    alert('Please select a target store');
                    return false;
                }
                
                if (!confirm(`Add ${selectedCount} product(s) to the selected store?\n\nProducts will be added with 0 quantity and need to be stocked.`)) {
                    e.preventDefault();
                    return false;
                }
            });
        });
        
        // POS Modal Functions
        function togglePOSModal(storeId, storeName, hasPos, terminalId) {
            document.getElementById('modal_store_id').value = storeId;
            document.getElementById('modal_store_name').textContent = storeName;
            document.getElementById('modal_enable_pos').checked = hasPos == 1;
            document.getElementById('modal_terminal_id').value = terminalId || '';
            
            // Show/hide terminal ID field based on checkbox
            togglePOSSettings();
            
            document.getElementById('posModal').style.display = 'flex';
        }
        
        function closePOSModal() {
            document.getElementById('posModal').style.display = 'none';
        }
        
        function togglePOSSettings() {
            const checkbox = document.getElementById('modal_enable_pos');
            const settings = document.getElementById('pos_settings');
            settings.style.display = checkbox.checked ? 'block' : 'none';
        }
        
        // Event listener for checkbox
        document.getElementById('modal_enable_pos').addEventListener('change', togglePOSSettings);
        
        // Close modal when clicking outside
        document.getElementById('posModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closePOSModal();
            }
        });
        
        // Auto-refresh every 60 seconds (disabled if form has changes)
        let hasFormChanges = false;
        document.querySelectorAll('.product-checkbox').forEach(cb => {
            cb.addEventListener('change', () => { hasFormChanges = true; });
        });
        
        setTimeout(() => {
            if (!hasFormChanges) {
                location.reload();
            }
        }, 60000);
    </script>
</body>
</html>
