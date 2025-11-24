<?php
/**
 * Modern Dashboard Header Component with Navigation
 * Provides consistent navigation and header styling across all dashboard modules
 */

// Ensure required functions are available
if (!function_exists('currentUserHasPermission')) {
    // Determine the relative path to root based on current location
    $currentPath = $_SERVER['PHP_SELF'];
    if (strpos($currentPath, 'modules/users/profile/') !== false) {
        require_once __DIR__ . '/../../functions.php';
    } elseif (strpos($currentPath, 'modules/') !== false) {
        require_once __DIR__ . '/../functions.php';
    } else {
        // Called from root level
        require_once __DIR__ . '/../functions.php';
    }
}

// Calculate the correct path prefix based on the current file's depth
$currentPath = $_SERVER['PHP_SELF'];
$depth = substr_count($currentPath, '/') - 1; // Subtract 1 for the root level

// Determine the relative path to root
if (strpos($currentPath, 'modules/users/profile/') !== false) {
    // 3 levels deep: modules/users/profile/
    $baseUrl = '../../../';
} elseif (strpos($currentPath, 'modules/') !== false) {
    // 2 levels deep: modules/something/
    $baseUrl = '../../';
} else {
    // Root level or includes level
    $baseUrl = '';
}

// Determine active section for navigation highlighting
$activeSection = '';
if (strpos($currentPath, '/index.php') !== false && strpos($currentPath, '/modules/') === false) {
    $activeSection = 'dashboard';
} elseif (strpos($currentPath, '/modules/stock/') !== false || strpos($currentPath, '/modules/pos/') !== false) {
    $activeSection = 'stock';
} elseif (strpos($currentPath, '/modules/stores/') !== false) {
    $activeSection = 'stores';
} elseif (strpos($currentPath, '/modules/suppliers/') !== false || strpos($currentPath, '/modules/purchase_orders/') !== false) {
    $activeSection = 'supply_chain';
} elseif (strpos($currentPath, '/modules/reports/') !== false || strpos($currentPath, '/modules/forecasting/') !== false) {
    $activeSection = 'reports';
} elseif (strpos($currentPath, '/modules/alerts/') !== false) {
    $activeSection = 'alerts';
}

// Log page visit
if (file_exists(__DIR__ . '/../activity_logger.php')) {
    require_once __DIR__ . '/../activity_logger.php';
    if (function_exists('logActivity') && isset($_SESSION['user_id'])) {
        $pageName = basename($_SERVER['PHP_SELF']);
        // Avoid logging API/AJAX calls
        if (strpos($pageName, 'api') === false && strpos($pageName, 'ajax') === false) {
            // Prevent duplicate logging in a single request
            if (!isset($GLOBALS['page_visit_logged'])) {
                $GLOBALS['page_visit_logged'] = true;
                // error_log("Attempting to log page visit for: " . $pageName);
                $logResult = logActivity('page_visit', "Visited page: " . $pageName, [
                    'url' => $_SERVER['REQUEST_URI'],
                    'page' => $pageName
                ]);
                // error_log("Page visit log result: " . ($logResult ? 'Success' : 'Failed'));
            }
        }
    } else {
        // error_log("Page visit log skipped: User not logged in or logActivity not found");
    }
} else {
    // error_log("Page visit log skipped: activity_logger.php not found");
}

?>

<style>
/* Reset and Base Styles */
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    background: #f8fafc;
    color: #2d3748;
    line-height: 1.6;
}

.dashboard-wrapper {
    /* Let page content determine height; avoid forcing full-viewport spacer that creates gaps */
    min-height: auto;
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
}

/* Top Navigation Bar */
.top-navbar {
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    position: sticky;
    top: 0;
    z-index: 1000;
}

.navbar-container {
    max-width: 1400px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 2rem;
    height: 70px;
}

/* Brand Section */
.navbar-brand {
    flex-shrink: 0;
}

.brand-link {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: inherit;
    transition: transform 0.2s ease;
}

.brand-link:hover {
    transform: translateY(-1px);
}

