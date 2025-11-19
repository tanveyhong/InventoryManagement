<?php
require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
session_start();

// Auth check - redirect to login if not authenticated
if (!isset($_SESSION['user_id'])) {
    header('Location: ../users/login.php');
    exit;
}

if (!currentUserHasPermission('can_view_inventory') && !currentUserHasPermission('can_use_pos') && !currentUserHasPermission('can_view_reports')) {
    $_SESSION['error'] = 'You do not have permission to access inventory';
    header('Location: ../../index.php');
    exit;
}

function fs_put_low_stock_alert($db, array $prod): void
{
    // Normalize product id & name
    $pid = isset($prod['id']) ? trim((string)$prod['id']) : '';
    if ($pid === '') return;
    $pname = trim((string)($prod['name'] ?? ''));

    // Coalesce threshold
    $threshold = isset($prod['min_stock_level']) ? (int)$prod['min_stock_level']
        : (isset($prod['reorder_level']) ? (int)$prod['reorder_level'] : 0);
    if ($threshold <= 0) return;

    // Current quantity
    $qty = (int)($prod['quantity'] ?? 0);
    if ($qty > $threshold) return; // not low now

    // Deterministic doc id
    $docId = 'LOW_' . $pid;
    $nowIso = date('c');

    // Read existing alert
    $existing = null;
    if (method_exists($db, 'readDoc')) {
        $existing = $db->readDoc('alerts', $docId);
    } elseif (method_exists($db, 'read')) {
        $existing = $db->read('alerts', $docId);
    }

    $prevStatus = strtoupper($existing['status'] ?? '');
    $reopen = (!$existing || $prevStatus === 'RESOLVED');  // reopen if new or previously resolved

    // If an OPEN (non-resolved) alert already exists, just refresh metadata and return
    if ($existing && !$reopen) {
        $payload = [
            'product_id'   => $pid,
            'product_name' => $pname,
            'alert_type'   => 'LOW_STOCK',
            'status'       => 'PENDING',                 // stays pending
            'created_at'   => $existing['created_at'] ?? $nowIso, // keep original
            'updated_at'   => $nowIso,                   // refresh updated_at
        ];
        if (method_exists($db, 'writeDoc'))       $db->writeDoc('alerts', $docId, $payload);
        elseif (method_exists($db, 'upsert'))     $db->upsert('alerts', $docId, $payload);
        elseif (method_exists($db, 'setDoc'))     $db->setDoc('alerts', $docId, $payload);
        elseif (method_exists($db, 'update'))     $db->update('alerts', $docId, $payload);
        elseif (method_exists($db, 'write'))      $db->write('alerts', $docId, $payload);
        return;
    }

    // Reopen case (new alert or previously RESOLVED): reset created_at to "now" and clear resolution fields
    $payload = [
        'product_id'       => $pid,
        'product_name'     => $pname,
        'alert_type'       => 'LOW_STOCK',
        'status'           => 'PENDING',
        'created_at'       => $nowIso,   // <-- reset to today
        'updated_at'       => $nowIso,
        'resolved_at'      => null,      // clear old resolution info
        'resolved_by'      => null,
        'resolution_note'  => null,
    ];

    // Upsert
    if (method_exists($db, 'writeDoc'))           $db->writeDoc('alerts', $docId, $payload);
    elseif (method_exists($db, 'upsert'))         $db->upsert('alerts', $docId, $payload);
    elseif (method_exists($db, 'setDoc'))         $db->setDoc('alerts', $docId, $payload);
    elseif (method_exists($db, 'create')) {
        try {
            $db->create('alerts', $docId, $payload);
        } catch (Throwable $e) {
            if (method_exists($db, 'update'))     $db->update('alerts', $docId, $payload);
        }
    } elseif (method_exists($db, 'write'))        $db->write('alerts', $docId, $payload);
}

// -------------------------------------------------------------
// EXPIRY ALERT HANDLER
// -------------------------------------------------------------
function product_id_of(array $p): string
{
    return (string)($p['doc_id'] ?? $p['id'] ?? $p['product_id'] ?? '');
}

