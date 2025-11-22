<?php
/**
 * User Management Dashboard
 * Comprehensive admin interface for managing users, activities, permissions, and store access
 */

require_once '../../config.php';
require_once '../../db.php';
require_once '../../sql_db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Check if user has manage_users permission (admin or user manager)
if (!currentUserHasPermission('can_manage_users') && !currentUserHasPermission('can_view_users')) {
    $_SESSION['error'] = 'You do not have permission to access User Management';
    header('Location: ../../index.php');
    exit;
}

$db = getDB(); // Firebase fallback
$sqlDb = SQLDatabase::getInstance(); // PostgreSQL - PRIMARY
$currentUserId = $_SESSION['user_id'];

// Try PostgreSQL first for current user
try {
    $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$currentUserId, $currentUserId]);
    if (!$currentUser) {
        $currentUser = $db->read('users', $currentUserId);
    }
} catch (Exception $e) {
    $currentUser = $db->read('users', $currentUserId);
}

$isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');

// Get selected user for viewing (default to showing all)
$selectedUserId = $_GET['user_id'] ?? 'all';

$pageTitle = 'User Management';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - Inventory System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .management-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.98);
            color: #2d3748;
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
        }

        .page-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: #2d3748;
        }

        .page-header p {
            margin: 0;
            opacity: 1;
            font-size: 16px;
            font-weight: 500;
            color: #718096;
        }

        .tabs-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: visible;
        }

        .tabs {
            display: flex;
            background: #f8f9fa;
            border-bottom: 2px solid #e5e7eb;
            overflow-x: auto;
        }

        .tab {
            padding: 18px 30px;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 15px;
            font-weight: 600;
            color: #6b7280;
            transition: all 0.3s;
            white-space: nowrap;
            border-bottom: 3px solid transparent;
        }

        .tab:hover {
            background: rgba(102, 126, 234, 0.1);
            color: #667eea;
        }

        .tab.active {
            color: #667eea;
            background: white;
            border-bottom-color: #667eea;
        }

        .tab i {
            margin-right: 8px;
        }

        .tab-content {
            display: none;
            padding: 30px;
            animation: fadeIn 0.3s;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .user-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s;
            cursor: pointer;
        }

        .user-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }

        .user-card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: 700;
        }

        .user-info {
            flex: 1;
        }

        .user-name {
            font-size: 18px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .user-role {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .role-admin {
            background: #ef4444;
            color: white;
        }

        .role-manager {
            background: #f59e0b;
            color: white;
        }

        .role-staff {
            background: #10b981;
            color: white;
        }
        
        .user-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .status-active {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-deleted {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .user-card.deleted {
            opacity: 0.7;
            border: 2px solid #fecaca;
        }

        .user-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: 13px;
            color: #6b7280;
        }

        .user-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .btn-sm {
            padding: 6px 10px;
            font-size: 11px;
            flex: 1;
            min-width: fit-content;
        }
        
        .btn-restore {
            background: #10b981;
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h3 {
            margin: 0 0 10px 0;
            color: #374151;
        }

        .loading {
            text-align: center;
            padding: 40px;
        }

        .loading i {
            font-size: 48px;
            color: #667eea;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(400px);
                opacity: 0;
            }
        }
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #e5e7eb;
            transition: .3s;
            border-radius: 26px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #10b981;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }
        
        input:disabled + .toggle-slider {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .enhanced-select {
            width: 100%;
            max-width: 400px;
            padding: 12px 16px;
            font-size: 16px;
            color: #2d3748;
            background-color: #fff;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23718096' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }

        .enhanced-select:hover {
            border-color: #cbd5e0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .enhanced-select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .enhanced-select option {
            padding: 12px;
            font-size: 16px;
        }
        
        /* Searchable Dropdown Styles */
        .searchable-select-wrapper {
            position: relative;
            width: 100%;
            max-width: 400px;
        }

        .searchable-select-input {
            width: 100%;
            padding: 12px 16px;
            font-size: 16px;
            color: #2d3748;
            background-color: #fff;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: text;
            transition: all 0.3s ease;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23718096' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        .searchable-select-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .searchable-select-options {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-height: 300px;
            overflow-y: auto;
            z-index: 9999;
            display: none;
            /* Custom Scrollbar */
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f7fafc;
        }

        /* Webkit Scrollbar Styling */
        .searchable-select-options::-webkit-scrollbar {
            width: 8px;
        }

        .searchable-select-options::-webkit-scrollbar-track {
            background: #f7fafc;
            border-radius: 0 10px 10px 0;
        }

        .searchable-select-options::-webkit-scrollbar-thumb {
            background-color: #cbd5e0;
            border-radius: 4px;
            border: 2px solid #f7fafc;
        }

        .searchable-select-options::-webkit-scrollbar-thumb:hover {
            background-color: #a0aec0;
        }

        .searchable-select-option {
            padding: 12px 16px;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 1px solid #f7fafc;
            color: #4a5568;
        }

        .searchable-select-option:last-child {
            border-bottom: none;
        }

        .searchable-select-option:hover {
            background-color: #f8fafc;
            color: #667eea;
            padding-left: 20px; /* Subtle slide effect */
        }

        .searchable-select-option.selected {
            background-color: #ebf4ff;
            color: #5a67d8;
            font-weight: 600;
        }

        /* Role-based styling for dropdown options */
        .option-role-admin {
            border-left: 4px solid #ef4444;
        }
        .option-role-manager {
            border-left: 4px solid #f59e0b;
        }
        .option-role-staff {
            border-left: 4px solid #10b981;
        }
        .option-role-user {
            border-left: 4px solid #6b7280;
        }
        
        .user-role.role-admin { background: #ef4444; color: white; border-radius: 4px; }
        .user-role.role-manager { background: #f59e0b; color: white; border-radius: 4px; }
        .user-role.role-staff { background: #10b981; color: white; border-radius: 4px; }
        .user-role.role-user { background: #6b7280; color: white; border-radius: 4px; }

        .searchable-select-no-results {
            padding: 16px;
            color: #a0aec0;
            text-align: center;
            font-style: italic;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include '../../includes/dashboard_header.php'; ?>

    <div class="management-container">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-users-cog"></i>
                User Management
            </h1>
            <p>Manage users, activities, permissions, and store access from one central dashboard</p>
        </div>

        <!-- Tabs Navigation -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab active" onclick="switchTab('users')">
                    <i class="fas fa-users"></i> Users
                </button>
                <button class="tab" onclick="switchTab('activities')">
                    <i class="fas fa-history"></i> Activity Logs
                </button>
                <button class="tab" onclick="switchTab('permissions')">
                    <i class="fas fa-shield-alt"></i> Permissions
                </button>
                <button class="tab" onclick="switchTab('store-access')">
                    <i class="fas fa-store"></i> Store Access
                </button>
            </div>

            <!-- Tab Contents -->
            
            <!-- Users Tab -->
            <div id="tab-users" class="tab-content active">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <h2 style="margin: 0 0 10px 0;">All Users</h2>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-sm" onclick="filterUsers('all')" id="filter-all" style="background: #667eea; color: white;">All</button>
                            <button class="btn btn-sm" onclick="filterUsers('active')" id="filter-active" style="background: #e5e7eb; color: #374151;">Active</button>
                            <button class="btn btn-sm" onclick="filterUsers('deleted')" id="filter-deleted" style="background: #e5e7eb; color: #374151;">Deleted</button>
                        </div>
                    </div>
                    <button class="btn btn-primary" onclick="showCreateUserModal()">
                        <i class="fas fa-user-plus"></i> Create User
                    </button>
                </div>
                
                <div id="users-loading" class="loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading users...</p>
                </div>
                
                <div id="users-content" style="display: none;"></div>
            </div>

            <!-- Activities Tab -->
            <div id="tab-activities" class="tab-content">
                <div id="activity-loading" class="loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading activities...</p>
                </div>
                <div id="activity-content" style="display: none;"></div>
            </div>

            <!-- Permissions Tab -->
            <div id="tab-permissions" class="tab-content">
                <div id="permissions-container">
                    <!-- Permissions Manager will be loaded here -->
                </div>
            </div>

            <!-- Store Access Tab -->
            <div id="tab-store-access" class="tab-content">
                <div id="store-access-container">
                    <!-- Store Access Manager will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        const isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;
        const currentUserId = '<?php echo $currentUserId; ?>';
        let allUsers = [];

        // Tab Switching
        function switchTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            event.target.closest('.tab').classList.add('active');

            // Update tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(`tab-${tabName}`).classList.add('active');

            // Load content if not loaded yet
            if (tabName === 'users' && allUsers.length === 0) {
                loadUsers();
            } else if (tabName === 'activities') {
                loadActivities();
            } else if (tabName === 'permissions') {
                loadPermissions();
            } else if (tabName === 'store-access') {
                loadStoreAccess();
            }
        }
        
        // Force refresh functions (clear cache)
        function refreshPermissions() {
            permissionsCache = null;
            permissionsLoaded = false;
            loadPermissions();
        }
        
        function refreshStoreAccess() {
            storeAccessCache = null;
            storeAccessLoaded = false;
            loadStoreAccess();
        }
        
        // Show cache indicator
        function showCacheIndicator(tabName) {
            const indicator = document.createElement('div');
            indicator.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: linear-gradient(135deg, #10b981, #059669);
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
                font-size: 14px;
                font-weight: 600;
                z-index: 9999;
                animation: slideInUp 0.3s ease-out;
            `;
            indicator.innerHTML = `
                <i class="fas fa-check-circle"></i> Loaded from cache (faster!)
            `;
            document.body.appendChild(indicator);
            
            // Add animation
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideInUp {
                    from { transform: translateY(100px); opacity: 0; }
                    to { transform: translateY(0); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
            
            // Remove after 2 seconds
            setTimeout(() => {
                indicator.style.animation = 'slideInUp 0.3s ease-out reverse';
                setTimeout(() => indicator.remove(), 300);
            }, 2000);
        }

        let currentFilter = 'all';
        
        // Load Users
        async function loadUsers() {
            try {
                const response = await fetch('profile/api.php?action=get_all_users&include_deleted=true');
                const data = await response.json();

                document.getElementById('users-loading').style.display = 'none';
                document.getElementById('users-content').style.display = 'block';

                if (data.success && data.data.length > 0) {
                    allUsers = data.data;
                    filterUsers(currentFilter);
                } else {
                    document.getElementById('users-content').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <h3>No Users Found</h3>
                            <p>Start by creating your first user</p>
                            <button class="btn btn-primary" onclick="showCreateUserModal()">
                                <i class="fas fa-user-plus"></i> Create User
                            </button>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading users:', error);
                document.getElementById('users-content').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Users</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }

        // Render Users
        function renderUsers(users) {
            const container = document.getElementById('users-content');
            
            const html = `
                <div class="user-grid">
                    ${users.map(user => `
                        <div class="user-card ${user.deleted_at ? 'deleted' : ''}" onclick="viewUser('${user.id}')">
                            <div class="user-card-header">
                                <div class="user-avatar">
                                    ${getInitials(user.first_name || user.username, user.last_name)}
                                </div>
                                <div class="user-info">
                                    <div class="user-name">
                                        ${escapeHtml(user.first_name || user.username)} ${escapeHtml(user.last_name || '')}
                                        <span class="user-status status-${user.deleted_at ? 'deleted' : 'active'}">
                                            ${user.deleted_at ? '🗑️ Deleted' : '✓ Active'}
                                        </span>
                                    </div>
                                    <span class="user-role role-${(user.role || 'staff').toLowerCase()}">${user.role || 'Staff'}</span>
                                </div>
                            </div>
                            <div class="user-meta">
                                <div><i class="fas fa-envelope"></i> ${escapeHtml(user.email || 'N/A')}</div>
                                <div><i class="fas fa-user-circle"></i> ${escapeHtml(user.username)}</div>
                            </div>
                            <div class="user-actions" onclick="event.stopPropagation();">
                                ${!user.deleted_at ? `
                                    <button class="btn btn-sm btn-primary" onclick="editUser('${user.id}')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn btn-sm btn-warning" onclick="managePermissions('${user.id}')">
                                        <i class="fas fa-shield-alt"></i> Permissions
                                    </button>
                                    <button class="btn btn-sm btn-success" onclick="manageStoreAccess('${user.id}')">
                                        <i class="fas fa-store"></i> Stores
                                    </button>
                                    <button class="btn btn-sm btn-danger" onclick="softDeleteUser('${user.id}', '${escapeHtml(user.username)}')">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </button>
                                ` : `
                                    <button class="btn btn-sm btn-restore" onclick="restoreUser('${user.id}', '${escapeHtml(user.username)}')">
                                        <i class="fas fa-undo"></i> Restore User
                                    </button>
                                `}
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
            
            container.innerHTML = html;
        }

        // Filter Users
        function filterUsers(filter) {
            currentFilter = filter;
            
            // Update button styles
            document.querySelectorAll('[id^="filter-"]').forEach(btn => {
                btn.style.background = '#e5e7eb';
                btn.style.color = '#374151';
            });
            document.getElementById(`filter-${filter}`).style.background = '#667eea';
            document.getElementById(`filter-${filter}`).style.color = 'white';
            
            // Filter users
            let filteredUsers = allUsers;
            if (filter === 'active') {
                filteredUsers = allUsers.filter(u => !u.deleted_at);
            } else if (filter === 'deleted') {
                filteredUsers = allUsers.filter(u => u.deleted_at);
            }
            
            renderUsers(filteredUsers);
        }
        
        // Restore User
        async function restoreUser(userId, username) {
            if (!confirm(`Restore user "${username}"?\n\nThis will reactivate the user account.`)) {
                return;
            }
            
            try {
                const response = await fetch('profile/api.php?action=restore_user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: userId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('User restored successfully!');
                    // Reload users
                    await loadUsers();
                } else {
                    alert('Failed to restore user: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error restoring user:', error);
                alert('Error restoring user: ' + error.message);
            }
        }
        
        // Soft Delete User
        async function softDeleteUser(userId, username) {
            if (!confirm(`Are you sure you want to delete user "${username}"?\n\nThis will soft-delete the user and they will no longer be able to access the system.`)) {
                return;
            }
            
            try {
                const response = await fetch('profile/api.php?action=soft_delete_user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: userId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('User deleted successfully!');
                    // Reload users to refresh the list
                    await loadUsers();
                } else {
                    alert('Failed to delete user: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error deleting user:', error);
                alert('Error deleting user: ' + error.message);
            }
        }

        // Helper Functions
        function getInitials(first, last) {
            const f = (first || '').charAt(0).toUpperCase();
            const l = (last || '').charAt(0).toUpperCase();
            return f + (l || '');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function showCreateUserModal() {
            const modal = document.createElement('div');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 10000; backdrop-filter: blur(4px);';
            modal.innerHTML = `
                <div style="background: white; border-radius: 16px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 24px; border-radius: 16px 16px 0 0; color: white;">
                        <h3 style="margin: 0; font-size: 22px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-user-plus"></i> Create New User
                        </h3>
                        <p style="margin: 8px 0 0 0; opacity: 0.9; font-size: 14px;">Add a new user to the system</p>
                    </div>
                    
                    <form id="create-user-form" style="padding: 24px;">
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                <i class="fas fa-user"></i> Username <span style="color: #ef4444;">*</span>
                            </label>
                            <input type="text" name="username" required style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="Enter username">
                        </div>
                        
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                <i class="fas fa-envelope"></i> Email <span style="color: #ef4444;">*</span>
                            </label>
                            <input type="email" name="email" required style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="user@example.com">
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                    <i class="fas fa-id-badge"></i> First Name
                                </label>
                                <input type="text" name="first_name" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="First name">
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                    Last Name
                                </label>
                                <input type="text" name="last_name" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="Last name">
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                <i class="fas fa-lock"></i> Password <span style="color: #ef4444;">*</span>
                            </label>
                            <div style="position: relative;">
                                <input type="password" name="password" id="create-password" required style="width: 100%; padding: 10px 40px 10px 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="Enter password">
                                <button type="button" onclick="togglePasswordField('create-password', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: none; cursor: pointer; color: #667eea; padding: 5px;" title="Show/Hide Password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <small style="display: block; margin-top: 4px; color: #6b7280;">Minimum 6 characters</small>
                        </div>
                        
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                <i class="fas fa-lock"></i> Confirm Password <span style="color: #ef4444;">*</span>
                            </label>
                            <div style="position: relative;">
                                <input type="password" name="password_confirm" id="create-password-confirm" required style="width: 100%; padding: 10px 40px 10px 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="Confirm password">
                                <button type="button" onclick="togglePasswordField('create-password-confirm', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: none; cursor: pointer; color: #667eea; padding: 5px;" title="Show/Hide Password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div style="display: flex; gap: 12px; margin-top: 24px;">
                            <button type="button" onclick="this.closest('div').parentElement.parentElement.parentElement.remove()" class="btn btn-secondary" style="flex: 1;">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="submit" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-check"></i> Create User
                            </button>
                        </div>
                    </form>
                </div>
            `;
            document.body.appendChild(modal);
            
            // Handle form submission
            document.getElementById('create-user-form').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                const data = Object.fromEntries(formData);
                
                // Validate passwords match
                if (data.password !== data.password_confirm) {
                    alert('Passwords do not match!');
                    return;
                }
                
                // Validate password length
                if (data.password.length < 6) {
                    alert('Password must be at least 6 characters long!');
                    return;
                }
                
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
                
                try {
                    const response = await fetch('../users/register.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        alert('User created successfully!');
                        modal.remove();
                        loadUsers();
                    } else {
                        alert('Failed to create user: ' + (result.error || 'Unknown error'));
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-check"></i> Create User';
                    }
                } catch (error) {
                    console.error('Error:', error);
                    alert('Failed to create user: ' + error.message);
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-check"></i> Create User';
                }
            });
        }

        function togglePasswordField(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function viewUser(userId) {
            window.location.href = `profile.php?user_id=${userId}`;
        }

        async function editUser(userId) {
            // Load user data first
            try {
                let user = allUsers.find(u => u.id === userId);
                
                // If user not found in array, fetch from API
                if (!user) {
                    const response = await fetch(`profile/api.php?action=get_user&user_id=${userId}`);
                    const data = await response.json();
                    if (!data.success || !data.data) {
                        alert('User not found');
                        return;
                    }
                    user = data.data;
                }
                
                const modal = document.createElement('div');
                modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 10000; backdrop-filter: blur(4px);';
                modal.innerHTML = `
                    <div style="background: white; border-radius: 16px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                        <div style="background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%); padding: 24px; border-radius: 16px 16px 0 0; color: white;">
                            <h3 style="margin: 0; font-size: 22px; display: flex; align-items: center; gap: 10px;">
                                <i class="fas fa-user-edit"></i> Edit User
                            </h3>
                            <p style="margin: 8px 0 0 0; opacity: 0.9; font-size: 14px;">Update user information</p>
                        </div>
                        
                        <form id="edit-user-form" style="padding: 24px;">
                            <input type="hidden" name="user_id" value="${userId}">
                            
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                    <i class="fas fa-user"></i> Username <span style="color: #ef4444;">*</span>
                                </label>
                                <input type="text" name="username" required value="${escapeHtml(user.username)}" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                            </div>
                            
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                    <i class="fas fa-envelope"></i> Email <span style="color: #ef4444;">*</span>
                                </label>
                                <input type="email" name="email" required value="${escapeHtml(user.email || '')}" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                                <div>
                                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                        <i class="fas fa-id-badge"></i> First Name
                                    </label>
                                    <input type="text" name="first_name" value="${escapeHtml(user.first_name || '')}" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                        Last Name
                                    </label>
                                    <input type="text" name="last_name" value="${escapeHtml(user.last_name || '')}" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                                </div>
                            </div>
                            
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                    <i class="fas fa-user-tag"></i> Role <span style="color: #ef4444;">*</span>
                                </label>
                                <select name="role" required style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                                    <option value="Staff" ${user.role === 'Staff' ? 'selected' : ''}>Staff</option>
                                    <option value="Manager" ${user.role === 'Manager' ? 'selected' : ''}>Manager</option>
                                    <option value="Admin" ${user.role === 'Admin' ? 'selected' : ''}>Admin</option>
                                </select>
                            </div>
                            
                            <div style="background: #fef3c7; border: 2px solid #fbbf24; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
                                <div style="font-weight: 600; color: #92400e; margin-bottom: 4px;">
                                    <i class="fas fa-info-circle"></i> Change Password (Optional)
                                </div>
                                <small style="color: #92400e;">Leave blank to keep current password</small>
                            </div>
                            
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                    <i class="fas fa-lock"></i> New Password
                                </label>
                                <input type="password" name="password" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="Leave blank to keep current">
                            </div>
                            
                            <div style="margin-bottom: 16px;">
                                <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                                    <i class="fas fa-lock"></i> Confirm New Password
                                </label>
                                <input type="password" name="password_confirm" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="Leave blank to keep current">
                            </div>
                            
                            <div style="display: flex; gap: 12px; margin-top: 24px;">
                                <button type="button" onclick="this.closest('div').parentElement.parentElement.parentElement.remove()" class="btn btn-secondary" style="flex: 1;">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                                <button type="submit" class="btn btn-warning" style="flex: 1;">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                `;
                document.body.appendChild(modal);
                
                // Handle form submission
                document.getElementById('edit-user-form').addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const data = Object.fromEntries(formData);
                    
                    // Validate passwords match if changing password
                    if (data.password || data.password_confirm) {
                        if (data.password !== data.password_confirm) {
                            alert('Passwords do not match!');
                            return;
                        }
                        if (data.password.length < 6) {
                            alert('Password must be at least 6 characters long!');
                            return;
                        }
                    } else {
                        // Remove password fields if not changing
                        delete data.password;
                        delete data.password_confirm;
                    }
                    
                    const submitBtn = this.querySelector('button[type="submit"]');
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    
                    try {
                        const response = await fetch('profile/api.php?action=update_user', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(data)
                        });
                        
                        const result = await response.json();
                        
                        if (result.success) {
                            alert('User updated successfully!');
                            modal.remove();
                            loadUsers();
                        } else {
                            alert('Failed to update user: ' + (result.error || 'Unknown error'));
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
                        }
                    } catch (error) {
                        console.error('Error:', error);
                        alert('Failed to update user: ' + error.message);
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
                    }
                });
                
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to load user data');
            }
        }

        async function managePermissions(userId) {
            // Switch to permissions tab
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelector('[onclick*="permissions"]').classList.add('active');
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById('tab-permissions').classList.add('active');
            
            // Load permissions and select the user
            await loadPermissions();
            
            // Select the user in the dropdown if it exists
            setTimeout(() => {
                const userSelect = document.getElementById('perm-user-select');
                if (userSelect) {
                    userSelect.value = userId;
                    userSelect.dispatchEvent(new Event('change'));
                }
            }, 500);
        }

        async function manageStoreAccess(userId) {
            // Switch to store-access tab
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelector('[onclick*="store-access"]').classList.add('active');
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById('tab-store-access').classList.add('active');
            
            // Load store access and select the user
            await loadStoreAccess();
            
            // Select the user in the dropdown if it exists
            setTimeout(() => {
                const userSelect = document.getElementById('store-user-select');
                if (userSelect) {
                    userSelect.value = userId;
                    userSelect.dispatchEvent(new Event('change'));
                }
            }, 500);
        }

        // Activity Manager Variables
        let allActivitiesCache = [];
        let currentPage = 1;
        const itemsPerPage = 10;
        
        // Activity Manager: Load Activities
        async function loadActivities(useCache = true, forceRefresh = false) {
            const loading = document.getElementById('activity-loading');
            const content = document.getElementById('activity-content');
            
            loading.style.display = 'flex';
            content.style.display = 'none';
            
            // Reset pagination
            currentPage = 1;
            
            try {
                // Check localStorage cache first
                const cacheKey = 'activities_cache_' + currentUserId;
                const cached = localStorage.getItem(cacheKey);
                
                if (useCache && cached && !forceRefresh) {
                    const cacheData = JSON.parse(cached);
                    const cacheAge = Date.now() - cacheData.timestamp;
                    
                    // Use cache if less than 5 minutes old
                    if (cacheAge < 5 * 60 * 1000) {
                        allActivitiesCache = cacheData.activities;
                        loading.style.display = 'none';
                        content.style.display = 'block';
                        addActivityManagerToolbar();
                        renderActivities();
                        updateActivityStats();
                        return;
                    }
                }
                
                // Fetch from API
                const userFilter = isAdmin ? (document.getElementById('activity-user-filter')?.value || 'all') : currentUserId;
                const userParam = userFilter !== 'all' ? `&user_id=${userFilter}` : '';
                
                const response = await fetch(`profile/api.php?action=get_activities&limit=1000${userParam}`);
                const data = await response.json();
                
                if (data.success) {
                    allActivitiesCache = data.data || [];
                    
                    // Cache the data
                    localStorage.setItem(cacheKey, JSON.stringify({
                        activities: allActivitiesCache,
                        timestamp: Date.now()
                    }));
                    
                    loading.style.display = 'none';
                    content.style.display = 'block';
                    addActivityManagerToolbar();
                    renderActivities();
                    updateActivityStats();
                } else {
                    throw new Error(data.error || 'Failed to load activities');
                }
            } catch (error) {
                console.error('Error loading activities:', error);
                loading.style.display = 'none';
                content.style.display = 'block';
                content.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Activities</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        // Add Activity Manager Toolbar
        function addActivityManagerToolbar() {
            const container = document.getElementById('activity-content');
            if (!container || container.querySelector('.activity-manager-toolbar')) return;
            
            let userSelectHTML = '';
            if (isAdmin) {
                userSelectHTML = `
                    <select id="activity-user-filter" class="form-input" style="width: auto; padding: 8px 12px; min-width: 180px; background: white; color: #1f2937;">
                        <option value="all">All Users</option>
                    </select>
                `;
            }
            
            const toolbarHTML = `
                <div class="activity-manager-toolbar">
                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px; margin-bottom: 20px; color: white; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);">
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                            <i class="fas fa-chart-line" style="font-size: 28px;"></i>
                            <div>
                                <h3 style="margin: 0; font-size: 18px; font-weight: 600;">Activity Manager</h3>
                                <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 13px;">Monitor, filter, and analyze user activities</p>
                            </div>
                        </div>
                        
                        ${isAdmin ? `
                        <div style="background: rgba(255,255,255,0.15); padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                                <i class="fas fa-user-circle"></i> View Activities For:
                            </label>
                            ${userSelectHTML}
                        </div>
                        ` : ''}
                        
                        <!-- Date Range Filter -->
                        <div style="background: rgba(255,255,255,0.15); padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                                <i class="fas fa-calendar-alt"></i> Date Range:
                            </label>
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <input type="date" id="activity-date-from" class="form-input" style="flex: 1; min-width: 150px; padding: 8px 12px; background: white; color: #1f2937;">
                                <span style="display: flex; align-items: center; font-weight: 600;">to</span>
                                <input type="date" id="activity-date-to" class="form-input" style="flex: 1; min-width: 150px; padding: 8px 12px; background: white; color: #1f2937;">
                                <button onclick="applyDateFilter()" class="btn" style="background: rgba(255,255,255,0.25); color: white; padding: 8px 16px; border: 1px solid rgba(255,255,255,0.3);">
                                    <i class="fas fa-filter"></i> Apply
                                </button>
                                <button onclick="clearDateFilter()" class="btn" style="background: rgba(255,255,255,0.15); color: white; padding: 8px 16px;">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div id="activity-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 15px;">
                            <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 24px; font-weight: 700;" id="stat-total-activities">-</div>
                                <div style="font-size: 12px; opacity: 0.9;">Total Activities</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 24px; font-weight: 700;" id="stat-today-activities">-</div>
                                <div style="font-size: 12px; opacity: 0.9;">Today</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 24px; font-weight: 700;" id="stat-this-week">-</div>
                                <div style="font-size: 12px; opacity: 0.9;">This Week</div>
                            </div>
                            <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px; text-align: center;">
                                <div style="font-size: 24px; font-weight: 700;" id="stat-unique-types">-</div>
                                <div style="font-size: 12px; opacity: 0.9;">Activity Types</div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button onclick="exportActivities('csv')" class="btn" style="background: #10b981; color: white; padding: 8px 16px; font-size: 14px;">
                                    <i class="fas fa-file-csv"></i> Export CSV
                                </button>
                                <button onclick="exportActivities('json')" class="btn" style="background: #3b82f6; color: white; padding: 8px 16px; font-size: 14px;">
                                    <i class="fas fa-file-code"></i> Export JSON
                                </button>
                                <button onclick="exportActivities('pdf')" class="btn" style="background: #f59e0b; color: white; padding: 8px 16px; font-size: 14px;">
                                    <i class="fas fa-file-pdf"></i> Export PDF
                                </button>
                                <button onclick="showActivityAnalytics()" class="btn" style="background: #8b5cf6; color: white; padding: 8px 16px; font-size: 14px;">
                                    <i class="fas fa-chart-pie"></i> Analytics
                                </button>
                            </div>
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <input type="text" id="activity-search" placeholder="🔍 Search activities..." class="form-input" style="width: 220px; padding: 8px 12px; font-size: 14px;">
                                <select id="activity-filter" class="form-input" style="width: auto; padding: 8px 12px;">
                                    <option value="">📋 All Types</option>
                                    <option value="login">🔐 Login</option>
                                    <option value="logout">🚪 Logout</option>
                                    <option value="create">➕ Create</option>
                                    <option value="update">✏️ Update</option>
                                    <option value="delete">🗑️ Delete</option>
                                    <option value="store">🏪 Store</option>
                                    <option value="product">📦 Product</option>
                                    <option value="user">👤 User</option>
                                </select>
                                <button onclick="loadActivities(false, true)" class="btn" style="background: #6b7280; color: white; padding: 8px 16px; font-size: 14px;" title="Refresh activities">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="activity-list"></div>
                </div>
            `;
            
            container.innerHTML = toolbarHTML;
            
            // Setup event listeners
            setTimeout(() => {
                const searchInput = document.getElementById('activity-search');
                
                if (searchInput) {
                    searchInput.addEventListener('input', () => {
                        currentPage = 1;
                        renderActivities();
                    });
                }
                
                // Initialize searchable dropdown for Type Filter
                setupSearchableDropdown('activity-filter', function(value) {
                    currentPage = 1;
                    renderActivities();
                });
                
                // Load users for admin dropdown
                if (isAdmin) {
                    loadUsersForFilter();
                }
            }, 100);
        }
        
        // Load users for activity filter dropdown
        async function loadUsersForFilter() {
            try {
                const response = await fetch('profile/api.php?action=get_all_users');
                const data = await response.json();
                
                if (data.success) {
                    const select = document.getElementById('activity-user-filter');
                    if (select) {
                        // Sort users by role then username
                        const roleOrder = { 'admin': 1, 'manager': 2, 'staff': 3, 'user': 4 };
                        
                        data.data.sort((a, b) => {
                            const roleA = roleOrder[a.role.toLowerCase()] || 99;
                            const roleB = roleOrder[b.role.toLowerCase()] || 99;
                            
                            if (roleA !== roleB) return roleA - roleB;
                            return a.username.localeCompare(b.username);
                        });

                        // Clear existing options except the first one (All Users)
                        while (select.options.length > 1) {
                            select.remove(1);
                        }

                        data.data.forEach(user => {
                            const opt = document.createElement('option');
                            opt.value = user.id;
                            opt.textContent = `${user.username}`;
                            opt.className = `option-role-${user.role.toLowerCase()}`;
                            select.appendChild(opt);
                        });
                        
                        // Initialize searchable dropdown
                        setupSearchableDropdown('activity-user-filter', function(value) {
                            loadActivities(false, true);
                        });
                    }
                }
            } catch (error) {
                console.error('Error loading users for filter:', error);
            }
        }
        
        // Render Activities
        function renderActivities() {
            const container = document.getElementById('activity-list');
            if (!container) return;
            
            let filtered = [...allActivitiesCache];
            
            // Sort by date descending (newest first)
            filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            
            // Apply date filter
            if (window.activityDateFilter) {
                const { from, to } = window.activityDateFilter;
                filtered = filtered.filter(activity => {
                    const activityDate = new Date(activity.created_at).toISOString().split('T')[0];
                    if (from && activityDate < from) return false;
                    if (to && activityDate > to) return false;
                    return true;
                });
            }
            
            // Apply type filter
            const filterValue = document.getElementById('activity-filter')?.value;
            if (filterValue) {
                filtered = filtered.filter(activity => {
                    const type = (activity.action_type || activity.activity_type || '').toLowerCase();
                    return type.includes(filterValue.toLowerCase());
                });
            }
            
            // Apply search filter
            const searchTerm = document.getElementById('activity-search')?.value.toLowerCase();
            if (searchTerm) {
                filtered = filtered.filter(activity => {
                    const searchable = [
                        activity.action_type,
                        activity.activity_type,
                        activity.description,
                        activity.metadata ? JSON.stringify(activity.metadata) : ''
                    ].join(' ').toLowerCase();
                    return searchable.includes(searchTerm);
                });
            }
            
            // Show only visible count
            const totalItems = filtered.length;
            const totalPages = Math.ceil(totalItems / itemsPerPage);
            
            // Ensure current page is valid
            if (currentPage < 1) currentPage = 1;
            if (currentPage > totalPages && totalPages > 0) currentPage = totalPages;
            
            const startIndex = (currentPage - 1) * itemsPerPage;
            const endIndex = startIndex + itemsPerPage;
            const visible = filtered.slice(startIndex, endIndex);
            
            if (visible.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Activities Found</h3>
                        <p>No activities match your current filters</p>
                    </div>
                `;
                return;
            }
            
            // Group by date
            const grouped = {};
            visible.forEach(activity => {
                const date = new Date(activity.created_at);
                const today = new Date();
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                
                let dateLabel = date.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
                
                if (date.toDateString() === today.toDateString()) {
                    dateLabel = 'Today';
                } else if (date.toDateString() === yesterday.toDateString()) {
                    dateLabel = 'Yesterday';
                }
                
                if (!grouped[dateLabel]) grouped[dateLabel] = [];
                grouped[dateLabel].push(activity);
            });
            
            let html = '';
            for (const [dateLabel, activities] of Object.entries(grouped)) {
                html += `<div style="margin-top: 20px; margin-bottom: 10px; font-size: 13px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; padding-left: 5px; border-left: 3px solid #cbd5e0; line-height: 1;">${dateLabel}</div>`;
                
                html += activities.map(activity => {
                    const date = new Date(activity.created_at);
                    const timeStr = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
                    const timeAgo = getTimeAgo(date);
                    const icon = getActivityIcon(activity.action_type || activity.activity_type);
                    
                    // Determine color based on action type
                    let iconColor = '#6b7280';
                    let iconBg = '#f3f4f6';
                    const type = (activity.action_type || activity.activity_type || '').toLowerCase();
                    
                    if (type.includes('create') || type.includes('add')) { iconColor = '#10b981'; iconBg = '#d1fae5'; }
                    else if (type.includes('update') || type.includes('edit')) { iconColor = '#f59e0b'; iconBg = '#fef3c7'; }
                    else if (type.includes('delete') || type.includes('remove')) { iconColor = '#ef4444'; iconBg = '#fee2e2'; }
                    else if (type.includes('login')) { iconColor = '#3b82f6'; iconBg = '#dbeafe'; }
                    else if (type.includes('logout')) { iconColor = '#6b7280'; iconBg = '#f3f4f6'; }
                    
                    return `
                        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; margin-bottom: 12px; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <div style="display: flex; gap: 16px; align-items: flex-start;">
                                <div style="width: 40px; height: 40px; border-radius: 10px; background: ${iconBg}; color: ${iconColor}; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                                    ${icon}
                                </div>
                                <div style="flex: 1;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 4px;">
                                        <div style="font-weight: 600; color: #1f2937; font-size: 15px;">
                                            ${escapeHtml(activity.action_type || activity.activity_type || 'Activity')}
                                        </div>
                                        <div style="font-size: 12px; color: #9ca3af; white-space: nowrap; margin-left: 10px;" title="${date.toLocaleString()}">
                                            ${timeStr}
                                        </div>
                                    </div>
                                    <div style="color: #4b5563; font-size: 14px; line-height: 1.5; margin-bottom: 8px;">
                                        ${escapeHtml(activity.description || 'No description')}
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 12px; color: #6b7280;">
                                        <span style="background: #f3f4f6; padding: 2px 8px; border-radius: 4px;">
                                            <i class="fas fa-user" style="font-size: 10px; margin-right: 4px;"></i> 
                                            ${activity.user_name ? escapeHtml(activity.user_name) : 'User'}
                                        </span>
                                        <span><i class="fas fa-clock" style="margin-right: 4px;"></i> ${timeAgo}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                }).join('');
            }
            
            container.innerHTML = html;
            
            // Render Pagination Controls
            if (totalPages > 1) {
                renderPaginationControls(container, totalPages);
            }
        }

        function renderPaginationControls(container, totalPages) {
            let paginationHtml = '<div class="pagination-controls" style="display: flex; justify-content: center; gap: 5px; margin-top: 20px; margin-bottom: 40px;">';
            
            // Previous Button
            paginationHtml += `
                <button onclick="changePage(${currentPage - 1})" class="btn" style="background: ${currentPage === 1 ? '#f3f4f6' : 'white'}; color: ${currentPage === 1 ? '#9ca3af' : '#374151'}; border: 1px solid #d1d5db; padding: 8px 12px;" ${currentPage === 1 ? 'disabled' : ''}>
                    <i class="fas fa-chevron-left"></i>
                </button>
            `;

            // Page Numbers
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }

            if (startPage > 1) {
                paginationHtml += `
                    <button onclick="changePage(1)" class="btn" style="background: white; color: #374151; border: 1px solid #d1d5db; padding: 8px 12px;">1</button>
                `;
                if (startPage > 2) {
                    paginationHtml += `<span style="padding: 8px; color: #6b7280;">...</span>`;
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                const isActive = i === currentPage;
                paginationHtml += `
                    <button onclick="changePage(${i})" class="btn" style="background: ${isActive ? '#667eea' : 'white'}; color: ${isActive ? 'white' : '#374151'}; border: 1px solid ${isActive ? '#667eea' : '#d1d5db'}; padding: 8px 12px;">
                        ${i}
                    </button>
                `;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    paginationHtml += `<span style="padding: 8px; color: #6b7280;">...</span>`;
                }
                paginationHtml += `
                    <button onclick="changePage(${totalPages})" class="btn" style="background: white; color: #374151; border: 1px solid #d1d5db; padding: 8px 12px;">${totalPages}</button>
                `;
            }

            // Next Button
            paginationHtml += `
                <button onclick="changePage(${currentPage + 1})" class="btn" style="background: ${currentPage === totalPages ? '#f3f4f6' : 'white'}; color: ${currentPage === totalPages ? '#9ca3af' : '#374151'}; border: 1px solid #d1d5db; padding: 8px 12px;" ${currentPage === totalPages ? 'disabled' : ''}>
                    <i class="fas fa-chevron-right"></i>
                </button>
            `;

            paginationHtml += '</div>';
            container.innerHTML += paginationHtml;
        }

        function changePage(page) {
            currentPage = page;
            renderActivities();
            // Scroll to top of activity list
            const activityList = document.getElementById('activity-list');
            if (activityList) {
                const yOffset = -100; // Offset for sticky header if any
                const y = activityList.getBoundingClientRect().top + window.pageYOffset + yOffset;
                window.scrollTo({top: y, behavior: 'smooth'});
            }
        }
        
        function loadMoreActivities() {
            // Deprecated
        }
        
        function getActivityIcon(type) {
            const icons = {
                'login': '🔐',
                'logout': '🚪',
                'create': '➕',
                'update': '✏️',
                'delete': '🗑️',
                'store': '🏪',
                'product': '📦',
                'user': '👤'
            };
            
            for (const [key, icon] of Object.entries(icons)) {
                if (type?.toLowerCase().includes(key)) return icon;
            }
            
            return '📋';
        }
        
        function getTimeAgo(date) {
            const seconds = Math.floor((new Date() - date) / 1000);
            
            if (seconds < 60) return 'Just now';
            if (seconds < 3600) return Math.floor(seconds / 60) + ' minutes ago';
            if (seconds < 86400) return Math.floor(seconds / 3600) + ' hours ago';
            if (seconds < 604800) return Math.floor(seconds / 86400) + ' days ago';
            
            return date.toLocaleDateString();
        }
        
        // Update Statistics
        function updateActivityStats() {
            if (!allActivitiesCache || allActivitiesCache.length === 0) return;
            
            const now = new Date();
            const today = now.toDateString();
            const oneWeekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
            
            let todayCount = 0;
            let weekCount = 0;
            const uniqueTypes = new Set();
            
            allActivitiesCache.forEach(activity => {
                const activityDate = new Date(activity.created_at);
                uniqueTypes.add(activity.action_type || activity.activity_type);
                
                if (activityDate.toDateString() === today) todayCount++;
                if (activityDate >= oneWeekAgo) weekCount++;
            });
            
            document.getElementById('stat-total-activities').textContent = allActivitiesCache.length.toLocaleString();
            document.getElementById('stat-today-activities').textContent = todayCount.toLocaleString();
            document.getElementById('stat-this-week').textContent = weekCount.toLocaleString();
            document.getElementById('stat-unique-types').textContent = uniqueTypes.size.toLocaleString();
        }
        
        // Date Filter Functions
        function applyDateFilter() {
            const dateFrom = document.getElementById('activity-date-from').value;
            const dateTo = document.getElementById('activity-date-to').value;
            
            if (!dateFrom && !dateTo) {
                alert('Please select at least one date');
                return;
            }
            
            window.activityDateFilter = { from: dateFrom, to: dateTo };
            currentPage = 1;
            renderActivities();
        }
        
        function clearDateFilter() {
            document.getElementById('activity-date-from').value = '';
            document.getElementById('activity-date-to').value = '';
            window.activityDateFilter = null;
            currentPage = 1;
            renderActivities();
        }
        
        // Export Activities
        async function exportActivities(format) {
            try {
                const userFilter = isAdmin ? (document.getElementById('activity-user-filter')?.value || 'all') : '';
                const userParam = userFilter && userFilter !== 'all' ? `&user_id=${userFilter}` : '';
                
                if (format === 'pdf') {
                    window.open(`profile/api.php?action=export_activities&format=pdf${userParam}`, '_blank');
                } else {
                    const response = await fetch(`profile/api.php?action=export_activities&format=${format}${userParam}`);
                    const blob = await response.blob();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `activities_${new Date().toISOString().split('T')[0]}.${format}`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                }
            } catch (error) {
                console.error('Export failed:', error);
                alert('Failed to export activities');
            }
        }
        
        // Show Analytics
        function showActivityAnalytics() {
            if (!allActivitiesCache || allActivitiesCache.length === 0) {
                alert('No activity data to analyze');
                return;
            }
            
            // Calculate analytics
            const typeCount = {};
            const hourCount = {};
            const dayCount = {};
            
            allActivitiesCache.forEach(activity => {
                const type = activity.action_type || activity.activity_type || 'unknown';
                typeCount[type] = (typeCount[type] || 0) + 1;
                
                const date = new Date(activity.created_at);
                const hour = date.getHours();
                const day = date.toDateString();
                
                hourCount[hour] = (hourCount[hour] || 0) + 1;
                dayCount[day] = (dayCount[day] || 0) + 1;
            });
            
            const topTypes = Object.entries(typeCount).sort((a, b) => b[1] - a[1]).slice(0, 10);
            const topHours = Object.entries(hourCount).sort((a, b) => b[1] - a[1]).slice(0, 5);
            const topDays = Object.entries(dayCount).sort((a, b) => b[1] - a[1]).slice(0, 7);
            
            const modal = document.createElement('div');
            modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); display: flex; align-items: center; justify-content: center; z-index: 10000; backdrop-filter: blur(4px);';
            modal.innerHTML = `
                <div style="background: white; border-radius: 16px; padding: 30px; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.4); width: 90%;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                        <div>
                            <h2 style="margin: 0; color: #1f2937; font-size: 24px;">
                                <i class="fas fa-chart-pie" style="color: #8b5cf6;"></i> Activity Analytics
                            </h2>
                            <p style="margin: 5px 0 0 0; color: #6b7280;">Insights from ${allActivitiesCache.length.toLocaleString()} activities</p>
                        </div>
                        <button onclick="this.closest('div').parentElement.remove()" style="background: #ef4444; color: white; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white;">
                            <h3 style="margin: 0 0 15px 0; font-size: 16px;"><i class="fas fa-fire"></i> Top Activity Types</h3>
                            ${topTypes.map(([type, count]) => `
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; padding: 8px; background: rgba(255,255,255,0.15); border-radius: 6px;">
                                    <span>${escapeHtml(type)}</span>
                                    <strong>${count}</strong>
                                </div>
                            `).join('')}
                        </div>
                        
                        <div style="background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%); border-radius: 12px; padding: 20px; color: white;">
                            <h3 style="margin: 0 0 15px 0; font-size: 16px;"><i class="fas fa-clock"></i> Peak Activity Hours</h3>
                            ${topHours.map(([hour, count]) => `
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; padding: 8px; background: rgba(255,255,255,0.15); border-radius: 6px;">
                                    <span>${hour}:00 - ${hour}:59</span>
                                    <strong>${count}</strong>
                                </div>
                            `).join('')}
                        </div>
                        
                        <div style="background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%); border-radius: 12px; padding: 20px; color: white;">
                            <h3 style="margin: 0 0 15px 0; font-size: 16px;"><i class="fas fa-calendar-check"></i> Most Active Days</h3>
                            ${topDays.map(([day, count]) => `
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px; padding: 8px; background: rgba(255,255,255,0.15); border-radius: 6px;">
                                    <span style="font-size: 12px;">${day}</span>
                                    <strong>${count}</strong>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        // Permissions Manager
        let permissionsLoaded = false;
        let permissionsCache = null;
        
        async function loadPermissions() {
            // Check if already loaded
            if (permissionsLoaded && permissionsCache) {
                document.getElementById('permissions-container').innerHTML = permissionsCache;
                reattachPermissionListeners();
                
                // Show cache indicator
                showCacheIndicator('permissions');
                return;
            }
            
            const container = document.getElementById('permissions-container');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><p>Loading permissions...</p></div>';
            
            try {
                // For admin, show all users and their permissions
                if (isAdmin) {
                    await loadAllUsersPermissions();
                } else {
                    // For non-admin, just show their own permissions
                    await loadOwnPermissions();
                }
                
                // Cache the content
                permissionsCache = document.getElementById('permissions-container').innerHTML;
                permissionsLoaded = true;
            } catch (error) {
                console.error('Error loading permissions:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Permissions</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        function reattachPermissionListeners() {
            const select = document.getElementById('perm-user-select');
            if (select) {
                select.addEventListener('change', function() {
                    if (this.value) {
                        loadUserPermissions(this.value);
                    } else {
                        document.getElementById('user-permissions-display').innerHTML = '';
                    }
                });
            }
        }
        
        async function loadAllUsersPermissions() {
            const container = document.getElementById('permissions-container');
            
            try {
                const response = await fetch('profile/api.php?action=get_all_users');
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to load users');
                
                const users = data.data;
                
                // Sort users by role then username
                const roleOrder = { 'admin': 1, 'manager': 2, 'staff': 3, 'user': 4 };
                users.sort((a, b) => {
                    const roleA = roleOrder[a.role.toLowerCase()] || 99;
                    const roleB = roleOrder[b.role.toLowerCase()] || 99;
                    
                    if (roleA !== roleB) return roleA - roleB;
                    return a.username.localeCompare(b.username);
                });
                
                let html = `
                    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 20px; border-radius: 12px; margin-bottom: 25px; color: white;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-shield-alt" style="font-size: 28px;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 8px 0; font-size: 24px;">Permission Management</h3>
                                <p style="margin: 0; opacity: 0.95; font-size: 14px;">Manage user roles and permissions across the system</p>
                            </div>
                            <button onclick="refreshPermissions()" class="btn" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 10px 16px;" title="Refresh permissions">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">
                            <i class="fas fa-user-circle"></i> Select User:
                        </label>
                        <select id="perm-user-select" class="enhanced-select">
                            <option value="">-- Select a user --</option>
                            ${users.map(u => `<option value="${u.id}" class="option-role-${u.role.toLowerCase()}">${escapeHtml(u.username)}</option>`).join('')}
                        </select>
                    </div>
                    
                    <div id="user-permissions-display"></div>
                `;
                
                container.innerHTML = html;
                
                // Setup searchable dropdown
                setupSearchableDropdown('perm-user-select', function(value) {
                    if (value) {
                        window._lastSelectedUserId = value;
                        loadUserPermissions(value);
                    } else {
                        window._lastSelectedUserId = '';
                        document.getElementById('user-permissions-display').innerHTML = '';
                    }
                });

                // Add event listener
                const permSelect = document.getElementById('perm-user-select');
                if (permSelect) {
                    permSelect.addEventListener('change', function() {
                        console.log('[User Dropdown] Changed:', this.value);
                        if (this.value) {
                            // Save selected value to a variable to persist selection
                            window._lastSelectedUserId = this.value;
                            loadUserPermissions(this.value);
                        } else {
                            window._lastSelectedUserId = '';
                            document.getElementById('user-permissions-display').innerHTML = '';
                        }
                    });
                    // Restore last selected user if available
                    if (window._lastSelectedUserId) {
                        permSelect.value = window._lastSelectedUserId;
                        loadUserPermissions(window._lastSelectedUserId);
                    }
                }
                
            } catch (error) {
                console.error('Error:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Users</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        // Cache for user permissions by user ID
        const userPermissionsCache = {};
        
        async function loadUserPermissions(userId) {
            console.log('=== loadUserPermissions called ===');
            console.log('userId:', userId);
            
            const display = document.getElementById('user-permissions-display');
            
            // Check cache first
            if (userPermissionsCache[userId]) {
                console.log('✅ Using cached permissions for user:', userId);
                display.innerHTML = userPermissionsCache[userId];
                reattachPermissionListeners();
                return;
            }
            
            display.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading permissions...</div>';
            
            try {
                console.log('📤 Fetching permissions from API...');
                const response = await fetch(`profile/api.php?action=get_permissions&user_id=${userId}`);
                console.log('📥 Response status:', response.status);
                
                const data = await response.json();
                console.log('📋 Permissions data received:', data);
                
                if (!data.success) throw new Error(data.error || 'Failed to load permissions');
                
                const perms = data.data;
                console.log('🔑 User permissions:', perms);
                console.log('   Role:', perms.role);
                console.log('   can_view_reports:', perms.can_view_reports);
                console.log('   can_manage_inventory:', perms.can_manage_inventory);
                console.log('   can_manage_users:', perms.can_manage_users);
                console.log('   can_manage_stores:', perms.can_manage_stores);
                console.log('   can_configure_system:', perms.can_configure_system);
                
                const permissionsList = [
                    // Reports Module
                    { key: 'can_view_reports', name: 'View Reports', icon: 'chart-line', category: 'Reports', desc: 'View all system reports and analytics', color: '#8b5cf6' },
                    
                    // Inventory Module
                    { key: 'can_view_inventory', name: 'View Inventory', icon: 'eye', category: 'Inventory', desc: 'View product list and stock levels', color: '#10b981' },
                    { key: 'can_add_inventory', name: 'Add Inventory', icon: 'plus-circle', category: 'Inventory', desc: 'Add new products and stock', color: '#10b981' },
                    { key: 'can_edit_inventory', name: 'Edit Inventory', icon: 'edit', category: 'Inventory', desc: 'Update product details and adjust stock', color: '#10b981' },
                    { key: 'can_delete_inventory', name: 'Delete Inventory', icon: 'trash-alt', category: 'Inventory', desc: 'Remove products from system', color: '#10b981' },
                    
                    // Stores Module
                    { key: 'can_view_stores', name: 'View Stores', icon: 'eye', category: 'Stores', desc: 'View store list and details', color: '#f59e0b' },
                    { key: 'can_add_stores', name: 'Add Stores', icon: 'plus-circle', category: 'Stores', desc: 'Create new store locations', color: '#f59e0b' },
                    { key: 'can_edit_stores', name: 'Edit Stores', icon: 'edit', category: 'Stores', desc: 'Modify store information', color: '#f59e0b' },
                    { key: 'can_delete_stores', name: 'Delete Stores', icon: 'trash-alt', category: 'Stores', desc: 'Remove stores from system', color: '#f59e0b' },
                    
                    // POS Module
                    { key: 'can_use_pos', name: 'Use POS', icon: 'cash-register', category: 'POS', desc: 'Access point of sale terminal', color: '#06b6d4' },
                    { key: 'can_manage_pos', name: 'Manage POS', icon: 'cogs', category: 'POS', desc: 'Configure POS settings and integrations', color: '#06b6d4' },
                    
                    // User Management
                    { key: 'can_view_users', name: 'View Users', icon: 'eye', category: 'Users', desc: 'View user list and profiles', color: '#ec4899' },
                    { key: 'can_manage_users', name: 'Manage Users', icon: 'users-cog', category: 'Users', desc: 'Add, edit, delete users and permissions', color: '#ec4899' },
                    
                    // System
                    { key: 'can_configure_system', name: 'System Configuration', icon: 'cog', category: 'System', desc: 'Access system settings and configuration', color: '#6366f1' }
                ];
                
                const grantedCount = permissionsList.filter(p => perms[p.key]).length;
                
                // Define role templates with granular permissions
                const roleTemplates = {
                    'user': {
                        name: 'User',
                        color: '#3b82f6',
                        icon: 'user',
                        desc: 'Basic access - view only',
                        permissions: ['can_view_reports', 'can_view_inventory', 'can_view_stores']
                    },
                    'cashier': {
                        name: 'Cashier',
                        color: '#06b6d4',
                        icon: 'cash-register',
                        desc: 'POS and basic inventory',
                        permissions: ['can_view_reports', 'can_view_inventory', 'can_use_pos']
                    },
                    'manager': {
                        name: 'Manager',
                        color: '#f59e0b',
                        icon: 'user-tie',
                        desc: 'Store and inventory management',
                        permissions: ['can_view_reports', 'can_view_inventory', 'can_add_inventory', 'can_edit_inventory', 'can_view_stores', 'can_add_stores', 'can_edit_stores', 'can_use_pos', 'can_view_users']
                    },
                    'admin': {
                        name: 'Administrator',
                        color: '#ef4444',
                        icon: 'user-shield',
                        desc: 'Full system access',
                        permissions: permissionsList.map(p => p.key) // All permissions
                    }
                };
                
                let html = `
                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px; color: white; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                            <div>
                                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Current Role</div>
                                <div style="font-size: 28px; font-weight: 700;">${perms.role}</div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Permissions</div>
                                <div style="font-size: 28px; font-weight: 700;">${grantedCount}/${permissionsList.length}</div>
                            </div>
                        </div>
                    </div>
                    
                    ${isAdmin ? `
                    <div style="background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 25px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0; color: #1f2937; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-users-cog"></i> Quick Role Assignment
                        </h4>
                        <p style="margin: 0 0 20px 0; color: #6b7280; font-size: 14px;">
                            Select a predefined role to automatically assign its permission package
                        </p>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                            ${Object.entries(roleTemplates).map(([key, role]) => `
                                <div style="border: 2px solid ${perms.role.toLowerCase() === key ? role.color : '#e5e7eb'}; border-radius: 12px; padding: 20px; transition: all 0.3s; cursor: pointer; ${perms.role.toLowerCase() === key ? 'background: ' + role.color + '10;' : ''}" onclick="assignRole('${userId}', '${key}')">
                                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                                        <div style="width: 45px; height: 45px; background: ${role.color}; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px;">
                                            <i class="fas fa-${role.icon}"></i>
                                        </div>
                                        <div>
                                            <h5 style="margin: 0; color: #1f2937; font-size: 16px;">${role.name}</h5>
                                            ${perms.role.toLowerCase() === key ? '<small style="color: ' + role.color + '; font-weight: 600;"><i class="fas fa-check-circle"></i> Current</small>' : ''}
                                        </div>
                                    </div>
                                    <p style="margin: 0 0 12px 0; font-size: 13px; color: #6b7280; line-height: 1.5;">${role.desc}</p>
                                    <div style="font-size: 12px; color: #9ca3af;">
                                        <strong>${role.permissions.length}</strong> permission${role.permissions.length !== 1 ? 's' : ''}
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                        <div style="margin-top: 15px; padding: 12px; background: #f3f4f6; border-radius: 8px; font-size: 13px; color: #6b7280;">
                            <i class="fas fa-info-circle"></i> <strong>Note:</strong> Assigning a role will override current permissions with the role's permission package
                        </div>
                    </div>
                    ` : ''}
                    
                    <div style="margin-bottom: 15px;">
                        <h4 style="margin: 0; color: #1f2937; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-key"></i> Individual Permissions
                        </h4>
                        <p style="margin: 5px 0 0 0; color: #6b7280; font-size: 14px;">Grant or revoke specific permissions by module</p>
                    </div>
                    
                    ${// Group permissions by category
                    Object.entries(permissionsList.reduce((groups, perm) => {
                        if (!groups[perm.category]) groups[perm.category] = [];
                        groups[perm.category].push(perm);
                        return groups;
                    }, {})).map(([category, categoryPerms]) => `
                        <div style="background: white; border-radius: 12px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            <h5 style="margin: 0 0 15px 0; color: #1f2937; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; border-radius: 50%; background: ${categoryPerms[0].color};"></div>
                                ${category} Module
                            </h5>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px;">
                                ${categoryPerms.map(perm => {
                                    const granted = perms[perm.key] || false;
                                    return `
                                        <div data-permission="${perm.key}" style="background: ${granted ? perm.color + '10' : '#f9fafb'}; border: 2px solid ${granted ? perm.color : '#e5e7eb'}; border-radius: 8px; padding: 14px; transition: all 0.2s;">
                                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <div style="width: 36px; height: 36px; background: ${granted ? perm.color : '#e5e7eb'}; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 16px;">
                                                        <i class="fas fa-${perm.icon}"></i>
                                                    </div>
                                                    <div>
                                                        <div style="font-weight: 600; font-size: 13px; color: #1f2937;">${perm.name}</div>
                                                        <div style="font-size: 11px; color: ${granted ? perm.color : '#9ca3af'}; font-weight: 500;">
                                                            ${granted ? '✓ Enabled' : '○ Disabled'}
                                                        </div>
                                                    </div>
                                                </div>
                                                ${isAdmin ? `
                                                <label class="toggle-switch" title="${granted ? 'Click to revoke' : 'Click to grant'}">
                                                    <input type="checkbox" ${granted ? 'checked' : ''} 
                                                           onchange="togglePermissionFast('${userId}', '${perm.key}', this.checked)"
                                                           data-permission-toggle="${perm.key}">
                                                    <span class="toggle-slider"></span>
                                                </label>
                                                ` : `
                                                <label class="toggle-switch">
                                                    <input type="checkbox" ${granted ? 'checked' : ''} disabled>
                                                    <span class="toggle-slider"></span>
                                                </label>
                                                `}
                                            </div>
                                            <p style="margin: 0; font-size: 12px; color: #6b7280; line-height: 1.4;">${perm.desc}</p>
                                        </div>
                                    `;
                                }).join('')}
                            </div>
                        </div>
                    `).join('')}
                `;
                
                // Cache the rendered HTML
                userPermissionsCache[userId] = html;
                display.innerHTML = html;
                
            } catch (error) {
                console.error('Error:', error);
                display.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Permissions</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        async function loadOwnPermissions() {
            const container = document.getElementById('permissions-container');
            
            try {
                const response = await fetch('profile/api.php?action=get_permissions');
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to load permissions');
                
                const perms = data.data;
                
                const permissionsList = [
                    { key: 'can_view_reports', name: 'View Reports', icon: 'chart-bar', desc: 'Access and view system reports', details: 'View sales reports, inventory reports, and analytics dashboards' },
                    { key: 'can_manage_inventory', name: 'Manage Inventory', icon: 'boxes', desc: 'Add, edit, and delete inventory items', details: 'Create new products, update stock levels, adjust inventory, and manage product information' },
                    { key: 'can_manage_users', name: 'Manage Users', icon: 'users', desc: 'Create and manage user accounts', details: 'Add new users, modify user roles, view activity logs, and manage user permissions' },
                    { key: 'can_manage_stores', name: 'Manage Stores', icon: 'store', desc: 'Add and configure store locations', details: 'Create new stores, edit store details, manage store inventory, and configure POS integration' },
                    { key: 'can_configure_system', name: 'System Configuration', icon: 'cog', desc: 'Access system settings and configuration', details: 'Modify system settings, configure integrations, manage API keys, and access admin panel' }
                ];
                
                const grantedCount = permissionsList.filter(p => perms[p.key]).length;
                
                let html = `
                    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 20px; border-radius: 12px; margin-bottom: 25px; color: white;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-shield-alt" style="font-size: 28px;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 8px 0; font-size: 24px;">Your Role: ${perms.role}</h3>
                                <p style="margin: 0; opacity: 0.95; font-size: 14px;">Your permissions and access level are displayed below</p>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px; color: white;">
                            <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Assigned Stores</div>
                            <div style="font-size: 32px; font-weight: 700;">${grantedCount}/${permissionsList.length}</div>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                        ${permissionsList.map(perm => {
                            const granted = perms[perm.key] || false;
                            return `
                                <div style="background: white; border: 2px solid ${granted ? '#10b981' : '#e5e7eb'}; border-radius: 12px; padding: 20px;">
                                    <div style="display: flex; align-items: start; gap: 15px;">
                                        <div style="width: 50px; height: 50px; background: ${granted ? 'linear-gradient(135deg, #10b981, #059669)' : '#f3f4f6'}; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px;">
                                            <i class="fas fa-${perm.icon}"></i>
                                        </div>
                                        <div>
                                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                                <h4 style="margin: 0; color: #1f2937;">${perm.name}</h4>
                                                <span style="padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; ${granted ? 'background: #d4edda; color: #155724;' : 'background: #f8d7da; color: #721c24;'}">
                                                    ${granted ? '<i class="fas fa-check"></i> Granted' : '<i class="fas fa-times"></i> Denied'}
                                                </span>
                                            </div>
                                            <p style="margin: 0 0 8px 0; font-size: 14px; color: #6b7280; line-height: 1.5;">${perm.desc}</p>
                                            <small style="font-size: 12px; color: #9ca3af;">${perm.details}</small>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `;
                
                container.innerHTML = html;
                
            } catch (error) {
                console.error('Error:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Permissions</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        async function togglePermission(userId, permissionKey, grant) {
            if (!confirm(`Are you sure you want to ${grant ? 'grant' : 'revoke'} this permission?`)) return;
            
            try {
                const response = await fetch('profile/api.php?action=update_permission', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: userId,
                        permission: permissionKey,
                        value: grant
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Permission updated successfully');
                    // Clear cache to force reload
                    permissionsCache = null;
                    permissionsLoaded = false;
                    loadPermissions();
                } else {
                    alert('Failed to update permission: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to update permission');
            }
        }
        
        // Fast permission toggle with optimistic UI update
        async function togglePermissionFast(userId, permissionKey, grant) {
            console.log(`⚡ Fast toggle: ${permissionKey} = ${grant}`);
            
            // Get the toggle switch
            const toggle = document.querySelector(`[data-permission-toggle="${permissionKey}"]`);
            const permCard = document.querySelector(`[data-permission="${permissionKey}"]`);
            
            // Disable toggle during update
            if (toggle) {
                toggle.disabled = true;
            }
            if (permCard) {
                permCard.style.opacity = '0.7';
            }
            
            try {
                const response = await fetch('profile/api.php?action=update_permission', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: userId,
                        permission: permissionKey,
                        value: grant
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    console.log('✅ Permission updated successfully');
                    // Clear cache and reload
                    delete userPermissionsCache[userId];
                    await loadUserPermissions(userId);
                    
                    // Show quick toast
                    showToast(grant ? 'Permission granted ✓' : 'Permission revoked', 'success');
                } else {
                    throw new Error(data.error || 'Update failed');
                }
            } catch (error) {
                console.error('❌ Error:', error);
                showToast('Failed to update permission', 'error');
                // Restore toggle to previous state
                if (toggle) {
                    toggle.checked = !grant;
                    toggle.disabled = false;
                }
                if (permCard) {
                    permCard.style.opacity = '1';
                }
            }
        }
        
        // Quick toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : type === 'error' ? '#ef4444' : '#3b82f6'};
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10000;
                animation: slideIn 0.3s ease-out;
                font-size: 14px;
                font-weight: 500;
            `;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => toast.remove(), 300);
            }, 2000);
        }
        
        async function assignRole(userId, roleKey) {
            console.log('=== assignRole called ===');
            console.log('userId:', userId);
            console.log('roleKey:', roleKey);
            
            const roleNames = {
                'user': 'User',
                'manager': 'Manager',
                'admin': 'Administrator'
            };
            
            const roleName = roleNames[roleKey] || roleKey;
            
            if (!confirm(`Are you sure you want to assign the "${roleName}" role?\n\nThis will:\n• Change the user's role to ${roleName}\n• Update all permissions to match the role's package\n• Override any custom permissions`)) {
                console.log('❌ User cancelled role assignment');
                return;
            }
            
            try {
                console.log('📤 Sending role assignment request...');
                const response = await fetch('profile/api.php?action=assign_role', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: userId,
                        role: roleKey
                    })
                });
                
                console.log('📥 Response received, status:', response.status);
                const data = await response.json();
                console.log('📋 Response data:', data);
                
                if (data.success) {
                    console.log('✅ Role assigned successfully');
                    showToast(`${roleName} role assigned successfully!`, 'success');
                    
                    // Clear all caches
                    console.log('🗑️ Clearing all caches...');
                    permissionsCache = null;
                    permissionsLoaded = false;
                    delete userPermissionsCache[userId];
                    
                    console.log('🔄 Reloading permissions for user:', userId);
                    await loadUserPermissions(userId);
                    console.log('✅ Permissions reloaded');
                    
                    // Also reload users list if on users tab
                    if (document.getElementById('tab-users').style.display !== 'none') {
                        console.log('🔄 Reloading users list...');
                        loadUsers();
                    }
                } else {
                    console.error('❌ Role assignment failed:', data.error);
                    showToast('Failed to assign role: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                console.error('❌ Error during role assignment:', error);
                alert('Failed to assign role: ' + error.message);
            }
        }
        
        // Store Access Manager
        let storeAccessLoaded = false;
        let storeAccessCache = null;
        
        async function loadStoreAccess() {
            // Check if already loaded
            if (storeAccessLoaded && storeAccessCache) {
                document.getElementById('store-access-container').innerHTML = storeAccessCache;
                reattachStoreAccessListeners();
                
                // Show cache indicator
                showCacheIndicator('store-access');
                return;
            }
            
            const container = document.getElementById('store-access-container');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><p>Loading store access...</p></div>';
            
            try {
                // Show user selector for admin
                if (isAdmin) {
                    await loadAllUsersStoreAccess();
                } else {
                    await loadOwnStoreAccess();
                }
                
                // Cache the content
                storeAccessCache = document.getElementById('store-access-container').innerHTML;
                storeAccessLoaded = true;
            } catch (error) {
                console.error('Error loading store access:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Store Access</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        function reattachStoreAccessListeners() {
            const select = document.getElementById('store-user-select');
            if (select) {
                select.addEventListener('change', function() {
                    selectedUserId = this.value;
                    if (this.value) {
                        loadUserStores(this.value);
                    } else {
                        document.getElementById('user-stores-display').innerHTML = '';
                    }
                });
            }
        }        let selectedUserId = null;
        
        async function loadAllUsersStoreAccess() {
            const container = document.getElementById('store-access-container');
            
            try {
                // Exclude admins from the list since they have access to all stores by default
                const response = await fetch('profile/api.php?action=get_all_users&exclude_admins=true');
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to load users');
                
                const users = data.data;
                
                // Sort users by role then username
                const roleOrder = { 'admin': 1, 'manager': 2, 'staff': 3, 'user': 4 };
                users.sort((a, b) => {
                    const roleA = roleOrder[a.role.toLowerCase()] || 99;
                    const roleB = roleOrder[b.role.toLowerCase()] || 99;
                    
                    if (roleA !== roleB) return roleA - roleB;
                    return a.username.localeCompare(b.username);
                });
                
                let html = `
                    <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); padding: 20px; border-radius: 12px; margin-bottom: 25px; color: white;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-store" style="font-size: 28px;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 8px 0; font-size: 24px;">Store Access Management</h3>
                                <p style="margin: 0; opacity: 0.95; font-size: 14px;">Control which users can access each store location</p>
                            </div>
                            <button onclick="refreshStoreAccess()" class="btn" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 10px 16px;" title="Refresh store access">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    
                    <div style="background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                        <i class="fas fa-info-circle" style="color: #0ea5e9; font-size: 18px;"></i>
                        <div style="flex: 1; font-size: 14px; color: #374151;">
                            <strong>Note:</strong> Administrators and managers have access to all stores by default and are not listed here. Store access restrictions apply only to regular users and cashiers.
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">
                            <i class="fas fa-user-circle"></i> Select User:
                        </label>
                        <select id="store-user-select" class="enhanced-select">
                            <option value="">-- Select a user --</option>
                            ${users.map(u => `<option value="${u.id}" class="option-role-${u.role.toLowerCase()}">${escapeHtml(u.username)}</option>`).join('')}
                        </select>
                    </div>
                    
                    <div id="user-stores-display"></div>
                `;
                
                container.innerHTML = html;
                
                // Setup searchable dropdown
                setupSearchableDropdown('store-user-select', function(value) {
                    selectedUserId = value;
                    if (value) {
                        loadUserStores(value);
                    } else {
                        document.getElementById('user-stores-display').innerHTML = '';
                    }
                });

                // Add event listener
                const storeSelect = document.getElementById('store-user-select');
                if (storeSelect) {
                    storeSelect.addEventListener('change', function() {
                        selectedUserId = this.value;
                        if (this.value) {
                            loadUserStores(this.value);
                        } else {
                            document.getElementById('user-stores-display').innerHTML = '';
                        }
                    });
                }
                
            } catch (error) {
                console.error('Error:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Users</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        async function loadUserStores(userId) {
            console.log('=== loadUserStores called ===');
            console.log('userId:', userId);
            
            const display = document.getElementById('user-stores-display');
            display.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i></div>';
            
            try {
                const url = `profile/api.php?action=get_stores&user_id=${userId}`;
                console.log('Fetching stores from:', url);
                
                const response = await fetch(url);
                console.log('Response status:', response.status);
                console.log('Response OK:', response.ok);
                
                const data = await response.json();
                console.log('Response data:', data);
                
                if (!data.success) {
                    console.error('✗ Failed to load stores:', data.error);
                    throw new Error(data.error || 'Failed to load stores');
                }
                
                const stores = data.data || [];
                console.log('✓ Stores loaded successfully');
                console.log('Number of stores:', stores.length);
                console.log('Stores:', stores);
                
                let html = `
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <h4 style="margin: 0 0 5px 0; color: #2d3748;">Assigned Stores (${stores.length})</h4>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">Stores this user can access</p>
                        </div>
                        <button onclick="showAddStoreModal('${userId}')" class="btn btn-success">
                            <i class="fas fa-plus"></i> Assign Store
                        </button>
                    </div>
                `;
                
                if (stores.length > 0) {
                    console.log('Rendering store cards...');
                    html += `
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                            ${stores.map(store => `
                                <div style="background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; transition: all 0.3s;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                        <h4 style="margin: 0; color: #1f2937; font-size: 16px;">
                                            <i class="fas fa-store" style="color: #10b981; margin-right: 8px;"></i>
                                            ${escapeHtml(store.store_name || store.name)}
                                        </h4>
                                        ${store.active ? 
                                            '<span style="padding: 4px 10px; background: #d4edda; color: #155724; border-radius: 12px; font-size: 12px; font-weight: 600;"><i class="fas fa-check-circle"></i> Active</span>' : 
                                            '<span style="padding: 4px 10px; background: #f8d7da; color: #721c24; border-radius: 12px; font-size: 12px; font-weight: 600;"><i class="fas fa-times-circle"></i> Inactive</span>'
                                        }
                                    </div>
                                    <div style="font-size: 14px; color: #6b7280; margin-bottom: 12px;">
                                        <div style="margin-bottom: 5px;">
                                            <i class="fas fa-map-marker-alt"></i> ${escapeHtml(store.city || 'N/A')}, ${escapeHtml(store.state || 'N/A')}
                                        </div>
                                        ${store.phone ? `<div><i class="fas fa-phone"></i> ${escapeHtml(store.phone)}</div>` : ''}
                                    </div>
                                    <button onclick="removeStoreAccess('${userId}', '${store.id}')" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash"></i> Remove Access
                                    </button>
                                </div>
                            `).join('')}
                        </div>
                    `;
                } else {
                    console.log('No stores found, showing empty state');
                    html += `
                        <div class="empty-state">
                            <i class="fas fa-store-slash"></i>
                            <h3>No Store Access</h3>
                            <p>This user has no store access assigned</p>
                            <button onclick="showAddStoreModal('${userId}')" class="btn btn-success">
                                <i class="fas fa-plus"></i> Assign First Store
                            </button>
                        </div>
                    `;
                }
                
                console.log('Updating display HTML...');
                display.innerHTML = html;
                console.log('✓ Display updated successfully');
                
            } catch (error) {
                console.error('✗ Exception in loadUserStores:', error);
                console.error('Error stack:', error.stack);
                display.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Stores</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        async function loadOwnStoreAccess() {
            const container = document.getElementById('store-access-container');
            
            try {
                const response = await fetch('profile/api.php?action=get_stores');
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to load stores');
                
                const stores = data.data || [];
                
                let html = `
                    <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); padding: 20px; border-radius: 12px; margin-bottom: 25px; color: white;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-store" style="font-size: 28px;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 8px 0; font-size: 24px;">Your Store Access</h3>
                                <p style="margin: 0; opacity: 0.95; font-size: 14px;">Stores you have permission to access</p>
                            </div>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px; color: white;">
                            <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Assigned Stores</div>
                            <div style="font-size: 32px; font-weight: 700;">${stores.length}</div>
                        </div>
                    </div>
                `;
                
                if (stores.length > 0) {
                    html += `
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                            ${stores.map(store => `
                                <div style="background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                        <h4 style="margin: 0; color: #1f2937; font-size: 16px;">
                                            <i class="fas fa-store" style="color: #10b981; margin-right: 8px;"></i>
                                            ${escapeHtml(store.store_name || store.name)}
                                        </h4>
                                        ${store.active ? 
                                            '<span style="padding: 4px 10px; background: #d4edda; color: #155724; border-radius: 12px; font-size: 12px; font-weight: 600;"><i class="fas fa-check-circle"></i> Active</span>' : 
                                            '<span style="padding: 4px 10px; background: #f8d7da; color: #721c24; border-radius: 12px; font-size: 12px; font-weight: 600;"><i class="fas fa-times-circle"></i> Inactive</span>'
                                        }
                                    </div>
                                    <div style="font-size: 14px; color: #6b7280; margin-bottom: 12px;">
                                        <div style="margin-bottom: 5px;">
                                            <i class="fas fa-map-marker-alt"></i> ${escapeHtml(store.city || 'N/A')}, ${escapeHtml(store.state || 'N/A')}
                                        </div>
                                        ${store.phone ? `<div><i class="fas fa-phone"></i> ${escapeHtml(store.phone)}</div>` : ''}
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    `;
                } else {
                    html += `
                        <div class="empty-state">
                            <i class="fas fa-store-slash"></i>
                            <h3>No Store Access</h3>
                            <p>You have no store access assigned. Contact your administrator.</p>
                        </div>
                    `;
                }
                
                container.innerHTML = html;
                
            } catch (error) {
                console.error('Error:', error);
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <h3>Error Loading Stores</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            }
        }
        
        async function showAddStoreModal(userId) {
            // Show loading modal
            const loadingModal = document.createElement('div');
            loadingModal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 10000; backdrop-filter: blur(4px);';
            loadingModal.innerHTML = `
                <div style="background: white; padding: 40px; border-radius: 16px; text-align: center;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #10b981; margin-bottom: 16px;"></i>
                    <div style="font-size: 16px; color: #6b7280;">Loading stores...</div>
                </div>
            `;
            document.body.appendChild(loadingModal);
            
            try {
                const response = await fetch(`profile/api.php?action=get_available_stores&user_id=${userId}`);
                const data = await response.json();
                
                loadingModal.remove();
                
                if (!data.success) {
                    alert(data.error || 'Failed to load stores');
                    return;
                }
                
                const availableStores = data.data || [];
                
                if (availableStores.length === 0) {
                    alert('User already has access to all stores');
                    return;
                }
                
                // Create modal
                const modal = document.createElement('div');
                modal.className = 'store-modal-wrapper';
                modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 10000; backdrop-filter: blur(4px);';
                modal.innerHTML = `
                    <div style="background: white; border-radius: 16px; max-width: 600px; width: 90%; max-height: 85vh; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                        <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div>
                                    <h3 style="margin: 0; font-size: 20px; color: #111827;">
                                        <i class="fas fa-store" style="color: #10b981; margin-right: 8px;"></i>
                                        Assign Store Access
                                    </h3>
                                    <p style="color: #6b7280; margin: 8px 0 0 0; font-size: 14px;">Select stores to grant access</p>
                                </div>
                                <button onclick="closeStoreModal()" style="background: #ef4444; color: white; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div style="padding: 20px; max-height: 50vh; overflow-y: auto;">
                            <div id="store-count" style="margin-bottom: 12px; font-size: 13px; color: #6b7280;">
                                <span id="selected-count">0</span> of ${availableStores.length} stores selected
                            </div>
                            <div id="store-list">
                                ${availableStores.map(store => `
                                    <label style="display: flex; align-items: center; padding: 12px; background: #f9fafb; border: 2px solid #e5e7eb; border-radius: 8px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s;" onchange="updateSelectedCount()">
                                        <input type="checkbox" value="${store.id}" style="width: 18px; height: 18px; margin-right: 12px;" class="store-checkbox">
                                        <div style="flex: 1;">
                                            <div style="font-weight: 600; color: #1f2937;">${escapeHtml(store.name)}</div>
                                            <div style="font-size: 13px; color: #6b7280;">${escapeHtml(store.city || 'N/A')}, ${escapeHtml(store.state || 'N/A')}</div>
                                        </div>
                                    </label>
                                `).join('')}
                            </div>
                        </div>
                        
                        <div style="padding: 20px; border-top: 1px solid #e5e7eb; background: #f9fafb; display: flex; gap: 12px; justify-content: flex-end;">
                            <button onclick="closeStoreModal()" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button onclick="assignSelectedStores('${userId}', this)" class="btn btn-success">
                                <i class="fas fa-plus"></i> Assign Selected
                            </button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
                
                // Add count update function
                window.updateSelectedCount = function() {
                    const selected = document.querySelectorAll('.store-checkbox:checked').length;
                    document.getElementById('selected-count').textContent = selected;
                };
                
            } catch (error) {
                loadingModal.remove();
                console.error('Error:', error);
                alert('Failed to load stores');
            }
        }
        
        function closeStoreModal() {
            const modal = document.querySelector('.store-modal-wrapper');
            if (modal) {
                modal.remove();
            }
        }
        
        async function assignSelectedStores(userId, button) {
            const checkboxes = document.querySelectorAll('.store-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one store');
                return;
            }
            
            const storeIds = Array.from(checkboxes).map(cb => cb.value);
            
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning...';
            
            try {
                const response = await fetch('profile/api.php?action=add_store_access', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: userId,
                        store_ids: storeIds
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('Store access assigned successfully!');
                    closeStoreModal(); // Use the proper close function
                    // Clear cache to force reload with fresh data
                    storeAccessCache = null;
                    storeAccessLoaded = false;
                    await loadUserStores(userId);
                } else {
                    alert('Failed to assign stores: ' + (data.error || 'Unknown error'));
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-plus"></i> Assign Selected';
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to assign stores');
                button.disabled = false;
                button.innerHTML = '<i class="fas fa-plus"></i> Assign Selected';
            }
        }
        
        async function removeStoreAccess(userId, storeId) {
            console.log('=== removeStoreAccess called ===');
            console.log('userId:', userId);
            console.log('storeId:', storeId);
            
            if (!confirm('Are you sure you want to remove this store access?')) {
                console.log('User cancelled removal');
                return;
            }
            
            console.log('User confirmed removal, proceeding...');
            
            try {
                console.log('Sending API request to remove_store_access...');
                console.log('Request body:', JSON.stringify({
                    user_id: userId,
                    store_id: storeId
                }));
                
                const response = await fetch('profile/api.php?action=remove_store_access', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: userId,
                        store_id: storeId
                    })
                });
                
                console.log('Response status:', response.status);
                console.log('Response OK:', response.ok);
                
                const data = await response.json();
                console.log('Response data:', data);
                
                if (data.debug) {
                    console.log('🐛 Debug info:', data.debug);
                }
                
                if (data.success) {
                    console.log('✓ Store access removed successfully');
                    alert('Store access removed successfully');
                    
                    // Clear cache to force reload with fresh data
                    console.log('Clearing store access cache...');
                    storeAccessCache = null;
                    storeAccessLoaded = false;
                    
                    // Force refresh from API
                    console.log('Reloading user stores...');
                    await loadUserStores(userId);
                    console.log('✓ User stores reloaded');
                } else {
                    console.error('✗ Failed to remove store access:', data.error);
                    alert('Failed to remove store access: ' + (data.error || 'Unknown error'));
                }
            } catch (error) {
                console.error('✗ Exception in removeStoreAccess:', error);
                console.error('Error stack:', error.stack);
                alert('Failed to remove store access: ' + error.message);
            }
            
            console.log('=== removeStoreAccess completed ===');
        }

        // --- Searchable Dropdown Helper ---
        function setupSearchableDropdown(selectId, onChangeCallback) {
            const originalSelect = document.getElementById(selectId);
            if (!originalSelect) return;

            // Create wrapper
            const wrapper = document.createElement('div');
            wrapper.className = 'searchable-select-wrapper';
            
            // Create search input
            const searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'searchable-select-input';
            searchInput.placeholder = 'Search user...';
            searchInput.autocomplete = 'off';
            
            // Create options container
            const optionsList = document.createElement('div');
            optionsList.className = 'searchable-select-options';
            
            // Populate options
            const options = Array.from(originalSelect.options);
            let hasOptions = false;

            options.forEach(opt => {
                if (opt.value === "") return; // Skip placeholder
                hasOptions = true;
                const div = document.createElement('div');
                div.className = 'searchable-select-option ' + opt.className;
                div.textContent = opt.text;
                div.dataset.value = opt.value;
                div.dataset.text = opt.text.toLowerCase();
                
                // Add role badge if class exists
                if (opt.className.includes('option-role-')) {
                    const role = opt.className.split('option-role-')[1].split(' ')[0];
                    const badge = document.createElement('span');
                    badge.className = `user-role role-${role}`;
                    badge.style.fontSize = '10px';
                    badge.style.marginLeft = '8px';
                    badge.style.padding = '2px 6px';
                    badge.textContent = role.toUpperCase();
                    
                    div.appendChild(badge);
                }
                
                div.onclick = (e) => {
                    e.stopPropagation();
                    searchInput.value = opt.text;
                    originalSelect.value = opt.value;
                    optionsList.style.display = 'none';
                    
                    // Trigger callback
                    if (onChangeCallback) onChangeCallback(opt.value);
                };
                optionsList.appendChild(div);
            });

            if (!hasOptions) {
                const noOpt = document.createElement('div');
                noOpt.className = 'searchable-select-no-results';
                noOpt.textContent = 'No users found';
                optionsList.appendChild(noOpt);
            }

            // Filter logic
            searchInput.addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase();
                let matchCount = 0;
                
                Array.from(optionsList.children).forEach(child => {
                    if (child.classList.contains('searchable-select-no-results')) return;
                    
                    const text = child.dataset.text;
                    if (text.includes(term)) {
                        child.style.display = 'block';
                        matchCount++;
                    } else {
                        child.style.display = 'none';
                    }
                });

                optionsList.style.display = 'block';
                
                // Handle no results
                let noRes = optionsList.querySelector('.searchable-select-no-results');
                if (matchCount === 0) {
                    if (!noRes) {
                        noRes = document.createElement('div');
                        noRes.className = 'searchable-select-no-results';
                        noRes.textContent = 'No matches found';
                        optionsList.appendChild(noRes);
                    }
                    noRes.style.display = 'block';
                } else if (noRes) {
                    noRes.style.display = 'none';
                }
            });

            // Show/Hide logic
            searchInput.addEventListener('focus', () => {
                optionsList.style.display = 'block';
                // Reset filter on focus if needed, or keep current
            });
            
            searchInput.addEventListener('click', (e) => {
                e.stopPropagation();
                optionsList.style.display = 'block';
            });

            document.addEventListener('click', (e) => {
                if (!wrapper.contains(e.target)) {
                    optionsList.style.display = 'none';
                }
            });

            // Hide original select but keep it in DOM
            originalSelect.style.display = 'none';
            
            // Insert wrapper before select
            originalSelect.parentNode.insertBefore(wrapper, originalSelect);
            wrapper.appendChild(searchInput);
            wrapper.appendChild(optionsList);
            
            // If original select has a value, set input text
            if (originalSelect.value) {
                const selectedOpt = originalSelect.options[originalSelect.selectedIndex];
                if (selectedOpt) {
                    searchInput.value = selectedOpt.text;
                }
            }
        }

        // Auto-load users on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadUsers();
        });
    </script>
</body>
</html>
