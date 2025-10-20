<?php
/**
 * User Roles & Permissions Management System
 * Complete permission management interface with role-based access control
 */

// Enable output buffering and compression
ob_start();
if (extension_loaded('zlib')) {
    ini_set('zlib.output_compression', 'On');
}

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
$currentUserId = $_SESSION['user_id'];

// Get user info for permission checking
$currentUser = $db->read('users', $currentUserId);
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

// Only admins can manage permissions
if (!$isAdmin) {
    header('Location: ../../index.php');
    exit;
}

// Handle POST actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_user_role':
            $userId = $_POST['user_id'] ?? '';
            $role = $_POST['role'] ?? '';
            $customPermissions = $_POST['custom_permissions'] ?? [];
            
            if (empty($userId) || empty($role)) {
                $message = 'User and role selection required';
                $messageType = 'error';
                break;
            }
            
            try {
                // Validate role
                $validRoles = ['user', 'manager', 'admin'];
                if (!in_array(strtolower($role), $validRoles)) {
                    $message = 'Invalid role selected';
                    $messageType = 'error';
                    break;
                }
                
                // Update user role
                $update = [
                    'role' => strtolower($role),
                    'updated_at' => date('c')
                ];
                
                // Add custom permission overrides
                if (!empty($customPermissions)) {
                    $update['permission_overrides'] = json_encode($customPermissions);
                }
                
                $result = $db->update('users', $userId, $update);
                
                if ($result) {
                    // Log the action
                    $db->create('user_activities', [
                        'user_id' => $currentUserId,
                        'action_type' => 'permission_changed',
                        'description' => "Changed role to {$role} for user ID {$userId}",
                        'metadata' => json_encode([
                            'user_id' => $userId,
                            'new_role' => $role,
                            'custom_permissions' => $customPermissions
                        ]),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'created_at' => date('c')
                    ]);
                    
                    $message = 'User role and permissions updated successfully';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update user role';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                error_log('Update role error: ' . $e->getMessage());
                $message = 'An error occurred while updating permissions';
                $messageType = 'error';
            }
            break;
            
        case 'bulk_update':
            $userIds = $_POST['user_ids'] ?? [];
            $bulkRole = $_POST['bulk_role'] ?? '';
            
            if (empty($userIds) || empty($bulkRole)) {
                $message = 'Please select users and a role';
                $messageType = 'error';
                break;
            }
            
            try {
                $count = 0;
                foreach ($userIds as $userId) {
                    $result = $db->update('users', $userId, [
                        'role' => strtolower($bulkRole),
                        'updated_at' => date('c')
                    ]);
                    if ($result) $count++;
                }
                
                // Log bulk action
                $db->create('user_activities', [
                    'user_id' => $currentUserId,
                    'action_type' => 'bulk_permission_change',
                    'description' => "Bulk updated {$count} users to role {$bulkRole}",
                    'metadata' => json_encode([
                        'affected_users' => $userIds,
                        'new_role' => $bulkRole
                    ]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'created_at' => date('c')
                ]);
                
                $message = "Successfully updated {$count} user(s)";
                $messageType = 'success';
            } catch (Exception $e) {
                error_log('Bulk update error: ' . $e->getMessage());
                $message = 'An error occurred during bulk update';
                $messageType = 'error';
            }
            break;
            
        case 'reset_permissions':
            $userId = $_POST['user_id'] ?? '';
            
            if (empty($userId)) {
                $message = 'User selection required';
                $messageType = 'error';
                break;
            }
            
            try {
                $result = $db->update('users', $userId, [
                    'permission_overrides' => null,
                    'updated_at' => date('c')
                ]);
                
                if ($result) {
                    $db->create('user_activities', [
                        'user_id' => $currentUserId,
                        'action_type' => 'permissions_reset',
                        'description' => "Reset custom permissions for user ID {$userId}",
                        'metadata' => json_encode(['user_id' => $userId]),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'created_at' => date('c')
                    ]);
                    
                    $message = 'User permissions reset to role defaults';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to reset permissions';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                error_log('Reset permissions error: ' . $e->getMessage());
                $message = 'An error occurred while resetting permissions';
                $messageType = 'error';
            }
            break;
    }
}

// Fetch all users with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$search = $_GET['search'] ?? '';
$roleFilter = $_GET['role_filter'] ?? '';

try {
    // Build filter conditions
    $conditions = [];
    if (!empty($search)) {
        // Note: This is a simplified search, adjust based on your DB class capabilities
        $allUsers = $db->readAll('users');
        $filteredUsers = array_filter($allUsers, function($user) use ($search) {
            $searchLower = strtolower($search);
            return stripos($user['username'] ?? '', $searchLower) !== false ||
                   stripos($user['email'] ?? '', $searchLower) !== false ||
                   stripos($user['first_name'] ?? '', $searchLower) !== false ||
                   stripos($user['last_name'] ?? '', $searchLower) !== false;
        });
    } else {
        $filteredUsers = $db->readAll('users');
    }
    
    // Apply role filter
    if (!empty($roleFilter)) {
        $filteredUsers = array_filter($filteredUsers, function($user) use ($roleFilter) {
            return strtolower($user['role'] ?? 'user') === strtolower($roleFilter);
        });
    }
    
    // Sort by username
    usort($filteredUsers, function($a, $b) {
        return strcmp($a['username'] ?? '', $b['username'] ?? '');
    });
    
    $totalUsers = count($filteredUsers);
    $totalPages = ceil($totalUsers / $perPage);
    $offset = ($page - 1) * $perPage;
    $users = array_slice($filteredUsers, $offset, $perPage);
    
} catch (Exception $e) {
    error_log('Fetch users error: ' . $e->getMessage());
    $users = [];
    $totalUsers = 0;
    $totalPages = 1;
}

// Get role statistics
$roleStats = [
    'admin' => 0,
    'manager' => 0,
    'user' => 0
];

try {
    $allUsers = $db->readAll('users');
    foreach ($allUsers as $u) {
        $role = strtolower($u['role'] ?? 'user');
        if (isset($roleStats[$role])) {
            $roleStats[$role]++;
        }
    }
} catch (Exception $e) {
    error_log('Role stats error: ' . $e->getMessage());
}

// Available permissions definitions
$availablePermissions = [
    'view_reports' => [
        'name' => 'View Reports',
        'description' => 'Access reporting and analytics dashboards',
        'icon' => 'chart-bar',
        'category' => 'Reports'
    ],
    'manage_inventory' => [
        'name' => 'Manage Inventory',
        'description' => 'Add, edit, and delete inventory items',
        'icon' => 'boxes',
        'category' => 'Inventory'
    ],
    'manage_users' => [
        'name' => 'Manage Users',
        'description' => 'Create, edit, and manage user accounts',
        'icon' => 'users',
        'category' => 'Administration'
    ],
    'manage_stores' => [
        'name' => 'Manage Stores',
        'description' => 'Manage store locations and assignments',
        'icon' => 'store',
        'category' => 'Stores'
    ],
    'configure_system' => [
        'name' => 'System Configuration',
        'description' => 'Access system settings and configurations',
        'icon' => 'cog',
        'category' => 'Administration'
    ],
    'manage_pos' => [
        'name' => 'Manage POS',
        'description' => 'Access and manage point-of-sale terminals',
        'icon' => 'cash-register',
        'category' => 'Sales'
    ],
    'view_analytics' => [
        'name' => 'View Analytics',
        'description' => 'Access advanced analytics and insights',
        'icon' => 'chart-line',
        'category' => 'Reports'
    ],
    'manage_alerts' => [
        'name' => 'Manage Alerts',
        'description' => 'Configure and manage system alerts',
        'icon' => 'bell',
        'category' => 'System'
    ]
];

$page_title = 'User Roles & Permissions Management';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        .page-header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-header p {
            font-size: 1.1rem;
            opacity: 0.95;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border-left: 5px solid;
        }
        
        .stat-card.admin { border-left-color: #e74c3c; }
        .stat-card.manager { border-left-color: #3498db; }
        .stat-card.user { border-left-color: #95a5a6; }
        .stat-card.total { border-left-color: #2ecc71; }
        
        .stat-card h3 {
            font-size: 1rem;
            color: #7f8c8d;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-card .value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .card h2 {
            font-size: 1.5rem;
            margin-bottom: 20px;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 15px;
            border-bottom: 3px solid #667eea;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
        }
        
        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .search-box {
            display: flex;
            gap: 10px;
            flex: 1;
            min-width: 300px;
        }
        
        .search-box input {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .search-box input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .filter-group select {
            padding: 12px 20px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            text-decoration: none;
            color: white;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #95a5a6;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-danger {
            background: #e74c3c;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }
        
        .users-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .users-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .users-table thead th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }
        
        .users-table tbody tr {
            border-bottom: 1px solid #ecf0f1;
            transition: all 0.3s;
        }
        
        .users-table tbody tr:hover {
            background: #f8f9fa;
            transform: scale(1.01);
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        }
        
        .users-table tbody td {
            padding: 18px 15px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.2rem;
        }
        
        .user-details h4 {
            font-size: 1rem;
            color: #2c3e50;
            margin-bottom: 3px;
        }
        
        .user-details p {
            font-size: 0.85rem;
            color: #7f8c8d;
        }
        
        .role-badge {
            padding: 6px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-badge.admin {
            background: #fee;
            color: #e74c3c;
        }
        
        .role-badge.manager {
            background: #e3f2fd;
            color: #3498db;
        }
        
        .role-badge.user {
            background: #f5f5f5;
            color: #95a5a6;
        }
        
        .permissions-summary {
            font-size: 0.9rem;
            color: #7f8c8d;
        }
        
        .permission-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #e8f5e9;
            color: #27ae60;
            border-radius: 5px;
            font-size: 0.75rem;
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .checkbox-cell {
            text-align: center;
        }
        
        .checkbox-cell input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
        }
        
        .pagination a,
        .pagination span {
            padding: 10px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            text-decoration: none;
            color: #2c3e50;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .pagination .active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 35px;
            border-radius: 20px;
            max-width: 700px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 3px solid #667eea;
        }
        
        .modal-header h3 {
            font-size: 1.8rem;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .close-modal {
            font-size: 2rem;
            cursor: pointer;
            color: #95a5a6;
            background: none;
            border: none;
            padding: 0;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
        }
        
        .close-modal:hover {
            background: #ecf0f1;
            color: #2c3e50;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 10px;
            color: #2c3e50;
            font-size: 1rem;
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s;
        }
        
        .form-group select:focus,
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .permission-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .permission-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
        }
        
        .permission-card label {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            cursor: pointer;
        }
        
        .permission-card input[type="checkbox"] {
            width: 22px;
            height: 22px;
            margin-top: 2px;
            cursor: pointer;
        }
        
        .permission-info h4 {
            font-size: 1rem;
            color: #2c3e50;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .permission-info p {
            font-size: 0.85rem;
            color: #7f8c8d;
            line-height: 1.4;
        }
        
        .permission-category {
            font-size: 0.75rem;
            color: #667eea;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
        }
        
        .bulk-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .bulk-actions select {
            padding: 10px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .page-header {
                padding: 25px;
            }
            
            .page-header h1 {
                font-size: 1.8rem;
            }
            
            .toolbar {
                flex-direction: column;
            }
            
            .search-box {
                width: 100%;
            }
            
            .users-table {
                font-size: 0.85rem;
            }
            
            .users-table thead {
                display: none;
            }
            
            .users-table tbody tr {
                display: block;
                margin-bottom: 15px;
                border: 1px solid #ecf0f1;
                border-radius: 10px;
                padding: 15px;
            }
            
            .users-table tbody td {
                display: block;
                text-align: left;
                padding: 8px 0;
            }
            
            .users-table tbody td:before {
                content: attr(data-label);
                font-weight: 600;
                display: block;
                margin-bottom: 5px;
                color: #667eea;
            }
            
            .modal-content {
                padding: 20px;
                width: 95%;
            }
            
            .permissions-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require_once '../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <i class="fas fa-shield-alt"></i>
                User Roles & Permissions
            </h1>
            <p>Comprehensive user permission management system</p>
        </div>
        
        <!-- Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <h3>Total Users</h3>
                <div class="value"><?php echo $totalUsers; ?></div>
            </div>
            <div class="stat-card admin">
                <h3>Administrators</h3>
                <div class="value"><?php echo $roleStats['admin']; ?></div>
            </div>
            <div class="stat-card manager">
                <h3>Managers</h3>
                <div class="value"><?php echo $roleStats['manager']; ?></div>
            </div>
            <div class="stat-card user">
                <h3>Regular Users</h3>
                <div class="value"><?php echo $roleStats['user']; ?></div>
            </div>
        </div>
        
        <!-- Users Management Card -->
        <div class="card">
            <h2>
                <i class="fas fa-users"></i>
                Manage Users
            </h2>
            
            <!-- Toolbar -->
            <div class="toolbar">
                <form class="search-box" method="GET" action="">
                    <input type="text" 
                           name="search" 
                           placeholder="Search users by name, email, or username..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                        Search
                    </button>
                </form>
                
                <div class="filter-group">
                    <select name="role_filter" id="roleFilter" onchange="applyFilter()">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="manager" <?php echo $roleFilter === 'manager' ? 'selected' : ''; ?>>Manager</option>
                        <option value="user" <?php echo $roleFilter === 'user' ? 'selected' : ''; ?>>User</option>
                    </select>
                </div>
            </div>
            
            <!-- Bulk Actions -->
            <div class="bulk-actions" style="display: none;" id="bulkActions">
                <span id="selectedCount">0 selected</span>
                <form method="POST" action="" style="display: flex; gap: 10px; align-items: center;" id="bulkForm">
                    <input type="hidden" name="action" value="bulk_update">
                    <input type="hidden" name="user_ids" id="bulkUserIds">
                    <select name="bulk_role" required>
                        <option value="">Change role to...</option>
                        <option value="user">User</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-check"></i> Apply
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </form>
            </div>
            
            <!-- Users Table -->
            <?php if (!empty($users)): ?>
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                            </th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Permissions</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td class="checkbox-cell" data-label="Select">
                                    <input type="checkbox" 
                                           class="user-checkbox" 
                                           value="<?php echo htmlspecialchars($user['id']); ?>"
                                           onchange="updateBulkActions()">
                                </td>
                                <td data-label="User">
                                    <div class="user-info">
                                        <div class="user-avatar">
                                            <?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?>
                                        </div>
                                        <div class="user-details">
                                            <h4><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></h4>
                                            <p><?php echo htmlspecialchars($user['email'] ?? ''); ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Role">
                                    <span class="role-badge <?php echo strtolower($user['role'] ?? 'user'); ?>">
                                        <?php echo htmlspecialchars(ucfirst($user['role'] ?? 'user')); ?>
                                    </span>
                                </td>
                                <td data-label="Permissions">
                                    <div class="permissions-summary">
                                        <?php
                                        $userRole = strtolower($user['role'] ?? 'user');
                                        $permCount = 0;
                                        
                                        if ($userRole === 'admin') {
                                            $permCount = count($availablePermissions);
                                            echo '<span class="permission-badge">All Permissions</span>';
                                        } elseif ($userRole === 'manager') {
                                            $permCount = 4;
                                            echo '<span class="permission-badge">4 Permissions</span>';
                                        } else {
                                            $permCount = 1;
                                            echo '<span class="permission-badge">1 Permission</span>';
                                        }
                                        
                                        // Check for custom overrides
                                        if (!empty($user['permission_overrides'])) {
                                            echo ' <span style="color: #f39c12;"><i class="fas fa-star"></i> Custom</span>';
                                        }
                                        ?>
                                    </div>
                                </td>
                                <td data-label="Last Updated">
                                    <?php 
                                    $updated = $user['updated_at'] ?? $user['created_at'] ?? '';
                                    if ($updated) {
                                        $date = new DateTime($updated);
                                        echo $date->format('M d, Y');
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td data-label="Actions">
                                    <div class="action-buttons">
                                        <button type="button" 
                                                class="btn btn-primary btn-sm" 
                                                onclick="editUser('<?php echo htmlspecialchars($user['id']); ?>')">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <?php if (!empty($user['permission_overrides'])): ?>
                                            <form method="POST" action="" style="display: inline;">
                                                <input type="hidden" name="action" value="reset_permissions">
                                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                                <button type="submit" 
                                                        class="btn btn-secondary btn-sm"
                                                        onclick="return confirm('Reset custom permissions?')">
                                                    <i class="fas fa-undo"></i> Reset
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&role_filter=<?php echo urlencode($roleFilter); ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role_filter=<?php echo urlencode($roleFilter); ?>" 
                               class="<?php echo $i === $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&role_filter=<?php echo urlencode($roleFilter); ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <h3>No Users Found</h3>
                    <p>Try adjusting your search or filter criteria</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Edit User Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-user-edit"></i>
                    Edit User Permissions
                </h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            
            <form method="POST" action="" id="editForm">
                <input type="hidden" name="action" value="update_user_role">
                <input type="hidden" name="user_id" id="editUserId">
                
                <div class="form-group">
                    <label for="editRole">User Role</label>
                    <select name="role" id="editRole" required onchange="updatePermissionPreview()">
                        <option value="">-- Select Role --</option>
                        <option value="user">User - Basic Access</option>
                        <option value="manager">Manager - Extended Access</option>
                        <option value="admin">Administrator - Full Access</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Custom Permission Overrides</label>
                    <p style="color: #7f8c8d; margin-bottom: 15px; font-size: 0.9rem;">
                        Select additional permissions beyond the role's default permissions
                    </p>
                    
                    <div class="permissions-grid">
                        <?php 
                        $categories = [];
                        foreach ($availablePermissions as $key => $perm) {
                            $categories[$perm['category']][] = ['key' => $key, 'perm' => $perm];
                        }
                        
                        foreach ($categories as $category => $perms): 
                        ?>
                            <div style="grid-column: 1 / -1;">
                                <div class="permission-category"><?php echo htmlspecialchars($category); ?></div>
                            </div>
                            <?php foreach ($perms as $item): ?>
                                <div class="permission-card">
                                    <label>
                                        <input type="checkbox" 
                                               name="custom_permissions[<?php echo htmlspecialchars($item['key']); ?>]" 
                                               value="1"
                                               class="permission-checkbox"
                                               data-permission="<?php echo htmlspecialchars($item['key']); ?>">
                                        <div class="permission-info">
                                            <h4>
                                                <i class="fas fa-<?php echo htmlspecialchars($item['perm']['icon']); ?>"></i>
                                                <?php echo htmlspecialchars($item['perm']['name']); ?>
                                            </h4>
                                            <p><?php echo htmlspecialchars($item['perm']['description']); ?></p>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Store user data for editing
        const userData = <?php echo json_encode($users, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        
        // Apply filter
        function applyFilter() {
            const roleFilter = document.getElementById('roleFilter').value;
            const search = new URLSearchParams(window.location.search).get('search') || '';
            window.location.href = `?role_filter=${roleFilter}&search=${encodeURIComponent(search)}`;
        }
        
        // Toggle select all
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.user-checkbox');
            checkboxes.forEach(cb => cb.checked = checkbox.checked);
            updateBulkActions();
        }
        
        // Update bulk actions visibility
        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.user-checkbox:checked');
            const bulkActions = document.getElementById('bulkActions');
            const selectedCount = document.getElementById('selectedCount');
            const bulkUserIds = document.getElementById('bulkUserIds');
            
            if (checkboxes.length > 0) {
                bulkActions.style.display = 'flex';
                selectedCount.textContent = `${checkboxes.length} selected`;
                
                const ids = Array.from(checkboxes).map(cb => cb.value);
                bulkUserIds.value = JSON.stringify(ids);
            } else {
                bulkActions.style.display = 'none';
            }
        }
        
        // Clear selection
        function clearSelection() {
            document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
            updateBulkActions();
        }
        
        // Edit user
        function editUser(userId) {
            const user = userData.find(u => u.id === userId);
            if (!user) {
                alert('User not found');
                return;
            }
            
            document.getElementById('editUserId').value = userId;
            document.getElementById('editRole').value = user.role || 'user';
            
            // Clear all checkboxes
            document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = false);
            
            // Check custom permissions
            if (user.permission_overrides) {
                const overrides = typeof user.permission_overrides === 'string' 
                    ? JSON.parse(user.permission_overrides) 
                    : user.permission_overrides;
                    
                Object.keys(overrides).forEach(key => {
                    const checkbox = document.querySelector(`[data-permission="${key}"]`);
                    if (checkbox) checkbox.checked = true;
                });
            }
            
            updatePermissionPreview();
            document.getElementById('editModal').classList.add('active');
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        // Update permission preview based on role
        function updatePermissionPreview() {
            const role = document.getElementById('editRole').value;
            const permissionCards = document.querySelectorAll('.permission-card');
            
            // Default permissions by role
            const defaultPermissions = {
                'admin': ['view_reports', 'manage_inventory', 'manage_users', 'manage_stores', 'configure_system', 'manage_pos', 'view_analytics', 'manage_alerts'],
                'manager': ['view_reports', 'manage_inventory', 'manage_stores', 'manage_pos'],
                'user': ['view_reports']
            };
            
            // Highlight default permissions
            permissionCards.forEach(card => {
                const checkbox = card.querySelector('.permission-checkbox');
                const permKey = checkbox.dataset.permission;
                
                if (defaultPermissions[role] && defaultPermissions[role].includes(permKey)) {
                    card.style.background = '#e8f5e9';
                    card.style.borderColor = '#4caf50';
                } else {
                    card.style.background = '#f8f9fa';
                    card.style.borderColor = '#e0e0e0';
                }
            });
        }
        
        // Close modal on outside click
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Handle bulk form submission
        document.getElementById('bulkForm').addEventListener('submit', function(e) {
            const selected = document.querySelectorAll('.user-checkbox:checked').length;
            if (!confirm(`Update role for ${selected} user(s)?`)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
