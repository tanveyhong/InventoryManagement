<?php
/**
 * User Activity Management Module - Optimized with Lazy Loading
 * No data loading on initial page load - uses AJAX for everything
 */

require_once '../../../config.php';
require_once '../../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../login.php');
    exit;
}

$currentUserId = $_SESSION['user_id'];
$currentUser = $_SESSION['role'] ?? 'user';
$isAdmin = $currentUser === 'admin';

$page_title = 'Activity Log - User Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Loading states */
        .loading-spinner {
            text-align: center;
            padding: 40px;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .activity-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
        }
        
        .activity-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .activity-type {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .activity-time {
            color: #7f8c8d;
            font-size: 0.9em;
        }
        
        .activity-description {
            color: #555;
            margin-bottom: 5px;
        }
        
        .activity-meta {
            font-size: 0.85em;
            color: #95a5a6;
        }
        
        .filters-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .filters-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 15px;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination button {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            border-radius: 4px;
        }
        
        .pagination button:hover:not(:disabled) {
            background: #f8f9fa;
        }
        
        .pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #3498db;
        }
        
        .stat-label {
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }
        
        .empty-state i {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <?php include '../../../includes/dashboard_header.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <h2><i class="fas fa-history"></i> Activity Log</h2>
                <div class="page-actions">
                    <button onclick="loadActivities()" class="btn btn-primary" id="refreshBtn">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                    <button onclick="clearActivities()" class="btn btn-danger">
                        <i class="fas fa-trash"></i> Clear All
                    </button>
                </div>
            </div>
            
            <!-- Stats Section -->
            <div class="stats-grid" id="statsSection" style="display: none;">
                <div class="stat-card">
                    <div class="stat-number" id="totalActivities">-</div>
                    <div class="stat-label">Total Activities</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="todayActivities">-</div>
                    <div class="stat-label">Today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="recentActivities">-</div>
                    <div class="stat-label">Last 7 Days</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <div class="filters-row">
                    <?php if ($isAdmin): ?>
                    <div class="filter-group">
                        <label for="filterUser">User:</label>
                        <select id="filterUser">
                            <option value="">All Users</option>
                            <option value="<?php echo $currentUserId; ?>">Me</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="filter-group">
                        <label for="filterType">Action Type:</label>
                        <select id="filterType">
                            <option value="">All Types</option>
                            <option value="login">Login</option>
                            <option value="logout">Logout</option>
                            <option value="profile_updated">Profile Updated</option>
                            <option value="permission_changed">Permission Changed</option>
                            <option value="store_access_changed">Store Access Changed</option>
                            <option value="activity_cleared">Activity Cleared</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label for="filterDateFrom">From:</label>
                        <input type="date" id="filterDateFrom">
                    </div>
                    
                    <div class="filter-group">
                        <label for="filterDateTo">To:</label>
                        <input type="date" id="filterDateTo">
                    </div>
                </div>
                
                <div style="text-align: right;">
                    <button onclick="applyFilters()" class="btn btn-primary">Apply Filters</button>
                    <button onclick="resetFilters()" class="btn btn-secondary">Reset</button>
                </div>
            </div>
            
            <!-- Activities List -->
            <div id="activitiesContainer">
                <div class="loading-spinner">
                    <div class="spinner"></div>
                    <p>Loading activities...</p>
                </div>
            </div>
            
            <!-- Pagination -->
            <div class="pagination" id="paginationContainer" style="display: none;"></div>
        </div>
    </div>
    
    <script>
        let currentPage = 1;
        let isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        let currentUserId = '<?php echo $currentUserId; ?>';
        
        // Load activities
        async function loadActivities(page = 1) {
            currentPage = page;
            
            const container = document.getElementById('activitiesContainer');
            container.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>Loading activities...</p></div>';
            
            try {
                const params = new URLSearchParams({
                    action: 'list',
                    page: page,
                    per_page: 20
                });
                
                // Add filters
                const userId = document.getElementById('filterUser')?.value;
                if (userId) params.append('user_id', userId);
                
                const type = document.getElementById('filterType')?.value;
                if (type) params.append('action_type', type);
                
                const dateFrom = document.getElementById('filterDateFrom')?.value;
                if (dateFrom) params.append('date_from', dateFrom);
                
                const dateTo = document.getElementById('filterDateTo')?.value;
                if (dateTo) params.append('date_to', dateTo);
                
                const response = await fetch(`api/activities.php?${params}`);
                const result = await response.json();
                
                if (result.success) {
                    displayActivities(result.data, result.pagination);
                } else {
                    container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error: ${result.error || 'Failed to load activities'}</p></div>`;
                }
            } catch (error) {
                console.error('Error loading activities:', error);
                container.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading activities. Please try again.</p></div>';
            }
        }
        
        // Display activities
        function displayActivities(activities, pagination) {
            const container = document.getElementById('activitiesContainer');
            
            if (activities.length === 0) {
                container.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No activities found</p></div>';
                document.getElementById('paginationContainer').style.display = 'none';
                return;
            }
            
            let html = '';
            activities.forEach(activity => {
                const time = new Date(activity.created_at).toLocaleString();
                const type = activity.action_type || 'unknown';
                const description = activity.description || 'No description';
                const user = activity.user?.name || 'Unknown User';
                const ip = activity.ip_address || 'unknown';
                
                html += `
                    <div class="activity-card">
                        <div class="activity-header">
                            <span class="activity-type">${type}</span>
                            <span class="activity-time">${time}</span>
                        </div>
                        <div class="activity-description">${description}</div>
                        <div class="activity-meta">
                            ${isAdmin ? `<span><i class="fas fa-user"></i> ${user}</span> | ` : ''}
                            <span><i class="fas fa-map-marker-alt"></i> ${ip}</span>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
            
            // Update pagination
            if (pagination.total_pages > 1) {
                displayPagination(pagination);
            } else {
                document.getElementById('paginationContainer').style.display = 'none';
            }
        }
        
        // Display pagination
        function displayPagination(pagination) {
            const container = document.getElementById('paginationContainer');
            container.style.display = 'flex';
            
            let html = '';
            
            html += `<button onclick="loadActivities(${pagination.page - 1})" ${!pagination.has_prev ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i> Previous
            </button>`;
            
            html += `<span style="padding: 8px 16px;">Page ${pagination.page} of ${pagination.total_pages}</span>`;
            
            html += `<button onclick="loadActivities(${pagination.page + 1})" ${!pagination.has_next ? 'disabled' : ''}>
                Next <i class="fas fa-chevron-right"></i>
            </button>`;
            
            container.innerHTML = html;
        }
        
        // Load stats
        async function loadStats() {
            try {
                const userId = document.getElementById('filterUser')?.value || currentUserId;
                const response = await fetch(`api/activities.php?action=stats&user_id=${userId}`);
                const result = await response.json();
                
                if (result.success) {
                    document.getElementById('totalActivities').textContent = result.data.total;
                    document.getElementById('todayActivities').textContent = result.data.today_count;
                    document.getElementById('recentActivities').textContent = result.data.recent_count;
                    document.getElementById('statsSection').style.display = 'grid';
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }
        
        // Apply filters
        function applyFilters() {
            loadActivities(1);
        }
        
        // Reset filters
        function resetFilters() {
            if (document.getElementById('filterUser')) {
                document.getElementById('filterUser').value = '';
            }
            document.getElementById('filterType').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            loadActivities(1);
        }
        
        // Clear activities
        async function clearActivities() {
            if (!confirm('Are you sure you want to clear all activities? This cannot be undone.')) {
                return;
            }
            
            try {
                const userId = document.getElementById('filterUser')?.value || currentUserId;
                const formData = new FormData();
                formData.append('action', 'clear');
                formData.append('user_id', userId);
                
                const response = await fetch('api/activities.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert(`Cleared ${result.count} activities successfully`);
                    loadActivities(1);
                    loadStats();
                } else {
                    alert('Error: ' + (result.error || 'Failed to clear activities'));
                }
            } catch (error) {
                console.error('Error clearing activities:', error);
                alert('Error clearing activities. Please try again.');
            }
        }
        
        // Load on page load
        document.addEventListener('DOMContentLoaded', () => {
            loadActivities();
            loadStats();
        });
    </script>
</body>
</html>