.brand-icon {
    width: 44px;
    height: 44px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.brand-text {
    display: flex;
    flex-direction: column;
}

.brand-name {
    font-size: 1.25rem;
    font-weight: 700;
    color: #1a202c;
    line-height: 1.2;
}

.brand-subtitle {
    font-size: 0.8rem;
    color: #718096;
    line-height: 1;
}

/* Navigation Menu */
.navbar-menu {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    justify-content: center;
    margin: 0 2rem;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 10px;
    color: #4a5568;
    text-decoration: none;
    font-weight: 500;
    font-size: 0.95rem;
    transition: all 0.2s ease;
    position: relative;
    white-space: nowrap;
}

.nav-item:hover {
    background: #f7fafc;
    color: #667eea;
    transform: translateY(-1px);
}

.nav-item.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
}

.nav-item i {
    font-size: 1rem;
}

.dropdown-arrow {
    font-size: 0.7rem;
    margin-left: 4px;
    transition: transform 0.2s ease;
}

/* Dropdown Functionality */
.nav-dropdown {
    position: relative;
}

.nav-dropdown:hover .dropdown-arrow {
    transform: rotate(180deg);
}

.dropdown-content {
    position: absolute;
    top: 100%;
    left: 0;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    opacity: 0;
    visibility: hidden;
    transform: translateY(-10px);
    transition: all 0.3s ease;
    min-width: 200px;
    z-index: 1001;
    border: 1px solid #e2e8f0;
    padding: 8px 0;
    margin-top: 4px;
    pointer-events: none;
}

/* Create invisible bridge between trigger and dropdown */
.dropdown-content::before {
    content: '';
    position: absolute;
    top: -8px;
    left: 0;
    right: 0;
    height: 8px;
    background: transparent;
}

.nav-dropdown:hover .dropdown-content,
.user-dropdown:hover .dropdown-content {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
    pointer-events: auto;
}

.dropdown-content a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: #4a5568;
    text-decoration: none;
    font-size: 0.9rem;
    transition: all 0.2s ease;
    border-radius: 0;
}

.dropdown-content a:hover {
    background: #f7fafc;
    color: #667eea;
    transform: none;
}

.dropdown-content a i {
    font-size: 0.9rem;
    width: 16px;
}

/* Ensure dropdowns work on mobile */
@media (max-width: 768px) {
    .nav-dropdown .dropdown-content,
    .user-dropdown .dropdown-content {
        position: static;
        opacity: 1 !important;
        visibility: visible !important;
        transform: none !important;
        box-shadow: none;
        border: none;
        background: #f7fafc;
        margin: 8px 0 0 0;
        padding: 0;
        border-radius: 8px;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease, padding 0.3s ease;
    }
    
    .nav-dropdown:hover .dropdown-content,
    .nav-dropdown.active .dropdown-content,
    .user-dropdown:hover .dropdown-content,
    .user-dropdown.active .dropdown-content {
        max-height: 200px;
        padding: 8px 0;
    }
}

/* User Section */
.navbar-user {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-shrink: 0;
}

/* Connection Status Indicator */
.connection-status {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
    transition: all 0.3s ease;
    border: 1px solid;
}

.connection-status.online {
    background: #f0fdf4;
    color: #15803d;
    border-color: #86efac;
}

.connection-status.offline {
    background: #fef2f2;
    color: #b91c1c;
    border-color: #fca5a5;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    animation: pulse 2s ease-in-out infinite;
}

.connection-status.online .status-dot {
    background: #22c55e;
    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
}

.connection-status.offline .status-dot {
    background: #ef4444;
    box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    animation: pulse-offline 2s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% {
        box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.7);
    }
    50% {
        box-shadow: 0 0 0 6px rgba(34, 197, 94, 0);
    }
}

@keyframes pulse-offline {
    0%, 100% {
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7);
    }
    50% {
        box-shadow: 0 0 0 6px rgba(239, 68, 68, 0);
    }
}

.status-text {
    font-size: 0.875rem;
    letter-spacing: 0.01em;
}

.user-dropdown {
    position: relative;
}

.user-profile {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 10px;
    color: #4a5568;
    text-decoration: none;
    transition: all 0.2s ease;
    font-weight: 500;
}

.user-profile:hover {
    background: #f7fafc;
    color: #667eea;
}

