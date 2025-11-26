<?php
/**
 * User Management Dashboard
 * Comprehensive admin interface for managing users, activities, permissions, and store access
 */

require_once '../../config.php';
require_once '../../db.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';

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

// $db = getDB(); // Firebase fallback - Disabled for performance
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
$canManageUsers = currentUserHasPermission('can_manage_users');

// Get selected user for viewing (default to showing all)
$selectedUserId = $_GET['user_id'] ?? 'all';

$pageTitle = 'User Management';

// --- Server-Side Data Pre-fetching ---
// DISABLED FOR PERFORMANCE - Data will be loaded via AJAX
$usersData = [];
$activitiesData = [];
$storesData = [];

/*
// 1. Pre-fetch Users (including permissions)
$canManageUsers = ($currentUser['can_manage_users'] ?? false) || ($currentUser['can_view_users'] ?? false);

if ($isAdmin || $canManageUsers) {
    try {
        // Fetch all columns to get permissions
        $users = $sqlDb->fetchAll("SELECT * FROM users ORDER BY username ASC");
        foreach ($users as $user) {
            // Remove sensitive data
            unset($user['password_hash']);
            unset($user['remember_token']);
            unset($user['reset_token']);
            
            $nameParts = explode(' ', trim($user['full_name'] ?? ''), 2);
            
            // Base user data
            $userData = [
                'id' => $user['id'] ?? $user['firebase_id'] ?? '',
                'username' => $user['username'] ?? 'Unknown',
                'email' => $user['email'] ?? '',
                'first_name' => $nameParts[0] ?? '',
                'last_name' => $nameParts[1] ?? '',
                'role' => $user['role'] ?? 'staff',
                'status' => $user['status'] ?? 'active',
                'created_at' => $user['created_at'] ?? '',
                'last_login' => $user['last_login'] ?? '',
                'deleted_at' => $user['deleted_at'] ?? null,
                'profile_picture' => $user['profile_picture'] ?? null
            ];
            
            // Merge with all other columns (permissions)
            $usersData[] = array_merge($user, $userData);
        }
    } catch (Exception $e) {
        // Fallback or empty
    }
}

// 2. Pre-fetch Activities
$activitiesData = [];
try {
    // Increase initial fetch limit to 5000 to match client-side fetch
    $limit = 5000;
    $sql = "SELECT ua.*, u.username, u.full_name, u.role 
            FROM user_activities ua 
            LEFT JOIN users u ON ua.user_id = u.id 
            WHERE ua.deleted_at IS NULL";
    $params = [];

    if (!$isAdmin && !$canManageUsers) {
        $sql .= " AND (ua.user_id = ? OR ua.user_id IN (SELECT id FROM users WHERE firebase_id = ?))";
        $params[] = $currentUserId;
        $params[] = $currentUserId;
    }

    $sql .= " ORDER BY ua.created_at DESC LIMIT ?";
    $params[] = $limit;

    $activities = $sqlDb->fetchAll($sql, $params);
    
    foreach ($activities as $activity) {
        $userName = $activity['username'] ?? 'Unknown';
        if (!empty($activity['full_name'])) {
            $userName = $activity['full_name'];
        }
        
        // Format for JS
        $activitiesData[] = array_merge($activity, [
            'user_name' => $userName,
            'user_role' => ucfirst($activity['role'] ?? '')
        ]);
    }
} catch (Exception $e) {
    // Fallback
}
*/