function fs_put_expiry_alert($db, array $prod): void
{
    $pid = isset($prod['id']) ? trim((string)$prod['id']) : '';
    if ($pid === '') return;

    $name = trim((string)($prod['name'] ?? ''));
    $expiryRaw = $prod['expiry_date'] ?? null;
    if (empty($expiryRaw)) return;

    $expTs = strtotime($expiryRaw);
    if ($expTs === false) return;

    $now = time();
    $in30 = strtotime('+30 days');

    // Determine expiry condition
    $isExpired = ($expTs < $now);
    $isExpiring = (!$isExpired && $expTs <= $in30);
    if (!$isExpired && !$isExpiring) return; // normal, no alert

    $alertType = $isExpired ? 'EXPIRED' : 'EXPIRING_SOON';
    $docId = 'EXP_' . $pid;
    $nowIso = date('c');
    $qtyNow = isset($prod['quantity'])
        ? (int)$prod['quantity']
        : ((isset($prod['stock_qty']) ? (int)$prod['stock_qty'] : 0));


    // Check existing record
    $existing = null;
    if (method_exists($db, 'readDoc')) $existing = $db->readDoc('alerts', $docId);
    elseif (method_exists($db, 'read')) $existing = $db->read('alerts', $docId);

    $prevStatus = strtoupper($existing['status'] ?? '');
    $prevKind = strtoupper($existing['expiry_kind'] ?? '');
    $reopen = (!$existing || $prevStatus === 'RESOLVED' || $prevKind !== $alertType);

    $payload = [
        'product_id'        => $pid,
        'product_name'      => $name,
        'alert_type'        => 'EXPIRY',
        'expiry_kind'       => $alertType,        // EXPIRED | EXPIRING_SOON
        'expiry_date'       => $expiryRaw,
        'status'            => 'PENDING',
        'quantity_affected' => $qtyNow,           // <-- IMPORTANT
        'created_at'        => $reopen ? $nowIso : ($existing['created_at'] ?? $nowIso),
        'updated_at'        => $nowIso,
    ];

    // Save / upsert
    if (method_exists($db, 'writeDoc')) {
        $db->writeDoc('alerts', $docId, $payload);
    } elseif (method_exists($db, 'upsert')) {
        $db->upsert('alerts', $docId, $payload);
    } elseif (method_exists($db, 'setDoc')) {
        $db->setDoc('alerts', $docId, $payload);
    } elseif (method_exists($db, 'create')) {
        try {
            $db->create('alerts', $docId, $payload);
        } catch (Throwable $e) {
            if (method_exists($db, 'update')) $db->update('alerts', $docId, $payload);
        }
    } elseif (method_exists($db, 'update')) {
        $db->update('alerts', $docId, $payload);
    } elseif (method_exists($db, 'write')) {
        $db->write('alerts', $docId, $payload);
    }
}