.user-avatar {
    width: 36px;
    height: 36px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.9rem;
    overflow: hidden;
    flex-shrink: 0;
}

.user-name {
    font-size: 0.9rem;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.user-menu {
    right: 0;
    left: auto;
    min-width: 160px;
}

.logout-link {
    color: #e53e3e !important;
    border-top: 1px solid #e2e8f0;
    margin-top: 4px;
    padding-top: 12px !important;
}

.logout-link:hover {
    background: #fed7d7 !important;
    color: #c53030 !important;
}

/* Mobile Menu Toggle */
.mobile-menu-toggle {
    display: none;
    flex-direction: column;
    gap: 4px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    transition: background 0.2s ease;
}

.mobile-menu-toggle:hover {
    background: #f7fafc;
}

.mobile-menu-toggle span {
    width: 24px;
    height: 2px;
    background: #4a5568;
    border-radius: 1px;
    transition: all 0.2s ease;
}

/* Dashboard Content */
.dashboard-content {
    max-width: 1400px;
    margin: 0 auto;
    padding: 2rem;
}

/* Page Header */
.page-header {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    border: 1px solid #e2e8f0;
}

.header-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}

.header-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.header-text h1 {
    font-size: 1.875rem;
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 0.5rem;
}

.header-text p {
    font-size: 1rem;
    color: #718096;
}

.header-actions {
    display: flex;
    gap: 1rem;
}

.btn-compact-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    background: #f7fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    color: #4a5568;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 0.9rem;
}

