<?php
// Store List Page - Optimized for Fast Loading with Offline Support
ob_start('ob_gzhandler'); // Enable compression

require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';
require_once '../../firebase_rest_client.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

// Require permission to view stores
if (!currentUserHasPermission('can_view_stores') && !currentUserHasPermission('can_add_stores') && !currentUserHasPermission('can_edit_stores')) {
    $_SESSION['error'] = 'You do not have permission to access stores';
    header('Location: ../../index.php');
    exit;
}

// Cache configuration
$cacheDir = '../../storage/cache/';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$cacheFile = $cacheDir . 'stores_list_' . md5('stores_list_data') . '.cache';
$cacheMaxAge = 300; // 5 minutes
$isOfflineMode = false;
$cacheAgeDisplay = '';
$message = '';
$messageType = '';

// Check if manual refresh is requested
$shouldRefreshCache = isset($_GET['refresh_cache']);

// Check cache age
$cacheExists = file_exists($cacheFile);
$cacheAge = $cacheExists ? (time() - filemtime($cacheFile)) : PHP_INT_MAX;
$cacheIsFresh = $cacheAge < $cacheMaxAge;

// Calculate cache age display
if ($cacheExists) {
    $cacheTimestamp = filemtime($cacheFile);
    $cacheAgeSeconds = time() - $cacheTimestamp;
    
    if ($cacheAgeSeconds < 60) {
        $cacheAgeDisplay = 'just now';
    } elseif ($cacheAgeSeconds < 3600) {
        $minutes = floor($cacheAgeSeconds / 60);
        $cacheAgeDisplay = $minutes . ' ' . ($minutes == 1 ? 'minute' : 'minutes') . ' ago';
    } elseif ($cacheAgeSeconds < 86400) {
        $hours = floor($cacheAgeSeconds / 3600);
        $cacheAgeDisplay = $hours . ' ' . ($hours == 1 ? 'hour' : 'hours') . ' ago';
    } else {
        $days = floor($cacheAgeSeconds / 86400);
        $cacheAgeDisplay = $days . ' ' . ($days == 1 ? 'day' : 'days') . ' ago';
    }
}

// Initialize variables
$all_stores = [];
$all_products = [];
$fetchedFromFirebase = false;

// Try to fetch from Firebase if cache is stale or refresh requested
if ($shouldRefreshCache || !$cacheIsFresh) {
    try {
        $client = new FirebaseRestClient();
        // IMPORTANT: Limit queries to prevent excessive Firebase reads
        // queryCollection now has a default limit of 100, but we explicitly set reasonable limits
        $firebaseStores = $client->queryCollection('stores', 200); // Max 200 stores
        $firebaseProducts = $client->queryCollection('products', 300); // Max 300 products for overview
        
        error_log("Firebase fetch attempt - Stores count: " . count($firebaseStores) . ", Products count: " . count($firebaseProducts));
        
        // Check if we got data (not just empty arrays from errors)
        if (is_array($firebaseStores) && count($firebaseStores) > 0) {
            // Filter active stores
            $all_stores = array_filter($firebaseStores, function($s) {
                return isset($s['active']) && $s['active'] == 1;
            });
            
            // Filter active products (might be empty, that's ok)
            $all_products = is_array($firebaseProducts) ? array_filter($firebaseProducts, function($p) {
                return isset($p['active']) && $p['active'] == 1;
            }) : [];
            
            // Save to cache
            $cacheData = [
                'stores' => array_values($all_stores),
                'products' => array_values($all_products),
                'timestamp' => time()
            ];
            file_put_contents($cacheFile, json_encode($cacheData));
            
            $fetchedFromFirebase = true;
            error_log("Firebase fetch successful - saved to cache");
            
            if ($shouldRefreshCache && !isset($_GET['silent'])) {
                $message = 'Cache refreshed successfully! You\'re viewing the latest data.';
                $messageType = 'success';
            }
        } else {
            error_log("Firebase returned empty or invalid data");
            throw new Exception('Firebase returned no data');
        }
    } catch (Exception $e) {
        error_log('Firebase fetch failed for stores list: ' . $e->getMessage());
        // Fall through to cache loading below
    }
}

// Load from cache if Firebase fetch failed or wasn't attempted
if (empty($all_stores) && $cacheExists) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if ($cacheData) {
        $all_stores = $cacheData['stores'] ?? [];
        $all_products = $cacheData['products'] ?? [];
        // Only set offline mode if we tried to fetch from Firebase but failed
        if ($shouldRefreshCache || !$cacheIsFresh) {
            $isOfflineMode = true;
        }
    }
}