// 3. Pre-fetch Stores (for current user)
$storesData = [];
try {
    if ($isAdmin) {
        // Admin sees all stores
        $storesData = $sqlDb->fetchAll("SELECT * FROM stores WHERE deleted_at IS NULL ORDER BY name ASC");
    } else {
        // User sees assigned stores
        $storesData = $sqlDb->fetchAll("
            SELECT s.* 
            FROM stores s
            JOIN user_store_access usa ON s.id = usa.store_id
            WHERE usa.user_id = ? AND s.deleted_at IS NULL
            ORDER BY s.name ASC
        ", [$currentUserId]);
    }
} catch (Exception $e) {
    // Fallback
}
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
            overflow: hidden;
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

        .role-warehouse {
            background: #8b5cf6;
            color: white;
        }

        .role-analyst {
            background: #ec4899;
            color: white;
        }

        .role-staff {
            background: #10b981;
            color: white;
        }

        .role-cashier {
            background: #06b6d4;
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
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 13px;
            color: #6b7280;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f3f4f6;
        }
        
        .user-meta div {
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .user-meta i {
            width: 16px;
            text-align: center;
            color: #9ca3af;
            flex-shrink: 0;
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

        /* User Selection Modal */
        .user-selector-modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5); /* Reduced opacity slightly */
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            /* backdrop-filter: blur(4px); REMOVED for performance */
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease-in-out; /* Faster transition */
            will-change: opacity;
        }
        
        .user-selector-modal.active {
            opacity: 1;
            pointer-events: auto;
        }
        
        .user-selector-content {
            background: white;
            border-radius: 12px; /* Reduced radius */
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1); /* Simpler shadow */
            transform: scale(0.98); /* Scale instead of translate for smoother feel */
            transition: transform 0.2s ease-in-out;
            will-change: transform;
        }
        
        .user-selector-modal.active .user-selector-content {
            transform: scale(1);
        }
        
        .user-selector-header {
            padding: 16px 20px; /* Reduced padding */
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .user-selector-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
            -webkit-overflow-scrolling: touch; /* Smooth scrolling on iOS */
        }
        
        .user-selector-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); /* Slightly smaller cards */
            gap: 12px;
        }
        
        .user-selector-card {
            border: 1px solid #e5e7eb; /* Thinner border */
            border-radius: 8px; /* Reduced radius */
            padding: 12px;
            cursor: pointer;
            transition: border-color 0.1s, background-color 0.1s; /* Fast transition, no transform */
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            background-color: #fff;
            content-visibility: auto; /* Browser optimization */
            contain: content; /* Browser optimization */
        }
        
        .user-selector-card:hover {
            border-color: #667eea;
            background-color: #f8fafc;
            /* No transform on hover to prevent repaints */
        }
        
        .user-selector-card.selected {
            border-color: #667eea;
            background: #eff6ff;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }
        
        .user-selector-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            font-weight: bold;
            color: #4b5563;
            font-size: 20px;
        }
        
        .user-selector-search {
            margin-bottom: 20px;
            position: relative;
        }
        
        .user-selector-search i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }
        
        .user-selector-search input {
            width: 100%;
            padding: 12px 16px 12px 44px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.2s;
        }
        
        .user-selector-search input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
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
                    
                    <div style="flex: 1; min-width: 300px; display: flex; gap: 10px; justify-content: flex-end; align-items: center;">
                        <div style="position: relative; width: 300px;">
                            <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none;"></i>
                            <input type="text" id="user-search" placeholder="Search users..." 
                                   style="width: 100%; padding: 10px 10px 10px 35px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; transition: all 0.2s; height: 42px;"
                                   onkeyup="filterUsersRealTime()">
                        </div>
                        <select id="role-filter" onchange="filterUsersRealTime()" 
                                style="padding: 0 10px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; min-width: 120px; cursor: pointer; height: 42px;">
                            <option value="all">All Roles</option>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="user">User</option>
                            <option value="staff">Staff</option>
                            <option value="cashier">Cashier</option>
                            <option value="warehouse">Warehouse</option>
                            <option value="analyst">Analyst</option>
                        </select>
                        <button class="btn" onclick="loadUsers(true)" style="white-space: nowrap; height: 42px; display: flex; align-items: center; gap: 8px; background-color: #fff; border: 1px solid #e5e7eb; color: #4b5563;" title="Refresh List">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <?php if ($canManageUsers): ?>
                        <button class="btn btn-primary" onclick="showCreateUserModal()" style="white-space: nowrap; height: 42px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-user-plus"></i> Create
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div id="users-loading" class="loading" style="display: <?php echo !empty($usersData) ? 'none' : 'block'; ?>;">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading users...</p>
                </div>
                
                <div id="users-content" style="display: <?php echo !empty($usersData) ? 'block' : 'none'; ?>;"></div>
            </div>

            <!-- Activities Tab -->
            <div id="tab-activities" class="tab-content">
                <div id="activity-loading" class="loading" style="display: <?php echo !empty($activitiesData) ? 'none' : 'flex'; ?>;">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading activities...</p>
                </div>
                <div id="activity-content" style="display: <?php echo !empty($activitiesData) ? 'block' : 'none'; ?>;"></div>
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
        const canManageUsers = <?php echo $canManageUsers ? 'true' : 'false'; ?>;
        const currentUserId = '<?php echo $currentUserId; ?>';
        let allUsers = <?php echo json_encode($usersData ?? []); ?>;
        let allActivities = <?php echo json_encode($activitiesData ?? []); ?>;
        let myStores = <?php echo json_encode($storesData ?? []); ?>;
        let currentPage = 1;
        let itemsPerPage = 20; // Default to 20 for better readability
        let currentActivityUser = isAdmin ? 'all' : currentUserId;
        
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
            loadPermissions();
        }
        
        function refreshStoreAccess() {
            loadStoreAccess();
        }

        let currentFilter = 'all';
        
        // Load Users
        async function loadUsers(forceRefresh = false) {
            if (!forceRefresh && allUsers && allUsers.length > 0) {
                document.getElementById('users-loading').style.display = 'none';
                document.getElementById('users-content').style.display = 'block';
                filterUsers(currentFilter);
                return;
            }

            try {
                const response = await fetch('profile/api.php?action=get_all_users&include_deleted=true');
                const data = await response.json();

                document.getElementById('users-loading').style.display = 'none';
                document.getElementById('users-content').style.display = 'block';

                if (data.success) {
                    if (data.data && data.data.length > 0) {
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
                } else {
                    throw new Error(data.error || 'Failed to load users');
                }
            } catch (error) {
                console.error('Error loading users:', error);
                document.getElementById('users-loading').style.display = 'none';
                document.getElementById('users-content').style.display = 'block';
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
                        <div class="user-card ${user.deleted_at ? 'deleted' : ''}">
                            <div class="user-card-header">
                                <div class="user-avatar">
                                    ${user.profile_picture ? 
                                        `<img src="../../${user.profile_picture}" alt="${escapeHtml(user.username)}" style="width: 100%; height: 100%; object-fit: cover;">` : 
                                        getInitials(user.first_name || user.username, user.last_name)
                                    }
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
                                ${canManageUsers ? (!user.deleted_at ? `
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
                                `) : ''}
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
            
            container.innerHTML = html;
        }

        function updateRoleCounts() {
            const roleCounts = {
                'all': 0,
                'admin': 0,
                'manager': 0,
                'user': 0,
                'staff': 0,
                'cashier': 0,
                'warehouse': 0,
                'analyst': 0
            };

            // Base set of users to count from (respecting Active/Deleted filter)
            let baseUsers = allUsers;
            if (currentFilter === 'active') {
                baseUsers = allUsers.filter(u => !u.deleted_at);
            } else if (currentFilter === 'deleted') {
                baseUsers = allUsers.filter(u => u.deleted_at);
            }

            roleCounts['all'] = baseUsers.length;

            baseUsers.forEach(user => {
                const role = (user.role || 'staff').toLowerCase();
                if (roleCounts.hasOwnProperty(role)) {
                    roleCounts[role]++;
                }
            });

            // Update the select options
            const select = document.getElementById('role-filter');
            if (select) {
                const roleNames = {
                    'all': 'All Roles',
                    'admin': 'Admin',
                    'manager': 'Manager',
                    'user': 'User',
                    'staff': 'Staff',
                    'cashier': 'Cashier',
                    'warehouse': 'Warehouse',
                    'analyst': 'Analyst'
                };

                for (const [role, count] of Object.entries(roleCounts)) {
                    const option = select.querySelector(`option[value="${role}"]`);
                    if (option) {
                        option.textContent = `${roleNames[role]} (${count})`;
                    }
                }
            }
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
            
            updateRoleCounts();
            filterUsersRealTime();
        }

        function filterUsersRealTime() {
            const searchTerm = document.getElementById('user-search').value.toLowerCase();
            const roleFilter = document.getElementById('role-filter').value;
            
            let filtered = allUsers;
            
            // Apply Status Filter
            if (currentFilter === 'active') {
                filtered = filtered.filter(u => !u.deleted_at);
            } else if (currentFilter === 'deleted') {
                filtered = filtered.filter(u => u.deleted_at);
            }
            
            // Apply Role Filter
            if (roleFilter !== 'all') {
                filtered = filtered.filter(u => (u.role || '').toLowerCase() === roleFilter);
            }
            
            // Apply Search Filter
            if (searchTerm) {
                filtered = filtered.filter(u => 
                    (u.username || '').toLowerCase().includes(searchTerm) ||
                    (u.email || '').toLowerCase().includes(searchTerm) ||
                    (u.first_name || '').toLowerCase().includes(searchTerm) ||
                    (u.last_name || '').toLowerCase().includes(searchTerm)
                );
            }
            
            renderUsers(filtered);
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
            document.getElementById('static-create-user-form').reset();
            document.getElementById('static-create-user-modal').style.display = 'flex';
        }

        function closeStaticCreateUserModal() {
            document.getElementById('static-create-user-modal').style.display = 'none';
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
                
                // Populate form
                document.getElementById('edit-user-id').value = user.id;
                document.getElementById('edit-username').value = user.username;
                document.getElementById('edit-email').value = user.email || '';
                document.getElementById('edit-first-name').value = user.first_name || '';
                document.getElementById('edit-last-name').value = user.last_name || '';
                document.getElementById('edit-password-input').value = '';
                
                // Show modal
                const modal = document.getElementById('static-edit-user-modal');
                modal.style.display = 'flex';
                
            } catch (error) {
                console.error('Error preparing edit form:', error);
                alert('Error loading user data');
            }
        }
        
        function closeStaticEditUserModal() {
            document.getElementById('static-edit-user-modal').style.display = 'none';
        }

        // Initialize Edit User Form Listener
        document.addEventListener('DOMContentLoaded', function() {
            // Create User Form Listener
            const createForm = document.getElementById('static-create-user-form');
            if (createForm) {
                createForm.addEventListener('submit', async function(e) {
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
                    
                    const submitBtn = document.getElementById('create-user-submit-btn');
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
                            closeStaticCreateUserModal();
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

            const editForm = document.getElementById('static-edit-user-form');
            if (editForm) {
                editForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const data = Object.fromEntries(formData.entries());
                    
                    // Validation
                    if (!data.username || data.username.trim().length < 3) {
                        alert('Username must be at least 3 characters long');
                        return;
                    }
                    
                    if (!data.email || !data.email.includes('@')) {
                        alert('Please enter a valid email address');
                        return;
                    }

                    // Validate passwords match if changing password
                    if (data.password) {
                        if (data.password.length < 6) {
                            alert('Password must be at least 6 characters long!');
                            return;
                        }
                    } else {
                        // Remove password fields if not changing
                        delete data.password;
                    }
                    
                    const submitBtn = document.getElementById('static-edit-submit-btn');
                    const originalBtnContent = submitBtn.innerHTML;
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
                            closeStaticEditUserModal();
                            await loadUsers();
                        } else {
                            alert('Failed to update user: ' + (result.error || 'Unknown error'));
                        }
                    } catch (error) {
                        console.error('Error updating user:', error);
                        alert('Error updating user: ' + error.message);
                    } finally {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnContent;
                    }
                });
            }
        });

        async function managePermissions(userId) {
            // Switch to permissions tab
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelector('[onclick*="permissions"]').classList.add('active');
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById('tab-permissions').classList.add('active');
            
            // Set target user for auto-selection
            window._lastSelectedUserId = userId;
            
            // Load permissions
            await loadPermissions();
        }

        async function manageStoreAccess(userId) {
            // Switch to store-access tab
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelector('[onclick*="store-access"]').classList.add('active');
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            document.getElementById('tab-store-access').classList.add('active');
            
            // Set target user for auto-selection
            window._lastSelectedStoreUserId = userId;
            
            // Load store access
            await loadStoreAccess();
        }

        // Activity Manager Variables
        // currentPage and itemsPerPage are already defined globally
        
        // Activity Manager: Load Activities
        async function loadActivities(forceRefresh = false, keepPage = false) {
            const loading = document.getElementById('activity-loading');
            const content = document.getElementById('activity-content');
            
            if (!forceRefresh && allActivities && allActivities.length > 0) {
                loading.style.display = 'none';
                content.style.display = 'block';
                addActivityManagerToolbar();
                renderActivities();
                updateActivityStats();
                return;
            }

            loading.style.display = 'flex';
            content.style.display = 'none';
            
            // Reset pagination
            if (!keepPage) currentPage = 1;
            
            try {
                // Fetch from API
                // For admins, always fetch ALL activities so we can filter client-side (like stock list)
                const userFilter = isAdmin ? 'all' : currentUserId;
                const userParam = `&user_id=${userFilter}`;
                
                // Try to fetch with specific params first
                let response;
                try {
                    // Fetch up to 5000 activities to ensure we have a good history
                    response = await fetch(`profile/api.php?action=get_activities&limit=5000${userParam}`);
                } catch (e) {
                    // If offline and specific fetch fails, try to fallback to the generic cached version
                    if (!navigator.onLine && userFilter === 'all') {
                        console.log('Offline: Falling back to cached activities...');
                        response = await fetch(`profile/api.php?action=get_activities&limit=5000&user_id=all`);
                    } else {
                        throw e;
                    }
                }
                
                const data = await response.json();
                
                if (data.success) {
                    allActivities = data.data || [];
                    
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
                    <div id="activity-user-filter-container" style="min-width: 200px;"></div>
                `;
            }
            
            const toolbarHTML = `
                <div class="activity-manager-toolbar">
                    <!-- Compact Header & Stats -->
                    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 36px; height: 36px; background: #f3f4f6; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #4b5563;">
                                <i class="fas fa-history"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 15px; font-weight: 600; color: #1f2937;">Activity Log</h3>
                                <div style="font-size: 11px; color: #6b7280;">
                                    <span id="stat-total-activities" style="font-weight: 600; color: #374151;">-</span> total events
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; gap: 16px; font-size: 12px; color: #6b7280;">
                            <div>Today: <span id="stat-today-activities" style="font-weight: 600; color: #374151;">-</span></div>
                            <div>This Week: <span id="stat-this-week" style="font-weight: 600; color: #374151;">-</span></div>
                        </div>
                    </div>

                    <!-- Compact Filter Bar -->
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        
                        <!-- Date Range -->
                        <div style="display: flex; align-items: center; gap: 8px; background: white; border: 1px solid #cbd5e0; border-radius: 6px; padding: 4px 8px;">
                            <i class="fas fa-calendar-alt" style="color: #9ca3af; font-size: 12px;"></i>
                            <input type="date" id="activity-date-from" style="border: none; font-size: 12px; color: #4b5563; width: 110px; outline: none;">
                            <span style="color: #cbd5e0;">-</span>
                            <input type="date" id="activity-date-to" style="border: none; font-size: 12px; color: #4b5563; width: 110px; outline: none;">
                            <button onclick="applyDateFilter()" style="border: none; background: none; color: #3b82f6; cursor: pointer; font-size: 12px; font-weight: 600; padding: 0 4px;">Apply</button>
                            <button onclick="clearDateFilter()" style="border: none; background: none; color: #9ca3af; cursor: pointer; font-size: 12px; padding: 0 4px;"><i class="fas fa-times"></i></button>
                        </div>

                        ${isAdmin ? userSelectHTML : ''}

                        <!-- Type Filter -->
                        <select id="activity-filter" class="form-input" style="width: auto; padding: 6px 12px; font-size: 13px; height: 34px;">
                            <option value="">📋 All Types</option>
                            <optgroup label="Authentication">
                                <option value="login">🔐 Login</option>
                                <option value="logout">🚪 Logout</option>
                            </optgroup>
                            <optgroup label="Inventory">
                                <option value="product_added">➕ Product Added</option>
                                <option value="product_updated">✏️ Product Updated</option>
                                <option value="product_deleted">🗑️ Product Deleted</option>
                                <option value="stock_adjusted">📉 Stock Adjusted</option>
                            </optgroup>
                            <optgroup label="Purchase Orders">
                                <option value="po_created">📝 PO Created</option>
                                <option value="po_updated">✏️ PO Updated</option>
                                <option value="po_status_updated">🔄 PO Status Change</option>
                                <option value="po_received_shipment">🚚 PO Received</option>
                            </optgroup>
                            <optgroup label="Suppliers">
                                <option value="supplier_added">➕ Supplier Added</option>
                                <option value="supplier_updated">✏️ Supplier Updated</option>
                            </optgroup>
                            <optgroup label="System">
                                <option value="user_created">👤 User Created</option>
                                <option value="user_updated">✏️ User Updated</option>
                                <option value="store_updated">🏪 Store Updated</option>
                                <option value="page_visit">👀 Page Visit</option>
                            </optgroup>
                        </select>

                        <!-- Limit Filter -->
                        <select id="activity-limit" class="form-input" style="width: auto; padding: 6px 12px; font-size: 13px; height: 34px;" title="Rows per page">
                            <option value="10">10 rows</option>
                            <option value="20" selected>20 rows</option>
                            <option value="50">50 rows</option>
                            <option value="100">100 rows</option>
                            <option value="1000">1000 rows</option>
                            <option value="all">All rows</option>
                        </select>

                        <div style="flex: 1;"></div>
                        
                        <button onclick="loadActivities(true, true)" class="btn" style="background: white; border: 1px solid #cbd5e0; color: #4b5563; padding: 6px 12px; font-size: 12px; height: 34px;">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                    
                    <div id="activity-list"></div>
                </div>
            `;
            
            container.innerHTML = toolbarHTML;
            
            // Setup event listeners
            setTimeout(() => {
                // Type Filter Listener
                const typeFilter = document.getElementById('activity-filter');
                if (typeFilter) {
                    typeFilter.addEventListener('change', () => {
                        currentPage = 1;
                        renderActivities();
                    });
                }

                // Limit Filter Listener
                const limitFilter = document.getElementById('activity-limit');
                if (limitFilter) {
                    limitFilter.addEventListener('change', (e) => {
                        const val = e.target.value;
                        if (val === 'all') {
                            itemsPerPage = 999999;
                        } else {
                            itemsPerPage = parseInt(val);
                        }
                        currentPage = 1;
                        renderActivities();
                    });
                }
                
                // Load users for admin dropdown
                if (isAdmin) {
                    loadUsersForFilter();
                }
            }, 100);
        }
        
        // Load users for activity filter dropdown
        async function loadUsersForFilter() {
            const container = document.getElementById('activity-user-filter-container');
            if (!container) return;

            // Create hidden input for value storage if not exists
            let hiddenInput = document.getElementById('activity-user-filter');
            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.id = 'activity-user-filter';
                hiddenInput.value = currentActivityUser;
                container.appendChild(hiddenInput);
            }

            const btn = setupUserSelectionModal('activity-user-filter-container', function(value) {
                const input = document.getElementById('activity-user-filter');
                if (input) input.value = value;
                currentActivityUser = value;
                loadActivities(false, true);
            }, { includeAllOption: true });
            
            // Set initial text
            if (btn) {
                const textSpan = btn.querySelector('.selected-user-text');
                if (textSpan) {
                    if (currentActivityUser === 'all') {
                        textSpan.textContent = 'All Users';
                    } else {
                        // Try to find user in allUsers if loaded, otherwise just show ID or wait for user load
                        // Since we don't want to block, we'll just check what we have
                        const user = allUsers.find(u => u.id == currentActivityUser);
                        if (user) {
                            textSpan.textContent = user.full_name || user.username;
                        } else if (currentActivityUser == currentUserId) {
                            textSpan.textContent = 'My Activity';
                        } else {
                            textSpan.textContent = 'User #' + currentActivityUser;
                        }
                    }
                }
            }
        }
        
        // Render Activities
        function renderActivities() {
            const container = document.getElementById('activity-list');
            if (!container) return;
            
            let filtered = [...allActivities];

            // Apply User Filter (Client-side)
            if (currentActivityUser && currentActivityUser !== 'all') {
                filtered = filtered.filter(activity => activity.user_id == currentActivityUser);
            }
            
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
                    const type = (activity.action || activity.action_type || activity.activity_type || '').toLowerCase();
                    // Exact match for specific types to avoid partial matches (e.g. 'product_updated' matching 'product_added')
                    // But allow partial matches if it's a broad category search if we had one
                    return type === filterValue.toLowerCase();
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
                    const icon = getActivityIcon(activity.action || activity.action_type || activity.activity_type);
                    
                    // Determine color based on action type
                    let iconColor = '#6b7280';
                    let iconBg = '#f3f4f6';
                    const type = (activity.action || activity.action_type || activity.activity_type || '').toLowerCase();
                    
                    if (type.includes('create') || type.includes('add')) { iconColor = '#10b981'; iconBg = '#d1fae5'; }
                    else if (type.includes('update') || type.includes('edit')) { iconColor = '#f59e0b'; iconBg = '#fef3c7'; }
                    else if (type.includes('delete') || type.includes('remove')) { iconColor = '#ef4444'; iconBg = '#fee2e2'; }
                    else if (type.includes('login')) { iconColor = '#3b82f6'; iconBg = '#dbeafe'; }
                    else if (type.includes('logout')) { iconColor = '#6b7280'; iconBg = '#f3f4f6'; }
                    
                    // Format Title
                    let title = escapeHtml(activity.action || activity.action_type || activity.activity_type || 'Activity');
                    title = title.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    
                    // Special formatting for Page Visits
                    if (title === 'Page Visit') {
                        const desc = activity.description || '';
                        if (desc.startsWith('Visited ')) {
                            title = desc.replace('Visited ', '');
                            // Clean up filenames if present
                            title = title.replace('.php', '').replace('management', 'User Management').replace('list', 'List');
                            title = title.replace(/\b\w/g, l => l.toUpperCase());
                        }
                    }

                    // Determine border color based on type
                    let borderColor = '#cbd5e0'; // Default gray
                    if (type.includes('create') || type.includes('add')) borderColor = '#10b981'; // Green
                    else if (type.includes('update') || type.includes('edit')) borderColor = '#f59e0b'; // Orange
                    else if (type.includes('delete') || type.includes('remove')) borderColor = '#ef4444'; // Red
                    else if (type.includes('login')) borderColor = '#3b82f6'; // Blue

                    // Process Metadata
                    let metadataHtml = '';
                    try {
                        let meta = activity.metadata;
                        if (typeof meta === 'string' && (meta.startsWith('{') || meta.startsWith('['))) {
                            meta = JSON.parse(meta);
                        }
                        
                        if (meta && typeof meta === 'object' && Object.keys(meta).length > 0) {
                            const details = [];
                            
                            // Helper to format label: value
                            const addDetail = (label, value, color = '#4b5563') => {
                                if (value !== undefined && value !== null && value !== '') {
                                    details.push(`<span style="color: ${color}; background: #f8fafc; padding: 1px 4px; border-radius: 3px; border: 1px solid #e2e8f0;">${label}: <b>${escapeHtml(String(value))}</b></span>`);
                                }
                            };

                            if (meta.sku) addDetail('SKU', meta.sku);
                            if (meta.product_name) addDetail('Product', meta.product_name);
                            if (meta.po_number) addDetail('PO', meta.po_number);
                            if (meta.supplier_name) addDetail('Supplier', meta.supplier_name);
                            if (meta.store_name) addDetail('Store', meta.store_name);
                            if (meta.quantity) addDetail('Qty', meta.quantity);
                            if (meta.old_quantity && meta.new_quantity) {
                                details.push(`<span style="color: #6b7280; font-size: 10px;">${meta.old_quantity} ➝ ${meta.new_quantity}</span>`);
                            }
                            if (meta.status) addDetail('Status', meta.status, '#059669');
                            
                            // Handle 'changes' object for updates
                            if (meta.changes && typeof meta.changes === 'object') {
                                let changedFields = [];
                                
                                // Scenario 1: { before: {...}, after: {...} } - Full object snapshots
                                if ('before' in meta.changes && 'after' in meta.changes) {
                                    const before = meta.changes.before || {};
                                    const after = meta.changes.after || {};
                                    
                                    if (typeof before === 'object' && typeof after === 'object') {
                                        const allKeys = new Set([...Object.keys(before), ...Object.keys(after)]);
                                        allKeys.forEach(key => {
                                            if (key === 'updated_at' || key === 'created_at') return;
                                            // Simple comparison
                                            if (JSON.stringify(before[key]) !== JSON.stringify(after[key])) {
                                                changedFields.push(key);
                                            }
                                        });
                                    }
                                } 
                                // Scenario 2: { field: {from: x, to: y} } - Explicit diffs
                                else {
                                    changedFields = Object.keys(meta.changes).filter(k => k !== 'updated_at');
                                }

                                if (changedFields.length > 0) {
                                    // Format nicely (capitalize, remove underscores)
                                    const formattedFields = changedFields.map(f => f.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())).join(', ');
                                    details.push(`<span style="color: #d97706; font-style: italic;">Changed: ${formattedFields}</span>`);
                                }
                            }

                            if (details.length > 0) {
                                metadataHtml = `<div style="font-size: 10px; margin-top: 4px; display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">${details.join('')}</div>`;
                            }
                        }
                    } catch (e) {
                        console.warn('Metadata parse error', e);
                    }

                    return `
                        <div style="background: white; border-bottom: 1px solid #f1f5f9; border-left: 4px solid ${borderColor}; padding: 8px 12px; transition: background 0.1s; display: flex; align-items: flex-start; gap: 12px;">
                            <!-- Icon -->
                            <div style="width: 32px; height: 32px; border-radius: 6px; background: ${iconBg}; color: ${iconColor}; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; margin-top: 2px;">
                                ${icon}
                            </div>
                            
                            <!-- Main Content -->
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; align-items: baseline; gap: 8px; margin-bottom: 2px;">
                                    <span style="font-weight: 600; font-size: 13px; color: #1f2937;">${title}</span>
                                    <span style="font-size: 11px; color: #9ca3af; background: #f3f4f6; padding: 1px 6px; border-radius: 4px;">
                                        ${activity.user_name ? escapeHtml(activity.user_name) : 'User'}
                                    </span>
                                </div>
                                ${((activity.action || activity.action_type) !== 'page_visit' && activity.description) ? `
                                <div style="font-size: 12px; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    ${escapeHtml(activity.description)}
                                </div>` : ''}
                                ${metadataHtml}
                            </div>

                            <!-- Time -->
                            <div style="text-align: right; flex-shrink: 0; margin-left: 8px;">
                                <div style="font-size: 12px; font-weight: 500; color: #4b5563;">${timeStr}</div>
                                <div style="font-size: 10px; color: #9ca3af;">${timeAgo}</div>
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
            // Calculate total pages based on current filtered list
            let filtered = [...allActivities];
            if (currentActivityUser && currentActivityUser !== 'all') {
                filtered = filtered.filter(activity => activity.user_id == currentActivityUser);
            }
            // Apply other filters if needed (date, type, search) - logic duplicated from renderActivities
            // Ideally refactor to getFilteredActivities() but for now just ensure page is valid
            
            const totalItems = filtered.length;
            const totalPages = Math.ceil(totalItems / itemsPerPage);

            if (page < 1) page = 1;
            if (page > totalPages) page = totalPages;
            
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
            if (!allActivities || allActivities.length === 0) return;
            
            const now = new Date();
            const today = now.toDateString();
            const oneWeekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
            
            let todayCount = 0;
            let weekCount = 0;
            const uniqueTypes = new Set();
            
            allActivities.forEach(activity => {
                const activityDate = new Date(activity.created_at);
                uniqueTypes.add(activity.action || activity.action_type || activity.activity_type);
                
                if (activityDate.toDateString() === today) todayCount++;
                if (activityDate >= oneWeekAgo) weekCount++;
            });
            
            document.getElementById('stat-total-activities').textContent = allActivities.length.toLocaleString();
            document.getElementById('stat-today-activities').textContent = todayCount.toLocaleString();
            document.getElementById('stat-this-week').textContent = weekCount.toLocaleString();
            // document.getElementById('stat-unique-types').textContent = uniqueTypes.size.toLocaleString();
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
            if (!allActivities || allActivities.length === 0) {
                alert('No activity data to analyze');
                return;
            }
            
            // Calculate analytics
            const typeCount = {};
            const hourCount = {};
            const dayCount = {};
            
            allActivities.forEach(activity => {
                const type = activity.action || activity.action_type || activity.activity_type || 'unknown';
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
                            <p style="margin: 5px 0 0 0; color: #6b7280;">Insights from ${allActivities.length.toLocaleString()} activities</p>
                        </div>
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="background: #ef4444; color: white; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer;">
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
        
        async function loadPermissions() {
            const container = document.getElementById('permissions-container');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><p>Loading permissions...</p></div>';
            
            try {
                // For admin or user manager, show all users and their permissions
                if (isAdmin || canManageUsers) {
                    await loadAllUsersPermissions();
                } else {
                    // For non-admin, just show their own permissions
                    await loadOwnPermissions();
                }
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
                let users = allUsers;
                if (!users || users.length === 0) {
                    const response = await fetch('profile/api.php?action=get_all_users');
                    const data = await response.json();
                    if (!data.success) throw new Error(data.error || 'Failed to load users');
                    users = data.data;
                }
                
                // Sort users by role then username
                const roleOrder = { 'admin': 1, 'manager': 2, 'staff': 3, 'user': 4 };
                users.sort((a, b) => {
                    const roleA = roleOrder[(a.role || 'user').toLowerCase()] || 99;
                    const roleB = roleOrder[(b.role || 'user').toLowerCase()] || 99;
                    
                    if (roleA !== roleB) return roleA - roleB;
                    return (a.username || '').localeCompare(b.username || '');
                });
                
                let html = `
                    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 15px; border-radius: 10px; margin-bottom: 15px; color: white;">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-shield-alt" style="font-size: 20px;"></i>
                            </div>
                            <div style="flex: 1;">
                                <h3 style="margin: 0 0 4px 0; font-size: 18px;">Permission Management</h3>
                                <p style="margin: 0; opacity: 0.95; font-size: 12px;">Manage user roles and permissions across the system</p>
                            </div>
                            <button onclick="refreshPermissions()" class="btn" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 6px 12px; font-size: 12px;" title="Refresh permissions">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151; font-size: 13px;">
                            <i class="fas fa-user-circle"></i> Select User:
                        </label>
                        <div id="perm-user-select-container"></div>
                    </div>
                    
                    <div id="user-permissions-display"></div>
                `;
                
                container.innerHTML = html;
                
                // Setup user selection modal
                const btn = setupUserSelectionModal('perm-user-select-container', function(value) {
                    if (value) {
                        window._lastSelectedUserId = value;
                        loadUserPermissions(value);
                    } else {
                        window._lastSelectedUserId = '';
                        document.getElementById('user-permissions-display').innerHTML = '';
                    }
                });

                // Restore last selected user if available
                if (window._lastSelectedUserId) {
                    // We need to find the user name to update the button text
                    // Use loose equality (==) to handle string/number type mismatches
                    const user = users.find(u => u.id == window._lastSelectedUserId);
                    if (user && btn) {
                        const textSpan = btn.querySelector('.selected-user-text');
                        if (textSpan) textSpan.textContent = user.username;
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
        
        
        async function loadUserPermissions(userId, forceRefresh = false) {
            console.log('=== loadUserPermissions called ===');
            console.log('userId:', userId);
            console.log('forceRefresh:', forceRefresh);
            
            const display = document.getElementById('user-permissions-display');
            
            // Try to use pre-loaded data if not forcing refresh
            if (!forceRefresh) {
                const user = allUsers.find(u => u.id == userId);
                if (user) {
                    // Check if user object has permissions loaded (look for a key permission)
                    if (user.hasOwnProperty('can_view_reports')) {
                        renderUserPermissions(user, display, userId);
                        return;
                    }
                }
            }

            display.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading permissions...</div>';
            
            try {
                console.log('📤 Fetching permissions from API...');
                let response;
                try {
                    let url = `profile/api.php?action=get_permissions&user_id=${userId}`;
                    if (forceRefresh) {
                        url += `&_t=${new Date().getTime()}`;
                    }
                    response = await fetch(url);
                } catch (e) {
                    // Fallback for offline mode
                    if (!navigator.onLine) {
                        if (userId == currentUserId) {
                             response = await fetch(`profile/api.php?action=get_permissions`);
                        } else {
                             throw new Error("Offline Mode: You can only view your own permissions. Managing other users requires an internet connection.");
                        }
                    } else {
                        throw e;
                    }
                }
                
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to load permissions');
                
                renderUserPermissions(data.data, display, userId);
                
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
        
        function renderUserPermissions(perms, display, userId, hideDisabled = false) {
            const permissionsList = [
                // Reports Module
                { key: 'can_view_reports', name: 'View Reports', icon: 'chart-line', category: 'Reports', desc: 'View all system reports and analytics', color: '#8b5cf6' },
                
                // Stock Module
                { key: 'can_view_inventory', name: 'View Stock', icon: 'eye', category: 'Stock', desc: 'View product list and stock levels', color: '#10b981' },
                { key: 'can_add_inventory', name: 'Add Stock', icon: 'plus-circle', category: 'Stock', desc: 'Add new products and stock', color: '#10b981' },
                { key: 'can_edit_inventory', name: 'Edit Stock', icon: 'edit', category: 'Stock', desc: 'Update product details and adjust stock', color: '#10b981' },
                { key: 'can_delete_inventory', name: 'Delete Stock', icon: 'trash-alt', category: 'Stock', desc: 'Remove products from system', color: '#10b981' },
                { key: 'can_restock_inventory', name: 'Restock Items', icon: 'boxes', category: 'Stock', desc: 'Access restock options and manual adjustments', color: '#10b981' },
                { key: 'can_view_audit_logs', name: 'View Audit Logs', icon: 'history', category: 'Stock', desc: 'View stock movement history', color: '#10b981' },
                { key: 'can_scan_barcodes', name: 'Scan Barcodes', icon: 'barcode', category: 'Stock', desc: 'Use barcode scanner for products', color: '#10b981' },

                // Stores Module
                { key: 'can_view_stores', name: 'View Stores', icon: 'eye', category: 'Stores', desc: 'View store list and details', color: '#f59e0b' },
                { key: 'can_add_stores', name: 'Add Stores', icon: 'plus-circle', category: 'Stores', desc: 'Create new store locations', color: '#f59e0b' },
                { key: 'can_edit_stores', name: 'Edit Stores', icon: 'edit', category: 'Stores', desc: 'Modify store information', color: '#f59e0b' },
                { key: 'can_delete_stores', name: 'Delete Stores', icon: 'trash-alt', category: 'Stores', desc: 'Remove stores from system', color: '#f59e0b' },
                
                // POS Module
                { key: 'can_manage_pos', name: 'Manage POS', icon: 'cogs', category: 'POS', desc: 'Configure POS settings and integrations', color: '#06b6d4' },
                { key: 'can_use_pos', name: 'Use POS', icon: 'cash-register', category: 'POS', desc: 'Access point of sale terminal', color: '#06b6d4' },
                
                // Supply Chain Module
                { key: 'can_manage_suppliers', name: 'Manage Suppliers', icon: 'truck', category: 'Supply Chain', desc: 'Add and manage suppliers', color: '#f97316' },
                { key: 'can_manage_purchase_orders', name: 'Purchase Orders', icon: 'file-invoice-dollar', category: 'Supply Chain', desc: 'Create and manage purchase orders', color: '#f97316' },
                { key: 'can_send_purchase_orders', name: 'Send Orders', icon: 'paper-plane', category: 'Supply Chain', desc: 'Approve and send purchase orders', color: '#f97316' },
                { key: 'can_manage_stock_transfers', name: 'Receive Shipment', icon: 'truck-loading', category: 'Supply Chain', desc: 'Receive shipments and transfer stock', color: '#f97316' },

                // Forecasting & Alerts
                { key: 'can_view_forecasting', name: 'View Forecasting', icon: 'chart-line', category: 'Analytics', desc: 'Access demand forecasting tools', color: '#8b5cf6' },
                { key: 'can_manage_alerts', name: 'Manage Alerts', icon: 'bell', category: 'Analytics', desc: 'Configure low stock and system alerts', color: '#8b5cf6' },

                // User Management
                { key: 'can_view_users', name: 'View Users', icon: 'eye', category: 'Users', desc: 'View user list and profiles', color: '#ec4899' },
                { key: 'can_manage_users', name: 'Manage Users', icon: 'users-cog', category: 'Users', desc: 'Add, edit, delete users and permissions', color: '#ec4899' }
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
                    permissions: ['can_view_reports', 'can_view_inventory', 'can_use_pos', 'can_manage_pos', 'can_view_stores', 'can_scan_barcodes']
                },
                'warehouse': {
                    name: 'Warehouse',
                    color: '#8b5cf6',
                    icon: 'boxes',
                    desc: 'Stock & shipment management',
                    permissions: [
                        'can_view_inventory', 'can_edit_inventory', 'can_restock_inventory', 
                        'can_manage_stock_transfers', 'can_manage_suppliers', 'can_manage_purchase_orders',
                        'can_view_audit_logs', 'can_scan_barcodes'
                    ]
                },
                'analyst': {
                    name: 'Analyst',
                    color: '#ec4899',
                    icon: 'chart-pie',
                    desc: 'Reports & forecasting',
                    permissions: [
                        'can_view_reports', 'can_view_forecasting', 'can_manage_alerts', 
                        'can_view_inventory', 'can_view_stores', 'can_view_audit_logs', 'can_view_users'
                    ]
                },
                'manager': {
                    name: 'Manager',
                    color: '#f59e0b',
                    icon: 'user-tie',
                    desc: 'Store and inventory management',
                    permissions: [
                        'can_view_reports', 'can_view_inventory', 'can_add_inventory', 'can_edit_inventory', 
                        'can_view_stores', 'can_add_stores', 'can_edit_stores', 'can_use_pos', 'can_view_users',
                        'can_manage_suppliers', 'can_manage_purchase_orders', 'can_send_purchase_orders', 'can_manage_stock_transfers',
                        'can_view_forecasting', 'can_manage_alerts', 'can_restock_inventory', 'can_view_audit_logs', 'can_scan_barcodes'
                    ]
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
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 12px; border-radius: 10px; color: white; margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <div>
                            <div style="font-size: 11px; opacity: 0.9; margin-bottom: 2px;">Current Role</div>
                            <div style="font-size: 20px; font-weight: 700;">${perms.role}</div>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 11px; opacity: 0.9; margin-bottom: 2px;">Permissions</div>
                            <div style="font-size: 20px; font-weight: 700;">${grantedCount}/${permissionsList.length}</div>
                        </div>
                    </div>
                </div>
                
                ${isAdmin ? `
                <div style="background: white; border: 1px solid #e5e7eb; border-radius: 10px; padding: 15px; margin-bottom: 15px;">
                    <h4 style="margin: 0 0 8px 0; color: #1f2937; display: flex; align-items: center; gap: 8px; font-size: 14px;">
                        <i class="fas fa-users-cog"></i> Quick Role Assignment
                    </h4>
                    <p style="margin: 0 0 12px 0; color: #6b7280; font-size: 11px;">
                        Select a predefined role to automatically assign its permission package
                    </p>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px;">
                        ${Object.entries(roleTemplates).map(([key, role]) => `
                            <div style="border: 1px solid ${perms.role.toLowerCase() === key ? role.color : '#e5e7eb'}; border-radius: 8px; padding: 10px; transition: all 0.3s; cursor: pointer; ${perms.role.toLowerCase() === key ? 'background: ' + role.color + '10;' : ''}" onclick="assignRole('${userId}', '${key}')">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 6px;">
                                    <div style="width: 28px; height: 28px; background: ${role.color}; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: white; font-size: 12px;">
                                        <i class="fas fa-${role.icon}"></i>
                                    </div>
                                    <div>
                                        <h5 style="margin: 0; color: #1f2937; font-size: 12px;">${role.name}</h5>
                                        ${perms.role.toLowerCase() === key ? '<small style="color: ' + role.color + '; font-weight: 600; font-size: 10px;"><i class="fas fa-check-circle"></i> Current</small>' : ''}
                                    </div>
                                </div>
                                <p style="margin: 0 0 6px 0; font-size: 10px; color: #6b7280; line-height: 1.2;">${role.desc}</p>
                                <div style="font-size: 10px; color: #9ca3af;">
                                    <strong>${role.permissions.length}</strong> permission${role.permissions.length !== 1 ? 's' : ''}
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    <div style="margin-top: 8px; padding: 8px; background: #f3f4f6; border-radius: 6px; font-size: 11px; color: #6b7280;">
                        <i class="fas fa-info-circle"></i> <strong>Note:</strong> Assigning a role will override current permissions with the role's permission package
                    </div>
                </div>
                ` : ''}
                
                <div style="margin-bottom: 8px;">
                    <h4 style="margin: 0; color: #1f2937; display: flex; align-items: center; gap: 8px; font-size: 14px;">
                        <i class="fas fa-key"></i> Individual Permissions
                    </h4>
                    <p style="margin: 2px 0 0 0; color: #6b7280; font-size: 11px;">Grant or revoke specific permissions by module</p>
                </div>
                
                ${// Group permissions by category
                Object.entries(permissionsList.reduce((groups, perm) => {
                    if (!groups[perm.category]) groups[perm.category] = [];
                    groups[perm.category].push(perm);
                    return groups;
                }, {})).map(([category, categoryPerms]) => {
                    // Filter permissions if hideDisabled is true
                    const visiblePerms = hideDisabled 
                        ? categoryPerms.filter(perm => perms[perm.key]) 
                        : categoryPerms;
                    
                    if (visiblePerms.length === 0) return '';

                    return `
                    <div style="background: white; border-radius: 8px; padding: 12px; margin-bottom: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <h5 style="margin: 0 0 8px 0; color: #1f2937; font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                            <div style="width: 5px; height: 5px; border-radius: 50%; background: ${categoryPerms[0].color};"></div>
                            ${category} Module
                        </h5>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px;">
                            ${visiblePerms.map(perm => {
                                const granted = perms[perm.key] || false;
                                return `
                                    <div data-permission="${perm.key}" data-color="${perm.color}" class="permission-card" style="background: ${granted ? perm.color + '10' : '#f9fafb'}; border: 1px solid ${granted ? perm.color : '#e5e7eb'}; border-radius: 6px; padding: 8px; transition: all 0.2s;">
                                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <div class="permission-icon-container" style="width: 24px; height: 24px; background: ${granted ? perm.color : '#e5e7eb'}; border-radius: 5px; display: flex; align-items: center; justify-content: center; color: white; font-size: 10px;">
                                                    <i class="fas fa-${perm.icon}"></i>
                                                </div>
                                                <div>
                                                    <div style="font-weight: 600; font-size: 11px; color: #1f2937;">${perm.name}</div>
                                                    <div class="permission-status-text" style="font-size: 9px; color: ${granted ? perm.color : '#9ca3af'}; font-weight: 500;">
                                                        ${granted ? '✓ Enabled' : '○ Disabled'}
                                                    </div>
                                                </div>
                                            </div>
                                            ${isAdmin ? `
                                            <label class="toggle-switch" title="${granted ? 'Click to revoke' : 'Click to grant'}" style="transform: scale(0.6); margin-right: -8px;">
                                                <input type="checkbox" ${granted ? 'checked' : ''} 
                                                       onchange="togglePermissionFast('${userId}', '${perm.key}', this.checked)"
                                                       data-permission-toggle="${perm.key}">
                                                <span class="toggle-slider"></span>
                                            </label>
                                            ` : (hideDisabled ? '' : `
                                            <label class="toggle-switch" style="transform: scale(0.6); margin-right: -8px;">
                                                <input type="checkbox" ${granted ? 'checked' : ''} disabled>
                                                <span class="toggle-slider"></span>
                                            </label>
                                            `)}
                                        </div>
                                        <p style="margin: 0; font-size: 10px; color: #6b7280; line-height: 1.2;">${perm.desc}</p>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                `;
                }).join('')}
            `;
            
            display.innerHTML = html;
        }
        
        async function loadOwnPermissions() {
            const container = document.getElementById('permissions-container');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading permissions...</div>';
            
            try {
                const response = await fetch('profile/api.php?action=get_permissions');
                const data = await response.json();
                
                if (!data.success) throw new Error(data.error || 'Failed to load permissions');
                
                renderUserPermissions(data.data, container, currentUserId, true);
                
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
                    
                    // Optimistic UI update - don't reload the whole list
                    if (permCard) {
                        const color = permCard.getAttribute('data-color') || '#667eea';
                        const iconContainer = permCard.querySelector('.permission-icon-container');
                        const statusText = permCard.querySelector('.permission-status-text');
                        
                        if (grant) {
                            // Enabled state
                            permCard.style.background = color + '10'; // 10% opacity hex
                            permCard.style.borderColor = color;
                            if (iconContainer) iconContainer.style.background = color;
                            if (statusText) {
                                statusText.style.color = color;
                                statusText.innerHTML = '✓ Enabled';
                            }
                        } else {
                            // Disabled state
                            permCard.style.background = '#f9fafb';
                            permCard.style.borderColor = '#e5e7eb';
                            if (iconContainer) iconContainer.style.background = '#e5e7eb';
                            if (statusText) {
                                statusText.style.color = '#9ca3af';
                                statusText.innerHTML = '○ Disabled';
                            }
                        }
                    }
                    
                    // Show quick toast
                    showToast(grant ? 'Permission granted ✓' : 'Permission revoked', 'success');
                } else {
                    console.error('❌ Role assignment failed:', data.error);
                    showToast('Failed to update permission: ' + (data.error || 'Unknown error'), 'error');
                    // Revert toggle
                    if (toggle) toggle.checked = !grant;
                }
            } catch (error) {
                console.error('❌ Error:', error);
                showToast('Failed to update permission', 'error');
                // Restore toggle to previous state
                if (toggle) {
                    toggle.checked = !grant;
                }
            }
            
            // Re-enable controls
            if (toggle) {
                toggle.disabled = false;
            }
            if (permCard) {
                permCard.style.opacity = '1';
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
                'cashier': 'Cashier',
                'warehouse': 'Warehouse',
                'analyst': 'Analyst',
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
                    
                    console.log('🔄 Reloading permissions for user:', userId);
                    // Force refresh to get new permissions from server
                    await loadUserPermissions(userId, true);
                    console.log('✅ Permissions reloaded');
                    
                    // Also reload users list if on users tab to update role badge
                    if (document.getElementById('tab-users').style.display !== 'none') {
                        console.log('🔄 Reloading users list...');
                        loadUsers(true); // Force refresh users list too
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
        
        async function loadStoreAccess() {
            const container = document.getElementById('store-access-container');
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i><p>Loading store access...</p></div>';
            
            try {
                // Show user selector for admin or user manager
                if (isAdmin || canManageUsers) {
                    await loadAllUsersStoreAccess();
                } else {
                    await loadOwnStoreAccess();
                }
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

        async function loadOwnStoreAccess() {
            const container = document.getElementById('store-access-container');
            
            const stores = myStores;
            
            let html = `
                <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); padding: 20px; border-radius: 12px; margin-bottom: 25px; color: white;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-store" style="font-size: 28px;"></i>
                        </div>
                        <div style="flex: 1;">
                            <h3 style="margin: 0 0 8px 0; font-size: 24px;">My Store Access</h3>
                            <p style="margin: 0; opacity: 0.95; font-size: 14px;">Stores you are authorized to access</p>
                        </div>
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
                        <p>You have no store access assigned.</p>
                    </div>
                `;
            }
            
            container.innerHTML = html;
        }
        
        async function loadAllUsersStoreAccess() {
            const container = document.getElementById('store-access-container');
            
            try {
                // Exclude admins from the list since they have access to all stores by default
                // Use pre-fetched users
                let users = allUsers;
                
                if (!users || users.length === 0) {
                    const response = await fetch('profile/api.php?action=get_all_users&exclude_admins=true');
                    const data = await response.json();
                    if (!data.success) throw new Error(data.error || 'Failed to load users');
                    users = data.data;
                } else {
                    // Filter out admins locally
                    users = users.filter(u => (u.role || '').toLowerCase() !== 'admin');
                }
                
                // Sort users by role then username
                const roleOrder = { 'admin': 1, 'manager': 2, 'staff': 3, 'user': 4 };
                users.sort((a, b) => {
                    const roleA = roleOrder[(a.role || 'user').toLowerCase()] || 99;
                    const roleB = roleOrder[(b.role || 'user').toLowerCase()] || 99;
                    
                    if (roleA !== roleB) return roleA - roleB;
                    return (a.username || '').localeCompare(b.username || '');
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
                        <div id="store-user-select-container"></div>
                    </div>
                    
                    <div id="user-stores-display"></div>
                `;
                
                container.innerHTML = html;
                
                // Setup user selection modal
                const btn = setupUserSelectionModal('store-user-select-container', function(value) {
                    selectedUserId = value;
                    if (value) {
                        window._lastSelectedStoreUserId = value;
                        loadUserStores(value);
                    } else {
                        window._lastSelectedStoreUserId = '';
                        document.getElementById('user-stores-display').innerHTML = '';
                    }
                }, { excludeAdmins: true });
                
                // Restore last selected user if available
                if (window._lastSelectedStoreUserId) {
                    // Use loose equality (==) to handle string/number type mismatches
                    const user = users.find(u => u.id == window._lastSelectedStoreUserId);
                    if (user && btn) {
                        const textSpan = btn.querySelector('.selected-user-text');
                        if (textSpan) textSpan.textContent = user.username;
                        selectedUserId = window._lastSelectedStoreUserId;
                        loadUserStores(window._lastSelectedStoreUserId);
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
        
        async function loadUserStores(userId) {
            console.log('=== loadUserStores called ===');
            console.log('userId:', userId);
            
            const display = document.getElementById('user-stores-display');
            display.innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i></div>';
            
            try {
                const url = `profile/api.php?action=get_stores&user_id=${userId}`;
                console.log('Fetching stores from:', url);
                
                let response;
                try {
                    response = await fetch(url);
                } catch (e) {
                    if (!navigator.onLine) {
                        if (userId == currentUserId) {
                            response = await fetch(`profile/api.php?action=get_stores`);
                        } else {
                            throw new Error("Offline Mode: You can only view your own store access. Managing other users requires an internet connection.");
                        }
                    } else {
                        throw e;
                    }
                }
                
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
                            <h4 style="margin: 0 0 5px 0; color: #2d3748;">Assigned Stores (<span id="assigned-count">${stores.length}</span>)</h4>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">Stores this user can access</p>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <div style="position: relative;">
                                <i class="fas fa-search" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
                                <input type="text" placeholder="Search assigned stores..." 
                                       style="padding: 8px 8px 8px 32px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; width: 200px;"
                                       onkeyup="filterAssignedStores(this.value)">
                            </div>
                            <button onclick="showAddStoreModal('${userId}')" class="btn btn-success">
                                <i class="fas fa-plus"></i> Assign Store
                            </button>
                        </div>
                    </div>
                `;
                
                if (stores.length > 0) {
                    html += `
                        <div id="assigned-stores-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                            ${stores.map(store => `
                                <div class="assigned-store-card" style="background: white; border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; transition: all 0.2s;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                        <h4 class="store-name" style="margin: 0; color: #1f2937; font-size: 16px;">
                                            <i class="fas fa-store" style="color: #10b981; margin-right: 8px;"></i>
                                            ${escapeHtml(store.store_name || store.name)}
                                        </h4>
                                        ${store.active ? 
                                            '<span style="padding: 4px 10px; background: #d4edda; color: #155724; border-radius: 12px; font-size: 12px; font-weight: 600;"><i class="fas fa-check-circle"></i> Active</span>' : 
                                            '<span style="padding: 4px 10px; background: #f8d7da; color: #721c24; border-radius: 12px; font-size: 12px; font-weight: 600;"><i class="fas fa-times-circle"></i> Inactive</span>'
                                        }
                                    </div>
                                    <div class="store-details" style="font-size: 14px; color: #6b7280; margin-bottom: 12px;">
                                        <div style="margin-bottom: 5px;" class="store-location">
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
                        <div id="no-assigned-stores-found" style="display: none; text-align: center; padding: 40px; background: white; border-radius: 12px; border: 2px dashed #e5e7eb;">
                            <i class="fas fa-search" style="font-size: 32px; color: #d1d5db; margin-bottom: 15px;"></i>
                            <h3 style="margin: 0 0 5px 0; color: #4b5563;">No stores found</h3>
                            <p style="margin: 0; color: #9ca3af;">No assigned stores match your search.</p>
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
                
                display.innerHTML = html;

                // Add filter function for assigned stores
                window.filterAssignedStores = function(query) {
                    const term = query.toLowerCase();
                    const cards = document.querySelectorAll('.assigned-store-card');
                    let visible = 0;
                    
                    cards.forEach(card => {
                        const name = card.querySelector('.store-name').textContent.toLowerCase();
                        const location = card.querySelector('.store-location').textContent.toLowerCase();
                        
                        if (name.includes(term) || location.includes(term)) {
                            card.style.display = 'block';
                            visible++;
                        } else {
                            card.style.display = 'none';
                        }
                    });
                    
                    const noResults = document.getElementById('no-assigned-stores-found');
                    if (noResults) {
                        noResults.style.display = visible === 0 && cards.length > 0 ? 'block' : 'none';
                    }
                };
                
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
        
        async function showAddStoreModal(userId) {
            // Show loading modal
            const loadingModal = document.createElement('div');
            loadingModal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 10000; transition: opacity 0.2s;';
            loadingModal.innerHTML = `
                <div style="background: white; padding: 30px; border-radius: 12px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
                    <i class="fas fa-spinner fa-spin" style="font-size: 28px; color: #10b981; margin-bottom: 12px;"></i>
                    <div style="font-size: 14px; color: #6b7280; font-weight: 500;">Loading available stores...</div>
                </div>
            `;
            document.body.appendChild(loadingModal);
            
            try {
                // Determine correct API path based on current location
                const apiPath = window.location.pathname.includes('modules/users') ? 'profile/api.php' : 'modules/users/profile/api.php';
                console.log('Fetching available stores from:', apiPath);
                
                const response = await fetch(`${apiPath}?action=get_available_stores&user_id=${userId}`);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const text = await response.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Server returned invalid JSON response');
                }
                
                loadingModal.style.opacity = '0';
                setTimeout(() => loadingModal.remove(), 200);
                
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
                modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.6); display: flex; align-items: center; justify-content: center; z-index: 10000; opacity: 0; transition: opacity 0.2s ease-out;';
                modal.innerHTML = `
                    <div class="store-modal-content" style="background: white; border-radius: 16px; max-width: 600px; width: 90%; max-height: 85vh; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3); transform: scale(0.95); transition: transform 0.2s ease-out;">
                        <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <div>
                                    <h3 style="margin: 0; font-size: 18px; color: #1f2937;">
                                        <i class="fas fa-store" style="color: #10b981; margin-right: 8px;"></i>
                                        Assign Store Access
                                    </h3>
                                    <p style="color: #6b7280; margin: 4px 0 0 0; font-size: 13px;">Select stores to grant access</p>
                                </div>
                                <button onclick="closeStoreModal()" style="background: #f3f4f6; color: #6b7280; border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background-color 0.2s;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            
                            <div style="position: relative;">
                                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af;"></i>
                                <input type="text" id="store-search-input" placeholder="Search stores by name, city or state..." 
                                       style="width: 100%; padding: 10px 10px 10px 36px; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s;"
                                       onkeyup="filterStoreList(this.value)">
                            </div>
                        </div>
                        
                        <div style="padding: 0 20px; max-height: 50vh; overflow-y: auto;" id="store-list-container">
                            <div style="padding: 15px 0; position: sticky; top: 0; background: white; z-index: 10; border-bottom: 1px solid #f3f4f6; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
                                <div id="store-count" style="font-size: 13px; color: #6b7280;">
                                    <span id="selected-count" style="font-weight: 600; color: #10b981;">0</span> selected
                                </div>
                                <div style="font-size: 12px; color: #9ca3af;">
                                    Showing <span id="visible-count">0</span> of ${availableStores.length}
                                </div>
                            </div>
                            
                            <div id="store-list">
                                <!-- Stores will be rendered here -->
                            </div>
                            <div id="load-more-container" style="text-align: center; padding: 10px; display: none;">
                                <button onclick="renderMoreStores()" style="background: #f3f4f6; border: none; padding: 8px 16px; border-radius: 20px; color: #4b5563; cursor: pointer; font-size: 13px;">Load More</button>
                            </div>
                            <div id="no-stores-found" style="display: none; text-align: center; padding: 30px 0; color: #9ca3af;">
                                <i class="fas fa-search" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                                <p style="margin: 0; font-size: 14px;">No stores found matching your search</p>
                            </div>
                        </div>
                        
                        <div style="padding: 20px; border-top: 1px solid #e5e7eb; background: #f9fafb; display: flex; gap: 12px; justify-content: flex-end;">
                            <button onclick="closeStoreModal()" class="btn" style="background: white; border: 1px solid #d1d5db; color: #374151; padding: 8px 16px; border-radius: 6px;">
                                Cancel
                            </button>
                            <button onclick="assignSelectedStores('${userId}', this)" class="btn" style="background: #10b981; color: white; border: none; padding: 8px 20px; border-radius: 6px; font-weight: 500; box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);">
                                <i class="fas fa-plus" style="margin-right: 6px;"></i> Assign Selected
                            </button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
                
                // Store data globally for filtering/rendering
                window._currentAvailableStores = availableStores;
                window._renderedStoreCount = 0;
                window._storeSearchQuery = '';
                
                // Add render batch function
                window.renderStoreBatch = function() {
                    const BATCH_SIZE = 50;
                    const container = document.getElementById('store-list');
                    const query = window._storeSearchQuery;
                    
                    let matches = window._currentAvailableStores;
                    if (query) {
                        matches = matches.filter(store => 
                            store.name.toLowerCase().includes(query) || 
                            (store.city && store.city.toLowerCase().includes(query)) || 
                            (store.state && store.state.toLowerCase().includes(query))
                        );
                    }
                    
                    const totalMatches = matches.length;
                    const start = window._renderedStoreCount;
                    const end = Math.min(start + BATCH_SIZE, totalMatches);
                    const batch = matches.slice(start, end);
                    
                    if (batch.length > 0) {
                        const fragment = document.createDocumentFragment();
                        batch.forEach(store => {
                            const label = document.createElement('label');
                            label.className = 'store-item';
                            label.style.cssText = 'display: flex; align-items: center; padding: 10px; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 8px; cursor: pointer;';
                            // Only add hover effect via CSS class to avoid inline style thrashing
                            
                            label.innerHTML = `
                                <input type="checkbox" value="${store.id}" style="width: 16px; height: 16px; margin-right: 12px; accent-color: #10b981;" class="store-checkbox" onchange="updateSelectedCount(this)">
                                <div style="flex: 1;">
                                    <div class="store-name" style="font-weight: 600; color: #1f2937; font-size: 14px;">${escapeHtml(store.name)}</div>
                                    <div class="store-location" style="font-size: 12px; color: #6b7280;">${escapeHtml(store.city || 'N/A')}, ${escapeHtml(store.state || 'N/A')}</div>
                                </div>
                            `;
                            fragment.appendChild(label);
                        });
                        container.appendChild(fragment);
                        window._renderedStoreCount = end;
                    }
                    
                    // Update UI state
                    document.getElementById('visible-count').textContent = window._renderedStoreCount;
                    document.getElementById('no-stores-found').style.display = (totalMatches === 0) ? 'block' : 'none';
                    
                    // Show/hide load more button (infinite scroll trigger)
                    const loadMoreContainer = document.getElementById('load-more-container');
                    if (end < totalMatches) {
                        loadMoreContainer.style.display = 'block';
                        // Setup intersection observer for infinite scroll
                        if (!window._storeObserver) {
                            window._storeObserver = new IntersectionObserver((entries) => {
                                if (entries[0].isIntersecting) {
                                    renderStoreBatch();
                                }
                            }, { root: document.getElementById('store-list-container'), threshold: 0.1 });
                            window._storeObserver.observe(loadMoreContainer);
                        }
                    } else {
                        loadMoreContainer.style.display = 'none';
                        if (window._storeObserver) {
                            window._storeObserver.disconnect();
                            window._storeObserver = null;
                        }
                    }
                };

                // Add filter function
                window.filterStoreList = function(query) {
                    window._storeSearchQuery = query.toLowerCase();
                    window._renderedStoreCount = 0;
                    document.getElementById('store-list').innerHTML = '';
                    renderStoreBatch();
                };

                // Add count update function
                window.updateSelectedCount = function(checkbox) {
                    const selected = document.querySelectorAll('.store-checkbox:checked').length;
                    document.getElementById('selected-count').textContent = selected;
                    
                    if (checkbox) {
                        const item = checkbox.closest('.store-item');
                        if (checkbox.checked) {
                            item.style.borderColor = '#10b981';
                            item.style.backgroundColor = '#ecfdf5';
                        } else {
                            item.style.borderColor = '#e5e7eb';
                            item.style.backgroundColor = 'transparent';
                        }
                    }
                };

                window.renderMoreStores = function() {
                    renderStoreBatch();
                };
                
                // Initial render (limit to 50)
                renderStoreBatch();
                
                // Trigger animation
                requestAnimationFrame(() => {
                    modal.style.opacity = '1';
                    modal.querySelector('.store-modal-content').style.transform = 'scale(1)';
                });
                
                // Focus search input
                setTimeout(() => document.getElementById('store-search-input').focus(), 100);
                
            } catch (error) {
                loadingModal.remove();
                console.error('Error:', error);
                alert('Failed to load stores: ' + error.message);
            }
        }
        
        function closeStoreModal() {
            const modal = document.querySelector('.store-modal-wrapper');
            if (modal) {
                modal.style.opacity = '0';
                const content = modal.querySelector('.store-modal-content');
                if (content) content.style.transform = 'scale(0.95)';
                
                if (window._storeObserver) {
                    window._storeObserver.disconnect();
                    window._storeObserver = null;
                }
                
                setTimeout(() => modal.remove(), 200);
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
                    console.error('❌ Failed to remove store access:', data.error);
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

            // Check if already setup and remove old wrapper
            if (originalSelect.previousElementSibling && originalSelect.previousElementSibling.classList.contains('searchable-select-wrapper')) {
                originalSelect.previousElementSibling.remove();
                originalSelect.style.display = 'block';
            }

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
                div.className = 'searchable-select-option ' + (opt.className || '');
                div.textContent = opt.text;
                div.dataset.value = opt.value;
                div.dataset.text = (opt.text || '').toLowerCase();
                
                // Add role badge if class exists
                if (opt.className && opt.className.includes('option-role-')) {
                    try {
                        const role = opt.className.split('option-role-')[1].split(' ')[0];
                        if (role) {
                            const badge = document.createElement('span');
                            badge.className = `user-role role-${role}`;
                            badge.style.fontSize = '10px';
                            badge.style.marginLeft = '8px';
                            badge.style.padding = '2px 6px';
                            badge.textContent = role.toUpperCase();
                            div.appendChild(badge);
                        }
                    } catch (e) { console.error('Error parsing role class', e); }
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
                    
                    const text = child.dataset.text || '';
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

        // User Selection Modal Logic
        function setupUserSelectionModal(containerId, onSelectCallback, options = {}) {
            const container = document.getElementById(containerId);
            if (!container) return;
            
            // Create button to open modal
            const button = document.createElement('button');
            button.className = 'enhanced-select';
            button.style.textAlign = 'left';
            button.style.display = 'flex';
            button.style.alignItems = 'center';
            button.style.justifyContent = 'space-between';
            button.innerHTML = `
                <span class="selected-user-text">Select a user...</span>
                <i class="fas fa-chevron-down" style="font-size: 12px; color: #718096;"></i>
            `;
            
            // Replace existing select or append button
            const existingSelect = container.querySelector('select');
            if (existingSelect) {
                // Preserve any existing value
                if (existingSelect.value) {
                    // We'll need to fetch the user name if we only have ID
                    // For now, just keep the ID or placeholder
                }
                existingSelect.style.display = 'none';
                existingSelect.parentNode.insertBefore(button, existingSelect);
            } else {
                container.appendChild(button);
            }
            
            button.onclick = () => openUserSelectionModal(onSelectCallback, options, button);
            
            return button;
        }

        // Cache for user selector
        window.userSelectorCache = null;

        async function openUserSelectionModal(onSelectCallback, options = {}, triggerButton) {
            let modal = document.getElementById('user-selector-modal');
            
            if (!modal) {
                // Create modal structure (only once)
                modal = document.createElement('div');
                modal.id = 'user-selector-modal';
                modal.className = 'user-selector-modal';
                
                modal.innerHTML = `
                    <div class="user-selector-content">
                        <div class="user-selector-header">
                            <h3 style="margin: 0; font-size: 20px; color: #1f2937;">
                                <i class="fas fa-users" style="color: #667eea; margin-right: 10px;"></i>
                                Select User
                            </h3>
                            <button class="btn btn-secondary" style="padding: 6px 12px; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; min-width: auto; flex: none;" onclick="closeUserSelectionModal()">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="user-selector-body">
                            <div class="user-selector-search">
                                <i class="fas fa-search"></i>
                                <input type="text" id="user-selector-input" placeholder="Search by name, email, or role...">
                            </div>
                            <div id="user-selector-grid" class="user-selector-grid">
                                <div class="loading">
                                    <i class="fas fa-spinner fa-spin"></i> Loading users...
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(modal);
                
                // Close on click outside
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) closeUserSelectionModal();
                });
            }
            
            // Reset search input
            const searchInput = document.getElementById('user-selector-input');
            if (searchInput) searchInput.value = '';
            
            // Show modal with animation frame to ensure smooth transition
            requestAnimationFrame(() => {
                modal.classList.add('active');
                if (searchInput) setTimeout(() => searchInput.focus(), 50);
            });
            
            // Load users
            try {
                let users = [];
                
                // Use cache if available
                if (window.userSelectorCache && window.userSelectorCache.length > 0) {
                    users = [...window.userSelectorCache];
                } else if (typeof allUsers !== 'undefined' && allUsers && allUsers.length > 0) {
                    // Use global allUsers if available
                    users = [...allUsers];
                    window.userSelectorCache = users;
                } else {
                    // Fetch if no cache
                    const response = await fetch('profile/api.php?action=get_all_users' + (options.excludeAdmins ? '&exclude_admins=true' : ''));
                    const data = await response.json();
                    
                    if (data.success) {
                        users = data.data;
                        window.userSelectorCache = users;
                    } else {
                        throw new Error(data.error || 'Failed to load users');
                    }
                }
                
                // Filter if needed (e.g. exclude admins option)
                if (options.excludeAdmins) {
                    users = users.filter(u => u.role !== 'admin');
                }

                // Add "All Users" option if requested
                if (options.includeAllOption) {
                    // Check if already added to avoid duplicates in cache
                    if (!users.some(u => u.id === 'all')) {
                        users = [{
                            id: 'all',
                            username: 'All Users',
                            first_name: 'All',
                            last_name: 'Users',
                            role: 'System',
                            email: 'View all activities',
                            profile_picture: null,
                            is_all_option: true
                        }, ...users];
                    }
                }
                
                renderUserGrid(users, onSelectCallback, triggerButton);
                
                // Setup search with debounce
                const handleSearch = debounce((e) => {
                    const term = e.target.value.toLowerCase();
                    requestAnimationFrame(() => {
                        const filtered = users.filter(u => 
                            (u.username && u.username.toLowerCase().includes(term)) || 
                            (u.email && u.email.toLowerCase().includes(term)) ||
                            (u.first_name && u.first_name.toLowerCase().includes(term)) ||
                            (u.last_name && u.last_name && u.last_name.toLowerCase().includes(term)) ||
                            (u.role && u.role.toLowerCase().includes(term))
                        );
                        renderUserGrid(filtered, onSelectCallback, triggerButton);
                    });
                }, 150);
                
                // Replace input to clear old listeners
                const oldInput = document.getElementById('user-selector-input');
                const newInput = oldInput.cloneNode(true);
                oldInput.parentNode.replaceChild(newInput, oldInput);
                newInput.addEventListener('input', handleSearch);

            } catch (error) {
                console.error('Error loading users for modal:', error);
                document.getElementById('user-selector-grid').innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Error loading users</p>
                    </div>
                `;
            }
        }

        function closeUserSelectionModal() {
            const modal = document.getElementById('user-selector-modal');
            if (modal) {
                modal.classList.remove('active');
                // Keep in DOM for performance
            }
        }

        function renderUserGrid(users, onSelectCallback, triggerButton) {
            const grid = document.getElementById('user-selector-grid');
            if (!users.length) {
                grid.innerHTML = `
                    <div class="empty-state" style="grid-column: 1/-1; padding: 40px;">
                        <i class="fas fa-search" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <p>No users found matching your search</p>
                    </div>
                `;
                return;
            }
            
            // Limit to first 50 users for performance
            const displayUsers = users.slice(0, 50);
            const hasMore = users.length > 50;
            
            // Use a single HTML string update
            grid.innerHTML = displayUsers.map((user, index) => `
                <div class="user-selector-card" data-index="${index}">
                    <div class="user-selector-avatar" style="${user.profile_picture ? `background-image: url('../../${user.profile_picture}'); background-size: cover;` : ''}">
                        ${!user.profile_picture ? getInitials(user.first_name || user.username, user.last_name) : ''}
                    </div>
                    <div style="font-weight: 600; color: #1f2937; margin-bottom: 4px;">
                        ${escapeHtml(user.first_name || user.username)} ${escapeHtml(user.last_name || '')}
                    </div>
                    <div style="font-size: 12px; color: #6b7280; margin-bottom: 8px;">
                        ${escapeHtml(user.email || '')}
                    </div>
                    <span class="user-role role-${(user.role || 'staff').toLowerCase()}" style="font-size: 11px; padding: 2px 8px;">
                        ${user.role || 'Staff'}
                    </span>
                </div>
            `).join('') + (hasMore ? `
                <div style="grid-column: 1/-1; text-align: center; padding: 15px; color: #6b7280; font-size: 13px; background: #f9fafb; border-radius: 8px; margin-top: 10px;">
                    <i class="fas fa-info-circle"></i> Showing top 50 results. Use the search bar to find specific users.
                </div>
            ` : '');
            
            // Use Event Delegation: Single listener on the grid container
            grid.onclick = (e) => {
                const card = e.target.closest('.user-selector-card');
                if (card) {
                    const index = parseInt(card.dataset.index);
                    const user = displayUsers[index];
                    
                    if (user) {
                        // Update trigger button text
                        if (triggerButton) {
                            const textSpan = triggerButton.querySelector('.selected-user-text');
                            if (textSpan) textSpan.textContent = user.username;
                        }
                        
                        // Call callback
                        if (onSelectCallback) onSelectCallback(user.id);
                        
                        closeUserSelectionModal();
                    }
                }
            };
        }

        // Debounce helper
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                const context = this;
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(context, args), wait);
            };
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Load initial tab content
            const activeTab = document.querySelector('.tab.active');
            if (activeTab) {
                const tabName = activeTab.getAttribute('onclick').match(/'([^']+)'/)[1];
                if (tabName === 'users') {
                    loadUsers();
                } else if (tabName === 'activities') {
                    loadActivities();
                } else if (tabName === 'permissions') {
                    loadPermissions();
                } else if (tabName === 'store-access') {
                    loadStoreAccess();
                }
            } else {
                // Default to users if no active tab
                loadUsers();
            }
        });
    </script>
    <!-- Static Create User Modal -->
    <div id="static-create-user-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 10000;">
        <div style="background: white; border-radius: 16px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 24px; border-radius: 16px 16px 0 0; color: white;">
                <h3 style="margin: 0; font-size: 22px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-user-plus"></i> Create New User
                </h3>
                <p style="margin: 8px 0 0 0; opacity: 0.9; font-size: 14px;">Add a new user to the system</p>
            </div>
            
            <form id="static-create-user-form" style="padding: 24px;">
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
                    <button type="button" onclick="closeStaticCreateUserModal()" class="btn btn-secondary" style="flex: 1;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" id="create-user-submit-btn" class="btn btn-primary" style="flex: 1;">
                        <i class="fas fa-check"></i> Create User
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Static Edit User Modal -->
    <div id="static-edit-user-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 10000;">
        <div style="background: white; border-radius: 16px; max-width: 500px; width: 90%; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
            <div style="background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%); padding: 24px; border-radius: 16px 16px 0 0; color: white;">
                <h3 style="margin: 0; font-size: 22px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-user-edit"></i> Edit User
                </h3>
                <p style="margin: 8px 0 0 0; opacity: 0.9; font-size: 14px;">Update user information</p>
            </div>
            
            <form id="static-edit-user-form" style="padding: 24px;">
                <input type="hidden" name="user_id" id="edit-user-id">
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                        <i class="fas fa-user"></i> Username <span style="color: #ef4444;">*</span>
                    </label>
                    <input type="text" name="username" id="edit-username" required style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                </div>
                
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                        <i class="fas fa-envelope"></i> Email <span style="color: #ef4444;">*</span>
                    </label>
                    <input type="email" name="email" id="edit-email" required style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                    <div>
                        <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                            <i class="fas fa-id-badge"></i> First Name
                        </label>
                        <input type="text" name="first_name" id="edit-first-name" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 6px; font-weight: 600; color: #374151;">
                            Last Name
                        </label>
                        <input type="text" name="last_name" id="edit-last-name" style="width: 100%; padding: 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;">
                    </div>
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
                    <div style="position: relative;">
                        <input type="password" name="password" id="edit-password-input" style="width: 100%; padding: 10px 40px 10px 10px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 14px;" placeholder="Leave blank to keep current">
                        <button type="button" onclick="togglePasswordField('edit-password-input', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: none; cursor: pointer; color: #667eea; padding: 5px;" title="Show/Hide Password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px;">
                    <button type="button" onclick="closeStaticEditUserModal()" style="padding: 10px 20px; border: 1px solid #e5e7eb; background: white; color: #374151; border-radius: 8px; font-weight: 600; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit" id="static-edit-submit-btn" style="padding: 10px 20px; background: linear-gradient(135deg, #f59e0b 0%, #ef4444 100%); color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
