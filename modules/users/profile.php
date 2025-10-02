<?php
/**
 * Optimized User Profile Page - Lazy Loading Implementation
 * Loads data on-demand via AJAX for better performance
 */

// Enable output compression
ob_start('ob_gzhandler');

require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Only load essential user data on page load
$user = $db->read('users', $userId);
$pageTitle = 'My Profile';

// Get role info (lightweight query)
$role = ['role_name' => 'User'];

// Check if user has direct role field (string) or role_id (reference)
if (!empty($user['role']) && is_string($user['role'])) {
    // Direct role assignment (e.g., 'admin', 'manager', 'user')
    $role['role_name'] = ucfirst(strtolower($user['role']));
} elseif (!empty($user['role_id'])) {
    // Role ID reference
    $roleData = $db->read('roles', $user['role_id']);
    if ($roleData) {
        $role = $roleData;
    }
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $updateData = [
            'first_name' => $_POST['first_name'] ?? '',
            'last_name' => $_POST['last_name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'updated_at' => date('c')
        ];
        
        if ($db->update('users', $userId, $updateData)) {
            $message = 'Profile updated successfully!';
            $messageType = 'success';
            $user = $db->read('users', $userId); // Refresh data
        } else {
            $message = 'Failed to update profile.';
            $messageType = 'error';
        }
    }
    
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Password must be at least 8 characters long.';
            $messageType = 'error';
        } else {
            // Verify current password
            if (verifyPassword($currentPassword, $user['password_hash'])) {
                $hashedPassword = hashPassword($newPassword);
                if ($db->update('users', $userId, [
                    'password_hash' => $hashedPassword,
                    'require_password_change' => false,
                    'updated_at' => date('c')
                ])) {
                    $message = 'Password changed successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to change password.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Current password is incorrect.';
                $messageType = 'error';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Inventory System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Dashboard Header Styles -->
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            padding: 0;
        }
        
        /* Adjust main content to account for dashboard header */
        .dashboard-content {
            padding: 20px;
            margin-top: 70px; /* Space for fixed header */
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .profile-header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 30px;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: bold;
            flex-shrink: 0;
        }
        
        .profile-info {
            flex-grow: 1;
        }
        
        .profile-name {
            font-size: 32px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .profile-role {
            color: #718096;
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .profile-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #4a5568;
            font-size: 14px;
        }
        
        .meta-item i {
            color: #667eea;
        }
        
        .tabs {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .tab-button {
            padding: 12px 24px;
            border: none;
            background: transparent;
            color: #4a5568;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab-button:hover {
            background: #f7fafc;
            color: #667eea;
        }
        
        .tab-button.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s ease-in-out infinite;
            border-radius: 10px;
            height: 20px;
            margin-bottom: 10px;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        .data-card {
            background: #f7fafc;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
        }
        
        .data-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .data-row:last-child {
            border-bottom: none;
        }
        
        .data-label {
            font-weight: 600;
            color: #4a5568;
        }
        
        .data-value {
            color: #2d3748;
        }
        
        .activity-item {
            display: flex;
            align-items: start;
            gap: 15px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            background: #edf2f7;
            transform: translateX(5px);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex-grow: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .activity-meta {
            font-size: 13px;
            color: #718096;
        }
        
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .permission-card {
            padding: 20px;
            background: #f7fafc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .permission-card.granted {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        
        .permission-card.denied {
            opacity: 0.5;
        }
        
        .permission-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .permission-card.granted .permission-icon {
            color: #28a745;
        }
        
        .permission-info h4 {
            margin-bottom: 5px;
            color: #2d3748;
        }
        
        .permission-info p {
            font-size: 13px;
            color: #718096;
        }
        
        .store-card {
            padding: 20px;
            background: #f7fafc;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .store-card:hover {
            background: #edf2f7;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .store-info h4 {
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .store-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: #718096;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .back-button:hover {
            transform: translateX(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .load-more-btn {
            width: 100%;
            margin-top: 15px;
            background: #edf2f7;
            color: #4a5568;
        }
        
        .load-more-btn:hover {
            background: #e2e8f0;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            font-size: 16px;
        }
    </style>
</head>
<body>
    <!-- Dashboard content wrapper -->
    <div class="dashboard-content">
        <div class="container">
        
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <div class="profile-header">
            <div class="profile-avatar">
                <?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1)) . strtoupper(substr($user['last_name'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="profile-info">
                <h1 class="profile-name"><?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></h1>
                <div class="profile-role"><?= htmlspecialchars($role['role_name'] ?? 'User') ?></div>
                <div class="profile-meta">
                    <div class="meta-item">
                        <i class="fas fa-envelope"></i>
                        <?= htmlspecialchars($user['email'] ?? '') ?>
                    </div>
                    <?php if (!empty($user['phone'])): ?>
                    <div class="meta-item">
                        <i class="fas fa-phone"></i>
                        <?= htmlspecialchars($user['phone']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <i class="fas fa-user-tag"></i>
                        <?= htmlspecialchars($user['username'] ?? '') ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tabs">
            <div class="tab-buttons">
                <button class="tab-button active" data-tab="profile">
                    <i class="fas fa-user"></i> Profile Info
                </button>
                <button class="tab-button" data-tab="activity">
                    <i class="fas fa-history"></i> Activity Log
                </button>
                <button class="tab-button" data-tab="permissions">
                    <i class="fas fa-shield-alt"></i> Permissions
                </button>
                <button class="tab-button" data-tab="stores">
                    <i class="fas fa-store"></i> Store Access
                </button>
                <button class="tab-button" data-tab="security">
                    <i class="fas fa-lock"></i> Security
                </button>
            </div>
            
            <!-- Profile Tab -->
            <div class="tab-content active" id="tab-profile">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-input" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-input" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
            
            <!-- Activity Tab (Lazy Loaded) -->
            <div class="tab-content" id="tab-activity">
                <div id="activity-loading">
                    <div class="loading-skeleton" style="height: 80px;"></div>
                    <div class="loading-skeleton" style="height: 80px;"></div>
                    <div class="loading-skeleton" style="height: 80px;"></div>
                </div>
                <div id="activity-content" style="display: none;"></div>
            </div>
            
            <!-- Permissions Tab (Lazy Loaded) -->
            <div class="tab-content" id="tab-permissions">
                <div id="permissions-loading">
                    <div class="loading-skeleton" style="height: 100px;"></div>
                    <div class="loading-skeleton" style="height: 100px;"></div>
                    <div class="loading-skeleton" style="height: 100px;"></div>
                </div>
                <div id="permissions-content" style="display: none;"></div>
            </div>
            
            <!-- Stores Tab (Lazy Loaded) -->
            <div class="tab-content" id="tab-stores">
                <div id="stores-loading">
                    <div class="loading-skeleton" style="height: 80px;"></div>
                    <div class="loading-skeleton" style="height: 80px;"></div>
                    <div class="loading-skeleton" style="height: 80px;"></div>
                </div>
                <div id="stores-content" style="display: none;"></div>
            </div>
            
            <!-- Security Tab -->
            <div class="tab-content" id="tab-security">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-input" required minlength="8">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-input" required minlength="8">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>
        </div>
        </div> <!-- Close container -->
    </div> <!-- Close dashboard-content -->
    
    <script>
        // Tab switching
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                
                // Update active states
                document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById('tab-' + tabName).classList.add('active');
                
                // Lazy load data when tab is clicked
                if (tabName === 'activity' && !window.activityLoaded) {
                    loadActivities();
                    window.activityLoaded = true;
                } else if (tabName === 'permissions' && !window.permissionsLoaded) {
                    loadPermissions();
                    window.permissionsLoaded = true;
                } else if (tabName === 'stores' && !window.storesLoaded) {
                    loadStores();
                    window.storesLoaded = true;
                }
            });
        });
        
        let activityOffset = 0;
        const activityLimit = 10;
        
        async function loadActivities(append = false) {
            try {
                if (!append) {
                    activityOffset = 0;
                }
                
                const response = await fetch(`profile/api.php?action=get_activities&limit=${activityLimit}&offset=${activityOffset}`);
                const data = await response.json();
                
                document.getElementById('activity-loading').style.display = 'none';
                document.getElementById('activity-content').style.display = 'block';
                
                if (data.success && data.data.length > 0) {
                    const container = document.getElementById('activity-content');
                    
                    if (!append) {
                        container.innerHTML = '';
                    }
                    
                    data.data.forEach(activity => {
                        const item = document.createElement('div');
                        item.className = 'activity-item';
                        item.innerHTML = `
                            <div class="activity-icon">
                                <i class="fas fa-${getActivityIcon(activity.activity_type)}"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">${escapeHtml(activity.description || activity.activity_type)}</div>
                                <div class="activity-meta">
                                    ${formatDate(activity.created_at)} • ${escapeHtml(activity.ip_address || 'N/A')}
                                </div>
                            </div>
                        `;
                        container.appendChild(item);
                    });
                    
                    activityOffset += data.data.length;
                    
                    // Add load more button if there are more items
                    if (data.has_more) {
                        let loadMoreBtn = document.getElementById('load-more-activities');
                        if (!loadMoreBtn) {
                            loadMoreBtn = document.createElement('button');
                            loadMoreBtn.id = 'load-more-activities';
                            loadMoreBtn.className = 'btn load-more-btn';
                            loadMoreBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Load More';
                            loadMoreBtn.onclick = () => loadActivities(true);
                            container.appendChild(loadMoreBtn);
                        }
                    } else {
                        const loadMoreBtn = document.getElementById('load-more-activities');
                        if (loadMoreBtn) loadMoreBtn.remove();
                    }
                } else if (!append) {
                    document.getElementById('activity-content').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No Activity Yet</h3>
                            <p>Your activity history will appear here</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading activities:', error);
                document.getElementById('activity-loading').style.display = 'none';
                document.getElementById('activity-content').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Failed to load activities. Please try again.
                    </div>
                `;
                document.getElementById('activity-content').style.display = 'block';
            }
        }
        
        async function loadPermissions() {
            try {
                const response = await fetch('profile/api.php?action=get_permissions');
                const data = await response.json();
                
                document.getElementById('permissions-loading').style.display = 'none';
                document.getElementById('permissions-content').style.display = 'block';
                
                if (data.success) {
                    const perms = data.data;
                    const container = document.getElementById('permissions-content');
                    
                    const permissionsList = [
                        { key: 'can_view_reports', name: 'View Reports', icon: 'chart-bar', desc: 'Access and view system reports' },
                        { key: 'can_manage_inventory', name: 'Manage Inventory', icon: 'boxes', desc: 'Add, edit, and delete inventory items' },
                        { key: 'can_manage_users', name: 'Manage Users', icon: 'users', desc: 'Create and manage user accounts' },
                        { key: 'can_manage_stores', name: 'Manage Stores', icon: 'store', desc: 'Add and configure store locations' },
                        { key: 'can_configure_system', name: 'System Configuration', icon: 'cog', desc: 'Access system settings and configuration' }
                    ];
                    
                    container.innerHTML = '<div class="permission-grid"></div>';
                    const grid = container.querySelector('.permission-grid');
                    
                    permissionsList.forEach(perm => {
                        const granted = perms[perm.key] || false;
                        const card = document.createElement('div');
                        card.className = `permission-card ${granted ? 'granted' : 'denied'}`;
                        card.innerHTML = `
                            <div class="permission-icon">
                                <i class="fas fa-${perm.icon}"></i>
                            </div>
                            <div class="permission-info">
                                <h4>${perm.name}</h4>
                                <p>${perm.desc}</p>
                            </div>
                        `;
                        grid.appendChild(card);
                    });
                }
            } catch (error) {
                console.error('Error loading permissions:', error);
                document.getElementById('permissions-loading').style.display = 'none';
                document.getElementById('permissions-content').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Failed to load permissions. Please try again.
                    </div>
                `;
                document.getElementById('permissions-content').style.display = 'block';
            }
        }
        
        async function loadStores() {
            try {
                const response = await fetch('profile/api.php?action=get_stores');
                const data = await response.json();
                
                document.getElementById('stores-loading').style.display = 'none';
                document.getElementById('stores-content').style.display = 'block';
                
                if (data.success && data.data.length > 0) {
                    const container = document.getElementById('stores-content');
                    container.innerHTML = '';
                    
                    data.data.forEach(store => {
                        const card = document.createElement('div');
                        card.className = 'store-card';
                        card.innerHTML = `
                            <div class="store-info">
                                <h4>${escapeHtml(store.store_name)}</h4>
                                <div class="store-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(store.city || 'N/A')}, ${escapeHtml(store.state || 'N/A')}</span>
                                    ${store.phone ? `<span><i class="fas fa-phone"></i> ${escapeHtml(store.phone)}</span>` : ''}
                                </div>
                            </div>
                            <a href="../stores/profile.php?id=${store.id}" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View
                            </a>
                        `;
                        container.appendChild(card);
                    });
                } else {
                    document.getElementById('stores-content').innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-store"></i>
                            <h3>No Store Access</h3>
                            <p>You don't have access to any stores yet</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading stores:', error);
                document.getElementById('stores-loading').style.display = 'none';
                document.getElementById('stores-content').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Failed to load stores. Please try again.
                    </div>
                `;
                document.getElementById('stores-content').style.display = 'block';
            }
        }
        
        function getActivityIcon(type) {
            const icons = {
                'login': 'sign-in-alt',
                'logout': 'sign-out-alt',
                'create': 'plus-circle',
                'update': 'edit',
                'delete': 'trash',
                'view': 'eye'
            };
            return icons[type] || 'circle';
        }
        
        function formatDate(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diff = now - date;
            const seconds = Math.floor(diff / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);
            
            if (days > 7) {
                return date.toLocaleDateString();
            } else if (days > 0) {
                return `${days} day${days > 1 ? 's' : ''} ago`;
            } else if (hours > 0) {
                return `${hours} hour${hours > 1 ? 's' : ''} ago`;
            } else if (minutes > 0) {
                return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
            } else {
                return 'Just now';
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