// If still no data, show error
if (empty($all_stores)) {
    die('Unable to load store data. Please check your connection and try again.');
}

// Pagination and search
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$search = sanitizeInput($_GET['search'] ?? '');

$stores = [];
if (!empty($search)) {
    $search_lower = mb_strtolower($search);
    foreach ($all_stores as $store) {
        $fields = [
            mb_strtolower($store['name'] ?? ''),
            mb_strtolower($store['address'] ?? ''),
            mb_strtolower($store['phone'] ?? '')
        ];
        $found = false;
        foreach ($fields as $field) {
            if (strpos($field, $search_lower) !== false) {
                $found = true;
                break;
            }
        }
        if ($found) $stores[] = $store;
    }
} else {
    $stores = $all_stores;
}

$total_records = count($stores);
$pagination = paginate($page, $per_page, $total_records);
$stores = array_slice($stores, $pagination['offset'], $pagination['per_page']);

// Group products by store_id for fast lookup
$products_by_store = [];
foreach ($all_products as $product) {
    $store_id = $product['store_id'] ?? null;
    if ($store_id) {
        if (!isset($products_by_store[$store_id])) {
            $products_by_store[$store_id] = [];
        }
        $products_by_store[$store_id][] = $product;
    }
}

// Now attach counts to each store (O(1) lookup instead of O(N) queries)
foreach ($stores as &$store) {
    $store_products = $products_by_store[$store['id']] ?? [];
    $store['product_count'] = count($store_products);
    $store['total_stock'] = 0;
    foreach ($store_products as $product) {
        $store['total_stock'] += isset($product['quantity']) ? (int)$product['quantity'] : 0;
    }
}
unset($store);

// HTTP caching headers
header('Cache-Control: private, max-age=300'); // Cache for 5 minutes
header('Vary: Cookie');

