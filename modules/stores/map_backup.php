<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Store Map - Inventory System</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .map-container {
            height: 600px;
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .map-controls {
            display: flex;
            gap: 10px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .store-info-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin: 10px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .store-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .store-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            background: white;
            transition: box-shadow 0.3s;
        }
        
        .store-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .store-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .search-section {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .search-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .legend {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            margin: 10px 0;
            font-size: 12px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin: 5px 0;
        }
        
        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #007bff;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>Store Mapping & Management</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="list.php">Stores</a></li>
                    <li><a href="map.php" class="active">Store Map</a></li>
                    <li><a href="regional_dashboard.php">Regional Reports</a></li>
                    <li><a href="../users/logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <div class="page-header">
                <h2>Interactive Store Map</h2>
                <div class="page-actions">
                    <a href="add.php" class="btn btn-primary">Add New Store</a>
                    <a href="list.php" class="btn btn-secondary">List View</a>
                </div>
            </div>

            <!-- Statistics Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number" id="total-stores">0</div>
                    <div class="stat-label">Total Stores</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="active-stores">0</div>
                    <div class="stat-label">Active Stores</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="regions-count">0</div>
                    <div class="stat-label">Regions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="total-inventory">0</div>
                    <div class="stat-label">Total Inventory Value</div>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="search-section">
                    <input type="text" id="search-input" class="search-input" placeholder="Search stores by name, city, or region...">
                    <button onclick="searchStores()" class="btn btn-primary">Search</button>
                    <button onclick="clearSearch()" class="btn btn-outline">Clear</button>
                </div>
                
                <div class="map-controls">
                    <select id="region-filter" onchange="filterByRegion()">
                        <option value="">All Regions</option>
                    </select>
                    
                    <select id="type-filter" onchange="filterByType()">
                        <option value="">All Store Types</option>
                        <option value="retail">Retail</option>
                        <option value="warehouse">Warehouse</option>
                        <option value="distribution">Distribution</option>
                        <option value="flagship">Flagship</option>
                    </select>
                    
                    <select id="status-filter" onchange="filterByStatus()">
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                    
                    <button onclick="centerMap()" class="btn btn-secondary">Center Map</button>
                    <button onclick="showAllStores()" class="btn btn-outline">Show All</button>
                </div>
            </div>

            <!-- Map Legend -->
            <div class="legend">
                <strong>Store Types:</strong>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #007bff;"></div>
                    <span>Retail Store</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #28a745;"></div>
                    <span>Warehouse</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #ffc107;"></div>
                    <span>Distribution Center</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #dc3545;"></div>
                    <span>Flagship Store</span>
                </div>
            </div>

            <!-- Interactive Map -->
            <div id="map" class="map-container">
                <div style="display: flex; align-items: center; justify-content: center; height: 100%; background: #f8f9fa; color: #666;">
                    <div style="text-align: center;">
                        <h3>Interactive Store Map</h3>
                        <p>Loading store locations...</p>
                        <p><small>Note: This demo uses a simulated map. In production, integrate with Google Maps, Leaflet, or similar mapping service.</small></p>
                    </div>
                </div>
            </div>

            <!-- Store List View -->
            <div class="store-list" id="store-list">
                <!-- Store cards will be dynamically loaded here -->
            </div>
        </main>
    </div>

    <script>
        // Store data - In production, this would be loaded from the backend
        let storesData = [];
        let filteredStores = [];
        let currentFilters = {
            search: '',
            region: '',
            type: '',
            status: ''
        };

        // Initialize the map page
        document.addEventListener('DOMContentLoaded', function() {
            loadStoreData();
            initializeMap();
            loadRegions();
        });

        // Load store data from backend
        async function loadStoreData() {
            try {
                const response = await fetch('api/get_stores_with_location.php');
                const data = await response.json();
                
                if (data.success) {
                    storesData = data.stores;
                    filteredStores = [...storesData];
                    updateStatistics();
                    renderStoreList();
                    updateMapMarkers();
                }
            } catch (error) {
                console.error('Error loading store data:', error);
                // Load sample data for demo
                loadSampleData();
            }
        }

        // Load sample data for demonstration
        function loadSampleData() {
            storesData = [
                {
                    id: 1,
                    name: 'Downtown Store',
                    code: 'DT001',
                    address: '123 Main St',
                    city: 'New York',
                    state: 'NY',
                    latitude: 40.7128,
                    longitude: -74.0060,
                    store_type: 'retail',
                    region_name: 'East Region',
                    active: 1,
                    total_inventory: 15000,
                    inventory_value: 125000,
                    staff_count: 5
                },
                {
                    id: 2,
                    name: 'Westside Warehouse',
                    code: 'WW002',
                    address: '456 West Ave',
                    city: 'Los Angeles',
                    state: 'CA',
                    latitude: 34.0522,
                    longitude: -118.2437,
                    store_type: 'warehouse',
                    region_name: 'West Region',
                    active: 1,
                    total_inventory: 50000,
                    inventory_value: 450000,
                    staff_count: 12
                },
                {
                    id: 3,
                    name: 'Central Distribution',
                    code: 'CD003',
                    address: '789 Central Blvd',
                    city: 'Chicago',
                    state: 'IL',
                    latitude: 41.8781,
                    longitude: -87.6298,
                    store_type: 'distribution',
                    region_name: 'Central Region',
                    active: 1,
                    total_inventory: 75000,
                    inventory_value: 625000,
                    staff_count: 20
                }
            ];
            
            filteredStores = [...storesData];
            updateStatistics();
            renderStoreList();
        }

        // Update statistics display
        function updateStatistics() {
            document.getElementById('total-stores').textContent = storesData.length;
            document.getElementById('active-stores').textContent = storesData.filter(s => s.active).length;
            document.getElementById('regions-count').textContent = [...new Set(storesData.map(s => s.region_name))].length;
            
            const totalValue = storesData.reduce((sum, store) => sum + (store.inventory_value || 0), 0);
            document.getElementById('total-inventory').textContent = '$' + totalValue.toLocaleString();
        }

        // Render store list
        function renderStoreList() {
            const container = document.getElementById('store-list');
            container.innerHTML = '';

            filteredStores.forEach(store => {
                const storeCard = createStoreCard(store);
                container.appendChild(storeCard);
            });
        }

        // Create individual store card
        function createStoreCard(store) {
            const card = document.createElement('div');
            card.className = 'store-card';
            card.innerHTML = `
                <div style="display: flex; justify-content: between; align-items: start; margin-bottom: 10px;">
                    <h3 style="margin: 0;">${store.name}</h3>
                    <span class="store-status ${store.active ? 'status-active' : 'status-inactive'}">
                        ${store.active ? 'Active' : 'Inactive'}
                    </span>
                </div>
                <p><strong>Code:</strong> ${store.code}</p>
                <p><strong>Address:</strong> ${store.address}, ${store.city}, ${store.state}</p>
                <p><strong>Type:</strong> ${store.store_type.charAt(0).toUpperCase() + store.store_type.slice(1)}</p>
                <p><strong>Region:</strong> ${store.region_name || 'N/A'}</p>
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                    <div style="display: flex; justify-content: space-between; font-size: 0.9em;">
                        <span>Inventory: ${store.total_inventory || 0}</span>
                        <span>Value: $${(store.inventory_value || 0).toLocaleString()}</span>
                    </div>
                    <div style="margin-top: 10px;">
                        <a href="profile.php?id=${store.id}" class="btn btn-sm btn-primary">View Profile</a>
                        <a href="inventory_viewer.php?id=${store.id}" class="btn btn-sm btn-secondary">View Inventory</a>
                        <a href="edit.php?id=${store.id}" class="btn btn-sm btn-outline">Edit</a>
                    </div>
                </div>
            `;
            
            return card;
        }

        // Search functionality
        function searchStores() {
            const searchTerm = document.getElementById('search-input').value.toLowerCase();
            currentFilters.search = searchTerm;
            applyFilters();
        }

        function clearSearch() {
            document.getElementById('search-input').value = '';
            currentFilters.search = '';
            applyFilters();
        }

        // Filter functions
        function filterByRegion() {
            currentFilters.region = document.getElementById('region-filter').value;
            applyFilters();
        }

        function filterByType() {
            currentFilters.type = document.getElementById('type-filter').value;
            applyFilters();
        }

        function filterByStatus() {
            currentFilters.status = document.getElementById('status-filter').value;
            applyFilters();
        }

        // Apply all filters
        function applyFilters() {
            filteredStores = storesData.filter(store => {
                // Search filter
                if (currentFilters.search) {
                    const searchMatch = store.name.toLowerCase().includes(currentFilters.search) ||
                                      store.city.toLowerCase().includes(currentFilters.search) ||
                                      (store.region_name && store.region_name.toLowerCase().includes(currentFilters.search));
                    if (!searchMatch) return false;
                }
                
                // Region filter
                if (currentFilters.region && store.region_name !== currentFilters.region) {
                    return false;
                }
                
                // Type filter
                if (currentFilters.type && store.store_type !== currentFilters.type) {
                    return false;
                }
                
                // Status filter
                if (currentFilters.status === 'active' && !store.active) return false;
                if (currentFilters.status === 'inactive' && store.active) return false;
                
                return true;
            });
            
            renderStoreList();
            updateMapMarkers();
        }

        // Map functions (placeholder for actual map integration)
        function initializeMap() {
            // In production, initialize Google Maps, Leaflet, or other mapping service here
            console.log('Map initialized with', storesData.length, 'store locations');
        }

        function updateMapMarkers() {
            // In production, update map markers based on filtered stores
            console.log('Updating map markers for', filteredStores.length, 'filtered stores');
        }

        function centerMap() {
            // In production, center map on all visible stores
            console.log('Centering map on visible stores');
        }

        function showAllStores() {
            // Reset all filters
            document.getElementById('search-input').value = '';
            document.getElementById('region-filter').value = '';
            document.getElementById('type-filter').value = '';
            document.getElementById('status-filter').value = '';
            
            currentFilters = { search: '', region: '', type: '', status: '' };
            filteredStores = [...storesData];
            renderStoreList();
            updateMapMarkers();
        }

        // Load regions for filter dropdown
        async function loadRegions() {
            try {
                const response = await fetch('api/get_regions.php');
                const data = await response.json();
                
                if (data.success) {
                    const select = document.getElementById('region-filter');
                    data.regions.forEach(region => {
                        const option = document.createElement('option');
                        option.value = region.name;
                        option.textContent = region.name;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Error loading regions:', error);
                // Add sample regions for demo
                const regions = ['East Region', 'West Region', 'Central Region', 'North Region', 'South Region'];
                const select = document.getElementById('region-filter');
                regions.forEach(region => {
                    const option = document.createElement('option');
                    option.value = region;
                    option.textContent = region;
                    select.appendChild(option);
                });
            }
        }
    </script>
</body>
</html>