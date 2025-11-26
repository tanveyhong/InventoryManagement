<?php
// Store Inventory Viewer - PostgreSQL Optimized
ob_start('ob_gzhandler');

require_once '../../config.php';
require_once '../../db.php';
require_once '../../sql_db.php';
require_once '../../functions.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$sqlDb = SQLDatabase::getInstance();

// Get store ID
$store_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$store_id) {
    $_SESSION['error'] = 'Store ID is required';
    header('Location: list.php');
    exit;
}

// Get store info
try {
    $store = $sqlDb->fetch("SELECT s.*, r.name as region_name 
                            FROM stores s 
                            LEFT JOIN regions r ON s.region_id = r.id 
                            WHERE s.id = ? AND s.active = TRUE", [$store_id]);
    
    if (!$store) {
        $_SESSION['error'] = 'Store not found';
        header('Location: list.php');
        exit;
    }
} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

// Get all products for client-side processing
try {
    // Get summary stats first
    $summary = $sqlDb->fetch("SELECT 
                                COUNT(*) as total_products,
                                COALESCE(SUM(quantity), 0) as total_quantity,
                                COALESCE(SUM(quantity * CAST(NULLIF(price, '') AS NUMERIC)), 0) as total_value,
                                COUNT(CASE WHEN quantity = 0 THEN 1 END) as out_of_stock,
                                COUNT(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 END) as low_stock
                            FROM products 
                            WHERE store_id = ? AND active = TRUE", [$store_id]);

    // Fetch ALL active products for this store
    // Limit to 5000 to prevent browser crash
    $sql = "SELECT p.*,
                   CASE 
                       WHEN p.quantity = 0 THEN 'out_of_stock'
                       WHEN p.quantity <= p.reorder_level THEN 'low_stock'
                       ELSE 'in_stock'
                   END as stock_status
            FROM products p
            WHERE p.store_id = ? AND p.active = TRUE
            ORDER BY p.name ASC
            LIMIT 5000";
    
    $all_products = $sqlDb->fetchAll($sql, [$store_id]);
    
    // Get categories from the fetched products to avoid extra query
    $categories = [];
    $cats = array_unique(array_column($all_products, 'category'));
    sort($cats);
    foreach ($cats as $c) {
        if ($c) $categories[] = ['category' => $c];
    }
    
} catch (Exception $e) {
    error_log('Error fetching products: ' . $e->getMessage());
    $all_products = [];
    $categories = [];
    $summary = ['total_products' => 0, 'total_quantity' => 0, 'total_value' => 0, 'out_of_stock' => 0, 'low_stock' => 0];
}

$page_title = "Inventory - {$store['name']} - Inventory System";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* ...existing code... */
        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --background: #f3f4f6;
            --surface: #ffffff;
            --text-main: #1f2937;
            --text-light: #6b7280;
            --border: #e5e7eb;
        }

        body {
            background-color: var(--background);
            min-height: 100vh;
            margin: 0;
            font-family: 'Inter', 'Segoe UI', sans-serif;
            color: var(--text-main);
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        /* Header Styles */
        .header {
            background: var(--surface);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .header-content h1 {
            margin: 0 0 8px 0;
            color: var(--text-main);
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .header-content .subtitle {
            color: var(--text-light);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .header-content .subtitle span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: white;
            color: var(--text-main);
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            border: 1px solid var(--border);
            transition: all 0.2s;
            margin-bottom: 10px;
        }
        
        .back-btn:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: var(--surface);
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 15px;
            border: 1px solid var(--border);
            transition: transform 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card .icon {
            font-size: 20px;
            color: var(--primary);
            background: #e0e7ff;
            width: 40px;
            height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            flex-shrink: 0;
            margin-bottom: 0;
        }
        
        .stat-card .content {
            display: flex;
            flex-direction: column;
        }
        
        .stat-card .value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
            line-height: 1.2;
            margin-bottom: 0;
        }
        
        .stat-card .label {
            font-size: 11px;
            color: var(--text-light);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-card.warning .icon { color: #d97706; background: #fef3c7; }
        .stat-card.danger .icon { color: #dc2626; background: #fee2e2; }
        .stat-card.success .icon { color: #059669; background: #d1fae5; }

        /* Card & Table Styles */
        .card {
            background: var(--surface);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
            margin-bottom: 20px;
        }

        .products-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .products-table th {
            background: #f9fafb;
            color: var(--text-light);
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        
        .products-table th a {
            color: var(--text-light);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: color 0.2s;
        }
        
        .products-table th a:hover {
            color: var(--primary);
        }
        
        .products-table th a.active {
            color: var(--primary);
        }
        
        .products-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            color: var(--text-main);
            font-size: 14px;
        }
        
        .products-table tr:last-child td {
            border-bottom: none;
        }

        .table-responsive {
            overflow-x: auto;
        }

        /* Filter Styles */
        .filter-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-group label {
            font-size: 13px;
            color: var(--text-light);
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select {
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            color: var(--text-main);
            background: white;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: white;
            color: var(--text-main);
            border: 1px solid var(--border);
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.in-stock { background: #d1fae5; color: #065f46; }
        .status-badge.low-stock { background: #fef3c7; color: #92400e; }
        .status-badge.out-of-stock { background: #fee2e2; color: #991b1b; }
        .status-badge.expired { background: #fee2e2; color: #991b1b; }
        .status-badge.expiring-soon { background: #fef3c7; color: #92400e; }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
        }
        
        .pagination-info {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .pagination-links {
            display: flex;
            gap: 8px;
        }
        
        .pagination-links a,
        .pagination-links span {
            padding: 8px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        
        .pagination-links a {
            background: white;
            color: var(--text-main);
            border: 1px solid var(--border);
        }
        
        .pagination-links a:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }
        
        .pagination-links .current {
            background: var(--primary);
            color: white;
            border: 1px solid var(--primary);
        }

        .no-products {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .no-products i {
            font-size: 48px;
            color: #d1d5db;
            margin-bottom: 20px;
        }

        @media (max-width: 1024px) {
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <a href="profile.php?id=<?php echo $store_id; ?>" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Profile
                </a>
                <h1><i class="fas fa-boxes"></i> <?php echo htmlspecialchars($store['name']); ?> - Inventory</h1>
                <div class="subtitle">
                    <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($store['address'] ?? 'N/A'); ?>, <?php echo htmlspecialchars($store['city'] ?? 'N/A'); ?></span>
                    <?php if (!empty($store['region_name'])): ?>
                        <span>· <i class="fas fa-map"></i> <?php echo htmlspecialchars($store['region_name']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
            
        <!-- Summary Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon"><i class="fas fa-box"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($summary['total_products']); ?></div>
                    <div class="label">Total Products</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="icon"><i class="fas fa-cubes"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($summary['total_quantity']); ?></div>
                    <div class="label">Total Stock</div>
                </div>
            </div>
            <div class="stat-card success">
                <div class="icon"><i class="fas fa-dollar-sign"></i></div>
                <div class="content">
                    <div class="value">$<?php echo number_format($summary['total_value'], 2); ?></div>
                    <div class="label">Total Value</div>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($summary['low_stock']); ?></div>
                    <div class="label">Low Stock</div>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="icon"><i class="fas fa-times-circle"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($summary['out_of_stock']); ?></div>
                    <div class="label">Out of Stock</div>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="icon"><i class="fas fa-calendar-times"></i></div>
                <div class="content">
                    <div class="value"><?php echo number_format($summary['expired'] + $summary['expiring_soon']); ?></div>
                    <div class="label">Expired/Expiring</div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card">
            <form class="filter-form" id="filterForm">
                <div class="form-group">
                    <label>Search</label>
                    <input type="text" id="searchInput" placeholder="Product name, SKU, or barcode...">
                </div>
                
                <div class="form-group">
                    <label>Category</label>
                    <select id="categorySelect">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['category']); ?>">
                                <?php echo htmlspecialchars($cat['category']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select id="statusSelect">
                        <option value="">All Status</option>
                        <option value="low_stock">Low Stock</option>
                        <option value="out_of_stock">Out of Stock</option>
                        <option value="expired">Expired</option>
                        <option value="expiring_soon">Expiring Soon</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="button" id="clearFiltersBtn" class="btn btn-secondary" style="justify-content: center;">
                        <i class="fas fa-times"></i> Clear Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Products Table -->
        <div class="card">
            <div class="table-responsive">
                <table class="products-table" id="productsTable">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th><a href="#" data-sort="name" class="sort-link active">Product Name <i class="fas fa-sort-up"></i></a></th>
                            <th>Category</th>
                            <th><a href="#" data-sort="quantity" class="sort-link">Quantity <i class="fas fa-sort" style="color: #d1d5db;"></i></a></th>
                            <th><a href="#" data-sort="price" class="sort-link">Price <i class="fas fa-sort" style="color: #d1d5db;"></i></a></th>
                            <th><a href="#" data-sort="status" class="sort-link">Status <i class="fas fa-sort" style="color: #d1d5db;"></i></a></th>
                        </tr>
                    </thead>
                    <tbody id="productsBody">
                        <!-- Rows will be populated by JS -->
                    </tbody>
                </table>
            </div>
            
            <div id="noProductsMsg" class="no-products" style="display: none;">
                <i class="fas fa-box-open"></i>
                <h2>No Products Found</h2>
                <p>No products match your filters.</p>
            </div>
        </div>
        
        <!-- Pagination -->
        <div class="pagination" id="paginationContainer">
            <div class="pagination-info" id="paginationInfo">
                Showing 0-0 of 0 products
            </div>
            <div class="pagination-links" id="paginationLinks">
                <!-- Links generated by JS -->
            </div>
        </div>
    </div>

    <script>
        // Pass PHP data to JS
        const allProducts = <?php echo json_encode($all_products); ?>;
        let currentProducts = [...allProducts];
        
        // State
        let currentPage = 1;
        const perPage = 25;
        let currentSort = 'name';
        let currentOrder = 'asc';
        
        // DOM Elements
        const tableBody = document.getElementById('productsBody');
        const searchInput = document.getElementById('searchInput');
        const categorySelect = document.getElementById('categorySelect');
        const statusSelect = document.getElementById('statusSelect');
        const clearBtn = document.getElementById('clearFiltersBtn');
        const paginationInfo = document.getElementById('paginationInfo');
        const paginationLinks = document.getElementById('paginationLinks');
        const noProductsMsg = document.getElementById('noProductsMsg');
        const tableElement = document.getElementById('productsTable');

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            renderTable();
            setupEventListeners();
        });

        function setupEventListeners() {
            // Search & Filter
            searchInput.addEventListener('input', handleFilter);
            categorySelect.addEventListener('change', handleFilter);
            statusSelect.addEventListener('change', handleFilter);
            
            // Clear
            clearBtn.addEventListener('click', function() {
                searchInput.value = '';
                categorySelect.value = '';
                statusSelect.value = '';
                handleFilter();
            });

            // Sorting
            document.querySelectorAll('.sort-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const field = this.dataset.sort;
                    
                    if (currentSort === field) {
                        currentOrder = currentOrder === 'asc' ? 'desc' : 'asc';
                    } else {
                        currentSort = field;
                        currentOrder = 'asc';
                    }
                    
                    updateSortIcons();
                    sortProducts();
                    renderTable();
                });
            });
        }

        function updateSortIcons() {
            document.querySelectorAll('.sort-link').forEach(link => {
                const icon = link.querySelector('i');
                link.classList.remove('active');
                icon.className = 'fas fa-sort';
                icon.style.color = '#d1d5db';
                
                if (link.dataset.sort === currentSort) {
                    link.classList.add('active');
                    icon.className = currentOrder === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
                    icon.style.color = '';
                }
            });
        }

        function handleFilter() {
            const search = searchInput.value.toLowerCase();
            const category = categorySelect.value;
            const status = statusSelect.value;
            
            currentProducts = allProducts.filter(p => {
                // Search
                const matchSearch = !search || 
                    (p.name && p.name.toLowerCase().includes(search)) || 
                    (p.sku && p.sku.toLowerCase().includes(search)) || 
                    (p.barcode && p.barcode.toLowerCase().includes(search));
                
                // Category
                const matchCategory = !category || p.category === category;
                
                // Status
                let matchStatus = true;
                if (status) {
                    if (status === 'low_stock') matchStatus = p.stock_status === 'low_stock';
                    else if (status === 'out_of_stock') matchStatus = p.stock_status === 'out_of_stock';
                    // Add logic for expired if data available, currently assuming stock_status covers basic needs
                    // For expired, we'd need expiry_date logic in JS or pre-calculated in PHP
                }
                
                return matchSearch && matchCategory && matchStatus;
            });
            
            currentPage = 1;
            sortProducts();
            renderTable();
        }

        function sortProducts() {
            currentProducts.sort((a, b) => {
                let valA = a[currentSort];
                let valB = b[currentSort];
                
                // Handle numeric sorting
                if (currentSort === 'quantity' || currentSort === 'price') {
                    valA = parseFloat(valA) || 0;
                    valB = parseFloat(valB) || 0;
                }
                
                // Handle status sorting (custom order)
                if (currentSort === 'status') {
                    const statusRank = { 'out_of_stock': 0, 'low_stock': 1, 'in_stock': 2 };
                    valA = statusRank[a.stock_status] ?? 2;
                    valB = statusRank[b.stock_status] ?? 2;
                }

                // String comparison for names
                if (typeof valA === 'string') valA = valA.toLowerCase();
                if (typeof valB === 'string') valB = valB.toLowerCase();

                if (valA < valB) return currentOrder === 'asc' ? -1 : 1;
                if (valA > valB) return currentOrder === 'asc' ? 1 : -1;
                return 0;
            });
        }

        function renderTable() {
            const start = (currentPage - 1) * perPage;
            const end = start + perPage;
            const pageData = currentProducts.slice(start, end);
            
            // Toggle No Products
            if (currentProducts.length === 0) {
                tableElement.style.display = 'none';
                noProductsMsg.style.display = 'block';
                paginationContainer.style.display = 'none';
                return;
            } else {
                tableElement.style.display = 'table';
                noProductsMsg.style.display = 'none';
                paginationContainer.style.display = 'flex';
            }

            // Render Rows
            let html = '';
            pageData.forEach(p => {
                const price = parseFloat(p.price).toFixed(2);
                const statusText = p.stock_status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                const statusClass = p.stock_status.replace(/_/g, '-');
                
                html += `
                    <tr>
                        <td><strong>${p.sku || 'N/A'}</strong></td>
                        <td>${p.name}</td>
                        <td>${p.category || 'Uncategorized'}</td>
                        <td>${parseInt(p.quantity).toLocaleString()}</td>
                        <td>$${price}</td>
                        <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    </tr>
                `;
            });
            tableBody.innerHTML = html;
            
            renderPagination();
        }

        function renderPagination() {
            const totalPages = Math.ceil(currentProducts.length / perPage);
            const start = (currentPage - 1) * perPage + 1;
            const end = Math.min(currentPage * perPage, currentProducts.length);
            
            paginationInfo.textContent = `Showing ${start}-${end} of ${currentProducts.length.toLocaleString()} products`;
            
            let linksHtml = '';
            
            // Prev
            if (currentPage > 1) {
                linksHtml += `<a href="#" onclick="changePage(${currentPage - 1}); return false;"><i class="fas fa-chevron-left"></i> Previous</a>`;
            }
            
            // Pages (simplified window)
            let minPage = Math.max(1, currentPage - 2);
            let maxPage = Math.min(totalPages, currentPage + 2);
            
            for (let i = minPage; i <= maxPage; i++) {
                if (i === currentPage) {
                    linksHtml += `<span class="current">${i}</span>`;
                } else {
                    linksHtml += `<a href="#" onclick="changePage(${i}); return false;">${i}</a>`;
                }
            }
            
            // Next
            if (currentPage < totalPages) {
                linksHtml += `<a href="#" onclick="changePage(${currentPage + 1}); return false;">Next <i class="fas fa-chevron-right"></i></a>`;
            }
            
            paginationLinks.innerHTML = linksHtml;
        }

        // Global function for pagination onclick
        window.changePage = function(page) {
            currentPage = page;
            renderTable();
            // Scroll to top of table
            document.querySelector('.card').scrollIntoView({ behavior: 'smooth' });
        };
    </script>
</body>
</html>
</body>
</html>