.btn-compact-toggle:hover {
    background: #667eea;
    color: white;
    border-color: #667eea;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

/* Statistics Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    border: 1px solid #e2e8f0;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

.stat-card-inner {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
}

.stat-icon-wrapper {
    flex-shrink: 0;
}

.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: white;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.stat-icon.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stat-icon.success { background: linear-gradient(135deg, #48bb78 0%, #38b2ac 100%); }
.stat-icon.warning { background: linear-gradient(135deg, #ed8936 0%, #dd6b20 100%); }
.stat-icon.info { background: linear-gradient(135deg, #4299e1 0%, #3182ce 100%); }
.stat-icon.alert { background: linear-gradient(135deg, #f56565 0%, #e53e3e 100%); }

.stat-content {
    flex: 1;
    min-width: 0;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: #1a202c;
    line-height: 1.2;
    margin-bottom: 0.25rem;
}

.stat-label {
    font-size: 0.95rem;
    color: #718096;
    font-weight: 500;
    margin-bottom: 0.75rem;
}

.stat-trend {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    padding: 4px 8px;
    border-radius: 6px;
    width: fit-content;
}

.stat-trend.trend-up, .stat-trend.up {
    background: #f0fff4;
    color: #38a169;
}

.stat-trend.trend-down, .stat-trend.down {
    background: #fff5f5;
    color: #e53e3e;
}

.stat-trend.trend-neutral, .stat-trend.neutral {
    background: #f7fafc;
    color: #718096;
}

/* Responsive Design */
@media (max-width: 1024px) {
    .navbar-container {
        padding: 0 1.5rem;
    }
    
    .dashboard-content {
        padding: 1.5rem;
    }
    
    .navbar-menu {
        margin: 0 1rem;
        gap: 0.25rem;
    }
    
    .nav-item span {
        font-size: 0.85rem;
    }
}

@media (max-width: 768px) {
    .navbar-menu {
        display: flex; /* Keep visible but adjust layout */
        flex-wrap: wrap;
        margin: 0 0.5rem;
        gap: 0.25rem;
    }
    
    .nav-item {
        padding: 8px 12px;
        font-size: 0.8rem;
    }
    
    .nav-item span {
        display: none; /* Hide text labels on small screens */
    }
    
    .nav-item i {
        font-size: 1.1rem;
    }
    
    .mobile-menu-toggle {
        display: none; /* Not needed since menu is visible */
    }
    
    .navbar-container {
        padding: 0 0.5rem;
        height: 60px;
        flex-wrap: wrap;
    }
    
    .brand-name {
        font-size: 1.1rem;
    }
    
    .brand-subtitle {
        display: none;
    }
    
    /* Connection status on mobile */
    .connection-status .status-text {
        display: none;
    }
    
    .connection-status {
        padding: 6px 8px;
        min-width: auto;
    }
    
    .dashboard-content {
        padding: 1rem;
    }
    
    .page-header {
        padding: 1.5rem;
    }
    
    .header-content {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
    
    .header-left {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    
    .stat-card {
        padding: 1.25rem;
    }
    
    .user-name {
        display: none;
    }
}

@media (max-width: 480px) {
    .brand-text {
        display: none;
    }
    
    .header-icon {
        width: 50px;
        height: 50px;
        font-size: 1.25rem;
    }
    
    .header-text h1 {
        font-size: 1.5rem;
    }
    
    .stat-number {
        font-size: 1.5rem;
    }
}

/* Compact View */
body.compact-view .stats-grid {
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}

body.compact-view .stat-card {
    padding: 1rem;
}

body.compact-view .stat-number {
    font-size: 1.5rem;
}

body.compact-view .page-header {
    padding: 1.5rem;
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

/* Dashboard Header Styles */
.welcome-section {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px;
    border-radius: 20px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.welcome-text h1 {
    margin: 0 0 10px 0;
    font-size: 2.5rem;
    font-weight: 700;
}

.welcome-text p {
    margin: 0;
    font-size: 1.2rem;
    opacity: 0.9;
}

.welcome-controls {
    display: flex;
    gap: 15px;
    align-items: center;
}

.btn-toggle-compact {
    background: rgba(255,255,255,0.2);
    border: 1px solid rgba(255,255,255,0.3);
    color: white;
    padding: 10px 20px;
    border-radius: 25px;
    cursor: pointer;
    transition: all 0.3s ease;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-toggle-compact:hover {
    background: rgba(255,255,255,0.3);
    transform: translateY(-2px);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    padding: 30px;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.stat-icon.primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stat-icon.success { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
.stat-icon.warning { background: linear-gradient(135deg, #fcb045 0%, #fd1d1d 100%); }
.stat-icon.info { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stat-icon.alert { background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%); }
.stat-icon.stores { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.stat-icon.sales { background: linear-gradient(135deg, #fcb045 0%, #fd1d1d 100%); }

.stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: #2c3e50;
    margin: 10px 0;
}

.stat-label {
    color: #64748b;
    font-size: 1.1rem;
    font-weight: 500;
    margin: 0 0 15px 0;
}

.stat-trend {
    font-size: 0.9rem;
    margin-top: 10px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.trend-up { color: #27ae60; }
.trend-down { color: #e74c3c; }
.trend-neutral { color: #64748b; }

@media (max-width: 768px) {
    .welcome-section {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .welcome-text h1 {
        font-size: 2rem;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<!-- Modern Navigation Header -->
<div class="dashboard-wrapper">
    <!-- Top Navigation Bar -->
    <nav class="top-navbar">
        <div class="navbar-container">
            <!-- Left Section: Brand -->
            <div class="navbar-brand">
                <a href="<?php echo $baseUrl . 'index.php'; ?>" class="brand-link">
                    <div class="brand-icon">
                        <i class="fas fa-cube"></i>
                    </div>
                    <div class="brand-text">
                        <span class="brand-name">Inventory Pro</span>
                        <span class="brand-subtitle">Management System</span>
                    </div>
                </a>
            </div>

            <!-- Center Section: Navigation Menu -->
            <div class="navbar-menu">
                <a href="<?php echo $baseUrl . 'index.php'; ?>" class="nav-item <?php echo ($activeSection === 'dashboard') ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
                
                <?php if (currentUserHasPermission('can_view_inventory') || currentUserHasPermission('can_use_pos') || currentUserHasPermission('can_add_inventory')): ?>
                <div class="nav-dropdown">
                    <a href="#" class="nav-item <?php echo ($activeSection === 'stock') ? 'active' : ''; ?>">
                        <i class="fas fa-boxes"></i>
                        <span>Stock</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </a>
                    <div class="dropdown-content">
                        <a href="<?php echo $baseUrl . 'modules/stock/list.php'; ?>">
                            <i class="fas fa-list"></i> Stock Listing
                        </a>
                        <?php if (currentUserHasPermission('can_add_inventory') || currentUserHasPermission('can_edit_inventory')): ?>
                            <a href="<?php echo $baseUrl . 'modules/stock/add.php'; ?>">
                                <i class="fas fa-plus"></i> Add Stock
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo $baseUrl . 'modules/stock/stockAuditHis.php'; ?>">
                            <i class="fas fa-edit"></i> Stock Audit History
                        </a>
                        <?php if (currentUserHasPermission('can_manage_pos') || currentUserHasPermission('can_use_pos')): ?>
                        <a href="<?php echo $baseUrl . 'modules/pos/stock_pos_integration.php'; ?>">
                            <i class="fas fa-cash-register"></i> POS Integration
                        </a>
                        <?php endif; ?>
                            <a href="<?php echo $baseUrl . 'modules/stock/mobileBarcodeScan.php'; ?>">
                                <i class="fas fa-barcode"></i> Product Barcode Scanning
                            </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (currentUserHasPermission('can_view_stores') || currentUserHasPermission('can_add_stores')): ?>
                <div class="nav-dropdown">
                    <a href="#" class="nav-item <?php echo ($activeSection === 'stores') ? 'active' : ''; ?>">
                        <i class="fas fa-store"></i>
                        <span>Stores</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </a>
                    <div class="dropdown-content">
                        <a href="<?php echo $baseUrl . 'modules/stores/list.php'; ?>">
                            <i class="fas fa-list"></i> Store List
                        </a>
                        <?php if (currentUserHasPermission('can_add_stores')): ?>
                            <a href="<?php echo $baseUrl . 'modules/stores/add.php'; ?>">
                                <i class="fas fa-plus"></i> Add Store
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo $baseUrl . 'modules/stores/map.php'; ?>">
                            <i class="fas fa-map"></i> Store Map
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (currentUserHasPermission('can_edit_inventory')): ?>
                <div class="nav-dropdown">
                    <a href="#" class="nav-item <?php echo ($activeSection === 'supply_chain') ? 'active' : ''; ?>">
                        <i class="fas fa-truck"></i>
                        <span>Supply Chain</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </a>
                    <div class="dropdown-content">
                        <a href="<?php echo $baseUrl . 'modules/suppliers/list.php'; ?>">
                            <i class="fas fa-building"></i> Suppliers
                        </a>
                        <a href="<?php echo $baseUrl . 'modules/purchase_orders/list.php'; ?>">
                            <i class="fas fa-file-invoice"></i> Purchase Orders
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (currentUserHasPermission('can_view_reports')): ?>
                <div class="nav-dropdown">
                    <a href="#" class="nav-item <?php echo ($activeSection === 'reports') ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i>
                        <span>Reports</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </a>
                    <div class="dropdown-content">
                        <a href="<?php echo $baseUrl . 'modules/reports/sales.php'; ?>">
                            <i class="fas fa-dollar-sign"></i> Sales Reports
                        </a>
                        <a href="<?php echo $baseUrl . 'modules/reports/inventory_report.php'; ?>">
                            <i class="fas fa-boxes"></i> Inventory Reports
                        </a>
                        <a href="<?php echo $baseUrl . 'modules/forecasting/index.php'; ?>">
                            <i class="fas fa-chart-area"></i> Demand Forecast
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (currentUserHasPermission('can_view_inventory') || currentUserHasPermission('can_view_reports')): ?>
                <div class="nav-dropdown">
                    <a href="#" class="nav-item <?php echo ($activeSection === 'alerts') ? 'active' : ''; ?>">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Alerts</span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </a>
                    <div class="dropdown-content">
                        <a href="<?php echo $baseUrl . 'modules/alerts/expiry_alert.php'; ?>">
                            <i class="fas fa-calendar-times"></i> Expiry Products
                        </a>
                        <a href="<?php echo $baseUrl . 'modules/alerts/alert_history.php'; ?>">
                            <i class="fas fa-exclamation-circle"></i> Alert History
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Section: User Actions -->
            <div class="navbar-user">
                <!-- Online/Offline Indicator -->
                <div id="connection-status" class="connection-status online">
                    <div class="status-dot"></div>
                    <span class="status-text">Online</span>
                </div>
                
                <div class="user-dropdown">
                    <a href="#" class="user-profile">
                        <div class="user-avatar">
                            <?php if (!empty($_SESSION['profile_picture'])): ?>
                                <img src="<?php echo $baseUrl . htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <i class="fas fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <span class="user-name"><?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'User'; ?></span>
                        <i class="fas fa-chevron-down dropdown-arrow"></i>
                    </a>
                    <div class="dropdown-content user-menu">
                        <a href="<?php echo $baseUrl . 'modules/users/profile.php'; ?>">
                            <i class="fas fa-user-circle"></i> Profile
                        </a>
                        <?php if (currentUserHasPermission('can_manage_users') || currentUserHasPermission('can_view_users')): ?>
                        <a href="<?php echo $baseUrl . 'modules/users/management.php'; ?>">
                            <i class="fas fa-users-cog"></i> User Management
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo $baseUrl . 'modules/users/logout.php'; ?>" class="logout-link">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
                
                <!-- Mobile menu toggle -->
                <button class="mobile-menu-toggle">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

<!-- Page content should be rendered by the including page immediately after this include. -->

<!-- Modern Header Styles -->

<script>
// Compact view toggle functionality
function toggleCompactView() {
    document.body.classList.toggle('compact-view');
    const button = document.querySelector('.btn-compact-toggle span');
    const isCompact = document.body.classList.contains('compact-view');
    if (button) {
        button.textContent = isCompact ? 'Expanded View' : 'Compact View';
    }
    
    // Save preference
    localStorage.setItem('dashboardCompactView', isCompact);
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Load saved compact view preference
    const isCompact = localStorage.getItem('dashboardCompactView') === 'true';
    if (isCompact) {
        document.body.classList.add('compact-view');
        const button = document.querySelector('.btn-compact-toggle span');
        if (button) button.textContent = 'Expanded View';
    }
    
    // Mobile menu toggle functionality
    const mobileToggle = document.querySelector('.mobile-menu-toggle');
    const navbarMenu = document.querySelector('.navbar-menu');
    
    if (mobileToggle && navbarMenu) {
        mobileToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            navbarMenu.classList.toggle('mobile-active');
            mobileToggle.classList.toggle('active');
        });
        
        // Close mobile menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!navbarMenu.contains(e.target) && !mobileToggle.contains(e.target)) {
                navbarMenu.classList.remove('mobile-active');
                mobileToggle.classList.remove('active');
            }
        });
    }
    
    // Enhanced dropdown behavior
    const dropdowns = document.querySelectorAll('.nav-dropdown, .user-dropdown');
    dropdowns.forEach(dropdown => {
        const trigger = dropdown.querySelector('.nav-item, .user-profile');
        const content = dropdown.querySelector('.dropdown-content');
        
        if (trigger && content) {
            // Add click functionality as backup for touch devices
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // Close other dropdowns
                dropdowns.forEach(otherDropdown => {
                    if (otherDropdown !== dropdown) {
                        const otherContent = otherDropdown.querySelector('.dropdown-content');
                        if (otherContent) {
                            otherContent.style.opacity = '0';
                            otherContent.style.visibility = 'hidden';
                            otherContent.style.transform = 'translateY(-10px)';
                            otherDropdown.classList.remove('active');
                        }
                    }
                });
                
                // Toggle current dropdown
                const isVisible = content.style.visibility === 'visible' || dropdown.classList.contains('active');
                if (isVisible) {
                    content.style.opacity = '0';
                    content.style.visibility = 'hidden';
                    content.style.transform = 'translateY(-10px)';
                    dropdown.classList.remove('active');
                } else {
                    content.style.opacity = '1';
                    content.style.visibility = 'visible';
                    content.style.transform = 'translateY(0)';
                    dropdown.classList.add('active');
                }
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target)) {
                    content.style.opacity = '0';
                    content.style.visibility = 'hidden';
                    content.style.transform = 'translateY(-10px)';
                    dropdown.classList.remove('active');
                }
            });
            
            // Ensure hover works on desktop with delay
            let hoverTimeout;
            
            dropdown.addEventListener('mouseenter', function() {
                if (window.innerWidth > 768) {
                    clearTimeout(hoverTimeout);
                    content.style.opacity = '1';
                    content.style.visibility = 'visible';
                    content.style.transform = 'translateY(0)';
                    content.style.pointerEvents = 'auto';
                }
            });
            
            dropdown.addEventListener('mouseleave', function() {
                if (window.innerWidth > 768) {
                    // Add delay before closing dropdown
                    hoverTimeout = setTimeout(() => {
                        content.style.opacity = '0';
                        content.style.visibility = 'hidden';
                        content.style.transform = 'translateY(-10px)';
                        content.style.pointerEvents = 'none';
                        dropdown.classList.remove('active');
                    }, 300); // 300ms delay
                }
            });
            
            // Keep dropdown open when hovering over the dropdown content itself
            content.addEventListener('mouseenter', function() {
                if (window.innerWidth > 768) {
                    clearTimeout(hoverTimeout);
                }
            });
            
            content.addEventListener('mouseleave', function() {
                if (window.innerWidth > 768) {
                    hoverTimeout = setTimeout(() => {
                        content.style.opacity = '0';
                        content.style.visibility = 'hidden';
                        content.style.transform = 'translateY(-10px)';
                        content.style.pointerEvents = 'none';
                        dropdown.classList.remove('active');
                    }, 150); // Shorter delay when leaving the dropdown content
                }
            });
        }
    });
    
    // Add smooth transitions for statistics cards
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
        card.classList.add('fade-in-up');
    });
    
    // Connection Status Monitor
    const connectionStatus = document.getElementById('connection-status');
    const statusDot = connectionStatus?.querySelector('.status-dot');
    const statusText = connectionStatus?.querySelector('.status-text');
    
    function updateConnectionStatus() {
        if (!connectionStatus) return;
        
        if (navigator.onLine) {
            connectionStatus.classList.remove('offline');
            connectionStatus.classList.add('online');
            if (statusText) statusText.textContent = 'Online';
        } else {
            connectionStatus.classList.remove('online');
            connectionStatus.classList.add('offline');
            if (statusText) statusText.textContent = 'Offline';
        }
    }
    
    // Initial check
    updateConnectionStatus();
    
    // Listen for online/offline events
    window.addEventListener('online', function() {
        updateConnectionStatus();
        console.log('Connection restored');
        
        // Show brief notification
        if (typeof showNotification === 'function') {
            showNotification('Connection restored', 'success');
        }
    });
    
    window.addEventListener('offline', function() {
        updateConnectionStatus();
        console.log('Connection lost');
        
        // Show brief notification
        if (typeof showNotification === 'function') {
            showNotification('Connection lost - Working offline', 'warning');
        }
    });
    
    // Periodic check (every 10 seconds) as backup
    setInterval(function() {
        updateConnectionStatus();
    }, 10000);
});

// Add CSS animation classes
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .fade-in-up {
        animation: fadeInUp 0.6s ease forwards;
    }
    
    .mobile-menu-toggle.active span:nth-child(1) {
        transform: rotate(45deg) translate(5px, 5px);
    }
    
    .mobile-menu-toggle.active span:nth-child(2) {
        opacity: 0;
    }
    
    .mobile-menu-toggle.active span:nth-child(3) {
        transform: rotate(-45deg) translate(7px, -6px);
    }
    
    @media (max-width: 768px) {
        .navbar-menu.mobile-active {
            display: flex;
            position: fixed;
            top: 70px;
            left: 0;
            right: 0;
            background: white;
            border-top: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            flex-direction: column;
            padding: 1rem 0;
            max-height: calc(100vh - 70px);
            overflow-y: auto;
            z-index: 999;
        }
        
        .navbar-menu.mobile-active .nav-item {
            width: 100%;
            justify-content: flex-start;
            padding: 12px 2rem;
            border-radius: 0;
        }
        
        .navbar-menu.mobile-active .nav-dropdown .dropdown-content {
            position: static;
            opacity: 1;
            visibility: visible;
            transform: none;
            box-shadow: none;
            border: none;
            background: #f7fafc;
            margin: 0;
            padding: 0;
        }
        
        .navbar-menu.mobile-active .dropdown-content a {
            padding: 8px 3rem;
            font-size: 0.85rem;
        }
    }
`;
document.head.appendChild(style);
</script>
