<?php
/**
 * User Permissions Management Module
 * Comprehensive role and permission management system
 */

// Enable output buffering and compression for faster page delivery
ob_start();
if (extension_loaded('zlib')) {
    ini_set('zlib.output_compression', 'On');
}

require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

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

// Non-admin users cannot access this page
if (!$isAdmin) {
    header('Location: ../index.php');
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
            $roleId = $_POST['role_id'] ?? '';
            
            if (empty($userId) || empty($roleId)) {
                $message = 'User and role selection required';
                $messageType = 'error';
                break;
            }
            
            try {
                // Get role name
                $role = $db->read('roles', $roleId);
                $roleName = $role ? strtolower($role['role_name'] ?? '') : '';
                
                $update = [
                    'role_id' => $roleId,
                    'role' => $roleName,
                    'updated_at' => date('c')
                ];
                
                // Handle permission overrides
                $permOverrides = [];
                if (!empty($_POST['perm_override']) && is_array($_POST['perm_override'])) {
                    foreach ($_POST['perm_override'] as $k => $v) {
                        $permOverrides[$k] = true;
                    }
                    $update['permission_overrides'] = $permOverrides;
                }
                
                $result = $db->update('users', $userId, $update);
                
                if ($result) {
                    // Log the action
                    $db->create('user_activities', [
                        'user_id' => $currentUserId,
                        'action_type' => 'permission_changed',
                        'description' => "Updated role for user {$userId}",
                        'metadata' => json_encode(['role_id' => $roleId, 'overrides' => $permOverrides]),
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
            
        case 'create_role':
            $roleName = trim($_POST['role_name'] ?? '');
            $permissions = $_POST['permissions'] ?? [];
            
            if (empty($roleName)) {
                $message = 'Role name is required';
                $messageType = 'error';
                break;
            }
            
            try {
                $roleData = [
                    'role_name' => $roleName,
                    'permissions' => json_encode($permissions),
                    'created_at' => date('c'),
                    'updated_at' => date('c')
                ];
                
                $roleId = $db->create('roles', $roleData);
                
                if ($roleId) {
                    // Log the action
                    $db->create('user_activities', [
                        'user_id' => $currentUserId,
                        'action_type' => 'role_created',
                        'description' => "Created new role: {$roleName}",
                        'metadata' => json_encode(['role_name' => $roleName, 'permissions' => $permissions]),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'created_at' => date('c')
                    ]);
                    
                    $message = "Role '{$roleName}' created successfully";
                    $messageType = 'success';
                } else {
                    $message = 'Failed to create role';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                error_log('Create role error: ' . $e->getMessage());
                $message = 'An error occurred while creating role';
                $messageType = 'error';
            }
            break;
            
        case 'update_role':
            $roleId = $_POST['role_id'] ?? '';
            $permissions = $_POST['permissions'] ?? [];
            
            if (empty($roleId)) {
                $message = 'Role selection required';
                $messageType = 'error';
                break;
            }
            
            try {
                $update = [
                    'permissions' => json_encode($permissions),
                    'updated_at' => date('c')
                ];
                
                $result = $db->update('roles', $roleId, $update);
                
                if ($result) {
                    // Log the action
                    $db->create('user_activities', [
                        'user_id' => $currentUserId,
                        'action_type' => 'role_updated',
                        'description' => "Updated role {$roleId}",
                        'metadata' => json_encode(['role_id' => $roleId, 'permissions' => $permissions]),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'created_at' => date('c')
                    ]);
                    
                    $message = 'Role permissions updated successfully';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update role';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                error_log('Update role error: ' . $e->getMessage());
                $message = 'An error occurred while updating role';
                $messageType = 'error';
            }
            break;
    }
}

// Get users with pagination (limit to 50 for performance)
$allUsers = [];
try {
    $allUsers = $db->readAll('users', [], ['first_name', 'ASC'], 50);
} catch (Exception $e) {
    error_log('Fetch users error: ' . $e->getMessage());
}

// Get all roles
$allRoles = [];
try {
    $allRoles = $db->readAll('roles', [], ['role_name', 'ASC']);
} catch (Exception $e) {
    error_log('Fetch roles error: ' . $e->getMessage());
    // Provide default roles
    $allRoles = [
        ['id' => '1', 'role_name' => 'Admin', 'permissions' => json_encode(['view_inventory','manage_inventory','view_reports','manage_users','manage_stores','admin_access'])],
        ['id' => '2', 'role_name' => 'Manager', 'permissions' => json_encode(['view_inventory','manage_inventory','view_reports','manage_stores'])],
        ['id' => '3', 'role_name' => 'User', 'permissions' => json_encode(['view_inventory'])]
    ];
}

// Available permissions
$availablePermissions = [
    'view_inventory' => ['name' => 'View Inventory', 'description' => 'Can view inventory items', 'icon' => 'boxes'],
    'manage_inventory' => ['name' => 'Manage Inventory', 'description' => 'Can add, edit, and delete inventory items', 'icon' => 'edit'],
    'view_reports' => ['name' => 'View Reports', 'description' => 'Can access reporting dashboard', 'icon' => 'chart-bar'],
    'manage_users' => ['name' => 'Manage Users', 'description' => 'Can create and manage user accounts', 'icon' => 'users'],
    'manage_stores' => ['name' => 'Manage Stores', 'description' => 'Can manage store locations and access', 'icon' => 'store'],
    'admin_access' => ['name' => 'Admin Access', 'description' => 'Full system administration access', 'icon' => 'crown']
];

// Get selected user for editing
$selectedUserId = $_GET['edit_user'] ?? '';
$selectedUser = null;
$selectedUserPermissions = [];

if (!empty($selectedUserId)) {
    try {
        $selectedUser = $db->read('users', $selectedUserId);
        
        // Get user's role permissions
        $roleId = $selectedUser['role_id'] ?? '';
        if ($roleId) {
            $role = $db->read('roles', $roleId);
            if ($role) {
                $rolePerms = json_decode($role['permissions'] ?? '[]', true);
                $overrides = (array)($selectedUser['permission_overrides'] ?? []);
                
                foreach ($availablePermissions as $key => $perm) {
                    $selectedUserPermissions[$key] = [
                        'name' => $perm['name'],
                        'description' => $perm['description'],
                        'icon' => $perm['icon'],
                        'granted' => in_array($key, $rolePerms) || !empty($overrides[$key]),
                        'override' => !empty($overrides[$key])
                    ];
                }
            }
        }
    } catch (Exception $e) {
        error_log('Fetch selected user error: ' . $e->getMessage());
    }
}

$page_title = 'Permissions Management - Inventory System';
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
        .permissions-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .permissions-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .permissions-header h1 {
            margin: 0 0 10px 0;
            font-size: 2rem;
        }
        
        .section-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .section-card h3 {
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2c3e50;
        }
        
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .user-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .user-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }
        
        .user-card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .user-info h4 {
            margin: 0;
            color: #2c3e50;
        }
        
        .user-role {
            font-size: 13px;
            color: #6b7280;
        }
        
        .role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .role-admin { background: #fce7f3; color: #be185d; }
        .role-manager { background: #dbeafe; color: #1e40af; }
        .role-user { background: #f3f4f6; color: #374151; }
        
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .permission-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }
        
        .permission-card.granted {
            border-color: #4ecdc4;
            background: #f0fdfa;
        }
        
        .permission-card h4 {
            margin: 0 0 10px 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .permission-status {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-granted {
            background: #dcfdf7;
            color: #065f46;
        }
        
        .status-denied {
            background: #fef2f2;
            color: #991b1b;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: 6px;
        }
        
        .checkbox-item:hover {
            background: #f1f5f9;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #f0fdf4;
            border-left: 4px solid #22c55e;
            color: #166534;
        }
        
        .alert-error {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        
        @media (max-width: 768px) {
            .users-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <div class="permissions-container">
            <!-- Header -->
            <div class="permissions-header">
                <h1><i class="fas fa-shield-alt"></i> Permissions Management</h1>
                <p>Manage user roles and permissions across the system</p>
            </div>
            
            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Users List -->
            <div class="section-card">
                <h3><i class="fas fa-users"></i> User Roles</h3>
                <div class="users-grid">
                    <?php foreach ($allUsers as $user): ?>
                        <div class="user-card">
                            <div class="user-card-header">
                                <div class="user-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="user-info">
                                    <h4><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></h4>
                                    <div class="user-role">
                                        <?php 
                                        $userRole = $user['role'] ?? 'user';
                                        echo '<span class="role-badge role-' . htmlspecialchars($userRole) . '">' . 
                                             htmlspecialchars(ucfirst($userRole)) . '</span>';
                                        ?>
                                    </div>
                                </div>
                            </div>
                            <a href="?edit_user=<?php echo htmlspecialchars($user['id']); ?>" class="btn btn-primary" style="width: 100%; justify-content: center;">
                                <i class="fas fa-edit"></i> Edit Permissions
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Edit User Permissions -->
            <?php if ($selectedUser): ?>
                <div class="section-card">
                    <h3>
                        <i class="fas fa-user-edit"></i> 
                        Edit Permissions for <?php echo htmlspecialchars(($selectedUser['first_name'] ?? '') . ' ' . ($selectedUser['last_name'] ?? '')); ?>
                    </h3>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_user_role">
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($selectedUserId); ?>">
                        
                        <div class="form-group">
                            <label for="role_id">Assign Role</label>
                            <select name="role_id" id="role_id" required>
                                <option value="">-- Select Role --</option>
                                <?php foreach ($allRoles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role['id']); ?>" 
                                            <?php echo ($selectedUser['role_id'] ?? '') === $role['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <h4 style="margin: 20px 0 15px 0;">Permission Overrides</h4>
                        <p style="color: #6b7280; margin-bottom: 15px;">Force grant specific permissions regardless of role</p>
                        
                        <div class="permissions-grid">
                            <?php foreach ($selectedUserPermissions as $key => $perm): ?>
                                <div class="permission-card <?php echo $perm['granted'] ? 'granted' : ''; ?>">
                                    <h4>
                                        <i class="fas fa-<?php echo $perm['icon']; ?>"></i>
                                        <?php echo htmlspecialchars($perm['name']); ?>
                                    </h4>
                                    <p style="font-size: 13px; color: #6b7280; margin: 10px 0;"><?php echo htmlspecialchars($perm['description']); ?></p>
                                    <div style="margin-top: 10px;">
                                        <label class="checkbox-item">
                                            <input type="checkbox" name="perm_override[<?php echo htmlspecialchars($key); ?>]" 
                                                   value="1" <?php echo $perm['override'] ? 'checked' : ''; ?>>
                                            Force Grant
                                        </label>
                                        <span class="permission-status <?php echo $perm['granted'] ? 'status-granted' : 'status-denied'; ?>">
                                            <?php echo $perm['granted'] ? 'Granted' : 'Denied'; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 25px; display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <a href="permissions.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- Roles List -->
            <div class="section-card">
                <h3><i class="fas fa-user-tag"></i> Available Roles</h3>
                <?php foreach ($allRoles as $role): ?>
                    <div style="border: 2px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 15px;">
                        <h4 style="margin: 0 0 15px 0;">
                            <?php echo htmlspecialchars($role['role_name']); ?>
                        </h4>
                        <?php 
                        $rolePerms = json_decode($role['permissions'] ?? '[]', true);
                        if (!empty($rolePerms)):
                        ?>
                            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                <?php foreach ($rolePerms as $p): ?>
                                    <span class="permission-status status-granted">
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $p))); ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color: #6b7280; margin: 0;">No permissions assigned</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <script src="../../../assets/js/main.js"></script>
</body>
</html>
