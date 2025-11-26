<?php
// Enhanced Interactive Store Map with Leaflet
require_once '../../config.php';
require_once '../../db.php';
require_once '../../sql_db.php';
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

// Initialize variables
$all_stores = [];
$regions = [];
$message = '';
$messageType = '';
$dataSource = 'postgresql';

    // Fetch from PostgreSQL (fast, no caching needed)
    try {
        $sqlDb = SQLDatabase::getInstance();
        
        // Get current user info for store access filtering
        $userId = $_SESSION['user_id'] ?? null;
        $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
        $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
        $isManager = (strtolower($currentUser['role'] ?? '') === 'manager');
        
        // Build query based on user role - admins/managers see all stores
        if ($isAdmin || $isManager) {
            $storeRecords = $sqlDb->fetchAll("
                SELECT 
                    id, name, code, address, city, state, zip_code, phone, email, 
                    manager, manager_name, description, latitude, longitude, 
                    store_type, status, operating_hours, active, created_at, updated_at
                FROM stores 
                WHERE active = TRUE 
                ORDER BY name ASC
            ");
        } else {
            // Regular users only see their assigned stores
            $storeRecords = $sqlDb->fetchAll("
                SELECT 
                    s.id, s.name, s.code, s.address, s.city, s.state, s.zip_code, s.phone, s.email, 
                    s.manager, s.manager_name, s.description, s.latitude, s.longitude, 
                    s.store_type, s.status, s.operating_hours, s.active, s.created_at, s.updated_at
                FROM stores s
                INNER JOIN user_store_access usa ON s.id = usa.store_id
                WHERE s.active = TRUE AND usa.user_id = ?
                ORDER BY s.name ASC
            ", [$currentUser['id']]);
        }
        
        // Convert to array format expected by the map
        $all_stores = array_map(function($s) {
            return [
                'id' => $s['id'],
                'name' => $s['name'],
                'code' => $s['code'] ?? '',
                'address' => $s['address'] ?? '',
                'city' => $s['city'] ?? '',
                'state' => $s['state'] ?? '',
                'postal_code' => $s['zip_code'] ?? '',
                'latitude' => $s['latitude'] ?? null,
                'longitude' => $s['longitude'] ?? null,
                'phone' => $s['phone'] ?? '',
                'email' => $s['email'] ?? '',
                'manager' => $s['manager'] ?? ($s['manager_name'] ?? ''),
                'store_type' => $s['store_type'] ?? 'retail',
                'opening_hours' => $s['operating_hours'] ?? '',
                'status' => $s['status'] ?? 'active',
                'active' => 1,
                'created_at' => $s['created_at'] ?? null,
                'updated_at' => $s['updated_at'] ?? null
            ];
        }, $storeRecords);
    
    if (isset($_GET['refresh_cache']) && !isset($_GET['silent'])) {
        $message = 'Data loaded successfully from database!';
        $messageType = 'success';
    }
    
} catch (Exception $e) {
    error_log('PostgreSQL fetch failed for stores map, falling back to Firebase: ' . $e->getMessage());
    
    // Fallback to Firebase
    try {
        $client = new FirebaseRestClient();
        $firebaseStores = $client->queryCollection('stores', 200);
        
        if (is_array($firebaseStores) && count($firebaseStores) > 0) {
            $all_stores = array_filter($firebaseStores, function($s) {
                return isset($s['active']) && $s['active'] == 1;
            });
            
            $dataSource = 'firebase';
        }
    } catch (Exception $e2) {
        error_log('Firebase fetch also failed for stores map: ' . $e2->getMessage());
    }
}

// If still no data, show message instead of error
if (empty($all_stores)) {
    $message = 'No stores found. You may not have access to any stores.';
    $messageType = 'info';
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
            background: #f1f5f9;
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Dashboard header compatibility */
        body > header {
            display: none; /* Hide default header if present to maximize space */
        }
        
        /* Override Dashboard Wrapper */
        .dashboard-wrapper {
            display: block !important;
            min-height: 100vh !important;
        }
        
        /* Ensure Navbar stays at top */
        .top-navbar {
            position: relative !important;
            z-index: 1000;
            margin-bottom: 15px;
        }

        .container {
            width: 100% !important;
            max-width: 1200px !important;
            margin: 0 auto !important;
            padding: 15px !important;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        /* Compact Header */
        .page-header {
            background: white;
            padding: 15px 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 10;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 0;
        }
        
        .page-header h1 {
            margin: 0;
            color: #1e293b;
            font-size: 20px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .page-header h1 i {
            color: #667eea;
            font-size: 22px;
        }
        
        /* Inline Stats in Header */
        .header-stats-inline {
            display: flex;
            gap: 15px;
            margin: 0 20px;
        }
        
        .mini-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f7fafc;
            padding: 2px 8px;
            border-radius: 4px;
            border: 1px solid #edf2f7;
        }
        
        .mini-stat-value {
            font-weight: 700;
            color: #667eea;
            font-size: 14px;
        }
        
        .mini-stat-label {
            font-size: 11px;
            color: #718096;
            text-transform: uppercase;
            font-weight: 600;
        }

        .nav-links .btn {
            padding: 4px 10px;
            font-size: 12px;
        }
        
        /* Map Section - Fixed Height */
        .map-section {
            height: 600px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
            background: white;
        }
        
        .map-toolbar {
            background: white;
            padding: 10px 15px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            height: auto;
            min-height: 50px;
            border-radius: 12px 12px 0 0;
        }
        
        .map-toolbar h2 {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }
        
        .filter-controls {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        .filter-controls input,
        .filter-controls select {
            padding: 4px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 12px;
            height: 28px;
        }
        
        .filter-controls .btn {
            padding: 0 10px;
            height: 28px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Main Content Area */
        .map-content-wrapper {
            flex: 1;
            display: flex;
            overflow: hidden;
            position: relative;
        }
        
        /* Map */
        #map {
            flex: 1;
            height: 100%;
            z-index: 1;
        }
        
        /* Right Panel */
        .map-right-panel {
            width: 280px;
            background: white;
            border-left: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            z-index: 2;
            box-shadow: -2px 0 10px rgba(0,0,0,0.05);
        }
        
        .legend {
            padding: 10px;
            border-bottom: 1px solid #edf2f7;
            background: #f8fafc;
        }
        
        .legend h4 {
            margin: 0 0 8px 0;
            font-size: 12px;
            color: #4a5568;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .legend-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            font-size: 11px;
            color: #4a5568;
        }
        
        .legend-color {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
            flex-shrink: 0;
        }
        
        .store-list-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 10px;
        }
        
        .store-grid {
            flex: 1;
            overflow-y: auto;
            padding-right: 5px;
        }
        
        /* Scrollbar styling */
        .store-grid::-webkit-scrollbar {
            width: 4px;
        }
        
        .store-grid::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        .store-grid::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 2px;
        }
        
        .store-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .store-card:hover {
            border-color: #667eea;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .store-card h3 {
            margin: 0 0 4px 0;
            font-size: 13px;
            color: #2d3748;
        }
        
        .store-card .store-meta {
            font-size: 11px;
            color: #718096;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .store-card .store-meta i {
            width: 14px;
            text-align: center;
            margin-right: 4px;
        }
        
        .btn {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn:hover {
            background: #5a67d8;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid #cbd5e0;
            color: #4a5568;
        }
        
        .btn-outline:hover {
            background: #f7fafc;
            border-color: #a0aec0;
        }
        
        .btn-secondary {
            background: #edf2f7;
            color: #4a5568;
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }

        /* Leaflet Customization */
        .leaflet-popup-content-wrapper {
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .leaflet-popup-content {
            margin: 10px;
        }
        
        .store-popup h3 {
            margin: 0 0 5px 0;
            font-size: 14px;
            color: #2d3748;
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        
        .store-popup p {
            margin: 3px 0;
            font-size: 12px;
            color: #4a5568;
        }
        
        .store-popup .popup-actions {
            margin-top: 8px;
            display: flex;
            gap: 5px;
        }
        
        .store-popup .btn-xs {
            padding: 2px 6px;
            font-size: 10px;
            border-radius: 3px;
        }

        /* Custom Marker Animations */
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
        .marker-pin {
            position: relative;
            animation: markerBounce 0.6s ease-out;
        }
        .marker-inner {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        .marker-pulse {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            border-radius: 50%;
            opacity: 0;
            animation: pulse 2s infinite;
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
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'info-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-map-marked-alt"></i> Store Map</h1>
            </div>
            
            <!-- Inline Stats -->
            <div class="header-stats-inline">
                <div class="mini-stat">
                    <div class="mini-stat-value"><?php echo $total_stores; ?></div>
                    <div class="mini-stat-label">Total</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-value"><?php echo $active_stores; ?></div>
                    <div class="mini-stat-label">Active</div>
                </div>
                <div class="mini-stat">
                    <div class="mini-stat-value"><?php echo $stores_with_location; ?></div>
                    <div class="mini-stat-label">Mapped</div>
                </div>
            </div>

            <div class="nav-links" style="display: flex; align-items: center; gap: 10px;">
                <a href="../../index.php" class="btn btn-outline">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <?php if (currentUserHasPermission('can_add_stores')): ?>
                <a href="add.php" class="btn">
                    <i class="fas fa-plus"></i> Add
                </a>
                <?php endif; ?>
                <a href="list.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i> List
                </a>
            </div>
        </div>
        
        <!-- Map Section -->
        <div class="map-section">
            <div class="map-toolbar">
                <h2 style="margin: 0;"><i class="fas fa-map"></i> Locations</h2>
                
                <!-- Filter Controls -->
                <div class="filter-controls">
                    <input type="text" id="search-input" placeholder="Search stores..." style="width: 150px;">
                    
                    <select id="type-filter">
                        <option value="">All Types</option>
                        <?php foreach ($store_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>">
                                <?php echo ucfirst(htmlspecialchars($type)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <button class="btn" onclick="applyFilters()">
                        <i class="fas fa-filter"></i>
                    </button>
                    
                    <button class="btn btn-outline" onclick="resetFilters()">
                        <i class="fas fa-redo"></i>
                    </button>
                    
                    <button id="clustering-btn" class="btn btn-outline" onclick="toggleClustering()" title="Toggle Clustering">
                        <i class="fas fa-layer-group"></i>
                    </button>
                </div>
            </div>
            
            <div class="map-content-wrapper">
                <!-- Map Container -->
                <div id="map">
                    <div id="mapLoading" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; z-index: 1000; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                        <div style="width: 50px; height: 50px; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 15px;"></div>
                        <p style="margin: 0; color: #555; font-size: 14px;">Loading map...</p>
                    </div>
                </div>
                
                <!-- Right Panel: Legend and Store Directory -->
                <div class="map-right-panel">
                    <!-- Legend -->
                    <div class="legend">
                        <h4><i class="fas fa-info-circle"></i> Types</h4>
                        <div class="legend-grid">
                            <div class="legend-item">
                                <div class="legend-color" style="background: #1976d2;"></div>
                                <span>Retail</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color" style="background: #388e3c;"></div>
                                <span>Warehouse</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color" style="background: #f57c00;"></div>
                                <span>Dist. Center</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color" style="background: #c2185b;"></div>
                                <span>Flagship</span>
                            </div>
                            <div class="legend-item">
                                <div class="legend-color" style="background: #7b1fa2;"></div>
                                <span>Outlet</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Store List -->
                    <div class="store-list-section">
                        <h2 style="font-size: 14px; margin-bottom: 5px;"><i class="fas fa-store"></i> Directory</h2>
                        <div id="store-count" style="color: #718096; margin-bottom: 8px; font-size: 11px;">
                            Showing <span id="filtered-count"><?php echo $total_stores; ?></span> of <?php echo $total_stores; ?>
                        </div>
                        <div class="store-grid" id="store-list"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <!-- Leaflet MarkerCluster JS -->
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    
    <script>
        // Store data from PHP
        const storesData = <?php echo json_encode($all_stores); ?>;
        const canEditStores = <?php echo currentUserHasPermission('can_edit_stores') ? 'true' : 'false'; ?>;
        
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
                            html: `<div class="marker-pin">
                                     <div class="marker-inner" style="background: linear-gradient(135deg, ${color} 0%, ${adjustColor(color, -20)} 100%);">
                                       <i class="fas fa-store" style="color: white; font-size: 14px;"></i>
                                     </div>
                                     <div class="marker-pulse" style="background: ${color};"></div>
                                   </div>`,
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
                                    <button onclick="viewStore(${store.id})" class="btn popup-btn"><i class="fas fa-eye"></i> View</button>
                                    ${canEditStores ? `<button onclick="editStore(${store.id})" class="btn btn-warning popup-btn" style="background: #ffedd5; color: #c2410c;"><i class="fas fa-edit"></i> Edit</button>` : ''}
                                    <button onclick="viewInventory(${store.id})" class="btn btn-secondary popup-btn"><i class="fas fa-boxes"></i> Inventory</button>
                                </div>
                            </div>
                        `;
                        
                        marker.bindPopup(popupContent);
                        
                        // Add to appropriate layer based on current mode
                        if (isClusteringEnabled) {
                            clusterGroup.addLayer(marker);
                        } else {
                            markersLayer.addLayer(marker);
                        }
                    }
                }
            });
            
            // Update map layers visibility
            if (isClusteringEnabled) {
                if (!map.hasLayer(clusterGroup)) map.addLayer(clusterGroup);
                if (map.hasLayer(markersLayer)) map.removeLayer(markersLayer);
            } else {
                if (!map.hasLayer(markersLayer)) map.addLayer(markersLayer);
                if (map.hasLayer(clusterGroup)) map.removeLayer(clusterGroup);
            }
        }
        
        // View store profile
        function viewStore(storeId) {
            window.location.href = 'profile.php?id=' + storeId;
        }

        // Edit store
        function editStore(storeId) {
            window.location.href = 'edit.php?id=' + storeId;
        }
        
        // View store inventory
        function viewInventory(storeId) {
            window.location.href = 'inventory_viewer.php?id=' + storeId;
        }
        
        // Toggle clustering
        function toggleClustering() {
            isClusteringEnabled = !isClusteringEnabled;
            const clusterText = document.getElementById('cluster-text');
            const btn = document.getElementById('clustering-btn');
            
            if (clusterText) {
                if (isClusteringEnabled) {
                    clusterText.textContent = 'Disable Clustering';
                } else {
                    clusterText.textContent = 'Enable Clustering';
                }
            }
            
            if (btn) {
                if (isClusteringEnabled) {
                    btn.classList.remove('btn-outline');
                    btn.classList.add('btn-primary');
                } else {
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-outline');
                }
            }
            
            // Reload markers with new clustering state
            loadStoreMarkers(filteredStores);
        }
        
        // Apply filters
        function applyFilters() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
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
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; display: flex; gap: 6px;">
                        <button onclick="viewStore(${store.id}); event.stopPropagation();" class="btn" style="flex: 1; justify-content: center; font-size: 12px; padding: 8px 10px;">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button onclick="viewInventory(${store.id}); event.stopPropagation();" class="btn btn-secondary" style="flex: 1; justify-content: center; font-size: 12px; padding: 8px 10px;">
                            <i class="fas fa-boxes"></i> Inventory
                        </button>
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