$page_title = 'Store Management - Inventory System';
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
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .page-header h2 {
            margin: 0 0 10px 0;
            font-size: 2.2rem;
            font-weight: 700;
        }
        
        .page-header p {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .page-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .page-actions .btn {
            padding: 12px 24px;
            border-radius: 25px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        .btn-primary:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: white;
            border: 2px solid rgba(255,255,255,0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background: transparent;
            color: white;
            border: 2px solid rgba(255,255,255,0.4);
        }
        
        .btn-outline:hover {
            background: rgba(255,255,255,0.1);
            transform: translateY(-2px);
        }
        
        .search-section {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-form input {
            flex: 1;
            min-width: 250px;
            padding: 15px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }
        
        .search-form input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .search-form .btn {
            padding: 15px 25px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .search-form .btn-secondary {
            background: #667eea;
            color: white;
            border: 2px solid #667eea;
        }
        
        .search-form .btn-secondary:hover {
            background: #5a6fd8;
            transform: translateY(-2px);
        }
        
        .search-form .btn-outline {
            background: transparent;
            color: #64748b;
            border: 2px solid #e2e8f0;
        }
        
        .search-form .btn-outline:hover {
            border-color: #667eea;
            color: #667eea;
        }
        
        .table-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .data-table th,
        .data-table td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .data-table th {
            font-weight: 600;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table tbody tr {
            transition: all 0.3s ease;
        }
        
        .data-table tbody tr:hover {
            background: #f8fafc;
            transform: scale(1.01);
        }
        
        .store-stats {
            display: flex;
            gap: 15px;
            font-size: 0.9rem;
        }
        
        .stat-badge {
            background: #e2e8f0;
            color: #475569;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .stat-badge.products {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .stat-badge.stock {
            background: #dcfce7;
            color: #166534;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-buttons .btn {
            padding: 8px 16px;
            font-size: 0.85rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-sm.btn-primary {
            background: #667eea;
            color: white;
        }
        
        .btn-sm.btn-primary:hover {
            background: #5a6fd8;
            transform: translateY(-1px);
        }
        
        .btn-sm.btn-secondary {
            background: #64748b;
            color: white;
        }
        
        .btn-sm.btn-secondary:hover {
            background: #475569;
            transform: translateY(-1px);
        }
        
        .btn-sm.btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-sm.btn-warning:hover {
            background: #d97706;
            transform: translateY(-1px);
        }
        
        .no-data {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .no-data i {
            font-size: 4rem;
            color: #e2e8f0;
            margin-bottom: 20px;
        }
        
        .no-data p {
            font-size: 1.2rem;
            color: #64748b;
            margin-bottom: 30px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 30px 0;
        }
        
        .pagination a,
        .pagination span {
            padding: 12px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .pagination a {
            background: white;
            color: #667eea;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .pagination a:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
        }
        
        .pagination .current {
            background: #667eea;
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        /* Profile Section Styling (for Management Dashboard) */
        .profile-section {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .profile-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .section-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 1.2rem;
        }
        
        .section-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.4rem;
        }
        
        .info-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }
        
        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 35px rgba(0,0,0,0.15) !important;
        }
        
        /* Bulk Operations Toolbar */
        .bulk-toolbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .bulk-toolbar-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .bulk-selection-info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .bulk-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .bulk-actions .btn {
            border: 2px solid rgba(255,255,255,0.3);
            background: rgba(255,255,255,0.2);
            color: white;
            transition: all 0.3s ease;
        }
        
        .bulk-actions .btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .bulk-actions .btn-outline {
            background: transparent;
            border-color: white;
        }
        
        /* Quick Edit Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 10px 50px rgba(0,0,0,0.3);
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .close:hover {
            transform: scale(1.2);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .modal-footer {
            padding: 20px 30px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .status-badge.active {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Advanced Filters Panel */
        .advanced-filters {
            background: white;
            border-radius: 12px;
            padding: 0;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            animation: slideDown 0.3s ease;
            overflow: hidden;
        }
        
        .filters-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filters-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }
        
        .filters-header h3 i {
            margin-right: 10px;
        }
        
        .filters-content {
            padding: 30px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        
        .filter-group label i {
            margin-right: 8px;
            color: #667eea;
        }
        
        .filters-actions {
            display: flex;
            gap: 15px;
            align-items: center;
            padding-top: 20px;
            border-top: 2px solid #f1f5f9;
        }
        
        .filter-results {
            margin-left: auto;
            font-weight: 600;
            color: #667eea;
        }
    </style>
    <script>
        // Define all JavaScript functions in head to ensure they're available before HTML uses them
        
        // Bulk Operations Functions
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.store-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateBulkToolbar();
        }
        
        function updateBulkToolbar() {
            const checkboxes = document.querySelectorAll('.store-checkbox:checked');
            const toolbar = document.getElementById('bulkToolbar');
            const countSpan = document.getElementById('selectedCount');
            const selectAllCheckbox = document.getElementById('selectAll');
            
            if (!toolbar || !countSpan || !selectAllCheckbox) return;
            
            const totalCheckboxes = document.querySelectorAll('.store-checkbox').length;
            const checkedCount = checkboxes.length;
            
            if (checkedCount > 0) {
                toolbar.style.display = 'block';
                countSpan.textContent = checkedCount;
            } else {
                toolbar.style.display = 'none';
            }
            
            selectAllCheckbox.checked = checkedCount === totalCheckboxes && totalCheckboxes > 0;
            selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < totalCheckboxes;
        }
        
        function getSelectedStoreIds() {
            const checkboxes = document.querySelectorAll('.store-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }
        
        function clearSelection() {
            const checkboxes = document.querySelectorAll('.store-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) selectAllCheckbox.checked = false;
            updateBulkToolbar();
        }
        
        async function bulkActivate() {
            const storeIds = getSelectedStoreIds();
            console.log('Selected store IDs:', storeIds);
            
            if (storeIds.length === 0) {
                alert('Please select at least one store');
                return;
            }
            
            if (!confirm(`Are you sure you want to activate ${storeIds.length} store(s)?`)) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'bulk_activate');
                formData.append('store_ids', JSON.stringify(storeIds));
                
                console.log('Sending request to:', 'api/store_operations.php');
                
                const response = await fetch('api/store_operations.php', {
                    method: 'POST',
                    body: formData
                });
                
                console.log('Response status:', response.status);
                const data = await response.json();
                console.log('Response data:', data);
                
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while activating stores: ' + error.message);
            }
        }
        
        async function bulkDeactivate() {
            const storeIds = getSelectedStoreIds();
            console.log('Selected store IDs:', storeIds);
            
            if (storeIds.length === 0) {
                alert('Please select at least one store');
                return;
            }
            
            if (!confirm(`Are you sure you want to deactivate ${storeIds.length} store(s)?`)) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'bulk_deactivate');
                formData.append('store_ids', JSON.stringify(storeIds));
                
                console.log('Sending deactivate request...');
                
                const response = await fetch('api/store_operations.php', {
                    method: 'POST',
                    body: formData
                });
                
                console.log('Response status:', response.status);
                const data = await response.json();
                console.log('Response data:', data);
                
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while deactivating stores: ' + error.message);
            }
        }
        
        async function bulkDelete() {
            const storeIds = getSelectedStoreIds();
            console.log('Selected store IDs for deletion:', storeIds);
            
            if (storeIds.length === 0) {
                alert('Please select at least one store');
                return;
            }
            
            if (!confirm(`⚠️ WARNING: Are you sure you want to delete ${storeIds.length} store(s)? This action cannot be undone!`)) {
                return;
            }
            
            if (!confirm('Please confirm again that you want to delete these stores permanently.')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'bulk_delete');
                formData.append('store_ids', JSON.stringify(storeIds));
                
                console.log('Sending delete request...');
                
                const response = await fetch('api/store_operations.php', {
                    method: 'POST',
                    body: formData
                });
                
                console.log('Response status:', response.status);
                const data = await response.json();
                console.log('Response data:', data);
                
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while deleting stores: ' + error.message);
            }
        }
        
        async function bulkExport() {
            const storeIds = getSelectedStoreIds();
            if (storeIds.length === 0) {
                alert('Please select at least one store to export');
                return;
            }
            
            const format = confirm('Click OK for CSV format, Cancel for JSON format') ? 'csv' : 'json';
            const params = new URLSearchParams({
                action: 'export',
                format: format
            });
            
            storeIds.forEach(id => params.append('store_ids[]', id));
            window.location.href = `api/store_operations.php?${params.toString()}`;
        }
        
        async function toggleStoreStatus(storeId, currentStatus) {
            const action = currentStatus ? 'deactivate' : 'activate';
            if (!confirm(`Are you sure you want to ${action} this store?`)) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'toggle_status');
                formData.append('store_id', storeId);
                
                const response = await fetch('api/store_operations.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while updating store status');
            }
        }
        
        async function duplicateStore(storeId) {
            if (!confirm('Do you want to create a duplicate of this store?')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'duplicate');
                formData.append('store_id', storeId);
                
                const response = await fetch('api/store_operations.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while duplicating store');
            }
        }
        
        async function enablePOS(storeId, storeName) {
            if (!confirm(`Enable POS for "${storeName}"?\n\nThis will allow this store to use the Point of Sale system.`)) {
                return;
            }
            
            const button = document.getElementById(`enable-pos-${storeId}`);
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enabling...';
            }
            
            try {
                const formData = new FormData();
                formData.append('store_id', storeId);
                
                const response = await fetch('api/enable_pos.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                    if (button) {
                        button.disabled = false;
                        button.innerHTML = '<i class="fas fa-plus-circle"></i> Enable POS';
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while enabling POS');
                if (button) {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-plus-circle"></i> Enable POS';
                }
            }
        }

        
        // Quick Edit Modal Functions
        async function openQuickEdit(storeId) {
            try {
                const response = await fetch(`api/store_operations.php?action=get_store&store_id=${storeId}`);
                const data = await response.json();
                
                if (data.success) {
                    const store = data.store;
                    document.getElementById('edit_store_id').value = storeId;
                    document.getElementById('edit_name').value = store.name || '';
                    document.getElementById('edit_code').value = store.code || '';
                    document.getElementById('edit_phone').value = store.phone || '';
                    document.getElementById('edit_email').value = store.email || '';
                    document.getElementById('edit_manager').value = store.manager_name || '';
                    document.getElementById('edit_address').value = store.address || '';
                    document.getElementById('edit_city').value = store.city || '';
                    document.getElementById('edit_state').value = store.state || '';
                    
                    document.getElementById('quickEditModal').style.display = 'block';
                } else {
                    alert('Error loading store data: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while loading store data');
            }
        }
        
        function closeQuickEditModal() {
            document.getElementById('quickEditModal').style.display = 'none';
            document.getElementById('quickEditForm').reset();
        }
        
        async function saveQuickEdit() {
            const form = document.getElementById('quickEditForm');
            const formData = new FormData(form);
            formData.append('action', 'quick_edit');
            
            const name = document.getElementById('edit_name').value.trim();
            if (!name) {
                alert('Store name is required');
                document.getElementById('edit_name').focus();
                return;
            }
            
            try {
                const response = await fetch('api/store_operations.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert(data.message);
                    closeQuickEditModal();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while saving changes');
            }
        }
        
        // Advanced Filters Functions
        function toggleAdvancedFilters() {
            const panel = document.getElementById('advancedFilters');
            if (panel) {
                panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
            }
        }
        
        async function applyAdvancedFilters() {
            const filters = {
                search: document.querySelector('input[name="search"]')?.value || '',
                status: document.getElementById('filter_status')?.value || '',
                city: document.getElementById('filter_city')?.value || '',
                state: document.getElementById('filter_state')?.value || '',
                manager: document.getElementById('filter_manager')?.value || '',
                date_from: document.getElementById('filter_date_from')?.value || '',
                date_to: document.getElementById('filter_date_to')?.value || '',
                min_products: document.getElementById('filter_min_products')?.value || '',
                max_products: document.getElementById('filter_max_products')?.value || ''
            };
            
            const filterResults = document.getElementById('filter_results');
            if (filterResults) {
                filterResults.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Filtering...';
            }
            
            try {
                const params = new URLSearchParams(filters);
                const response = await fetch(`api/store_operations.php?action=search&${params.toString()}`);
                const data = await response.json();
                
                if (data.success && filterResults) {
                    filterResults.innerHTML = `<i class="fas fa-check-circle"></i> Found ${data.total} store(s)`;
                    
                    const url = new URL(window.location);
                    Object.keys(filters).forEach(key => {
                        if (filters[key]) {
                            url.searchParams.set('filter_' + key, filters[key]);
                        }
                    });
                    window.location.href = url.toString();
                } else if (filterResults) {
                    filterResults.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Error: ${data.message}`;
                }
            } catch (error) {
                console.error('Error:', error);
                if (filterResults) {
                    filterResults.innerHTML = '<i class="fas fa-exclamation-triangle"></i> An error occurred';
                }
            }
        }
        
        function clearAdvancedFilters() {
            const fields = ['filter_status', 'filter_city', 'filter_state', 'filter_manager', 
                          'filter_date_from', 'filter_date_to', 'filter_min_products', 'filter_max_products'];
            fields.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            
            const filterResults = document.getElementById('filter_results');
            if (filterResults) filterResults.innerHTML = '';
            
            window.location.href = 'list.php';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('quickEditModal');
            if (event.target == modal) {
                closeQuickEditModal();
            }
        }
    </script>
</head>
<body>
    <?php 
    $header_title = "Store Management";
    $header_subtitle = "Manage your store locations and monitor performance";
    $header_icon = "fas fa-store";
    $show_compact_toggle = true;
    $header_stats = [
        [
            'value' => number_format($total_records),
            'label' => 'Total Stores',
            'icon' => 'fas fa-store',
            'type' => 'primary',
            'trend' => [
                'type' => 'trend-up',
                'icon' => 'arrow-up',
                'text' => 'Active stores'
            ]
        ],
        [
            'value' => !empty($search) ? 'Filtered' : 'All',
            'label' => 'View Mode',
            'icon' => 'fas fa-filter',
            'type' => 'info',
            'trend' => [
                'type' => 'trend-neutral',
                'icon' => 'search',
                'text' => !empty($search) ? "Search: '$search'" : 'Showing all stores'
            ]
        ]
    ];
    include '../../includes/dashboard_header.php'; 
    ?>
    <div class="container">
        <!-- Offline Mode Banner -->
        <?php if ($isOfflineMode): ?>
        <div class="alert" style="background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; margin-bottom: 20px; padding: 15px 20px; border-radius: 8px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Offline Mode:</strong> You're viewing cached store data (updated <?= $cacheAgeDisplay ?>). Some features may be limited.
        </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>" style="padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; <?= $messageType === 'success' ? 'background: #10b981; color: white;' : 'background: #3b82f6; color: white;' ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'info-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Page header (rendered by the page since header include no longer prints it) -->
        <div class="page-header">
            <div class="header-left">
                <div class="header-icon">
                    <i class="<?php echo htmlspecialchars($header_icon ?? 'fas fa-store'); ?>"></i>
                </div>
                <div class="header-text">
                    <h1><?php echo htmlspecialchars($header_title ?? 'Store Management'); ?></h1>
                    <p><?php echo htmlspecialchars($header_subtitle ?? 'Manage your store locations and monitor performance'); ?></p>
                    <small id="cacheStatus" style="display: block; margin-top: 5px; color: #6b7280; font-size: 12px;">
                        Last updated: <?= $cacheAgeDisplay ?>
                    </small>
                </div>
            </div>
            <div class="header-actions" style="display: flex; align-items: center; gap: 10px;">
                <?php if (!empty($show_compact_toggle)): ?>
                <button class="btn-compact-toggle" onclick="toggleCompactView()">
                    <i class="fas fa-compress"></i>
                    <span>Compact View</span>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($header_stats)): ?>
        <div class="stats-grid">
            <?php foreach ($header_stats as $stat): ?>
            <div class="stat-card">
                <div class="stat-card-inner">
                    <div class="stat-icon-wrapper">
                        <div class="stat-icon <?php echo htmlspecialchars($stat['type'] ?? 'primary'); ?>">
                            <i class="<?php echo htmlspecialchars($stat['icon'] ?? 'fas fa-info'); ?>"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo htmlspecialchars($stat['value']); ?></div>
                        <div class="stat-label"><?php echo htmlspecialchars($stat['label']); ?></div>
                        <?php if (isset($stat['trend'])): ?>
                        <div class="stat-trend <?php echo htmlspecialchars($stat['trend']['type'] ?? 'neutral'); ?>">
                            <i class="fas fa-<?php echo htmlspecialchars($stat['trend']['icon'] ?? 'minus'); ?>"></i>
                            <span><?php echo htmlspecialchars($stat['trend']['text'] ?? ''); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Management Dashboard Section -->
        <?php if (isset($_SESSION['user_id'])): 
            // Check user role for conditional display
            $currentUser = $db->read('users', $_SESSION['user_id']);
            $userRole = $currentUser['role'] ?? 'user';
            $canManage = in_array($userRole, ['admin', 'manager']);
        ?>
        <?php if ($canManage): ?>
        <div class="profile-section" style="margin-bottom: 20px;">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-tachometer-alt"></i>
                </div>
                <h3>Management Dashboard</h3>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                <!-- Activity Manager Card -->
                <a href="../users/profile/activity_manager.php" style="text-decoration: none; color: inherit;">
                    <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-history" style="font-size: 24px; color: white;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0 0 5px 0;">Activity Manager</h4>
                                <p style="margin: 0; font-size: 14px; color: #666;">Track and manage all user activities</p>
                            </div>
                        </div>
                    </div>
                </a>
                
                <!-- Permissions Manager Card -->
                <?php if ($userRole === 'admin'): ?>
                <a href="../users/profile/permissions_manager.php" style="text-decoration: none; color: inherit;">
                    <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-shield-alt" style="font-size: 24px; color: white;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0 0 5px 0;">Permissions Manager</h4>
                                <p style="margin: 0; font-size: 14px; color: #666;">Manage roles and user permissions</p>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endif; ?>
                
                <!-- Stores Manager Card -->
                <a href="../users/profile/stores_manager.php" style="text-decoration: none; color: inherit;">
                    <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-store" style="font-size: 24px; color: white;"></i>
                            </div>
                            <div>
                                <h4 style="margin: 0 0 5px 0;">Stores Manager</h4>
                                <p style="margin: 0; font-size: 14px; color: #666;">Manage stores and user access</p>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <main>

            <!-- Search Form -->
            <div class="search-section">
                <form method="GET" action="" class="search-form">
                    <input type="text" name="search" placeholder="🔍 Search stores by name, address, or phone..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-secondary"><i class="fas fa-search"></i> Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="list.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-info" onclick="toggleAdvancedFilters()">
                        <i class="fas fa-sliders-h"></i> Advanced Filters
                    </button>
                </form>
            </div>

            <!-- Advanced Filters Panel -->
            <div id="advancedFilters" class="advanced-filters" style="display: none;">
                <div class="filters-header">
                    <h3><i class="fas fa-filter"></i> Advanced Filters</h3>
                    <button class="btn btn-sm btn-outline" onclick="toggleAdvancedFilters()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
                <div class="filters-content">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="filter_status"><i class="fas fa-toggle-on"></i> Status</label>
                            <select id="filter_status" class="form-control">
                                <option value="">All Stores</option>
                                <option value="active">Active Only</option>
                                <option value="inactive">Inactive Only</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="filter_city"><i class="fas fa-city"></i> City</label>
                            <input type="text" id="filter_city" class="form-control" placeholder="Filter by city">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filter_state"><i class="fas fa-flag"></i> State</label>
                            <input type="text" id="filter_state" class="form-control" placeholder="Filter by state">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filter_manager"><i class="fas fa-user-tie"></i> Manager</label>
                            <input type="text" id="filter_manager" class="form-control" placeholder="Filter by manager">
                        </div>
                    </div>
                    
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="filter_date_from"><i class="fas fa-calendar-alt"></i> Created From</label>
                            <input type="date" id="filter_date_from" class="form-control">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filter_date_to"><i class="fas fa-calendar-alt"></i> Created To</label>
                            <input type="date" id="filter_date_to" class="form-control">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filter_min_products"><i class="fas fa-box"></i> Min Products</label>
                            <input type="number" id="filter_min_products" class="form-control" min="0" placeholder="0">
                        </div>
                        
                        <div class="filter-group">
                            <label for="filter_max_products"><i class="fas fa-box"></i> Max Products</label>
                            <input type="number" id="filter_max_products" class="form-control" min="0" placeholder="999999">
                        </div>
                    </div>
                    
                    <div class="filters-actions">
                        <button class="btn btn-primary" onclick="applyAdvancedFilters()">
                            <i class="fas fa-check"></i> Apply Filters
                        </button>
                        <button class="btn btn-outline" onclick="clearAdvancedFilters()">
                            <i class="fas fa-undo"></i> Reset Filters
                        </button>
                        <span id="filter_results" class="filter-results"></span>
                    </div>
                </div>
            </div>

            <!-- Bulk Operations Toolbar -->
            <div id="bulkToolbar" class="bulk-toolbar" style="display: none;">
                <div class="bulk-toolbar-content">
                    <div class="bulk-selection-info">
                        <i class="fas fa-check-square"></i>
                        <span id="selectedCount">0</span> store(s) selected
                    </div>
                    <div class="bulk-actions">
                        <button onclick="alert('Button clicked! Function: bulkActivate'); bulkActivate();" class="btn btn-sm btn-success" title="Activate selected stores">
                            <i class="fas fa-check-circle"></i> Activate
                        </button>
                        <button onclick="alert('Button clicked! Function: bulkDeactivate'); bulkDeactivate();" class="btn btn-sm btn-warning" title="Deactivate selected stores">
                            <i class="fas fa-ban"></i> Deactivate
                        </button>
                        <button onclick="alert('Button clicked! Function: bulkExport'); bulkExport();" class="btn btn-sm btn-info" title="Export selected stores">
                            <i class="fas fa-download"></i> Export
                        </button>
                        <?php if (currentUserHasPermission('can_delete_stores')): ?>
                            <button onclick="alert('Button clicked! Function: bulkDelete'); bulkDelete();" class="btn btn-sm btn-danger" title="Delete selected stores">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        <?php endif; ?>
                        <button onclick="alert('Button clicked! Function: clearSelection'); clearSelection();" class="btn btn-sm btn-outline">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>
                </div>
            </div>

            <!-- Quick Edit Modal -->
            <div id="quickEditModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2><i class="fas fa-edit"></i> Quick Edit Store</h2>
                        <span class="close" onclick="closeQuickEditModal()">&times;</span>
                    </div>
                    <div class="modal-body">
                        <form id="quickEditForm">
                            <input type="hidden" id="edit_store_id" name="store_id">
                            
                            <div class="form-group">
                                <label for="edit_name"><i class="fas fa-store"></i> Store Name *</label>
                                <input type="text" id="edit_name" name="name" required class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_code"><i class="fas fa-tag"></i> Store Code</label>
                                <input type="text" id="edit_code" name="code" class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_phone"><i class="fas fa-phone"></i> Phone</label>
                                <input type="tel" id="edit_phone" name="phone" class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_email"><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" id="edit_email" name="email" class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_manager"><i class="fas fa-user-tie"></i> Manager Name</label>
                                <input type="text" id="edit_manager" name="manager_name" class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_address"><i class="fas fa-map-marker-alt"></i> Address</label>
                                <input type="text" id="edit_address" name="address" class="form-control">
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="edit_city"><i class="fas fa-city"></i> City</label>
                                    <input type="text" id="edit_city" name="city" class="form-control">
                                </div>
                                
                                <div class="form-group">
                                    <label for="edit_state"><i class="fas fa-flag"></i> State</label>
                                    <input type="text" id="edit_state" name="state" class="form-control">
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline" onclick="closeQuickEditModal()">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveQuickEdit()">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>

            <!-- Stores Table -->
            <?php if (empty($stores)): ?>
                <div class="no-data">
                    <i class="fas fa-store-slash"></i>
                    <p>No stores found</p>
                    <a href="add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Your First Store</a>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 50px;">
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                </th>
                                <th><i class="fas fa-store"></i> Store Name</th>
                                <th><i class="fas fa-map-marker-alt"></i> Address</th>
                                <th><i class="fas fa-phone"></i> Phone</th>
                                <th><i class="fas fa-user-tie"></i> Manager</th>
                                <th><i class="fas fa-chart-bar"></i> Statistics</th>
                                <th><i class="fas fa-cogs"></i> Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stores as $store): ?>
                                <tr>
                                    <td style="text-align: center;">
                                        <input type="checkbox" class="store-checkbox" value="<?php echo htmlspecialchars($store['id']); ?>" onchange="updateBulkToolbar()">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($store['name']); ?></strong>
                                        <?php if (!empty($store['code'])): ?>
                                            <br><small style="color: #64748b;">Code: <?php echo htmlspecialchars($store['code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($store['address'])): ?>
                                            <?php echo htmlspecialchars($store['address']); ?>
                                            <?php if (!empty($store['city'])): ?>
                                                <br><small style="color: #64748b;"><?php echo htmlspecialchars($store['city']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">Not provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($store['phone'] ?? 'Not provided'); ?></td>
                                    <td><?php echo htmlspecialchars($store['manager_name'] ?? 'Not assigned'); ?></td>
                                    <td>
                                        <div class="store-stats">
                                            <span class="stat-badge products">
                                                <i class="fas fa-box"></i> <?php echo number_format($store['product_count']); ?>
                                            </span>
                                            <span class="stat-badge stock">
                                                <i class="fas fa-cubes"></i> <?php echo number_format($store['total_stock']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="profile.php?id=<?php echo $store['id']; ?>" class="btn btn-sm btn-primary" title="View Store Profile">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (currentUserHasPermission('can_edit_stores')): ?>
                                                <button onclick="openQuickEdit('<?php echo $store['id']; ?>')" class="btn btn-sm btn-secondary" title="Quick Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="edit.php?id=<?php echo $store['id']; ?>" class="btn btn-sm btn-secondary" title="Full Edit" style="opacity: 0.8;">
                                                    <i class="fas fa-pen-square"></i>
                                                </a>
                                            <?php endif; ?>
                                            <a href="inventory_viewer.php?id=<?php echo $store['id']; ?>" class="btn btn-sm btn-warning" title="View Inventory">
                                                <i class="fas fa-boxes"></i>
                                            </a>
                                            <?php if (currentUserHasPermission('can_edit_stores')): ?>
                                                <button onclick="duplicateStore('<?php echo $store['id']; ?>')" class="btn btn-sm btn-info" title="Duplicate Store">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                                <button onclick="toggleStoreStatus('<?php echo $store['id']; ?>', <?php echo isset($store['active']) && $store['active'] ? 'true' : 'false'; ?>)" 
                                                        class="btn btn-sm <?php echo (isset($store['active']) && $store['active']) ? 'btn-success' : 'btn-danger'; ?>" 
                                                        title="<?php echo (isset($store['active']) && $store['active']) ? 'Deactivate' : 'Activate'; ?> Store">
                                                    <i class="fas fa-<?php echo (isset($store['active']) && $store['active']) ? 'check' : 'times'; ?>-circle"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (isset($store['has_pos']) && $store['has_pos']): ?>
                                            <a href="../pos/full_retail.php?store_firebase_id=<?php echo htmlspecialchars($store['id']); ?>" 
                                               class="btn btn-sm" 
                                               style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;" 
                                               title="Open POS for <?php echo htmlspecialchars($store['name']); ?>">
                                                <i class="fas fa-cash-register"></i> POS
                                            </a>
                                            <?php else: ?>
                                            <button onclick="enablePOS('<?php echo $store['id']; ?>', '<?php echo htmlspecialchars($store['name']); ?>')" 
                                                    class="btn btn-sm btn-outline-primary" 
                                                    title="Enable POS for <?php echo htmlspecialchars($store['name']); ?>"
                                                    id="enable-pos-<?php echo $store['id']; ?>">
                                                <i class="fas fa-plus-circle"></i> Enable POS
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($pagination['total_pages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($pagination['has_previous']): ?>
                            <a href="?page=<?php echo $pagination['page'] - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>

                        <span class="current">
                            <i class="fas fa-file-alt"></i>
                            Page <?php echo $pagination['page']; ?> of <?php echo $pagination['total_pages']; ?>
                            (<?php echo number_format($total_records); ?> stores)
                        </span>

                        <?php if ($pagination['has_next']): ?>
                            <a href="?page=<?php echo $pagination['page'] + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
    
    <script>
        // Background auto-refresh (every 30 seconds when online)
        document.addEventListener('DOMContentLoaded', () => {
            let autoRefreshInterval = null;
            
            function startAutoRefresh() {
                if (navigator.onLine) {
                    autoRefreshInterval = setInterval(() => {
                        if (navigator.onLine) {
                            fetch(window.location.href + (window.location.search ? '&' : '?') + 'refresh_cache=1&silent=1')
                                .then(response => {
                                    if (response.ok) {
                                        console.log('Background cache refresh successful');
                                        const status = document.getElementById('cacheStatus');
                                        if (status) {
                                            status.textContent = 'Last updated: just now';
                                        }
                                    }
                                })
                                .catch(error => {
                                    console.log('Background refresh failed:', error);
                                });
                        }
                    }, 30000); // 30 seconds
                }
            }
            
            function stopAutoRefresh() {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            }
            
            startAutoRefresh();
            window.addEventListener('online', startAutoRefresh);
            window.addEventListener('offline', stopAutoRefresh);
        });
    </script>
</body>
</html>