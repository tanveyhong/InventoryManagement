<?php
// Alternative Store Map using OpenStreetMap (Leaflet)
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

// Use Firebase for all data
$db = getDB();
$regions = $db->readAll('regions', [['active', '==', 1]]);
$store_types = [];
$all_stores = $db->readAll('stores', [['active', '==', 1]]);
foreach ($all_stores as $store) {
    if (!empty($store['store_type']) && !in_array($store['store_type'], $store_types)) {
        $store_types[] = $store['store_type'];
    }
}
$stats = [
    'total_stores' => count($all_stores),
    'active_stores' => count(array_filter($all_stores, function($s){ return ($s['status'] ?? 'active') === 'active'; })),
    'total_regions' => count($regions),
    'low_stock_alerts' => 0 // You can aggregate from products if needed
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Map - Inventory System</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        .map-container {
            height: 70vh;
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 12px;
            margin: 20px 0;
            position: relative;
            overflow: hidden;
        }
        
        #leaflet-map {
            width: 100%;
            height: 100%;
            border-radius: 12px;
        }
        
        .map-controls-overlay {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 1000;
            display: flex;
            gap: 10px;
        }
        
        .map-control-btn {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.3);
            font-size: 12px;
            transition: all 0.3s ease;
        }
        
        .map-control-btn:hover {
            background: #f0f0f0;
        }
        
        .map-control-btn.active {
            background: #007bff;
            color: white;
        }
        
        .custom-marker {
            background: #007bff;
            border: 2px solid white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
        }
        
        .marker-active {
            background: #28a745;
        }
        
        .marker-inactive {
            background: #dc3545;
        }
        
        .marker-warehouse {
            background: #ffc107;
        }
        
        .leaflet-popup-content {
            margin: 8px 12px;
            line-height: 1.4;
        }
        
        .leaflet-popup-content h4 {
            margin: 0 0 8px 0;
            color: #333;
        }
        
        .leaflet-popup-content p {
            margin: 4px 0;
            font-size: 13px;
        }
        
        .popup-actions {
            margin-top: 8px;
            display: flex;
            gap: 6px;
        }
        
        .popup-actions a {
            padding: 4px 8px;
            font-size: 11px;
            text-decoration: none;
            border-radius: 4px;
            color: white;
        }
        
        .btn-primary-popup {
            background: #007bff;
        }
        
        .btn-secondary-popup {
            background: #6c757d;
        }
        
        /* Enhanced controls styling */
        .enhanced-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .control-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .control-group label {
            font-weight: 600;
            color: #333;
            font-size: 0.9rem;
        }
        
        .control-group select,
        .control-group input {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .enhanced-store-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .enhanced-store-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        
        .enhanced-store-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }
        
        .enhanced-store-card.highlighted {
            border: 3px solid #007bff;
            box-shadow: 0 0 20px rgba(0,123,255,0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <?php 
        $header_title = "Store Map";
        $header_subtitle = "Interactive store locations using OpenStreetMap";
        $header_icon = "fas fa-map-marked-alt";
        $show_compact_toggle = true;
        $header_stats = [
            [
                'value' => number_format($stats['total_stores']),
                'label' => 'Total Stores',
                'icon' => 'fas fa-store',
                'type' => 'primary',
                'trend' => [
                    'type' => 'trend-up',
                    'icon' => 'arrow-up',
                    'text' => 'Locations tracked'
                ]
            ],
            [
                'value' => number_format($stats['active_stores']),
                'label' => 'Active Stores',
                'icon' => 'fas fa-store-alt',
                'type' => 'success',
                'trend' => [
                    'type' => 'trend-up',
                    'icon' => 'arrow-up',
                    'text' => 'Currently operational'
                ]
            ],
            [
                'value' => number_format($stats['total_regions']),
                'label' => 'Regions',
                'icon' => 'fas fa-globe',
                'type' => 'info',
                'trend' => [
                    'type' => 'trend-up',
                    'icon' => 'arrow-up',
                    'text' => 'Coverage areas'
                ]
            ]
        ];
        include '../../includes/dashboard_header.php'; 
        ?>

        <main class="main-content">
            <!-- Filter Controls -->
            <div class="enhanced-controls">
                <div class="control-group">
                    <label for="region-filter">Filter by Region</label>
                    <select id="region-filter">
                        <option value="">All Regions</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?= htmlspecialchars($region['id']) ?>"><?= htmlspecialchars($region['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="control-group">
                    <label for="status-filter">Filter by Status</label>
                    <select id="status-filter">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="control-group">
                    <label for="type-filter">Filter by Type</label>
                    <select id="type-filter">
                        <option value="">All Types</option>
                        <?php foreach ($store_types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars(ucfirst($type)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="control-group">
                    <label for="search-input">Search Stores</label>
                    <input type="text" id="search-input" placeholder="Search by name, address, or manager...">
                </div>
            </div>

            <!-- Interactive Map -->
            <div class="map-container">
                <div id="leaflet-map"></div>
                <div class="map-controls-overlay">
                    <button class="map-control-btn active" onclick="switchTileLayer('osm')" id="btn-osm">Street</button>
                    <button class="map-control-btn" onclick="switchTileLayer('satellite')" id="btn-satellite">Satellite</button>
                    <button class="map-control-btn" onclick="fitAllMarkers()" id="btn-fit">Fit All</button>
                </div>
            </div>

            <!-- Store Grid Display -->
            <div class="enhanced-store-grid" id="store-grid">
                <!-- Stores will be populated by JavaScript -->
            </div>
        </main>
    </div>

    <!-- Leaflet JavaScript -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    
    <script>
        let map;
        let markers = [];
        let allStores = [];
        let filteredStores = [];
        let currentTileLayer;

        // Initialize map
        document.addEventListener('DOMContentLoaded', function() {
            initializeMap();
            loadStores();
            setupEventListeners();
        });

        function initializeMap() {
            // Initialize map centered on US
            map = L.map('leaflet-map').setView([39.8283, -98.5795], 4);

            // Add OpenStreetMap tiles
            currentTileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);

            // Add scale control
            L.control.scale().addTo(map);

            // Add custom legend
            addMapLegend();
        }

        function addMapLegend() {
            const legend = L.control({position: 'bottomleft'});
            
            legend.onAdd = function(map) {
                const div = L.DomUtil.create('div', 'info legend');
                div.style.backgroundColor = 'white';
                div.style.padding = '8px 12px';
                div.style.borderRadius = '4px';
                div.style.boxShadow = '0 2px 6px rgba(0,0,0,0.3)';
                div.innerHTML = `
                    <div style="font-weight: bold; margin-bottom: 4px;">Store Legend</div>
                    <div><span class="custom-marker marker-active"></span> Active Stores</div>
                    <div><span class="custom-marker marker-inactive"></span> Inactive Stores</div>
                    <div><span class="custom-marker marker-warehouse"></span> Warehouses</div>
                `;
                return div;
            };
            
            legend.addTo(map);
        }

        function setupEventListeners() {
            document.getElementById('region-filter').addEventListener('change', filterStores);
            document.getElementById('status-filter').addEventListener('change', filterStores);
            document.getElementById('type-filter').addEventListener('change', filterStores);
            document.getElementById('search-input').addEventListener('input', debounce(filterStores, 300));
        }

        function loadStores() {
            // Use PHP-injected stores from Firebase
            allStores = <?php echo json_encode($all_stores); ?>;
            filteredStores = [...allStores];
            updateMapMarkers(filteredStores);
            displayStores(filteredStores);
        }

        function loadDemoStores() {
            allStores = [
                {
                    id: 1,
                    name: 'Downtown Manhattan Store',
                    status: 'active',
                    address: '123 Broadway, New York, NY 10001',
                    manager_name: 'John Smith',
                    region_name: 'East Region',
                    store_type: 'retail',
                    latitude: 40.7589,
                    longitude: -73.9851,
                    product_count: 245,
                    low_stock_count: 5,
                    formatted_sales: '$12,450.00',
                    formatted_rating: '4.2'
                },
                {
                    id: 2,
                    name: 'Hollywood Store',
                    status: 'active',
                    address: '456 Hollywood Blvd, Los Angeles, CA 90028',
                    manager_name: 'Sarah Johnson',
                    region_name: 'West Region',
                    store_type: 'retail',
                    latitude: 34.1016,
                    longitude: -118.3295,
                    product_count: 189,
                    low_stock_count: 12,
                    formatted_sales: '$8,320.00',
                    formatted_rating: '3.9'
                },
                {
                    id: 3,
                    name: 'Chicago Warehouse',
                    status: 'active',
                    address: '789 Industrial Dr, Chicago, IL 60601',
                    manager_name: 'Mike Johnson',
                    region_name: 'Central Region',
                    store_type: 'warehouse',
                    latitude: 41.8781,
                    longitude: -87.6298,
                    product_count: 1200,
                    low_stock_count: 25,
                    formatted_sales: '$45,600.00',
                    formatted_rating: '4.5'
                }
            ];
        }

        function updateMapMarkers(stores) {
            // Clear existing markers
            markers.forEach(marker => map.removeLayer(marker));
            markers = [];

            const group = new L.featureGroup();

            stores.forEach(store => {
                if (store.latitude && store.longitude) {
                    const lat = parseFloat(store.latitude);
                    const lng = parseFloat(store.longitude);
                    
                    // Create custom marker
                    const markerColor = getMarkerColor(store);
                    const icon = L.divIcon({
                        className: `custom-marker ${markerColor}`,
                        iconSize: [16, 16],
                        iconAnchor: [8, 8]
                    });

                    const marker = L.marker([lat, lng], {icon: icon}).addTo(map);
                    
                    // Create popup content
                    const popupContent = `
                        <h4>${escapeHtml(store.name)}</h4>
                        <p><strong>Address:</strong> ${escapeHtml(store.address || 'N/A')}</p>
                        <p><strong>Manager:</strong> ${escapeHtml(store.manager_name || 'N/A')}</p>
                        <p><strong>Status:</strong> ${store.status}</p>
                        <p><strong>Type:</strong> ${store.store_type || 'N/A'}</p>
                        <p><strong>Products:</strong> ${store.product_count || 0}</p>
                        <div class="popup-actions">
                            <a href="profile.php?id=${store.id}" class="btn-primary-popup">Profile</a>
                            <a href="inventory_viewer.php?id=${store.id}" class="btn-secondary-popup">Inventory</a>
                        </div>
                    `;
                    
                    marker.bindPopup(popupContent);
                    
                    // Add click event to highlight store card
                    marker.on('click', function() {
                        highlightStoreCard(store.id);
                    });

                    markers.push(marker);
                    group.addLayer(marker);
                }
            });

            // Fit map to show all markers
            if (group.getLayers().length > 0) {
                map.fitBounds(group.getBounds(), {padding: [20, 20]});
            }
        }

        function getMarkerColor(store) {
            if (store.status === 'inactive') {
                return 'marker-inactive';
            } else if (store.store_type === 'warehouse' || store.store_type === 'distribution') {
                return 'marker-warehouse';
            } else {
                return 'marker-active';
            }
        }

        function switchTileLayer(type) {
            // Remove current layer
            map.removeLayer(currentTileLayer);
            
            // Update button states
            document.querySelectorAll('.map-control-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Add new layer
            if (type === 'satellite') {
                currentTileLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                    attribution: 'Esri, DigitalGlobe, GeoEye, Earthstar Geographics'
                });
                document.getElementById('btn-satellite').classList.add('active');
            } else {
                currentTileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                });
                document.getElementById('btn-osm').classList.add('active');
            }
            
            currentTileLayer.addTo(map);
        }

        function fitAllMarkers() {
            if (markers.length > 0) {
                const group = new L.featureGroup(markers);
                map.fitBounds(group.getBounds(), {padding: [20, 20]});
            }
        }

        function filterStores() {
            const regionFilter = document.getElementById('region-filter').value;
            const statusFilter = document.getElementById('status-filter').value;
            const typeFilter = document.getElementById('type-filter').value;
            const searchQuery = document.getElementById('search-input').value.toLowerCase();
            
            filteredStores = allStores.filter(store => {
                const matchesRegion = !regionFilter || store.region_id == regionFilter;
                const matchesStatus = !statusFilter || store.status === statusFilter;
                const matchesType = !typeFilter || store.store_type === typeFilter;
                const matchesSearch = !searchQuery || 
                    store.name.toLowerCase().includes(searchQuery) ||
                    (store.address && store.address.toLowerCase().includes(searchQuery));
                
                return matchesRegion && matchesStatus && matchesType && matchesSearch;
            });
            
            updateMapMarkers(filteredStores);
            displayStores(filteredStores);
        }

        function displayStores(stores) {
            const grid = document.getElementById('store-grid');
            
            grid.innerHTML = stores.map(store => `
                <div class="enhanced-store-card" data-store-id="${store.id}">
                    <h3>${escapeHtml(store.name)}</h3>
                    <p><strong>Address:</strong> ${escapeHtml(store.address || 'N/A')}</p>
                    <p><strong>Manager:</strong> ${escapeHtml(store.manager_name || 'N/A')}</p>
                    <p><strong>Status:</strong> ${store.status}</p>
                    <p><strong>Products:</strong> ${store.product_count || 0}</p>
                    <div style="margin-top: 10px;">
                        <a href="profile.php?id=${store.id}" class="btn btn-primary btn-sm">Profile</a>
                        <a href="inventory_viewer.php?id=${store.id}" class="btn btn-secondary btn-sm">Inventory</a>
                    </div>
                </div>
            `).join('');
        }

        function highlightStoreCard(storeId) {
            document.querySelectorAll('.enhanced-store-card').forEach(card => {
                card.classList.remove('highlighted');
            });
            
            const card = document.querySelector(`[data-store-id="${storeId}"]`);
            if (card) {
                card.classList.add('highlighted');
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                setTimeout(() => {
                    card.classList.remove('highlighted');
                }, 3000);
            }
        }

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    </script>
</body>
</html>