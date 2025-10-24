<?php
// Enhanced Interactive Store Map with Leaflet
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
if (!currentUserHasPermission('can_view_stores')) {
    $_SESSION['error'] = 'You do not have permission to view store map';
    header('Location: ../../index.php');
    exit;
}

// Cache configuration
$cacheDir = '../../storage/cache/';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$cacheFile = $cacheDir . 'stores_map_' . md5('stores_map_data') . '.cache';
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
$regions = [];
$fetchedFromFirebase = false;

// Try to fetch from Firebase if cache is stale or refresh requested
if ($shouldRefreshCache || !$cacheIsFresh) {
    try {
        $client = new FirebaseRestClient();
        // IMPORTANT: Limit queries to prevent excessive Firebase reads
        $firebaseStores = $client->queryCollection('stores', 200); // Max 200 stores
        $firebaseRegions = $client->queryCollection('regions', 50); // Max 50 regions
        
        error_log("Firebase fetch attempt - Stores count: " . count($firebaseStores) . ", Regions count: " . count($firebaseRegions));
        
        // Check if we got data (not just empty arrays from errors)
        if (is_array($firebaseStores) && count($firebaseStores) > 0) {
            // Filter active stores
            $all_stores = array_filter($firebaseStores, function($s) {
                return isset($s['active']) && $s['active'] == 1;
            });
            
            // Filter active regions (might be empty, that's ok)
            $regions = is_array($firebaseRegions) ? array_filter($firebaseRegions, function($r) {
                return isset($r['active']) && $r['active'] == 1;
            }) : [];
            
            // Save to cache
            $cacheData = [
                'stores' => array_values($all_stores),
                'regions' => array_values($regions),
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
        error_log('Firebase fetch failed for stores map: ' . $e->getMessage());
        // Fall through to cache loading below
    }
}

// Load from cache if Firebase fetch failed or wasn't attempted
if (empty($all_stores) && $cacheExists) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if ($cacheData) {
        $all_stores = $cacheData['stores'] ?? [];
        $regions = $cacheData['regions'] ?? [];
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

// Calculate statistics
$total_stores = count($all_stores);
$active_stores = count(array_filter($all_stores, function($s) {
    return ($s['status'] ?? 'active') === 'active';
}));
$stores_with_location = count(array_filter($all_stores, function($s) {
    return !empty($s['latitude']) && !empty($s['longitude']);
}));

// Get unique store types
$store_types = array_unique(array_map(function($s) {
    return $s['store_type'] ?? 'retail';
}, $all_stores));

$page_title = 'Interactive Store Map - Inventory System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Leaflet MarkerCluster CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
            padding-top: 0; /* Allow dashboard header to be at top */
        }
        
        /* Dashboard header compatibility */
        body > header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
            padding-top: 20px; /* Space after dashboard header */
        }
        
        .page-header {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            animation: slideDown 0.5s ease-out;
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
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .page-header h1 {
            margin: 0;
            color: #2d3748;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 2em;
            font-weight: 700;
        }
        
        .page-header h1 i {
            color: #667eea;
            font-size: 1.2em;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .page-header p {
            margin: 5px 0 0 45px;
            color: #718096;
            font-size: 1em;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
            animation: fadeInUp 0.6s ease-out 0.1s both;
        }
        
        .stat-card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            text-align: center;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.5);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 15px 50px rgba(102, 126, 234, 0.3);
        }
        
        .stat-card:hover::before {
            transform: scaleX(1);
        }
        
        .stat-number {
            font-size: 3em;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            letter-spacing: -1px;
        }
        
        .stat-label {
            color: #718096;
            font-size: 0.95em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .map-section {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            margin-bottom: 25px;
            animation: fadeInUp 0.6s ease-out 0.2s both;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .map-section h2 {
            color: #2d3748;
            font-weight: 700;
            margin-bottom: 20px;
        }
        
        .filter-controls {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            align-items: center;
            background: #f7fafc;
            padding: 20px;
            border-radius: 15px;
            border: 2px dashed rgba(102, 126, 234, 0.2);
        }
        
        .filter-controls input,
        .filter-controls select {
            padding: 12px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
            font-family: inherit;
        }
        
        .filter-controls input:focus,
        .filter-controls select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .filter-controls input {
            flex: 1;
            min-width: 250px;
        }
        
        .filter-controls select {
            min-width: 170px;
            cursor: pointer;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn:hover::before {
            opacity: 1;
        }
        
        .btn > * {
            position: relative;
            z-index: 1;
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #718096 0%, #4a5568 100%);
            box-shadow: 0 4px 15px rgba(113, 128, 150, 0.3);
        }
        
        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        #map {
            height: 650px;
            width: 100%;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
            border: 3px solid rgba(255,255,255,0.5);
            overflow: hidden;
        }
        
        .leaflet-container {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .legend {
            background: linear-gradient(135deg, rgba(255,255,255,0.98) 0%, rgba(247,250,252,0.98) 100%);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 6px 25px rgba(0,0,0,0.1);
            margin-top: 20px;
            border: 1px solid rgba(255,255,255,0.5);
        }
        
        .legend h4 {
            margin: 0 0 15px 0;
            color: #2d3748;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .legend h4 i {
            color: #667eea;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin: 12px 0;
            padding: 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .legend-item:hover {
            background: rgba(102, 126, 234, 0.08);
            transform: translateX(5px);
        }
        
        .legend-color {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            margin-right: 12px;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: transform 0.3s ease;
        }
        
        .legend-item:hover .legend-color {
            transform: scale(1.2);
        }
        
        .legend-item span {
            font-weight: 500;
            color: #4a5568;
        }
        
        .store-list-section {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            margin-top: 25px;
            animation: fadeInUp 0.6s ease-out 0.3s both;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .store-list-section h2 {
            color: #2d3748;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        #store-count {
            color: #718096;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .store-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .store-card {
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .store-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.4s ease;
        }
        
        .store-card:hover {
            box-shadow: 0 12px 40px rgba(102, 126, 234, 0.2);
            transform: translateY(-5px);
            border-color: #667eea;
        }
        
        .store-card:hover::before {
            transform: scaleX(1);
        }
        
        .store-card h3 {
            margin: 0 0 12px 0;
            color: #2d3748;
            font-weight: 700;
            font-size: 1.2em;
        }
        
        .store-card p {
            margin: 8px 0;
            color: #718096;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .store-card i {
            color: #667eea;
            width: 16px;
        }
        
        .store-type-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .type-retail { 
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            color: #1565c0;
        }
        .type-warehouse { 
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #2e7d32;
        }
        .type-distribution { 
            background: linear-gradient(135deg, #fff3e0 0%, #ffe0b2 100%);
            color: #e65100;
        }
        .type-flagship { 
            background: linear-gradient(135deg, #fce4ec 0%, #f8bbd0 100%);
            color: #ad1457;
        }
        .type-outlet { 
            background: linear-gradient(135deg, #f3e5f5 0%, #e1bee7 100%);
            color: #6a1b9a;
        }
        
        .leaflet-popup-content {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-width: 250px;
        }
        
        .leaflet-popup-content-wrapper {
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }
        
        .popup-content h3 {
            margin: 0 0 12px 0;
            color: #2d3748;
            font-weight: 700;
            font-size: 1.2em;
        }
        
        .popup-content p {
            margin: 8px 0;
            font-size: 14px;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .popup-content i {
            color: #667eea;
            width: 16px;
        }
        
        .popup-actions {
            margin-top: 15px;
            display: flex;
            gap: 8px;
        }
        
        .popup-btn {
            padding: 8px 16px;
            font-size: 13px;
            border-radius: 10px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .popup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }
        
        .loading-spinner {
            background: white;
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 10px 50px rgba(0,0,0,0.3);
        }
        
        .loading-spinner i {
            font-size: 3em;
            color: #667eea;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .nav-links {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .page-header {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .page-header h1 {
                font-size: 1.5em;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-controls input,
            .filter-controls select {
                width: 100%;
            }
            
            #map {
                height: 400px;
            }
            
            .store-grid {
                grid-template-columns: 1fr;
            }
            
            .nav-links {
                width: 100%;
                justify-content: center;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }
    </style>
</head>
<body>
    <?php 
    $header_title = "Store Map";
    $header_subtitle = "Interactive store location visualization and management";
    $header_icon = "fas fa-map-marked-alt";
    $show_compact_toggle = false;
    $header_stats = [];
    include '../../includes/dashboard_header.php'; 
    ?>
    
    <div class="container">
        <!-- Offline Mode Banner -->
        <?php if ($isOfflineMode): ?>
        <div class="alert" style="background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Offline Mode:</strong> You're viewing cached map data (updated <?= $cacheAgeDisplay ?>). Some features may be limited.
        </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'info-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-map-marked-alt"></i> Interactive Store Map</h1>
                <p style="margin: 5px 0 0 0; color: #718096;">Visualize and manage all store locations</p>
                <small id="cacheStatus" style="display: block; margin-top: 5px; color: #6b7280; font-size: 12px;">
                    Last updated: <?= $cacheAgeDisplay ?>
                </small>
            </div>
            <div class="nav-links" style="display: flex; align-items: center; gap: 10px;">
                <a href="../../index.php" class="btn btn-outline">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a href="add.php" class="btn">
                    <i class="fas fa-plus"></i> Add Store
                </a>
                <a href="list.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i> List View
                </a>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_stores; ?></div>
                <div class="stat-label">Total Stores</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_stores; ?></div>
                <div class="stat-label">Active Stores</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stores_with_location; ?></div>
                <div class="stat-label">Mapped Stores</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($regions); ?></div>
                <div class="stat-label">Regions</div>
            </div>
        </div>
        
        <!-- Map Section -->
        <div class="map-section">
            <h2 style="margin: 0 0 15px 0;"><i class="fas fa-map"></i> Store Locations</h2>
            
            <!-- Filter Controls -->
            <div class="filter-controls">
                <input type="text" id="search-input" placeholder="Search stores by name, city, or region...">
                
                <select id="region-filter">
                    <option value="">All Regions</option>
                    <?php foreach ($regions as $region): ?>
                        <option value="<?php echo htmlspecialchars($region['id']); ?>">
                            <?php echo htmlspecialchars($region['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select id="type-filter">
                    <option value="">All Types</option>
                    <?php foreach ($store_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>">
                            <?php echo ucfirst(htmlspecialchars($type)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <button class="btn" onclick="applyFilters()">
                    <i class="fas fa-filter"></i> Apply Filters
                </button>
                
                <button class="btn btn-outline" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
                
                <button class="btn btn-secondary" onclick="toggleClustering()">
                    <i class="fas fa-layer-group"></i> <span id="cluster-text">Enable Clustering</span>
                </button>
            </div>
            
            <!-- Map Container -->
            <div id="map">
                <div id="mapLoading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; z-index: 1000; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <div style="width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 15px;"></div>
                    <p style="margin: 0; color: #555; font-size: 14px;">Loading map...</p>
                </div>
            </div>
            
            <style>
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
            
            <!-- Legend -->
            <div class="legend">
                <h4><i class="fas fa-info-circle"></i> Store Types</h4>
                <div class="legend-item">
                    <div class="legend-color" style="background: #1976d2;"></div>
                    <span>Retail Store</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #388e3c;"></div>
                    <span>Warehouse</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #f57c00;"></div>
                    <span>Distribution Center</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #c2185b;"></div>
                    <span>Flagship Store</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background: #7b1fa2;"></div>
                    <span>Outlet</span>
                </div>
            </div>
        </div>
        
        <!-- Store List -->
        <div class="store-list-section">
            <h2 style="margin: 0 0 15px 0;"><i class="fas fa-store"></i> Store Directory</h2>
            <div id="store-count" style="color: #666; margin-bottom: 10px;">
                Showing <span id="filtered-count"><?php echo $total_stores; ?></span> of <?php echo $total_stores; ?> stores
            </div>
            <div class="store-grid" id="store-list"></div>
        </div>
    </div>
    
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- Leaflet MarkerCluster JS -->
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    
    <script>
        // Store data from PHP
        const storesData = <?php echo json_encode($all_stores); ?>;
        const regionsData = <?php echo json_encode($regions); ?>;
        
        // Map and layer variables
        let map;
        let markersLayer;
        let clusterGroup;
        let isClusteringEnabled = false;
        let filteredStores = [...storesData];
        
        // Store type colors
        const storeColors = {
            'retail': '#1976d2',
            'warehouse': '#388e3c',
            'distribution': '#f57c00',
            'flagship': '#c2185b',
            'outlet': '#7b1fa2'
        };
        
        // Helper function to adjust color brightness
        function adjustColor(color, amount) {
            const clamp = (num) => Math.min(255, Math.max(0, num));
            const num = parseInt(color.replace('#', ''), 16);
            const r = clamp((num >> 16) + amount);
            const g = clamp(((num >> 8) & 0x00FF) + amount);
            const b = clamp((num & 0x0000FF) + amount);
            return '#' + (0x1000000 + (r << 16) + (g << 8) + b).toString(16).slice(1);
        }
        
        // Helper function to get store icon
        function getStoreIcon(type) {
            const icons = {
                'retail': 'fa-store',
                'warehouse': 'fa-warehouse',
                'distribution': 'fa-truck-loading',
                'flagship': 'fa-star',
                'outlet': 'fa-shopping-bag'
            };
            return icons[type] || 'fa-store';
        }
        
        // Initialize map
        function initMap() {
            try {
                // Hide loading indicator
                const loadingEl = document.getElementById('mapLoading');
                if (loadingEl) {
                    loadingEl.style.display = 'none';
                }
                
                // Create map centered on US (or adjust to your region)
                map = L.map('map').setView([39.8283, -98.5795], 4);
                
                // Force map to invalidate size after a short delay (fixes blank map issue)
                setTimeout(() => {
                    if (map) {
                        map.invalidateSize();
                        console.log('Map size invalidated');
                    }
                }, 100);
            } catch (error) {
                console.error('Error creating map:', error);
                const mapContainer = document.getElementById('map');
                if (mapContainer) {
                    mapContainer.innerHTML = '<div style="padding: 40px; text-align: center; color: #e53e3e;"><i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i><p>Error loading map. Please refresh the page.</p></div>';
                }
                throw error;
            }
            
            // Add OpenStreetMap tiles
            const tileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19
            }).addTo(map);
            
            // Log when tiles are loaded
            tileLayer.on('load', function() {
                console.log('Map tiles loaded successfully');
            });
            
            tileLayer.on('tileerror', function(error) {
                console.error('Error loading map tiles:', error);
            });
            
            // Initialize marker layers
            markersLayer = L.layerGroup().addTo(map);
            clusterGroup = L.markerClusterGroup({
                chunkedLoading: true,
                spiderfyOnMaxZoom: true,
                showCoverageOnHover: false
            });
            
            // Load all stores
            loadStoreMarkers(storesData);
            
            // Fit map to markers if stores exist
            if (storesData.length > 0) {
                const bounds = L.latLngBounds(
                    storesData
                        .filter(s => s.latitude && s.longitude)
                        .map(s => [parseFloat(s.latitude), parseFloat(s.longitude)])
                );
                if (bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [50, 50] });
                }
            }
        }
        
        // Load store markers
        function loadStoreMarkers(stores) {
            // Clear existing markers
            markersLayer.clearLayers();
            clusterGroup.clearLayers();
            
            stores.forEach(store => {
                if (store.latitude && store.longitude) {
                    const lat = parseFloat(store.latitude);
                    const lon = parseFloat(store.longitude);
                    
                    if (!isNaN(lat) && !isNaN(lon)) {
                        // Create custom icon
                        const storeType = store.store_type || 'retail';
                        const color = storeColors[storeType] || '#1976d2';
                        
                        const customIcon = L.divIcon({
                            className: 'custom-marker',
                            html: `<div class="marker-pin" style="position: relative; animation: markerBounce 0.6s ease-out;">
                                     <div class="marker-inner" style="background: linear-gradient(135deg, ${color} 0%, ${adjustColor(color, -20)} 100%); width: 36px; height: 36px; border-radius: 50%; border: 4px solid white; box-shadow: 0 4px 12px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; transition: all 0.3s ease;">
                                       <i class="fas fa-store" style="color: white; font-size: 14px;"></i>
                                     </div>
                                     <div class="marker-pulse" style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; border-radius: 50%; background: ${color}; opacity: 0; animation: pulse 2s infinite;"></div>
                                   </div>
                                   <style>
                                     @keyframes markerBounce {
                                       0% { transform: translateY(-100px) scale(0); opacity: 0; }
                                       60% { transform: translateY(5px) scale(1.1); opacity: 1; }
                                       100% { transform: translateY(0) scale(1); opacity: 1; }
                                     }
                                     @keyframes pulse {
                                       0% { transform: scale(1); opacity: 0.5; }
                                       100% { transform: scale(2.5); opacity: 0; }
                                     }
                                     .custom-marker:hover .marker-inner {
                                       transform: scale(1.2);
                                       box-shadow: 0 6px 20px rgba(0,0,0,0.4);
                                     }
                                   </style>`,
                            iconSize: [36, 36],
                            iconAnchor: [18, 18]
                        });
                        
                        // Create marker
                        const marker = L.marker([lat, lon], { icon: customIcon });
                        
                        // Create popup content
                        const popupContent = `
                            <div class="popup-content">
                                <h3><i class="fas fa-store"></i> ${store.name || 'Unnamed Store'}</h3>
                                <div class="store-type-badge type-${storeType}">${storeType.toUpperCase()}</div>
                                <p><i class="fas fa-map-marker-alt"></i> ${store.address || 'N/A'}, ${store.city || ''}, ${store.state || ''}</p>
                                ${store.phone ? `<p><i class="fas fa-phone"></i> ${store.phone}</p>` : ''}
                                ${store.email ? `<p><i class="fas fa-envelope"></i> ${store.email}</p>` : ''}
                                ${store.manager_name ? `<p><i class="fas fa-user"></i> Manager: ${store.manager_name}</p>` : ''}
                                ${store.opening_hours && store.closing_hours ? `<p><i class="fas fa-clock"></i> ${store.opening_hours} - ${store.closing_hours}</p>` : ''}
                                <div class="popup-actions">
                                    <a href="profile.php?id=${store.id}" class="btn popup-btn"><i class="fas fa-eye"></i> View</a>
                                    <a href="inventory_viewer.php?id=${store.id}" class="btn btn-secondary popup-btn"><i class="fas fa-boxes"></i> Inventory</a>
                                </div>
                            </div>
                        `;
                        
                        marker.bindPopup(popupContent);
                        
                        // Add to appropriate layer
                        if (isClusteringEnabled) {
                            clusterGroup.addLayer(marker);
                        } else {
                            markersLayer.addLayer(marker);
                        }
                    }
                }
            });
            
            // Add appropriate layer to map based on clustering state
            if (isClusteringEnabled) {
                // Add cluster group to map
                if (!map.hasLayer(clusterGroup)) {
                    map.addLayer(clusterGroup);
                }
            } else {
                // Add regular markers layer to map
                if (!map.hasLayer(markersLayer)) {
                    map.addLayer(markersLayer);
                }
            }
        }
        
        // Toggle clustering
        function toggleClustering() {
            isClusteringEnabled = !isClusteringEnabled;
            const clusterText = document.getElementById('cluster-text');
            
            if (isClusteringEnabled) {
                // Remove regular markers, enable clustering
                map.removeLayer(markersLayer);
                clusterText.textContent = 'Disable Clustering';
            } else {
                // Remove cluster group, enable regular markers
                map.removeLayer(clusterGroup);
                clusterText.textContent = 'Enable Clustering';
            }
            
            // Reload markers with new clustering state
            loadStoreMarkers(filteredStores);
        }
        
        // Apply filters
        function applyFilters() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
            const regionFilter = document.getElementById('region-filter').value;
            const typeFilter = document.getElementById('type-filter').value;
            
            filteredStores = storesData.filter(store => {
                // Search filter
                if (searchTerm) {
                    const searchFields = [
                        store.name || '',
                        store.city || '',
                        store.state || '',
                        store.address || ''
                    ].join(' ').toLowerCase();
                    
                    if (!searchFields.includes(searchTerm)) {
                        return false;
                    }
                }
                
                // Region filter
                if (regionFilter && store.region_id !== regionFilter) {
                    return false;
                }
                
                // Type filter
                if (typeFilter && store.store_type !== typeFilter) {
                    return false;
                }
                
                return true;
            });
            
            // Update map markers
            loadStoreMarkers(filteredStores);
            
            // Update store list
            renderStoreList();
            
            // Update count
            document.getElementById('filtered-count').textContent = filteredStores.length;
            
            // Fit map to filtered stores
            if (filteredStores.length > 0) {
                const bounds = L.latLngBounds(
                    filteredStores
                        .filter(s => s.latitude && s.longitude)
                        .map(s => [parseFloat(s.latitude), parseFloat(s.longitude)])
                );
                if (bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [50, 50] });
                }
            }
        }
        
        // Reset filters
        function resetFilters() {
            document.getElementById('search-input').value = '';
            document.getElementById('region-filter').value = '';
            document.getElementById('type-filter').value = '';
            
            filteredStores = [...storesData];
            loadStoreMarkers(filteredStores);
            renderStoreList();
            document.getElementById('filtered-count').textContent = filteredStores.length;
            
            // Reset map view
            if (storesData.length > 0) {
                const bounds = L.latLngBounds(
                    storesData
                        .filter(s => s.latitude && s.longitude)
                        .map(s => [parseFloat(s.latitude), parseFloat(s.longitude)])
                );
                if (bounds.isValid()) {
                    map.fitBounds(bounds, { padding: [50, 50] });
                }
            }
        }
        
        // Render store list
        function renderStoreList() {
            const container = document.getElementById('store-list');
            container.innerHTML = '';
            
            filteredStores.forEach(store => {
                const storeType = store.store_type || 'retail';
                const card = document.createElement('div');
                card.className = 'store-card';
                card.onclick = () => {
                    if (store.latitude && store.longitude) {
                        map.setView([parseFloat(store.latitude), parseFloat(store.longitude)], 15);
                    }
                };
                
                card.innerHTML = `
                    <div class="store-type-badge type-${storeType}">
                        <i class="fas ${getStoreIcon(storeType)}"></i> ${storeType.toUpperCase()}
                    </div>
                    <h3>${store.name || 'Unnamed Store'}</h3>
                    <p><i class="fas fa-map-marker-alt"></i> ${store.address || ''} ${store.city || 'N/A'}, ${store.state || 'N/A'}</p>
                    ${store.phone ? `<p><i class="fas fa-phone"></i> ${store.phone}</p>` : ''}
                    ${store.manager_name ? `<p><i class="fas fa-user"></i> ${store.manager_name}</p>` : ''}
                    ${store.opening_hours && store.closing_hours ? `<p><i class="fas fa-clock"></i> ${store.opening_hours} - ${store.closing_hours}</p>` : ''}
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #f0f0f0; display: flex; gap: 8px;">
                        <a href="profile.php?id=${store.id}" class="btn popup-btn" style="flex: 1; justify-content: center;">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <a href="edit.php?id=${store.id}" class="btn btn-outline popup-btn" style="flex: 1; justify-content: center;">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                    </div>
                `;
                
                container.appendChild(card);
            });
        }
        
        // Initialize on page load - Wait for both DOM and Leaflet to be ready
        let domReady = false;
        let leafletReady = false;
        
        function tryInit() {
            if (domReady && leafletReady) {
                console.log('Initializing map...');
                try {
                    initMap();
                    renderStoreList();
                    
                    // Add enter key support for search
                    document.getElementById('search-input').addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            applyFilters();
                        }
                    });
                    console.log('Map initialized successfully');
                } catch (error) {
                    console.error('Map initialization error:', error);
                    setTimeout(tryInit, 500); // Retry after 500ms
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            domReady = true;
            tryInit();
        });
        
        // Check if Leaflet is loaded
        function checkLeaflet() {
            if (typeof L !== 'undefined' && L.map) {
                leafletReady = true;
                tryInit();
            } else {
                setTimeout(checkLeaflet, 100);
            }
        }
        checkLeaflet();
    </script>
    
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