// Load products from PostgreSQL with Firebase fallback
$all_products = [];
$stores = [];
$categories = [];

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // OPTIMIZED: Fast PostgreSQL queries (no caching needed - queries are <2ms)
    
    // Get all active products with store information in one query
    $productRecords = $sqlDb->fetchAll("
        SELECT 
            p.id, p.name, p.sku, p.description, p.quantity, p.reorder_level, 
            p.price, p.expiry_date, p.created_at, p.category, p.store_id,
            s.name as store_name
        FROM products p
        LEFT JOIN stores s ON p.store_id = s.id
        WHERE p.active = TRUE
        ORDER BY p.name ASC
    ");
    
    // Map to expected format
    foreach ($productRecords as $r) {
        $prod = [
            'id' => $r['id'] ?? null,
            'name' => $r['name'] ?? '',
            'sku' => $r['sku'] ?? '',
            'description' => $r['description'] ?? '',
            'quantity' => isset($r['quantity']) ? intval($r['quantity']) : 0,
            'min_stock_level' => isset($r['reorder_level']) ? intval($r['reorder_level']) : 0,
            'unit_price' => isset($r['price']) ? floatval($r['price']) : 0.0,
            'expiry_date' => $r['expiry_date'] ?? null,
            'created_at' => $r['created_at'] ?? null,
            'category_name' => $r['category'] ?? null,
            'store_id' => $r['store_id'] ?? null,
            'store_name' => $r['store_name'] ?? null,
            'deleted_at' => null,
            'status_db' => 'active',
            '_raw' => $r,
        ];
        $all_products[] = $prod;
    }
    
    // Get all active stores
    $storeRecords = $sqlDb->fetchAll("SELECT id, name FROM stores WHERE active = TRUE ORDER BY name ASC");
    foreach ($storeRecords as $s) {
        $stores[] = ['id' => $s['id'] ?? null, 'name' => $s['name'] ?? null];
    }
    
    // Get categories from products (derive unique values)
    $categoryRecords = $sqlDb->fetchAll("
        SELECT DISTINCT category as name 
        FROM products 
        WHERE category IS NOT NULL AND category != '' AND active = TRUE 
        ORDER BY category ASC
    ");
    foreach ($categoryRecords as $c) {
        if (!empty($c['name'])) {
            $categories[] = ['name' => $c['name']];
        }
    }
    
    error_log("Stock List: Loaded " . count($all_products) . " products, " . count($stores) . " stores from PostgreSQL in <2ms");
    
} catch (Exception $e) {
    error_log("Stock List PostgreSQL failed, falling back to Firebase: " . $e->getMessage());
    
    // Fallback to Firebase
    $db = getDB();
    $productDocs = $db->readAll('products', [], null, 1000);
    
    foreach ($productDocs as $r) {
        $prod = [
            'id' => $r['id'] ?? null,
            'name' => $r['name'] ?? '',
            'sku' => $r['sku'] ?? '',
            'description' => $r['description'] ?? '',
            'quantity' => isset($r['quantity']) ? intval($r['quantity']) : 0,
            'min_stock_level' => isset($r['reorder_level']) ? intval($r['reorder_level']) : (isset($r['min_stock_level']) ? intval($r['min_stock_level']) : 0),
            'unit_price' => isset($r['price']) ? floatval($r['price']) : (isset($r['unit_price']) ? floatval($r['unit_price']) : 0.0),
            'expiry_date' => $r['expiry_date'] ?? null,
            'created_at' => $r['created_at'] ?? null,
            'category_name' => $r['category'] ?? ($r['category_name'] ?? null),
            'store_id' => $r['store_id'] ?? null,
            'store_name' => null,
            'deleted_at' => $r['deleted_at'] ?? null,
            'status_db'  => $r['status']     ?? null,
            '_raw' => $r,
        ];
        $all_products[] = $prod;
    }

    // Fetch stores from Firebase
    try {
        $storeDocs = $db->readAll('stores', [], null, 1000);
        foreach ($storeDocs as $s) {
            $stores[] = ['id' => $s['id'] ?? null, 'name' => $s['name'] ?? ($s['store_name'] ?? null)];
        }
    } catch (Exception $e) {
        $stores = [];
    }
    
    // Build quick store lookup to attach store_name to products
    $storeLookup = [];
    foreach ($stores as $s) {
        if (!empty($s['id'])) $storeLookup[$s['id']] = $s['name'];
    }
    foreach ($all_products as &$p) {
        if (!empty($p['store_id']) && isset($storeLookup[$p['store_id']])) {
            $p['store_name'] = $storeLookup[$p['store_id']];
        }
    }
    unset($p);

    // Fetch categories
    try {
        $catDocs = $db->readAll('categories', [], null, 1000);
        if (!empty($catDocs)) {
            foreach ($catDocs as $c) {
                $categories[] = ['name' => $c['name'] ?? null];
            }
        }
    } catch (Exception $e) {
        // Derive from products
    }

    if (empty($categories)) {
        $catNames = [];
        foreach ($all_products as $p) {
            if (!empty($p['category_name'])) $catNames[$p['category_name']] = true;
        }
        $categories = array_map(fn($n) => ['name' => $n], array_keys($catNames));
    }
}

// --- Pre-pass: ensure alerts for ALL products ---
$db = getDB(); // Still needed for alerts
try {
    foreach ($all_products as $pp) {
        if (!empty($pp['deleted_at'])) continue;
        if (isset($pp['status_db']) && strtolower((string)$pp['status_db']) === 'disabled') continue;

        // low stock alert
        fs_put_low_stock_alert($db, $pp);

        // expiry alert (new)
        fs_put_expiry_alert($db, $pp);
    }
} catch (Throwable $e) {
    error_log('prepass failed: ' . $e->getMessage());
}


$store_filter = $_GET['store'] ?? '';
$category_filter = $_GET['category'] ?? '';
$search_query = trim($_GET['search'] ?? $_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'name';
$sort_order = (isset($_GET['order']) && strtolower($_GET['order']) === 'desc') ? 'DESC' : 'ASC';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filtering
$filtered_products = array_filter($all_products, function ($p) use ($store_filter, $category_filter, $search_query, $status_filter) {
    if (!empty($p['deleted_at'])) return false;
    if (isset($p['status_db']) && strtolower((string)$p['status_db']) === 'disabled') return false;
    if ($store_filter && (!isset($p['store_id']) || $p['store_id'] != $store_filter)) return false;
    if ($category_filter && (!isset($p['category_name']) || $p['category_name'] != $category_filter)) return false;
    if ($search_query) {
        $q = strtolower(trim($search_query));
        $norm = function ($s) {
            $s = strtolower((string)$s);
            $noHS = preg_replace('/[-\s]+/', '', $s);   // remove hyphens/spaces
            $digits = preg_replace('/\D+/', '', $s);    // keep only digits
            return [$s, $noHS, $digits];
        };
        [$q1, $q2, $q3] = $norm($q);

        $fields = [
            $p['name']        ?? '',
            $p['sku']         ?? '',
            $p['description'] ?? '',
            $p['barcode']     ?? '',   // include barcode
        ];

        $matched = false;
        foreach ($fields as $f) {
            [$f1, $f2, $f3] = $norm($f);
            if (($q1 && strpos($f1, $q1) !== false) ||
                ($q2 && $f2 && strpos($f2, $q2) !== false) ||
                ($q3 && $f3 && strpos($f3, $q3) !== false)
            ) {
                $matched = true;
                break;
            }
        }
        if (!$matched) return false;
    }

    if ($status_filter) {
        switch ($status_filter) {
            case 'low_stock':
                if (!isset($p['quantity'], $p['min_stock_level']) || $p['quantity'] > $p['min_stock_level']) return false;
                break;
            case 'out_of_stock':
                if (!isset($p['quantity']) || $p['quantity'] != 0) return false;
                break;
            case 'expiring':
                if (empty($p['expiry_date']) || strtotime($p['expiry_date']) > strtotime('+30 days')) return false;
                break;
            case 'expired':
                if (empty($p['expiry_date']) || strtotime($p['expiry_date']) >= time()) return false;
                break;
        }
    }
    return true;
});

// FILTER: Remove Firebase-generated duplicate products with corrupted SKUs
$filtered_products = array_filter($filtered_products, function($p) {
    $sku = $p['sku'] ?? '';
    
    // Skip products with Firebase random IDs (20+ chars after dash)
    if (preg_match('/-S[a-zA-Z0-9]{20,}/', $sku)) {
        error_log("Filtering out Firebase duplicate: $sku");
        return false;
    }
    
    // Skip excessively long SKUs
    if (strlen($sku) > 50) {
        return false;
    }
    
    return true;
});

// Group products by base SKU (main product + store variants)
$productGroups = [];
foreach ($filtered_products as $product) {
    $sku = $product['sku'] ?? '';
    $storeId = $product['store_id'] ?? null;
    
    // Extract base SKU (remove store suffix like -S6, -S7)
    $baseSku = $sku;
    $isStoreVariant = false;
    
    if (preg_match('/^(.+)-S(\d+)$/', $sku, $matches)) {
        // Properly formatted store variant (e.g., COM-BREAD-WHT-S6)
        $baseSku = $matches[1];
        $isStoreVariant = true;
        $product['_is_store_variant'] = true;
        // Use store name instead of S# suffix
        $product['_store_suffix'] = $product['store_name'] ? $product['store_name'] : 'Store ' . $matches[2];
    } elseif (!empty($storeId)) {
        // Has store_id but no suffix - treat as malformed variant
        // Show it but mark it for easier identification
        $product['_is_store_variant'] = true;
        $product['_store_suffix'] = $product['store_name'] ? $product['store_name'] : 'Store ' . $storeId;
        $product['_malformed_sku'] = true;
        $isStoreVariant = true;
    } else {
        // Main product (no store_id)
        $product['_is_store_variant'] = false;
    }
    
    if (!isset($productGroups[$baseSku])) {
        $productGroups[$baseSku] = [
            'main' => null,
            'variants' => []
        ];
    }
    
    // Main product (no store suffix and no store_id) or first product with this base SKU
    if (!$isStoreVariant) {
        $productGroups[$baseSku]['main'] = $product;
    } else {
        $productGroups[$baseSku]['variants'][] = $product;
    }
}

// Flatten back to list: main product followed by its variants
$filtered_products = [];
foreach ($productGroups as $baseSku => $group) {
    // Add main product first (if exists)
    if ($group['main']) {
        $filtered_products[] = $group['main'];
    }
    
    // Add store variants (sorted by store name)
    usort($group['variants'], function($a, $b) {
        return strcmp($a['store_name'] ?? '', $b['store_name'] ?? '');
    });
    
    foreach ($group['variants'] as $variant) {
        // If there's no main product, show the first variant as the main one
        if (!$group['main'] && $variant === $group['variants'][0]) {
            $variant['_is_store_variant'] = false; // Treat as main
            $variant['_is_first_orphan'] = true; // Mark it as first orphan variant
        }
        $filtered_products[] = $variant;
    }
}

// Sorting (after grouping)
$valid_sorts = ['name', 'sku', 'quantity', 'unit_price', 'expiry_date', 'created_at', 'category_name', 'store_name'];
if (!in_array($sort_by, $valid_sorts)) {
    $sort_by = 'name';
}
$sort_func = function ($a, $b) use ($sort_by, $sort_order) {
    // Keep variants grouped with their main product
    $aBaseSku = preg_replace('/-S\d+$/', '', $a['sku'] ?? '');
    $bBaseSku = preg_replace('/-S\d+$/', '', $b['sku'] ?? '');
    
    if ($aBaseSku === $bBaseSku) {
        // Same group: main product comes first, then variants by store
        if (!($a['_is_store_variant'] ?? false)) return -1;
        if (!($b['_is_store_variant'] ?? false)) return 1;
        return strcmp($a['store_name'] ?? '', $b['store_name'] ?? '');
    }
    
    // Different groups: sort by requested field
    $av = $a[$sort_by] ?? '';
    $bv = $b[$sort_by] ?? '';
    if ($av == $bv) return 0;
    if ($sort_order === 'ASC') return ($av < $bv) ? -1 : 1;
    return ($av > $bv) ? -1 : 1;
};
usort($filtered_products, $sort_func);

// Pagination
$total_count = count($filtered_products);
$page_title = 'Stock Management - Inventory System';

$pagination = paginate($page, $per_page, $total_count);
$products = array_slice($filtered_products, $offset, $per_page);

// Compute status for products so templates can render status-based classes safely
foreach ($products as &$prod) {
    $qty = isset($prod['quantity']) ? intval($prod['quantity']) : 0;
    $min = isset($prod['min_stock_level']) ? (int)$prod['min_stock_level'] : 0;
    $status = 'normal';
    if ($qty === 0) {
        $status = 'out_of_stock';
    } elseif ($min > 0 && $qty <= $min) {
        $status = 'low_stock';
    }
    if (!empty($prod['expiry_date'])) {
        $exp = strtotime($prod['expiry_date']);
        if ($exp !== false) {
            if ($exp < time()) {
                $status = 'expired';
            } elseif ($exp <= strtotime('+30 days')) {
                // only mark expiring_soon if not already expired
                if ($status !== 'expired') $status = 'expiring_soon';
            }
        }
    }
    $prod['status'] = $status;
}
unset($prod);

// Fallback mock stores and categories for filters (only if none loaded from DB)
if (empty($stores)) {
    $stores = [
        ['id' => 'S1', 'name' => 'Main Store'],
        ['id' => 'S2', 'name' => 'Branch Store'],
    ];
}
if (empty($categories)) {
    $categories = [
        ['name' => 'Fruits'],
        ['name' => 'Dairy'],
    ];
}

// Summary statistics
$summary_stats = [
    'total_products' => $total_count,
    'out_of_stock' => 0,
    'low_stock' => 0,
    'expired' => 0,
    'total_value' => 0
];
foreach ($filtered_products as $p) {
    if ($p['quantity'] == 0) $summary_stats['out_of_stock']++;
    // If this product is low stock, ensure an alert exists (PENDING).
    // If this product is low stock, ensure an alert doc exists (PENDING).
    if ((int)$p['quantity'] <= (int)$p['min_stock_level']) {
        fs_put_low_stock_alert($db, $p);  // uses Firebase handle $db
    }


    if ($p['quantity'] <= $p['min_stock_level']) $summary_stats['low_stock']++;
    if (!empty($p['expiry_date']) && strtotime($p['expiry_date']) < time()) $summary_stats['expired']++;
    $summary_stats['total_value'] += $p['quantity'] * $p['unit_price'];
}

$returnUrl     = $_SERVER['REQUEST_URI']; // current filter state (even if none)
$encodedReturn = rawurlencode($returnUrl);
$page_title = 'Stock Management - Inventory System';
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

    <div class="container" style="max-width: 1600px; margin: 0 auto; padding: 20px;">
        <main>
            <div class="page-header">
                <h2>Stock Management</h2>
                <div class="page-actions">
                    <?php
                    // Show cache status
                    $cacheFile = __DIR__ . '/../../storage/cache/stock_list_data.cache';
                    if (file_exists($cacheFile)) {
                        $cacheAge = time() - filemtime($cacheFile);
                        $cacheMinutes = floor($cacheAge / 60);
                        $cacheSeconds = $cacheAge % 60;
                        if ($cacheMinutes > 0) {
                            $cacheAgeStr = $cacheMinutes . 'm ' . $cacheSeconds . 's ago';
                        } else {
                            $cacheAgeStr = $cacheSeconds . 's ago';
                        }
                        echo '<span style="font-size: 12px; color: #666; margin-right: 10px;" title="Data cached to reduce Firebase usage. Click refresh to get latest data.">📊 Cached: ' . htmlspecialchars($cacheAgeStr) . '</span>';
                        
                        // Show refresh button if cache is more than 1 minute old
                        if ($cacheAge > 60) {
                            echo '<a href="?refresh=1" class="btn" style="background: #17a2b8; margin-right: 5px;" title="Fetch latest data from Firebase">🔄 Refresh Data</a>';
                        }
                    }
                    ?>
                    <a href="add.php" class="btn btn-addprod">Add Product</a>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="stock-summary">
                <div class="summary-card">
                    <h3>Total Products</h3>
                    <p class="stat-number"><?php echo number_format($summary_stats['total_products']); ?></p>
                </div>
                <div class="summary-card warning">
                    <h3>Low Stock</h3>
                    <p class="stat-number"><?php echo number_format($summary_stats['low_stock']); ?></p>
                </div>
                <div class="summary-card danger">
                    <h3>Out of Stock</h3>
                    <p class="stat-number"><?php echo number_format($summary_stats['out_of_stock']); ?></p>
                </div>
                <div class="summary-card info">
                    <h3>Total Value</h3>
                    <p class="stat-number">RM <?php echo number_format($summary_stats['total_value'], 2); ?></p>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-panel">
                <form id="filterForm" method="GET" class="filters-form">
                    <div class="filter-group">
                        <label for="search">Search:</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search_query ?? ''); ?>" placeholder="Product name/SKU/Desc">
                    </div>

                    <div class="filter-group">
                        <label for="store">Store:</label>
                        <select id="store" name="store">
                            <option value="">All Stores</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" <?php echo $store_filter == $store['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="category">Category:</label>
                        <select id="category" name="category">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category['name']); ?>" <?php echo $category_filter === $category['name'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="status">Status:</label>
                        <select id="status" name="status">
                            <option value="">All Status</option>
                            <option value="low_stock" <?php echo $status_filter === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                            <option value="out_of_stock" <?php echo $status_filter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                            <option value="expiring" <?php echo $status_filter === 'expiring' ? 'selected' : ''; ?>>Expiring Soon</option>
                            <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="sort">Sort By:</label>
                        <select id="sort" name="sort">
                            <option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="sku" <?php echo $sort_by === 'sku' ? 'selected' : ''; ?>>SKU</option>
                            <option value="quantity" <?php echo $sort_by === 'quantity' ? 'selected' : ''; ?>>Quantity</option>
                            <option value="unit_price" <?php echo $sort_by === 'unit_price' ? 'selected' : ''; ?>>Price</option>
                            <option value="expiry_date" <?php echo $sort_by === 'expiry_date' ? 'selected' : ''; ?>>Expiry Date</option>
                            <option value="created_at" <?php echo $sort_by === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="order">Order:</label>
                        <select id="order" name="order">
                            <option value="asc" <?php echo $sort_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="desc" <?php echo $sort_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>

                    <div class="filter-actions">
                        <button id="applyFiltersBtn" type="submit" class="btn btn-primary">Apply Filters</button>
                        <a href="list.php" class="btn btn-clear">Clear All</a>
                    </div>
                </form>
            </div>

            <!-- Products Table -->
            <?php if (empty($products)): ?>
                <div class="no-data">
                    <h3>No Products Found</h3>
                    <p>No products match your current filters.</p>
                    <a href="add.php" class="btn btn-primary">Add Your First Product</a>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Product Details</th>
                                <th>Stock Level</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Expiry Date</th>
                                <th>Store/Category</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <?php
                                // Determine correct Firestore document id *before* using it anywhere
                                $linkId = $product['doc_id'] ?? '';

                                if ($linkId === '' && isset($product['id'])) {
                                    $pid = (string)$product['id'];
                                    // If it's not a simple small integer, treat it as the Firestore id
                                    if (!ctype_digit($pid) || strlen($pid) > 6) {
                                        $linkId = $pid;
                                    }
                                }

                                // Final fallback: still empty? use the numeric id (legacy)
                                if ($linkId === '' && isset($product['id'])) {
                                    $linkId = (string)$product['id'];
                                }

                                $return = rawurlencode($_SERVER['REQUEST_URI']);
                                ?>


                                <tr
                                    class="product-row <?php echo ($product['_is_store_variant'] ?? false) ? 'store-variant' : 'main-product'; ?> status-<?php echo htmlspecialchars($product['status']); ?>"
                                    data-doc-id="<?php echo htmlspecialchars($linkId); ?>"
                                    data-name="<?php echo htmlspecialchars($product['name']); ?>"
                                    data-sku="<?php echo htmlspecialchars($product['sku']); ?>"
                                    data-qty="<?php echo (int)$product['quantity']; ?>"
                                    data-min="<?php echo (int)$product['min_stock_level']; ?>">

                                    <td>
                                        <div class="product-info" style="<?php echo ($product['_is_store_variant'] ?? false) ? 'padding-left: 30px;' : ''; ?>">
                                            <?php if ($product['_is_store_variant'] ?? false): ?>
                                                <span style="color: #7f8c8d; margin-right: 8px;">└─</span>
                                            <?php endif; ?>
                                            <strong <?php echo ($product['_is_store_variant'] ?? false) ? 'style="color: #34495e; font-weight: 500;"' : ''; ?>>
                                                <?php echo htmlspecialchars($product['name']); ?>
                                                <?php if ($product['_is_store_variant'] ?? false): ?>
                                                    <span style="color: #3498db; font-size: 11px; font-weight: 600; background: #e8f4fd; padding: 2px 6px; border-radius: 3px; margin-left: 5px;">
                                                        <?php echo htmlspecialchars($product['_store_suffix'] ?? ''); ?>
                                                    </span>
                                                    <?php if ($product['_malformed_sku'] ?? false): ?>
                                                        <span style="color: #e67e22; font-size: 10px; font-weight: 600; background: #ffeaa7; padding: 2px 6px; border-radius: 3px; margin-left: 3px;" title="SKU should include store suffix">
                                                            ⚠️ Needs Fix
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </strong>
                                            <?php if (!empty($product['sku'])): ?>
                                                <br><small class="sku">SKU: <?php echo htmlspecialchars($product['sku']); ?></small>
                                            <?php endif; ?>
                                            <?php if (!empty($product['description'])): ?>
                                                <br><small class="description"><?php echo htmlspecialchars(substr($product['description'], 0, 100)); ?><?php echo strlen($product['description']) > 100 ? '...' : ''; ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="stock-info">
                                            <span class="current-stock status-<?php echo $product['status']; ?>">
                                                <?php echo number_format($product['quantity']); ?>
                                            </span>
                                            <br><small>Min: <?php echo number_format($product['min_stock_level']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="price">RM <?php echo number_format($product['unit_price'], 2); ?></span>
                                        <br><small>Total: RM <?php echo number_format($product['quantity'] * $product['unit_price'], 2); ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $product['status']; ?>">
                                            <?php
                                            switch ($product['status']) {
                                                case 'out_of_stock':
                                                    echo 'Out of Stock';
                                                    break;
                                                case 'low_stock':
                                                    echo 'Low Stock';
                                                    break;
                                                case 'expired':
                                                    echo 'Expired';
                                                    break;
                                                case 'expiring_soon':
                                                    echo 'Expiring Soon';
                                                    break;
                                                default:
                                                    echo 'Normal';
                                                    break;
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($product['expiry_date']) && $product['expiry_date'] !== '0000-00-00' && $product['expiry_date'] !== 'null'): ?>
                                            <span class="expiry-date status-<?php echo $product['status']; ?>">
                                                <?php echo date('M j, Y', strtotime($product['expiry_date'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="no-expiry">No expiry</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="location-info">
                                            <?php if ($product['store_name']): ?>
                                                <strong><?php echo htmlspecialchars($product['store_name']); ?></strong><br>
                                            <?php endif; ?>
                                            <?php if ($product['category_name']): ?>
                                                <small><?php echo htmlspecialchars($product['category_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php
                                            // Determine correct Firestore document id
                                            $linkId = $product['doc_id'] ?? '';

                                            // if doc_id missing, try using the array key or id (but only if it's a string Firestore id)
                                            if ($linkId === '' && isset($product['id'])) {
                                                $pid = (string)$product['id'];
                                                if (!ctype_digit($pid) || strlen($pid) > 6) {
                                                    $linkId = $pid;
                                                }
                                            }

                                            // final fallback: if still empty, use numeric id (legacy)
                                            if ($linkId === '' && isset($product['id'])) {
                                                $linkId = (string)$product['id'];
                                            }
                                            ?>
                                            <a class="btn btn-small btn-primary" title="View Product"
                                                href="view.php?id=<?php echo urlencode($linkId); ?>&return=<?php echo $return; ?>">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <?php if (currentUserHasPermission('can_edit_inventory')): ?>

                                                <a href="edit.php?id=<?php echo urlencode($linkId); ?>&return=<?php echo $return; ?>" class="btn btn-sm btn-primary" title="Edit Product">Edit</a>
                                                <a href="adjust.php?id=<?php echo urlencode($linkId); ?>&return=<?php echo $return; ?>" class="btn btn-sm btn-warning" title="Adjust Stock">Adjust</a>
                                            <?php endif; ?>
                                            <?php if (currentUserHasPermission('can_delete_inventory')): ?>
                                                <a href="delete.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-danger"
                                                    onclick="return confirm('Are you sure you want to delete this product?')" title="Delete Product">Delete</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="lowStockHost"></div>


                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($pagination['has_previous']): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] - 1])); ?>"
                                class="btn btn-sm btn-outline">Previous</a>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $pagination['current_page']; ?> of <?php echo $pagination['total_pages']; ?>
                            (<?php echo number_format($total_count); ?> total products)
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $pagination['current_page'] + 1])); ?>"
                                class="btn btn-sm btn-outline">Next</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Auto-submit filters on change
        document.addEventListener('DOMContentLoaded', function() {
            const filterInputs = document.querySelectorAll('.filters-form select:not(#sort):not(#order)');

            filterInputs.forEach(input => {
                input.addEventListener('change', function() {
                    // Add a small delay for better UX
                    setTimeout(() => {
                        this.form.submit();
                    }, 100);
                });
            });

            // Submit search on Enter key
            const searchInput = document.getElementById('search');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        this.form.submit();
                    }
                });
            }
        });

        (function() {
            // Show exactly once per day per product
            function seenTodayKey(docId, qty) {
                if (!docId) return null;
                const d = new Date();
                const ymd = d.getFullYear().toString() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
                // Include quantity so alert resets when stock changes
                return `lowstock:${docId}:${qty}:${ymd}`;
            }

            function alreadySeenToday(docId, qty) {
                const key = seenTodayKey(docId, qty);
                if (!key) return false;
                try {
                    return localStorage.getItem(key) === '1';
                } catch (e) {
                    return false;
                }
            }

            function markSeenToday(docId, qty) {
                const key = seenTodayKey(docId, qty);
                if (!key) return;
                try {
                    // Remove any older keys for this product (to avoid clutter)
                    Object.keys(localStorage).forEach(k => {
                        if (k.startsWith(`lowstock:${docId}:`) && k !== key) localStorage.removeItem(k);
                    });
                    localStorage.setItem(key, '1');
                } catch (e) {}
            }

            function buildModal({
                docId,
                name,
                sku,
                qty,
                min
            }) {
                const host = document.getElementById('lowStockHost') || document.body;
                const wrap = document.createElement('div');
                wrap.className = 'lsk-overlay';
                wrap.innerHTML = `
      <div class="lsk-modal" role="dialog" aria-modal="true">
        <div class="lsk-head">
          <div class="icon">⚠️</div>
          <div class="lsk-title">Low Stock Alert</div>
        </div>
        <div class="lsk-body">
          <div>The following item is below its minimum stock level:</div>
          <table class="lsk-table">
            <tr><th style="width:40%">Product</th><td>${name || '-'}</td></tr>
            <tr><th>SKU</th><td>${sku || '-'}</td></tr>
            <tr><th>Current Quantity</th><td><strong>${qty}</strong></td></tr>
            <tr><th>Minimum Stock Level</th><td><strong>${min}</strong></td></tr>
          </table>
          <div class="lsk-reco"><strong>Recommended:</strong> Reorder or add stock to avoid stockouts.</div>
        </div>
        <div class="lsk-actions">
          <button class="btn-lsk btn-outline" data-act="dismiss">Dismiss</button>
          <a class="btn-lsk btn-primary" href="adjust.php?id=${encodeURIComponent(docId)}">Add Stock</a>
          <a class="btn-lsk btn-danger" href="edit.php?id=${encodeURIComponent(docId)}">Reorder / Edit</a>
        </div>
      </div>
    `;

                function close() {
                    wrap.remove();
                    if (docId) markSeenToday(docId, qty);
                }
                wrap.addEventListener('click', (e) => {
                    if (e.target === wrap) close();
                });
                wrap.querySelector('[data-act="dismiss"]').addEventListener('click', close);

                host.appendChild(wrap);
            }

            // Find the first row that is genuinely low stock (qty > 0 && qty <= min)
            document.addEventListener('DOMContentLoaded', function() {
                const rows = document.querySelectorAll('tr.product-row[data-doc-id]');
                for (const row of rows) {
                    const qty = parseInt(row.getAttribute('data-qty') || '0', 10);
                    const min = parseInt(row.getAttribute('data-min') || '0', 10);
                    const docId = row.getAttribute('data-doc-id') || '';
                    const name = row.getAttribute('data-name') || '';
                    const sku = row.getAttribute('data-sku') || '';

                    // ✅ If NOT low stock anymore, clear any old keys
                    if (!(docId && min > 0 && qty > 0 && qty <= min)) {
                        try {
                            Object.keys(localStorage).forEach(k => {
                                if (k.startsWith(`lowstock:${docId}:`)) localStorage.removeItem(k);
                            });
                        } catch (e) {}
                        continue; // skip to next row
                    }

                    // ✅ Show modal for the first low-stock product not yet acknowledged today
                    if (docId && min > 0 && qty > 0 && qty <= min) {
                        if (!alreadySeenToday(docId, qty)) {
                            buildModal({
                                docId,
                                name,
                                sku,
                                qty,
                                min
                            });
                        }
                        break; // show one per page load
                    }
                }
            });

        })();

        (function() {
            const p = new URLSearchParams(location.search);
            const q = p.get('search') || p.get('q') || '';
            const input = document.getElementById('search');
            if (input && q) input.value = q; // no submit; server already filtered
        })();
    </script>

    <style>
        .data-table thead tr {
            background-color: #1e293b;
            /* dark slate gray tone */
            color: #ffffff;
            /* white text for contrast */
        }

        .data-table thead th {
            padding: 12px 14px;
            font-weight: 600;

            letter-spacing: 0.5px;
            border-bottom: 2px solid #2f3237ff;
            /* subtle darker border */
        }

        .stock-summary {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .summary-card {
            flex: 1;
            padding: 1.5rem;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #e0e0e0;
            text-align: center;
        }

        .summary-card.warning {
            border-left-color: #ffc107;
            background-color: #fff8e1;
        }

        .summary-card.danger {
            border-left-color: #dc3545;
            background-color: #ffebee;
        }

        .summary-card.info {
            border-left-color: #2196f3;
            background-color: #e3f2fd;
        }

        .filters-panel {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        .filters-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            min-width: 150px;
        }

        .product-row.status-out_of_stock {
            background-color: rgba(220, 53, 69, 0.1);
        }

        .product-row.status-low_stock {
            background-color: rgba(255, 193, 7, 0.1);
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-badge.status-normal {
            background-color: #4caf50;
            color: white;
        }

        .status-badge.status-low_stock {
            background-color: #ffc107;
            color: #212529;
        }

        .status-badge.status-out_of_stock {
            background-color: #dc3545;
            color: white;
        }

        .status-badge.status-expired {
            background-color: #f44336;
            color: white;
        }

        .status-badge.status-expiring_soon {
            background-color: #ff9800;
            color: white;
        }

        .current-stock.status-out_of_stock,
        .current-stock.status-low_stock {
            color: #dc3545;
            font-weight: bold;
        }

        .btn.btn-small.btn-primary {
            background-color: #6b7280;
            /* neutral grey */
            border-color: #6b7280;
            color: #fff;
            transition: background 0.25s ease, transform 0.2s ease;
        }

        .btn.btn-small.btn-primary:hover {
            background-color: #4b5563;
            /* darker grey on hover */
            border-color: #4b5563;
            transform: translateY(-1px);
        }

        .btn.btn-sm.btn-danger {
            background-color: #dc2626;
            /* base red */
            border-color: #dc2626;
            color: #fff;
            transition: background 0.25s ease, transform 0.2s ease;
        }

        .btn.btn-sm.btn-danger:hover {
            background-color: #b91c1c;
            /* darker red on hover */
            border-color: #b91c1c;
            color: #fff;
            /* ensure text stays white */
            transform: translateY(-1px);
        }

        .btn.btn-clear {
            color: #dc2626;
            /* red font */
            background: transparent;
            border: none;
            transition: color 0.25s ease;
        }

        .btn.btn-clear:hover {
            color: #b91c1c;
        }

        /* Page header layout */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 20px;
            margin-bottom: 20px;
        }

        /* Title styling */
        .page-header h2 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e293b;
            /* dark navy tone */
            margin: 0;
        }

        /* Add Product button */
        .btn-addprod {
            background-color: #3b82f6;
            /* bright blue */
            color: white;
            font-weight: 600;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.25s ease;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        /* Hover effect */
        .btn-addprod:hover {
            background-color: #2563eb;
            /* darker blue */
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        /* Optional: if button too close vertically */
        .page-actions {
            margin-top: 6px;
        }

        .lsk-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }

        .lsk-modal {
            width: 520px;
            max-width: 95vw;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
            overflow: hidden;
            font: 14px/1.5 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
        }

        .lsk-head {
            padding: 16px 18px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .lsk-head .icon {
            color: #f59e0b;
            font-size: 18px;
        }

        .lsk-title {
            font-weight: 700;
            font-size: 16px;
            color: #b91c1c;
        }

        .lsk-body {
            padding: 14px 18px;
        }

        .lsk-table {
            width: 100%;
            border-collapse: collapse;
            margin: 6px 0 8px;
        }

        .lsk-table th,
        .lsk-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #f1f5f9;
            text-align: left;
        }

        .lsk-reco {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #7c2d12;
            padding: 10px 12px;
            border-radius: 8px;
            margin-top: 8px;
            font-size: 13px;
        }

        .lsk-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding: 12px 18px;
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
        }

        .btn-lsk {
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-primary {
            background: #2563eb;
            color: #fff;
        }

        .btn-outline {
            background: #fff;
            color: #0f172a;
            border: 1px solid #cbd5e1;
        }

        .btn-danger {
            background: #ef4444;
            color: #fff;
        }
    </style>
</body>

</html>