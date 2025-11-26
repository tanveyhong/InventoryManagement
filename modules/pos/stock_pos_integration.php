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
            $existing_count = 0;
            $skip_reasons = [];
            $sqlDb = SQLDatabase::getInstance();
            
            // Fetch store name for meaningful SKU
            $targetStore = $sqlDb->fetch("SELECT name FROM stores WHERE id = ?", [$target_store_id]);
            $storeNameRaw = $targetStore ? $targetStore['name'] : 'Store ' . $target_store_id;
            // Generate suffix: Uppercase, alphanumeric only. e.g. "Main Store" -> "MAINSTORE"
            $storeSuffix = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $storeNameRaw));
            if (empty($storeSuffix)) $storeSuffix = 'S' . $target_store_id;

            error_log("===== BATCH ASSIGN PRODUCTS TO STORE =====");
            error_log("Target Store ID: $target_store_id ($storeNameRaw)");
            error_log("Product IDs to assign: " . json_encode($product_ids));
            
            foreach ($product_ids as $idx => $product_id) {
                error_log("\n--- Processing Main Product ID: $product_id ---");
                $mainProduct = null;
                // ZERO-COPY LOGIC: Always assign with 0 quantity. Stock must be added via restocking.
                $assignQty = 0; 
                
                // Load MAIN product (store_id = NULL)
                try {
                    $mainProduct = $sqlDb->fetch("SELECT * FROM products WHERE id = ? AND store_id IS NULL LIMIT 1", [$product_id]);
                    if ($mainProduct) {
                        error_log("✓ Found main product: {$mainProduct['name']} (SKU: {$mainProduct['sku']})");
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
                
                if (empty($sku)) {
                    $skipped_count++;
                    $skip_reasons[] = "Product '$productName': Missing SKU";
                    continue;
                }
                
                // Create store variant SKU (Format: SKU-POS-STORENAME)
                // This distinguishes POS-assigned products from normal store assignments
                $variantSku = $sku . '-POS-' . $storeSuffix;
                error_log("Creating store variant with SKU: $variantSku");
                
                // Check if variant already exists
                $existing = $sqlDb->fetch("SELECT id FROM products WHERE sku = ? AND active = TRUE LIMIT 1", [$variantSku]);
                if ($existing) {
                    error_log("✗ Store variant already exists: $variantSku");
                    $existing_count++;
                    continue;
                }
                
                // NEW ARCHITECTURE: Create store variant with 0 quantity (Zero-Copy)
                try {
                    $sqlDb->execute("BEGIN TRANSACTION");
                        
                        // 1. Create store variant
                        $query = "INSERT INTO products (
                            name, sku, barcode, description, category, unit,
                            cost_price, selling_price, price, quantity, reorder_level,
                            store_id, active, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                        
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
                            0, // Always 0 quantity
                            $mainProduct['reorder_level'] ?? 10,
                            $target_store_id,
                            1
                        ];
                        
                        $sqlDb->execute($query, $params);
                        $variant_id = $sqlDb->lastInsertId();
                        error_log("✓ Store variant created with ID: $variant_id");
                        
                        // 2. NO Update to main product quantity (Zero-Copy)
                        
                        // 3. Log stock movements (Only for the new variant creation)
                        $sqlDb->execute("
                            INSERT INTO stock_movements (product_id, store_id, movement_type, quantity, reference, notes, user_id, created_at)
                            VALUES (?, ?, 'in', ?, 'Store Assignment', ?, ?, NOW())
                        ", [
                            $variant_id,
                            $target_store_id,
                            0,
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
                                'quantity' => 0,
                                'reorder_level' => intval($mainProduct['reorder_level'] ?? 0),
                                'store_id' => $target_store_id,
                                'active' => 1,
                                'created_at' => date('c'),
                                'updated_at' => date('c'),
                            ];
                            $db->create('products', $variantDoc, (string)$variant_id);
                            
                        } catch (Exception $e) {
                            error_log("! Firebase sync warning: " . $e->getMessage());
                        }
                        
                        $added_count++;
                        
                    } catch (Exception $e) {
                        $sqlDb->execute("ROLLBACK");
                        error_log("✗ Transaction failed: " . $e->getMessage());
                        $skipped_count++;
                        $skip_reasons[] = "Product '$productName': Database error";
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
            
            if ($added_count > 0 || $existing_count > 0) {
                $parts = [];
                if ($added_count > 0) $parts[] = "Successfully assigned {$added_count} new product(s).";
                if ($existing_count > 0) $parts[] = "{$existing_count} product(s) already assigned.";
                
                $detailMsg = implode(' ', $parts);
                
                if ($skipped_count > 0) {
                    $detailMsg .= "<br>{$skipped_count} skipped:<br>" . implode('<br>', array_map(function($r) {
                        return "• " . htmlspecialchars($r);
                    }, $skip_reasons));
                    $messages[] = ['type' => 'warning', 'text' => $detailMsg];
                } else {
                    $messages[] = ['type' => 'success', 'text' => $detailMsg];
                }
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
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --secondary: #64748b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --surface: #ffffff;
            --background: #f1f5f9;
            --text-main: #0f172a;
            --text-secondary: #64748b;
            --border: #e2e8f0;
            --radius: 8px;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        body {
            background-color: var(--background);
            color: var(--text-main);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .main-content {
            margin-top: 60px;
            padding: 20px 0;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            background: var(--surface);
            padding: 15px 20px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .header-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .header-text h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            color: var(--text-main);
        }

        .header-text p {
            margin: 2px 0 0 0;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .integration-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .integration-card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 15px;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
        }
        
        .integration-card.warning { border-left-color: var(--warning); }
        .integration-card.danger { border-left-color: var(--danger); }
        .integration-card.success { border-left-color: var(--success); }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }
        
        .card-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .card-icon.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .card-icon.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
        .card-icon.danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
        .card-icon.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
        
        .card-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text-main);
        }
        
        .sync-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .sync-status.active { background: #d1fae5; color: #065f46; }
        .sync-status.syncing { background: #fef3c7; color: #92400e; }
        
        .product-mini-list {
            max-height: 250px;
            overflow-y: auto;
        }
        
        .product-mini-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid var(--border);
        }
        
        .product-mini-item:last-child { border-bottom: none; }
        .product-mini-item:hover { background: #f8fafc; }
        
        .product-name {
            font-weight: 500;
            color: var(--text-main);
            font-size: 13px;
            margin-bottom: 2px;
        }
        
        .product-store {
            font-size: 11px;
            color: var(--text-secondary);
        }
        
        .quantity-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 12px;
        }
        
        .quantity-badge.low { background: #fef3c7; color: #92400e; }
        .quantity-badge.out { background: #fee2e2; color: #991b1b; }
        
        .quick-actions {
            display: flex;
            gap: 8px;
            margin-top: 15px;
        }
        
        .quick-actions .btn {
            flex: 1;
            text-align: center;
            font-size: 13px;
            padding: 8px;
        }

        /* Compact Form Elements */
        .form-control-compact {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 13px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px;
        }

        .product-checkbox-label {
            display: flex;
            flex-direction: column;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s;
            background: white;
            height: 100%;
            position: relative;
        }

        .product-checkbox-label:hover {
            background: #f8fafc;
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .section-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-main);
        }

        @keyframes modalSlideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* List View Styles */
        .store-list-container {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 20px;
            max-height: 500px;
            overflow-y: auto;
            padding-left: 10px; /* Added padding on left for scrollbar spacing */
            direction: rtl; /* Moves scrollbar to the left */
        }

        /* Custom Scrollbar - More visible "slider" style */
        .store-list-container::-webkit-scrollbar {
            width: 10px;
        }
        .store-list-container::-webkit-scrollbar-track {
            background: #f5f5f5;
            border-radius: 5px;
            border: 1px solid #e0e0e0;
        }
        .store-list-container::-webkit-scrollbar-thumb {
            background-color: #a0a0a0;
            border-radius: 5px;
            border: 2px solid #f5f5f5; /* Creates padding around thumb */
        }
        .store-list-container::-webkit-scrollbar-thumb:hover {
            background-color: #707070;
        }

        .store-list-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            gap: 15px;
            transition: all 0.2s;
            direction: ltr; /* Reset direction for content */
        }
        
        .store-list-item:hover {
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .store-list-item.success {
            border-left: 3px solid var(--success);
            background: linear-gradient(to right, #f0fdf4, white 40%);
        }

        .store-info {
            flex: 2;
            min-width: 180px;
        }

        .store-name {
            font-weight: 600;
            color: var(--text-main);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-badge {
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 8px;
            background: #d1fae5;
            color: #065f46;
            font-weight: 600;
            white-space: nowrap;
        }

        .store-meta {
            font-size: 11px;
            color: var(--text-secondary);
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .store-stats {
            flex: 3;
            display: flex;
            gap: 20px;
            justify-content: center;
            border-left: 1px solid var(--border);
            border-right: 1px solid var(--border);
            padding: 0 15px;
        }

        .stat-box {
            text-align: center;
            min-width: 50px;
        }

        .stat-value {
            font-weight: 700;
            font-size: 14px;
            color: var(--text-main);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 9px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        .store-actions {
            flex: 2;
            display: flex;
            gap: 6px;
            justify-content: flex-end;
        }
        
        @media (max-width: 900px) {
            .store-list-item {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }
            .store-stats {
                border: none;
                border-top: 1px solid var(--border);
                border-bottom: 1px solid var(--border);
                padding: 10px 0;
                justify-content: space-around;
            }
            .store-actions {
                justify-content: stretch;
            }
            .store-actions .btn {
                flex: 1;
            }
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

        <?php
        $activePosStoresList = array_filter($allStores, function($s) { return $s['has_pos'] == 1; });
        $inactivePosStoresList = array_filter($allStores, function($s) { return $s['has_pos'] == 0; });
        ?>

        <div style="display: flex; flex-direction: column; gap: 20px; margin-bottom: 20px;">
            <!-- Active POS Terminals List -->
            <div>
                <?php if (!empty($activePosStoresList)): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h2 class="section-title" style="margin-bottom: 0;">
                        <i class="fas fa-cash-register"></i> Active POS Terminals
                        <span style="font-size: 13px; font-weight: 400; color: var(--text-secondary); margin-left: 10px;">
                            (<?php echo count($activePosStoresList); ?> active)
                        </span>
                    </h2>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <div style="position: relative;">
                            <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                            <input type="text" id="storeSearch" placeholder="Search stores..." 
                                   style="padding: 8px 10px 8px 35px; border: 1px solid var(--border); border-radius: 20px; font-size: 13px; width: 180px; outline: none;"
                                   onkeyup="filterStores()">
                        </div>
                        <button onclick="openBatchAddModal()" class="btn btn-sm btn-secondary" style="border-radius: 20px; padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-layer-group"></i> Batch Add
                        </button>
                        <?php if (!empty($inactivePosStoresList)): ?>
                        <button onclick="openAvailableStoresModal()" class="btn btn-sm btn-primary" style="border-radius: 20px; padding: 8px 15px; font-size: 13px;">
                            <i class="fas fa-plus"></i> Add Terminal
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="store-list-container" id="activeStoresList">
                    <?php foreach ($activePosStoresList as $store): 
                        $storeData = null;
                        foreach ($posStores as $ps) {
                            if ($ps['id'] == $store['id']) {
                                $storeData = $ps;
                                break;
                            }
                        }
                    ?>
                        <div class="store-list-item success" data-name="<?php echo strtolower(htmlspecialchars($store['name'])); ?>">
                            <!-- Info Column -->
                            <div class="store-info">
                                <div class="store-name">
                                    <?php echo htmlspecialchars($store['name']); ?>
                                    <span class="status-badge"><i class="fas fa-check-circle"></i> Active</span>
                                </div>
                                <?php if ($store['pos_terminal_id'] || $store['pos_enabled_at']): ?>
                                <div class="store-meta">
                                    <?php if ($store['pos_terminal_id']): ?>
                                        <span title="Terminal ID"><i class="fas fa-desktop"></i> <?php echo htmlspecialchars($store['pos_terminal_id']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($store['pos_enabled_at']): ?>
                                        <span title="Enabled Date" style="margin-left: 8px;"><i class="fas fa-clock"></i> <?php echo date('M j, Y', strtotime($store['pos_enabled_at'])); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Stats Column -->
                            <?php if ($storeData): ?>
                            <div class="store-stats">
                                <div class="stat-box">
                                    <div class="stat-value" style="color: var(--primary);">
                                        <?php echo number_format((int)($storeData['product_count'] ?? 0)); ?>
                                    </div>
                                    <div class="stat-label">Products</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-value" style="color: var(--success);">
                                        <?php echo number_format((int)($storeData['total_stock'] ?? 0)); ?>
                                    </div>
                                    <div class="stat-label">Stock</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-value" style="color: var(--warning);">
                                        <?php echo number_format((int)($storeData['total_sales'] ?? 0)); ?>
                                    </div>
                                    <div class="stat-label">Sales</div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="store-stats">
                                <span style="color: var(--text-secondary); font-size: 12px;">No data available</span>
                            </div>
                            <?php endif; ?>

                            <!-- Actions Column -->
                            <div class="store-actions">
                                <a href="terminal.php?store_id=<?php echo htmlspecialchars($store['id']); ?>" 
                                   class="btn btn-sm" 
                                   style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 6px 10px; font-size: 11px; white-space: nowrap;">
                                    <i class="fas fa-cash-register"></i> POS
                                </a>
                                <a href="../stock/list.php?store=<?php echo htmlspecialchars($store['id']); ?>" 
                                   class="btn btn-sm btn-secondary" 
                                   style="padding: 6px 10px; font-size: 11px;"
                                   title="View Stock">
                                    <i class="fas fa-boxes"></i>
                                </a>
                                <button onclick="selectStoreForBatchAdd('<?php echo $store['id']; ?>')" 
                                        class="btn btn-sm btn-secondary" 
                                        style="padding: 6px 10px; font-size: 11px;"
                                        title="Batch Add Products">
                                    <i class="fas fa-cart-plus"></i>
                                </button>
                                <?php if (currentUserHasPermission('can_manage_stores')): ?>
                                <button onclick="togglePOSModal('<?php echo $store['id']; ?>', '<?php echo htmlspecialchars($store['name']); ?>', 1, '<?php echo htmlspecialchars($store['pos_terminal_id']); ?>')" 
                                        class="btn btn-sm btn-secondary" 
                                        style="padding: 6px 10px; font-size: 11px;"
                                        title="Configure POS Settings">
                                    <i class="fas fa-cog"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>



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
                        <small style="color: var(--text-secondary); font-size: 11px;"><?php echo count($lowStockProducts); ?> items need reordering</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($lowStockProducts)): ?>
                        <div style="text-align: center; padding: 15px; color: var(--text-secondary);">
                            <i class="fas fa-check-circle" style="font-size: 32px; color: var(--success); margin-bottom: 8px;"></i>
                            <p style="font-size: 13px; margin: 0;">All products are well stocked!</p>
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
                        <small style="color: var(--text-secondary); font-size: 11px;"><?php echo count($outOfStockProducts); ?> items unavailable</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($outOfStockProducts)): ?>
                        <div style="text-align: center; padding: 15px; color: var(--text-secondary);">
                            <i class="fas fa-check-circle" style="font-size: 32px; color: var(--success); margin-bottom: 8px;"></i>
                            <p style="font-size: 13px; margin: 0;">No out of stock items!</p>
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
            <div class="integration-card" style="border-left-color: var(--primary);">
                <div class="card-header">
                    <div class="card-icon primary">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div>
                        <div class="card-title">Recent POS Sales</div>
                        <small style="color: var(--text-secondary); font-size: 11px;">Last <?php echo count($recentSales); ?> transactions</small>
                    </div>
                </div>
                <div class="product-mini-list">
                    <?php if (empty($recentSales)): ?>
                        <div style="text-align: center; padding: 15px; color: var(--text-secondary);">
                            <i class="fas fa-info-circle" style="font-size: 32px; color: var(--text-secondary); margin-bottom: 8px; opacity: 0.5;"></i>
                            <p style="font-size: 13px; margin: 0;">No sales recorded yet</p>
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
                                <div style="font-weight: 600; color: var(--success); font-size: 13px;">
                                    $<?php echo number_format($sale['total_amount'], 2); ?>
                                </div>
                                <div style="font-size: 10px; color: var(--text-secondary);">
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
        <div style="display: flex; gap: 10px; margin-top: 20px;">
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

    <!-- Available Stores Modal -->
    <div id="availableStoresModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: var(--surface); border-radius: var(--radius); padding: 25px; max-width: 800px; width: 90%; max-height: 80vh; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: modalSlideIn 0.3s ease-out;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 15px; flex: 1;">
                    <h2 style="margin: 0; font-size: 20px; color: var(--text-main); white-space: nowrap;">
                        <i class="fas fa-store"></i> Available Stores
                    </h2>
                    <div style="position: relative; flex: 1; max-width: 300px;">
                        <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-secondary);"></i>
                        <input type="text" id="availableStoreSearch" placeholder="Search available stores..." 
                               style="padding: 8px 10px 8px 35px; border: 1px solid var(--border); border-radius: 20px; font-size: 13px; width: 100%; outline: none;"
                               onkeyup="filterAvailableStores()">
                    </div>
                </div>
                <button onclick="closeAvailableStoresModal()" style="background: none; border: none; font-size: 20px; color: var(--text-secondary); cursor: pointer; margin-left: 15px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="store-list-container" id="availableStoresList" style="flex: 1; margin-bottom: 0; max-height: none; overflow-y: auto;">
                <?php foreach ($inactivePosStoresList as $store): ?>
                    <div class="store-list-item" data-name="<?php echo strtolower(htmlspecialchars($store['name'])); ?>" style="border-left: 4px solid var(--text-secondary);">
                        <!-- Info Column -->
                        <div class="store-info">
                            <div class="store-name">
                                <?php echo htmlspecialchars($store['name']); ?>
                                <span class="status-badge" style="background: var(--background); color: var(--text-secondary); border: 1px solid var(--border);"><i class="far fa-circle"></i> Not Configured</span>
                            </div>
                        </div>

                        <!-- Stats Column (Empty for inactive) -->
                        <div class="store-stats">
                            <span style="color: var(--text-secondary); font-size: 12px;">Enable POS to view stats</span>
                        </div>

                        <!-- Actions Column -->
                        <div class="store-actions">
                            <?php if (currentUserHasPermission('can_manage_stores')): ?>
                            <button onclick="togglePOSModal('<?php echo $store['id']; ?>', '<?php echo htmlspecialchars($store['name']); ?>', 0, '')" 
                                    class="btn btn-sm btn-primary" 
                                    style="padding: 6px 12px; font-size: 12px;">
                                <i class="fas fa-plus"></i> Enable POS
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($inactivePosStoresList)): ?>
                    <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                        <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px; color: var(--success);"></i>
                        <p>All stores have POS enabled!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Batch Add Products Modal -->
    <div id="batchAddModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: var(--surface); border-radius: var(--radius); padding: 25px; max-width: 900px; width: 90%; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 10px 25px rgba(0,0,0,0.2); animation: modalSlideIn 0.3s ease-out;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 20px; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-layer-group"></i> Batch Add Products to Store
                </h2>
                <button onclick="closeBatchAddModal()" style="background: none; border: none; font-size: 20px; color: var(--text-secondary); cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div style="flex: 1; overflow: hidden; display: flex; flex-direction: column;">
                <p style="margin-bottom: 15px; color: var(--primary-dark); font-size: 13px;">
                    <strong>Add products from your inventory to POS-enabled stores.</strong> Select products below and choose the target store. 
                    Products will be copied with 0 initial quantity.
                </p>
                
                <form method="POST" action="" id="batchAddForm" style="display: flex; flex-direction: column; flex: 1; overflow: hidden;">
                    <input type="hidden" name="batch_add_products" value="1">
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 6px; font-weight: 600; color: var(--text-main); font-size: 13px;">
                            Select Target Store:
                        </label>
                        <select name="target_store_id" required class="form-control-compact">
                            <option value="">-- Choose a POS-enabled store --</option>
                            <?php foreach ($posStores as $store): ?>
                                <option value="<?php echo htmlspecialchars($store['id']); ?>">
                                    <?php echo htmlspecialchars($store['name']); ?> 
                                    (<?php echo $store['product_count']; ?> products)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php 
                    $categories = array_unique(array_column($allAvailableProducts, 'category'));
                    sort($categories);
                    ?>
                    <div style="margin-bottom: 10px;">
                        <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                            <div style="position: relative; flex: 1;">
                                <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-secondary); font-size: 12px;"></i>
                                <input type="text" id="batchProductSearch" placeholder="Search products..." 
                                       style="width: 100%; padding: 8px 10px 8px 30px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 12px; outline: none;"
                                       onkeyup="filterBatchProducts()">
                            </div>
                            <select id="batchCategoryFilter" onchange="filterBatchProducts()" 
                                    style="width: 120px; padding: 8px; border: 1px solid var(--border); border-radius: var(--radius); font-size: 12px; outline: none;">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <label style="font-weight: 600; color: var(--text-main); font-size: 13px;">
                                Select Products:
                            </label>
                            <div style="display: flex; gap: 8px;">
                                <button type="button" onclick="selectAllProducts()" class="btn btn-sm btn-primary" style="padding: 4px 10px; font-size: 11px;">
                                    Select All
                                </button>
                                <button type="button" onclick="deselectAllProducts()" class="btn btn-sm btn-secondary" style="padding: 4px 10px; font-size: 11px;">
                                    Deselect All
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div style="flex: 1; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius); padding: 10px; background: var(--background); min-height: 200px;">
                        <div class="product-grid" id="batchProductList">
                            <?php foreach ($allAvailableProducts as $product): ?>
                                <label class="product-checkbox-label" 
                                       data-name="<?php echo strtolower(htmlspecialchars($product['name'] . ' ' . $product['sku'])); ?>"
                                       data-category="<?php echo htmlspecialchars($product['category']); ?>">
                                    <div style="display: flex; justify-content: space-between; width: 100%; margin-bottom: 8px;">
                                        <input type="checkbox" name="product_ids[]" value="<?php echo htmlspecialchars($product['id']); ?>" 
                                               class="product-checkbox"
                                               style="width: 16px; height: 16px;">
                                        <div style="font-size: 12px; color: var(--success); font-weight: 600;">
                                            RM <?php echo number_format($product['price'], 2); ?>
                                        </div>
                                    </div>
                                    
                                    <div style="flex: 1; width: 100%;">
                                        <div style="font-weight: 600; font-size: 13px; color: var(--text-main); margin-bottom: 4px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                                            <?php echo htmlspecialchars($product['name']); ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-secondary); margin-bottom: 4px;">
                                            <?php echo htmlspecialchars($product['sku']); ?>
                                        </div>
                                        <div style="font-size: 10px; color: var(--primary); background: #e0e7ff; display: inline-block; padding: 2px 6px; border-radius: 4px;">
                                            Qty: <?php echo number_format($product['quantity']); ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div style="margin-top: 15px; display: flex; gap: 10px; align-items: center; justify-content: space-between;">
                        <div style="color: var(--text-secondary); font-size: 13px;">
                            <span id="selectedCount" style="font-weight: 600; color: var(--primary);">0</span> product(s) selected
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding: 8px 20px; font-size: 13px;">
                            <i class="fas fa-plus-circle"></i> Add Selected Products
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- POS Toggle Modal -->
    <div id="posModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: var(--surface); border-radius: var(--radius); padding: 20px; max-width: 450px; width: 90%; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <h2 style="margin: 0 0 15px 0; font-size: 18px; color: var(--text-main);">
                <i class="fas fa-cog"></i> Configure POS System
            </h2>
            
            <form method="POST" action="">
                <input type="hidden" name="toggle_pos" value="1">
                <input type="hidden" name="store_id" id="modal_store_id">
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 13px; color: var(--text-main);">Store Name:</label>
                    <div id="modal_store_name" style="padding: 8px; background: var(--background); border-radius: var(--radius); color: var(--text-secondary); font-size: 13px;"></div>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 10px; background: var(--background); border-radius: var(--radius); border: 1px solid var(--border);">
                        <input type="checkbox" name="enable_pos" value="1" id="modal_enable_pos" style="width: 16px; height: 16px;">
                        <div>
                            <strong style="font-size: 13px; color: var(--text-main);">Enable POS System</strong>
                            <div style="font-size: 11px; color: var(--text-secondary); margin-top: 2px;">
                                Allow this store to use the POS terminal for sales
                            </div>
                        </div>
                    </label>
                </div>
                
                <div id="pos_settings" style="display: none; margin-bottom: 15px; padding: 10px; background: #f0fdf4; border-radius: var(--radius); border: 1px solid var(--success);">
                    <label style="display: block; margin-bottom: 4px; font-weight: 600; font-size: 13px; color: var(--text-main);">
                        Terminal ID (Optional):
                    </label>
                    <input type="text" name="pos_terminal_id" id="modal_terminal_id" 
                           placeholder="e.g., POS-001, CASH-REG-A" 
                           class="form-control-compact">
                    <small style="color: var(--text-secondary); display: block; margin-top: 4px; font-size: 11px;">
                        Identifier for the physical POS hardware
                    </small>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                    <button type="submit" class="btn btn-primary" style="flex: 1; padding: 8px;">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                    <button type="button" onclick="closePOSModal()" class="btn btn-secondary" style="flex: 1; padding: 8px;">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Filter Stores Function
        function filterStores() {
            const input = document.getElementById('storeSearch');
            const filter = input.value.toLowerCase();
            const container = document.getElementById('activeStoresList');
            const stores = container.getElementsByClassName('store-list-item');

            for (let i = 0; i < stores.length; i++) {
                const storeName = stores[i].getAttribute('data-name');
                if (storeName.indexOf(filter) > -1) {
                    stores[i].style.display = "";
                } else {
                    stores[i].style.display = "none";
                }
            }
        }

        // Filter Available Stores Function
        function filterAvailableStores() {
            const input = document.getElementById('availableStoreSearch');
            const filter = input.value.toLowerCase();
            const container = document.getElementById('availableStoresList');
            const stores = container.getElementsByClassName('store-list-item');

            for (let i = 0; i < stores.length; i++) {
                const storeName = stores[i].getAttribute('data-name');
                if (storeName.indexOf(filter) > -1) {
                    stores[i].style.display = "";
                } else {
                    stores[i].style.display = "none";
                }
            }
        }

        // Available Stores Modal Functions
        function openAvailableStoresModal() {
            document.getElementById('availableStoresModal').style.display = 'flex';
        }

        function closeAvailableStoresModal() {
            document.getElementById('availableStoresModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('availableStoresModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAvailableStoresModal();
            }
        });

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
        
        // Filter Batch Products
        function filterBatchProducts() {
            const searchInput = document.getElementById('batchProductSearch');
            const categorySelect = document.getElementById('batchCategoryFilter');
            const filterText = searchInput.value.toLowerCase();
            const filterCategory = categorySelect.value;
            
            const container = document.getElementById('batchProductList');
            const items = container.getElementsByClassName('product-checkbox-label');
            
            for (let i = 0; i < items.length; i++) {
                const name = items[i].getAttribute('data-name');
                const category = items[i].getAttribute('data-category');
                
                let show = true;
                
                if (filterText && name.indexOf(filterText) === -1) {
                    show = false;
                }
                
                if (filterCategory && category !== filterCategory) {
                    show = false;
                }
                
                items[i].style.display = show ? "" : "none";
            }
        }

        // Batch Add Modal Functions
        function openBatchAddModal() {
            document.getElementById('batchAddModal').style.display = 'flex';
        }

        function closeBatchAddModal() {
            document.getElementById('batchAddModal').style.display = 'none';
        }

        // Close modal when clicking outside
        document.getElementById('batchAddModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeBatchAddModal();
            }
        });

        // Select Store for Batch Add
        function selectStoreForBatchAdd(storeId) {
            openBatchAddModal();
            const select = document.querySelector('select[name="target_store_id"]');
            if (select) {
                select.value = storeId;
                // Focus the search input to encourage adding products
                setTimeout(() => {
                    document.getElementById('batchProductSearch').focus();
                }, 100);
            }
        }

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
