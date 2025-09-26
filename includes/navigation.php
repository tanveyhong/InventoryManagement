<?php
// Enhanced Navigation for Inventory Management System
?>
<nav class="main-navigation">
    <div class="nav-container">
        <div class="nav-brand">
            <a href="../../index.php">
                <i class="fas fa-cube"></i>
                <span>Inventory System</span>
            </a>
        </div>
        <ul class="nav-menu">
            <li><a href="../../index.php" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-boxes"></i> Stock <i class="fas fa-chevron-down"></i>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="../../modules/stock/list.php">View Stock</a></li>
                    <li><a href="../../modules/stock/add.php">Add Stock</a></li>
                    <li><a href="../../modules/stock/adjust.php">Stock Adjustments</a></li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-store"></i> Stores <i class="fas fa-chevron-down"></i>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="../../modules/stores/list.php">Store List</a></li>
                    <li><a href="../../modules/stores/add.php">Add Store</a></li>
                    <li><a href="../../modules/stores/enhanced_map.php">Store Map</a></li>
                    <li><a href="../../modules/stores/regional_dashboard.php">Regional Dashboard</a></li>
                </ul>
            </li>
            <li class="dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-chart-line"></i> Reports <i class="fas fa-chevron-down"></i>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="../../modules/reports/sales.php">Sales Reports</a></li>
                    <li><a href="../../modules/reports/inventory.php">Inventory Reports</a></li>
                    <li><a href="../../modules/reports/alerts.php">Alerts</a></li>
                </ul>
            </li>
            <li><a href="../../modules/users/profile.php" class="nav-link"><i class="fas fa-user"></i> Profile</a></li>
            <li><a href="../../modules/users/logout.php" class="nav-link logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
        <div class="nav-toggle">
            <span></span>
            <span></span>
            <span></span>
        </div>
    </div>
</nav>

<style>
.main-navigation {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    position: sticky;
    top: 0;
    z-index: 1000;
    margin-bottom: 20px;
}

.nav-container {
    max-width: 1200px;
    margin: 0 auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 20px;
}

.nav-brand a {
    display: flex;
    align-items: center;
    gap: 10px;
    color: white;
    text-decoration: none;
    font-size: 1.5rem;
    font-weight: 700;
}

.nav-menu {
    display: flex;
    list-style: none;
    margin: 0;
    padding: 0;
    align-items: center;
}

.nav-menu li {
    position: relative;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 15px 20px;
    color: white;
    text-decoration: none;
    transition: all 0.3s ease;
    border-radius: 8px;
    margin: 0 5px;
}

.nav-link:hover {
    background: rgba(255,255,255,0.1);
    transform: translateY(-2px);
}

.dropdown-menu {
    position: absolute;
    top: 100%;
    left: 0;
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s ease;
    min-width: 200px;
    z-index: 1001;
}

.dropdown:hover .dropdown-menu {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
}

.dropdown-menu li {
    list-style: none;
}

.dropdown-menu a {
    display: block;
    padding: 12px 20px;
    color: #333;
    text-decoration: none;
    transition: background 0.3s ease;
}

.dropdown-menu a:hover {
    background: #f8f9fa;
}

.nav-toggle {
    display: none;
    flex-direction: column;
    cursor: pointer;
    padding: 5px;
}

.nav-toggle span {
    width: 25px;
    height: 3px;
    background: white;
    margin: 3px 0;
    transition: 0.3s;
    border-radius: 2px;
}

.logout {
    background: rgba(220, 53, 69, 0.2);
    border: 1px solid rgba(220, 53, 69, 0.3);
}

.logout:hover {
    background: rgba(220, 53, 69, 0.3);
}

@media (max-width: 768px) {
    .nav-menu {
        position: fixed;
        top: 70px;
        left: -100%;
        width: 100%;
        height: calc(100vh - 70px);
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        flex-direction: column;
        align-items: flex-start;
        transition: left 0.3s ease;
        padding-top: 20px;
    }
    
    .nav-menu.active {
        left: 0;
    }
    
    .nav-toggle {
        display: flex;
    }
    
    .dropdown-menu {
        position: static;
        opacity: 1;
        visibility: visible;
        transform: none;
        background: rgba(255,255,255,0.1);
        box-shadow: none;
        margin-left: 20px;
        margin-top: 10px;
    }
    
    .dropdown-menu a {
        color: white;
        padding: 8px 15px;
    }
}

.main-content {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}
</style>

<script>
// Mobile navigation toggle
document.addEventListener('DOMContentLoaded', function() {
    const navToggle = document.querySelector('.nav-toggle');
    const navMenu = document.querySelector('.nav-menu');
    
    if (navToggle) {
        navToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
        });
    }
});
</script>