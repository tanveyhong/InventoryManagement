<?php
// Enhanced User Profile Page - Self-Contained with All Functions
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// ==================== CORE CLASSES AND FUNCTIONS ====================

/**
 * User Manager Class - Handles user operations
 */
class UserManager {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function updateUser($userId, $data) {
        try {
            return $this->db->update('users', $userId, $data);
        } catch (Exception $e) {
            error_log("User update error: " . $e->getMessage());
            return false;
        }
    }
    
    public function changePassword($userId, $currentPassword, $newPassword) {
        try {
            // Get current user data
            $user = $this->db->read('users', $userId);
            if (!$user || !verifyPassword($currentPassword, $user['password_hash'])) {
                return false;
            }
            
            $hashedPassword = hashPassword($newPassword);
            return $this->db->update('users', $userId, [
                'password_hash' => $hashedPassword,
                'require_password_change' => false,
                'updated_at' => date('c')
            ]);
        } catch (Exception $e) {
            error_log("Password change error: " . $e->getMessage());
            return false;
        }
    }
    
    public function createUser($userData) {
        try {
            // Check if username or email already exists
            $existingUser = $this->db->readAll('users', [
                ['username', '==', $userData['username']]
            ], null, 1);
            
            if (!empty($existingUser)) {
                return false; // Username already exists
            }
            
            $existingEmail = $this->db->readAll('users', [
                ['email', '==', $userData['email']]
            ], null, 1);
            
            if (!empty($existingEmail)) {
                return false; // Email already exists
            }
            
            $newUser = [
                'username' => $userData['username'],
                'email' => $userData['email'],
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'phone' => $userData['phone'] ?? '',
                'password_hash' => hashPassword($userData['password']),
                'role_id' => $userData['role_id'],
                'require_password_change' => $userData['require_password_change'] ?? true,
                'active' => true,
                'created_at' => date('c'),
                'updated_at' => date('c')
            ];
            
            $userId = $this->db->create('users', $newUser);
            
            // Assign store access if provided
            if ($userId && !empty($userData['store_access'])) {
                foreach ($userData['store_access'] as $storeId) {
                    $this->db->create('user_stores', [
                        'user_id' => $userId,
                        'store_id' => $storeId,
                        'role' => 'employee',
                        'created_at' => date('c')
                    ]);
                }
            }
            
            return $userId;
        } catch (Exception $e) {
            error_log("User creation error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getUserList($limit = 50) {
        try {
            return $this->db->readAll('users', [], ['created_at', 'DESC'], $limit);
        } catch (Exception $e) {
            error_log("Get user list error: " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Role Manager Class - Handles roles and permissions
 */
class RoleManager {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function getUserRole($userId): array {
        try {
            $user = $this->db->read('users', $userId);
            if (!$user || !isset($user['role_id']) || !$user['role_id']) {
                return ['role_name' => 'User', 'permissions' => '[]'];
            }
            $role = $this->db->read('roles', $user['role_id']);
            if (!$role) {
                return ['role_name' => 'User', 'permissions' => '[]'];
            }
            // Ensure permissions is a string
            if (isset($role['permissions']) && is_array($role['permissions'])) {
                $role['permissions'] = json_encode($role['permissions']);
            } elseif (!isset($role['permissions']) || !is_string($role['permissions'])) {
                $role['permissions'] = '[]';
            }
            return $role;
        } catch (Exception $e) {
            error_log("Get user role error: " . $e->getMessage());
            return ['role_name' => 'User', 'permissions' => '[]'];
        }
    }
    
    public function getUserPermissions($userId): array {
        $role = $this->getUserRole($userId);
        $permissionsRaw = $role['permissions'] ?? '[]';
        if (is_array($permissionsRaw)) {
            $permissionsRaw = json_encode($permissionsRaw);
        }
        $permissions = [];
        if (is_string($permissionsRaw)) {
            $decoded = json_decode($permissionsRaw, true);
            if (is_array($decoded)) {
                $permissions = $decoded;
            }
        }
        $permissionsList = [
            'view_inventory' => [
                'name' => 'View Inventory',
                'description' => 'Can view inventory items',
                'icon' => 'boxes',
                'granted' => in_array('view_inventory', $permissions)
            ],
            'manage_inventory' => [
                'name' => 'Manage Inventory',
                'description' => 'Can add, edit, and delete inventory items',
                'icon' => 'edit',
                'granted' => in_array('manage_inventory', $permissions)
            ],
            'view_reports' => [
                'name' => 'View Reports',
                'description' => 'Can access reporting dashboard',
                'icon' => 'chart-bar',
                'granted' => in_array('view_reports', $permissions)
            ],
            'manage_users' => [
                'name' => 'Manage Users',
                'description' => 'Can create and manage user accounts',
                'icon' => 'users',
                'granted' => in_array('manage_users', $permissions)
            ],
            'manage_stores' => [
                'name' => 'Manage Stores',
                'description' => 'Can manage store locations and access',
                'icon' => 'store',
                'granted' => in_array('manage_stores', $permissions)
            ],
            'admin_access' => [
                'name' => 'Admin Access',
                'description' => 'Full system administration access',
                'icon' => 'crown',
                'granted' => in_array('admin_access', $permissions)
            ]
        ];
        
        return $permissionsList;
    }
    
    public function canManageUsers($userId) {
        $permissions = $this->getUserPermissions($userId);
        return $permissions['manage_users']['granted'] || $permissions['admin_access']['granted'];
    }
    
    public function canCreateUsers($userId) {
        return $this->canManageUsers($userId);
    }
    
    public function canManageRoles($userId) {
        $permissions = $this->getUserPermissions($userId);
        return $permissions['admin_access']['granted'];
    }
    
    public function canManageStores($userId) {
        $permissions = $this->getUserPermissions($userId);
        return $permissions['manage_stores']['granted'] || $permissions['admin_access']['granted'];
    }
    
    public function getAllRoles() {
        try {
            return $this->db->readAll('roles', [], ['role_name', 'ASC']);
        } catch (Exception $e) {
            error_log("Get all roles error: " . $e->getMessage());
            return [
                ['id' => 1, 'role_name' => 'Admin'],
                ['id' => 2, 'role_name' => 'Manager'],
                ['id' => 3, 'role_name' => 'Employee']
            ];
        }
    }
}

/**
 * Activity Logger Class - Handles user activity tracking
 */
class ActivityLogger {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function log($userId, $actionType, $description, $metadata = []) {
        try {
            $activity = [
                'user_id' => $userId,
                'action_type' => $actionType,
                'description' => $description,
                'metadata' => json_encode($metadata),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('c')
            ];
            
            return $this->db->create('user_activities', $activity);
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getUserActivity($userId, $limit = 10) {
        try {
            $activities = $this->db->readAll('user_activities', [
                ['user_id', '==', $userId]
            ], ['created_at', 'DESC'], $limit);
            
            return array_map([$this, 'formatActivity'], $activities);
        } catch (Exception $e) {
            error_log("Get user activity error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getAllUserActivity($limit = 20) {
        try {
            $activities = $this->db->readAll('user_activities', [], ['created_at', 'DESC'], $limit);
            
            // Join with user data
            foreach ($activities as &$activity) {
                $user = $this->db->read('users', $activity['user_id']);
                $activity['user_name'] = $user ? ($user['first_name'] . ' ' . $user['last_name']) : 'Unknown User';
            }
            
            return array_map([$this, 'formatActivity'], $activities);
        } catch (Exception $e) {
            error_log("Get all user activity error: " . $e->getMessage());
            return [];
        }
    }
    
    private function formatActivity($activity) {
        $iconMap = [
            'login' => 'sign-in-alt',
            'logout' => 'sign-out-alt',
            'profile_updated' => 'user-edit',
            'password_changed' => 'key',
            'user_created' => 'user-plus',
            'inventory_added' => 'plus-circle',
            'inventory_updated' => 'edit',
            'inventory_deleted' => 'trash'
        ];
        
        $activity['icon'] = $iconMap[$activity['action_type']] ?? 'info-circle';
        $activity['formatted_time'] = $this->timeAgo($activity['created_at']);
        
        return $activity;
    }
    
    private function timeAgo($datetime) {
        $time = time() - strtotime($datetime);
        
        if ($time < 60) return 'just now';
        if ($time < 3600) return floor($time/60) . ' minutes ago';
        if ($time < 86400) return floor($time/3600) . ' hours ago';
        if ($time < 2592000) return floor($time/86400) . ' days ago';
        
        return date('M j, Y', strtotime($datetime));
    }
}

/**
 * Store Router Class - Handles multi-store access
 */
class StoreRouter {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function getUserStores($userId) {
        try {
            // Get all stores
            $allStores = $this->db->readAll('stores', [['active', '==', true]]);
            
            // Get user's store access
            $userStores = $this->db->readAll('user_stores', [
                ['user_id', '==', $userId]
            ]);
            
            $userStoreIds = array_column($userStores, 'store_id');
            $userStoreRoles = array_column($userStores, 'role', 'store_id');
            
            $storeList = [];
            foreach ($allStores as $store) {
                $storeList[] = [
                    'id' => $store['id'],
                    'name' => $store['name'],
                    'location' => $store['address'] ?? 'No address',
                    'accessible' => in_array($store['id'], $userStoreIds),
                    'role' => $userStoreRoles[$store['id']] ?? 'none'
                ];
            }
            
            return $storeList;
        } catch (Exception $e) {
            error_log("Get user stores error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getAllStores() {
        try {
            return $this->db->readAll('stores', [['active', '==', true]], ['name', 'ASC']);
        } catch (Exception $e) {
            error_log("Get all stores error: " . $e->getMessage());
            return [
                ['id' => 1, 'name' => 'Main Store', 'address' => 'Downtown'],
                ['id' => 2, 'name' => 'Branch Store', 'address' => 'Uptown']
            ];
        }
    }
    
    public function assignUserToStore($userId, $storeId, $role = 'employee') {
        try {
            $assignment = [
                'user_id' => $userId,
                'store_id' => $storeId,
                'role' => $role,
                'created_at' => date('c')
            ];
            
            return $this->db->create('user_stores', $assignment);
        } catch (Exception $e) {
            error_log("Assign user to store error: " . $e->getMessage());
            return false;
        }
    }
    
    public function removeUserFromStore($userId, $storeId) {
        try {
            return $this->db->delete('user_stores', [
                ['user_id', '==', $userId],
                ['store_id', '==', $storeId]
            ]);
        } catch (Exception $e) {
            error_log("Remove user from store error: " . $e->getMessage());
            return false;
        }
    }
}

// ==================== FORM HANDLING FUNCTIONS ====================

function handleProfileUpdate($data, $userId, $userManager, $activityLogger) {
    $errors = [];
    
    $first_name = sanitizeInput($data['first_name'] ?? '');
    $last_name = sanitizeInput($data['last_name'] ?? '');
    $email = sanitizeInput($data['email'] ?? '');
    $phone = sanitizeInput($data['phone'] ?? '');
    
    // Validation
    if (empty($first_name)) $errors[] = 'First name is required';
    if (empty($last_name)) $errors[] = 'Last name is required';
    if (empty($email)) $errors[] = 'Email is required';
    elseif (!validateEmail($email)) $errors[] = 'Invalid email format';
    
    if (empty($errors)) {
        $updateData = [
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'updated_at' => date('c')
        ];
        
        if ($userManager->updateUser($userId, $updateData)) {
            $activityLogger->log($userId, 'profile_updated', 'User updated profile information');
            addNotification('Profile updated successfully!', 'success');
            $_SESSION['email'] = $email; // Update session
            return ['success' => true, 'errors' => []];
        } else {
            $errors[] = 'Failed to update profile';
        }
    }
    
    return ['success' => false, 'errors' => $errors];
}

function handlePasswordChange($data, $userId, $userManager, $activityLogger) {
    $errors = [];
    
    $current_password = $data['current_password'] ?? '';
    $new_password = $data['new_password'] ?? '';
    $confirm_password = $data['confirm_password'] ?? '';
    
    if (empty($current_password)) $errors[] = 'Current password is required';
    if (empty($new_password)) $errors[] = 'New password is required';
    elseif (strlen($new_password) < 8) $errors[] = 'New password must be at least 8 characters';
    elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $new_password)) {
        $errors[] = 'Password must contain at least one uppercase letter, one lowercase letter, and one number';
    }
    if ($new_password !== $confirm_password) $errors[] = 'New passwords do not match';
    
    if (empty($errors)) {
        if ($userManager->changePassword($userId, $current_password, $new_password)) {
            $activityLogger->log($userId, 'password_changed', 'User changed password');
            addNotification('Password changed successfully!', 'success');
            return ['success' => true, 'errors' => []];
        } else {
            $errors[] = 'Current password is incorrect or update failed';
        }
    }
    
    return ['success' => false, 'errors' => $errors];
}

function handleUserCreation($data, $userManager, $roleManager, $activityLogger) {
    $errors = [];
    
    $username = sanitizeInput($data['username'] ?? '');
    $email = sanitizeInput($data['email'] ?? '');
    $first_name = sanitizeInput($data['first_name'] ?? '');
    $last_name = sanitizeInput($data['last_name'] ?? '');
    $role_id = (int)($data['role_id'] ?? 0);
    $phone = sanitizeInput($data['phone'] ?? '');
    $password = $data['password'] ?? '';
    $store_access = $data['store_access'] ?? [];
    
    // Validation
    if (empty($username)) $errors[] = 'Username is required';
    if (empty($email)) $errors[] = 'Email is required';
    elseif (!validateEmail($email)) $errors[] = 'Invalid email format';
    if (empty($first_name)) $errors[] = 'First name is required';
    if (empty($last_name)) $errors[] = 'Last name is required';
    if (empty($role_id)) $errors[] = 'Role is required';
    if (empty($password)) $errors[] = 'Password is required';
    elseif (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
    
    if (empty($errors)) {
        $userData = [
            'username' => $username,
            'email' => $email,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'phone' => $phone,
            'password' => $password,
            'role_id' => $role_id,
            'store_access' => $store_access,
            'require_password_change' => true
        ];
        
        $newUserId = $userManager->createUser($userData);
        if ($newUserId) {
            $activityLogger->log($_SESSION['user_id'], 'user_created', "Created new user: $username");
            addNotification("User '$username' created successfully!", 'success');
            return ['success' => true, 'errors' => []];
        } else {
            $errors[] = 'Failed to create user - username or email may already exist';
        }
    }
    
    return ['success' => false, 'errors' => $errors];
}

function handlePermissionUpdate($data, $roleManager, $activityLogger) {
    $errors = [];
    $userId = (int)($data['user_id'] ?? 0);
    $roleId = (int)($data['role_id'] ?? 0);
    
    if (empty($userId) || empty($roleId)) {
        $errors[] = 'User and role selection required';
        return ['success' => false, 'errors' => $errors];
    }
    
    // This would update user role in the database
    // Implementation depends on your specific needs
    $errors[] = 'Permission updates feature coming soon';
    return ['success' => false, 'errors' => $errors];
}

function handleStoreAccessUpdate($data, $storeRouter, $activityLogger) {
    $errors = [];
    $userId = (int)($data['user_id'] ?? 0);
    $storeIds = $data['store_ids'] ?? [];
    
    if (empty($userId)) {
        $errors[] = 'User selection required';
        return ['success' => false, 'errors' => $errors];
    }
    
    // This would update user store access in the database
    // Implementation depends on your specific needs
    $errors[] = 'Store access updates feature coming soon';
    return ['success' => false, 'errors' => $errors];
}

// ==================== MAIN EXECUTION ====================

$rawDb = getDB();

// Lightweight request-scoped cache wrapper to avoid duplicate remote/DB calls
class CachingDB {
    private $inner;
    private $cache = [];

    public function __construct($inner) {
        $this->inner = $inner;
    }

    private function key($method, $args) {
        return md5($method . ':' . serialize($args));
    }

    public function read($collection, $documentId) {
        $k = $this->key('read', [$collection, $documentId]);
        if (array_key_exists($k, $this->cache)) return $this->cache[$k];
        $res = $this->inner->read($collection, $documentId);
        $this->cache[$k] = $res;
        return $res;
    }

    public function readAll($collection, $conditions = [], $orderBy = null, $limit = null) {
        $k = $this->key('readAll', [$collection, $conditions, $orderBy, $limit]);
        if (array_key_exists($k, $this->cache)) return $this->cache[$k];
        $res = $this->inner->readAll($collection, $conditions, $orderBy, $limit);
        $this->cache[$k] = $res;
        return $res;
    }

    // Pass-through for write operations (do not cache)
    public function create($collection, $data, $documentId = null) { return $this->inner->create($collection, $data, $documentId); }
    public function update($collection, $documentId, $data) { return $this->inner->update($collection, $documentId, $data); }
    public function delete($collection, $documentId) { return $this->inner->delete($collection, $documentId); }
}

$db = new CachingDB($rawDb);
$userManager = new UserManager($db);
$roleManager = new RoleManager($db);
$activityLogger = new ActivityLogger($db);
$storeRouter = new StoreRouter($db);

// Load user record using cached read (avoid calling global getUserInfo which may re-query)
$user = $db->read('users', $_SESSION['user_id']);
$errors = [];
$success = false;
$activeTab = $_GET['tab'] ?? 'profile';

// Lazy-load data per tab to avoid unnecessary DB reads on every request
$userPermissions = null;
$userRole = null;
$availableStores = null;
$recentActivity = null;

// If there was a successful POST that changed profile, refresh $user from cached DB
if (isset($result) && !empty($result['success'])) {
    $user = $db->read('users', $_SESSION['user_id']);
}

// Only load heavy lists when the tab requests them
if ($activeTab === 'permissions' || $activeTab === 'admin') {
    $userPermissions = $roleManager->getUserPermissions($_SESSION['user_id']);
    $userRole = $roleManager->getUserRole($_SESSION['user_id']);
}

if ($activeTab === 'stores' || $activeTab === 'admin') {
    $availableStores = $storeRouter->getUserStores($_SESSION['user_id']);
}

if ($activeTab === 'activity' || $activeTab === 'admin') {
    $recentActivity = $activityLogger->getUserActivity($_SESSION['user_id'], 10);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_profile':
            $result = handleProfileUpdate($_POST, $_SESSION['user_id'], $userManager, $activityLogger);
            break;
            
        case 'change_password':
            $result = handlePasswordChange($_POST, $_SESSION['user_id'], $userManager, $activityLogger);
            break;
            
        case 'update_permissions':
            if ($roleManager->canManageRoles($_SESSION['user_id'])) {
                $result = handlePermissionUpdate($_POST, $roleManager, $activityLogger);
            } else {
                $errors[] = 'You do not have permission to update roles';
            }
            break;
            
        case 'update_store_access':
            if ($roleManager->canManageStores($_SESSION['user_id'])) {
                $result = handleStoreAccessUpdate($_POST, $storeRouter, $activityLogger);
            } else {
                $errors[] = 'You do not have permission to update store access';
            }
            break;
            
        case 'create_user':
            if ($roleManager->canCreateUsers($_SESSION['user_id'])) {
                $result = handleUserCreation($_POST, $userManager, $roleManager, $activityLogger);
            } else {
                $errors[] = 'You do not have permission to create users';
            }
            break;
    }
    
    if (isset($result)) {
        $errors = $result['errors'] ?? [];
        $success = $result['success'] ?? false;
        if ($success) {
            $user = getUserInfo($_SESSION['user_id']); // Refresh user data
        }
    }
}

$page_title = 'Enhanced User Profile - Inventory System';
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
        .profile-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 30px;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            border: 4px solid rgba(255,255,255,0.3);
        }
        
        .profile-info h1 {
            margin: 0 0 10px 0;
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .profile-meta {
            display: flex;
            gap: 30px;
            margin-top: 15px;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            opacity: 0.9;
        }
        
        .tab-navigation {
            display: flex;
            gap: 5px;
            margin-bottom: 30px;
            background: white;
            padding: 8px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        
        .tab-btn {
            flex: 1;
            padding: 15px 20px;
            border: none;
            background: transparent;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 500;
            color: #64748b;
        }
        
        .tab-btn.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .section-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .profile-section {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .profile-section:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .section-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            font-size: 1.2rem;
        }
        
        .section-header h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.4rem;
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
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group input:disabled {
            background: #f9fafb;
            color: #6b7280;
        }
        
        .form-group small {
            color: #6b7280;
            font-size: 12px;
            margin-top: 4px;
            display: block;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #4ecdc4, #44a08d);
            color: white;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
            color: white;
        }
        
        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .permission-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
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
        
        .activity-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: white;
        }
        
        .activity-icon.login { background: #4ecdc4; }
        .activity-icon.update { background: #667eea; }
        .activity-icon.create { background: #feca57; }
        .activity-icon.delete { background: #ff6b6b; }
        .activity-icon.profile_updated { background: #667eea; }
        .activity-icon.password_changed { background: #ff9500; }
        .activity-icon.user_created { background: #4ecdc4; }
        
        .activity-details h5 {
            margin: 0 0 5px 0;
            color: #2c3e50;
        }
        
        .activity-time {
            font-size: 12px;
            color: #6b7280;
        }
        
        .store-access-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .store-card {
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .store-card.accessible {
            border-color: #4ecdc4;
            background: #f0fdfa;
        }
        
        .store-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: #667eea;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #f0fdf4;
            border-color: #22c55e;
            color: #166534;
        }
        
        .alert-error {
            background: #fef2f2;
            border-color: #ef4444;
            color: #991b1b;
        }
        
        .user-creation-form {
            background: #f8fafc;
            padding: 25px;
            border-radius: 12px;
            margin-top: 20px;
        }
        
        .user-creation-form h4 {
            margin: 0 0 20px 0;
            color: #2c3e50;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: normal;
            padding: 8px;
            border-radius: 6px;
            transition: background 0.2s ease;
        }
        
        .checkbox-item:hover {
            background: #f1f5f9;
        }
        
        .password-strength {
            margin-top: 5px;
        }
        
        .password-strength-bar {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            margin-bottom: 5px;
            overflow: hidden;
        }
        
        .password-strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
                padding: 25px;
            }
            
            .profile-info h1 {
                font-size: 2rem;
            }
            
            .profile-meta {
                flex-direction: column;
                gap: 10px;
            }
            
            .section-grid {
                grid-template-columns: 1fr;
            }
            
            .tab-navigation {
                flex-direction: column;
                gap: 5px;
            }
            
            .tab-btn {
                justify-content: flex-start;
                padding: 12px 16px;
            }
        }
    </style>
</head>
<body>
    <?php 
        // Provide header variables for the shared header include
        $header_title = trim((($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: ($user['username'] ?? 'User'));
        $header_subtitle = $user['email'] ?? '';
        $header_icon = 'fas fa-user';
        $show_compact_toggle = false;
        $header_stats = [];
        include '../../includes/dashboard_header.php'; 
    ?>
    <div class="container">
        <div class="profile-container">
            <!-- Profile Header -->
            <div class="profile-header">
                <div class="profile-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="profile-info">
                    <?php
                        $user_first = isset($user['first_name']) ? $user['first_name'] : '';
                        $user_last = isset($user['last_name']) ? $user['last_name'] : '';
                        $user_email = isset($user['email']) ? $user['email'] : '';
                        $user_created = isset($user['created_at']) ? $user['created_at'] : null;
                        $user_last_login = isset($user['last_login']) ? $user['last_login'] : null;
                        $user_active = isset($user['active']) ? (bool)$user['active'] : false;
                        $stores_count = is_array($availableStores) ? count($availableStores) : 0;
                    ?>
                    <h1><?php echo htmlspecialchars(trim($user_first . ' ' . $user_last) ?: 'User'); ?></h1>
                    <div class="profile-meta">
                        <div class="meta-item">
                            <i class="fas fa-user-tag"></i>
                            <?php echo htmlspecialchars($userRole['role_name'] ?? 'User'); ?>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-envelope"></i>
                            <?php echo htmlspecialchars($user_email ?: '—'); ?>
                        </div>
                        <div class="meta-item">
                            <i class="fas fa-calendar"></i>
                            Member since <?php echo $user_created ? formatDate($user_created, 'M Y') : '—'; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-navigation">
                <button class="tab-btn <?php echo $activeTab === 'profile' ? 'active' : ''; ?>" data-tab="profile">
                    <i class="fas fa-user"></i> Profile
                </button>
                <button class="tab-btn <?php echo $activeTab === 'permissions' ? 'active' : ''; ?>" data-tab="permissions">
                    <i class="fas fa-shield-alt"></i> Permissions
                </button>
                <button class="tab-btn <?php echo $activeTab === 'activity' ? 'active' : ''; ?>" data-tab="activity">
                    <i class="fas fa-history"></i> Activity
                </button>
                <button class="tab-btn <?php echo $activeTab === 'stores' ? 'active' : ''; ?>" data-tab="stores">
                    <i class="fas fa-store"></i> Store Access
                </button>
                <?php if ($roleManager->canCreateUsers($_SESSION['user_id'])): ?>
                <button class="tab-btn <?php echo $activeTab === 'management' ? 'active' : ''; ?>" data-tab="management">
                    <i class="fas fa-users-cog"></i> User Management
                </button>
                <?php endif; ?>
            </div>

            <!-- Alerts -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <?php foreach ($errors as $error): ?>
                            <div><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php 
            $notifications = getNotifications();
            foreach ($notifications as $notification): 
            ?>
                <div class="alert alert-<?php echo $notification['type']; ?>">
                    <i class="fas fa-<?php echo $notification['type'] === 'success' ? 'check-circle' : 'info-circle'; ?>"></i>
                    <?php echo htmlspecialchars($notification['message']); ?>
                </div>
            <?php endforeach; ?>

            <!-- Profile Tab Content -->
            <div id="profile-content" class="tab-content <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">
                <div class="section-grid">
                    <!-- Profile Information -->
                    <div class="profile-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-user-edit"></i>
                            </div>
                            <h3>Profile Information</h3>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="update_profile">
                            <?php
                                // defensive form values to avoid undefined index notices
                                $user_username = isset($user['username']) ? $user['username'] : '';
                                $user_phone = isset($user['phone']) ? $user['phone'] : '';
                            ?>

                            <div class="form-group">
                                <label for="username">Username:</label>
                                <input type="text" id="username" value="<?php echo htmlspecialchars($user_username); ?>" disabled>
                                <small>Username cannot be changed</small>
                            </div>

                            <div class="form-group">
                                <label for="first_name">First Name:</label>
                                <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user_first); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="last_name">Last Name:</label>
                                <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user_last); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="email">Email:</label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user_email); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="phone">Phone:</label>
                                <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($user_phone); ?>">
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Update Profile
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Change Password -->
                    <div class="profile-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-lock"></i>
                            </div>
                            <h3>Change Password</h3>
                        </div>
                        <form method="POST" action="" id="password-form">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="form-group">
                                <label for="current_password">Current Password:</label>
                                <input type="password" id="current_password" name="current_password" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password">New Password:</label>
                                <input type="password" id="new_password" name="new_password" required>
                                <small>Must be at least 8 characters with uppercase, lowercase, and number</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password:</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Account Information -->
                    <div class="profile-section">
                        <div class="section-header">
                            <div class="section-icon">
                                <i class="fas fa-info-circle"></i>
                            </div>
                            <h3>Account Information</h3>
                        </div>
                        <div class="account-info">
                            <p><strong>Role:</strong> <?php echo htmlspecialchars($userRole['role_name'] ?? 'User'); ?></p>
                            <p><strong>Member Since:</strong> <?php echo $user_created ? formatDate($user_created, 'F j, Y') : '—'; ?></p>
                            <p><strong>Last Login:</strong> <?php echo $user_last_login ? formatDate($user_last_login, 'F j, Y g:i A') : 'Never'; ?></p>
                            <p><strong>Account Status:</strong> 
                                <span class="permission-status <?php echo $user_active ? 'status-granted' : 'status-denied'; ?>">
                                    <?php echo $user_active ? 'Active' : 'Inactive'; ?>
                                </span>
                            </p>
                            <p><strong>Total Stores Access:</strong> <?php echo $stores_count; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Permissions Tab Content -->
            <div id="permissions-content" class="tab-content <?php echo $activeTab === 'permissions' ? 'active' : ''; ?>">
                <div class="profile-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3>Role & Permissions</h3>
                    </div>
                    <div class="permissions-grid">
                        <?php foreach ($userPermissions as $permission): ?>
                            <div class="permission-card <?php echo $permission['granted'] ? 'granted' : ''; ?>">
                                <h4>
                                    <i class="fas fa-<?php echo $permission['icon']; ?>"></i>
                                    <?php echo htmlspecialchars($permission['name']); ?>
                                </h4>
                                <p><?php echo htmlspecialchars($permission['description']); ?></p>
                                <span class="permission-status <?php echo $permission['granted'] ? 'status-granted' : 'status-denied'; ?>">
                                    <?php echo $permission['granted'] ? 'Granted' : 'Denied'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Activity Tab Content -->
            <div id="activity-content" class="tab-content <?php echo $activeTab === 'activity' ? 'active' : ''; ?>">
                <div class="profile-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <h3>Recent Activity</h3>
                    </div>
                    <div class="activity-list">
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="activity-item">
                                <div class="activity-icon <?php echo $activity['action_type']; ?>">
                                    <i class="fas fa-<?php echo $activity['icon']; ?>"></i>
                                </div>
                                <div class="activity-details">
                                    <h5><?php echo htmlspecialchars($activity['description']); ?></h5>
                                    <div class="activity-time"><?php echo $activity['formatted_time']; ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($recentActivity)): ?>
                            <div class="activity-item">
                                <div class="activity-icon update">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                <div class="activity-details">
                                    <h5>No recent activity</h5>
                                    <div class="activity-time">Activity will appear here as you use the system</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Store Access Tab Content -->
            <div id="stores-content" class="tab-content <?php echo $activeTab === 'stores' ? 'active' : ''; ?>">
                <div class="profile-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-store"></i>
                        </div>
                        <h3>Store Access Management</h3>
                    </div>
                    <div class="store-access-grid">
                        <?php foreach ($availableStores as $store): ?>
                            <div class="store-card <?php echo $store['accessible'] ? 'accessible' : ''; ?>">
                                <div class="store-icon">
                                    <i class="fas fa-store"></i>
                                </div>
                                <h4><?php echo htmlspecialchars($store['name']); ?></h4>
                                <p><?php echo htmlspecialchars($store['location']); ?></p>
                                <span class="permission-status <?php echo $store['accessible'] ? 'status-granted' : 'status-denied'; ?>">
                                    <?php echo $store['accessible'] ? 'Access Granted' : 'No Access'; ?>
                                </span>
                                <?php if ($store['accessible']): ?>
                                    <div style="margin-top: 10px;">
                                        <small>Role: <?php echo htmlspecialchars($store['role']); ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($availableStores)): ?>
                            <div class="store-card">
                                <div class="store-icon">
                                    <i class="fas fa-info-circle"></i>
                                </div>
                                <h4>No Stores Available</h4>
                                <p>Contact your administrator for store access</p>
                                <span class="permission-status status-denied">No Access</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- User Management Tab Content -->
            <?php if ($roleManager->canCreateUsers($_SESSION['user_id'])): ?>
            <div id="management-content" class="tab-content <?php echo $activeTab === 'management' ? 'active' : ''; ?>">
                <div class="profile-section">
                    <div class="section-header">
                        <div class="section-icon">
                            <i class="fas fa-users-cog"></i>
                        </div>
                        <h3>User Management</h3>
                    </div>
                    
                    <!-- User Creation Form -->
                    <div class="user-creation-form">
                        <h4><i class="fas fa-user-plus"></i> Create New User</h4>
                        <form method="POST" action="" id="create-user-form">
                            <input type="hidden" name="action" value="create_user">
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="new_username">Username:</label>
                                    <input type="text" id="new_username" name="username" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_email">Email:</label>
                                    <input type="email" id="new_email" name="email" required>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="new_first_name">First Name:</label>
                                    <input type="text" id="new_first_name" name="first_name" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_last_name">Last Name:</label>
                                    <input type="text" id="new_last_name" name="last_name" required>
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group">
                                    <label for="new_role">Role:</label>
                                    <select id="new_role" name="role_id" required>
                                        <option value="">Select Role</option>
                                        <?php foreach ($roleManager->getAllRoles() as $role): ?>
                                            <option value="<?php echo $role['id']; ?>">
                                                <?php echo htmlspecialchars($role['role_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_phone">Phone (Optional):</label>
                                    <input type="text" id="new_phone" name="phone">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password">Temporary Password:</label>
                                <input type="password" id="new_password" name="password" required>
                                <small>User will be required to change this on first login</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="store_access">Store Access:</label>
                                <div class="checkbox-grid">
                                    <?php foreach ($storeRouter->getAllStores() as $store): ?>
                                        <label class="checkbox-item">
                                            <input type="checkbox" name="store_access[]" value="<?php echo $store['id']; ?>">
                                            <?php echo htmlspecialchars($store['name']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-user-plus"></i> Create User
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Recent User Activities -->
                    <div style="margin-top: 30px;">
                        <h4><i class="fas fa-users"></i> Recent User Activities</h4>
                        <div class="activity-list">
                            <?php 
                            $allUserActivity = $activityLogger->getAllUserActivity(20);
                            foreach ($allUserActivity as $activity): 
                            ?>
                                <div class="activity-item">
                                    <div class="activity-icon <?php echo $activity['action_type']; ?>">
                                        <i class="fas fa-<?php echo $activity['icon']; ?>"></i>
                                    </div>
                                    <div class="activity-details">
                                        <h5><?php echo htmlspecialchars($activity['user_name']); ?>: <?php echo htmlspecialchars($activity['description']); ?></h5>
                                        <div class="activity-time"><?php echo $activity['formatted_time']; ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($allUserActivity)): ?>
                                <div class="activity-item">
                                    <div class="activity-icon update">
                                        <i class="fas fa-info-circle"></i>
                                    </div>
                                    <div class="activity-details">
                                        <h5>No recent system activity</h5>
                                        <div class="activity-time">System activity will appear here</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Enhanced Profile Management System
        class ProfileManager {
            constructor() {
                this.init();
            }
            
            init() {
                this.setupTabNavigation();
                this.setupFormValidation();
                this.setupPasswordStrengthChecker();
                this.setupRealTimeUpdates();
            }
            
            setupTabNavigation() {
                const tabBtns = document.querySelectorAll('.tab-btn');
                const tabContents = document.querySelectorAll('.tab-content');
                
                tabBtns.forEach(btn => {
                    btn.addEventListener('click', () => {
                        const targetTab = btn.dataset.tab;
                        
                        // Update active tab button
                        tabBtns.forEach(b => b.classList.remove('active'));
                        btn.classList.add('active');
                        
                        // Update active content
                        tabContents.forEach(content => {
                            content.classList.remove('active');
                            if (content.id === targetTab + '-content') {
                                content.classList.add('active');
                            }
                        });
                        
                        // Update URL without refresh
                        const url = new URL(window.location);
                        url.searchParams.set('tab', targetTab);
                        window.history.pushState({}, '', url);
                    });
                });
            }
            
            setupFormValidation() {
                // Profile form validation
                const profileForm = document.querySelector('form[action=""][method="POST"]');
                if (profileForm) {
                    profileForm.addEventListener('submit', (e) => {
                        if (!this.validateProfileForm(profileForm)) {
                            e.preventDefault();
                        }
                    });
                }
                
                // Password form validation
                const passwordForm = document.getElementById('password-form');
                if (passwordForm) {
                    passwordForm.addEventListener('submit', (e) => {
                        if (!this.validatePasswordForm(passwordForm)) {
                            e.preventDefault();
                        }
                    });
                }
                
                // User creation form validation
                const createUserForm = document.getElementById('create-user-form');
                if (createUserForm) {
                    createUserForm.addEventListener('submit', (e) => {
                        if (!this.validateUserCreationForm(createUserForm)) {
                            e.preventDefault();
                        }
                    });
                }
            }
            
            validateProfileForm(form) {
                const firstName = form.querySelector('[name="first_name"]').value.trim();
                const lastName = form.querySelector('[name="last_name"]').value.trim();
                const email = form.querySelector('[name="email"]').value.trim();
                
                if (!firstName || !lastName) {
                    this.showValidationError('First name and last name are required');
                    return false;
                }
                
                if (!this.isValidEmail(email)) {
                    this.showValidationError('Please enter a valid email address');
                    return false;
                }
                
                return true;
            }
            
            validatePasswordForm(form) {
                const currentPassword = form.querySelector('[name="current_password"]').value;
                const newPassword = form.querySelector('[name="new_password"]').value;
                const confirmPassword = form.querySelector('[name="confirm_password"]').value;
                
                if (!currentPassword) {
                    this.showValidationError('Current password is required');
                    return false;
                }
                
                if (newPassword.length < 8) {
                    this.showValidationError('New password must be at least 8 characters long');
                    return false;
                }
                
                if (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(newPassword)) {
                    this.showValidationError('Password must contain at least one uppercase letter, one lowercase letter, and one number');
                    return false;
                }
                
                if (newPassword !== confirmPassword) {
                    this.showValidationError('New passwords do not match');
                    return false;
                }
                
                return true;
            }
            
            validateUserCreationForm(form) {
                const username = form.querySelector('[name="username"]').value.trim();
                const email = form.querySelector('[name="email"]').value.trim();
                const firstName = form.querySelector('[name="first_name"]').value.trim();
                const lastName = form.querySelector('[name="last_name"]').value.trim();
                const roleId = form.querySelector('[name="role_id"]').value;
                const password = form.querySelector('[name="password"]').value;
                
                if (!username || !email || !firstName || !lastName || !roleId || !password) {
                    this.showValidationError('All required fields must be filled');
                    return false;
                }
                
                if (!this.isValidEmail(email)) {
                    this.showValidationError('Please enter a valid email address');
                    return false;
                }
                
                if (password.length < 8) {
                    this.showValidationError('Password must be at least 8 characters long');
                    return false;
                }
                
                return true;
            }
            
            setupPasswordStrengthChecker() {
                const newPasswordInput = document.getElementById('new_password');
                if (newPasswordInput) {
                    const strengthIndicator = this.createPasswordStrengthIndicator();
                    newPasswordInput.parentNode.appendChild(strengthIndicator);
                    
                    newPasswordInput.addEventListener('input', (e) => {
                        this.updatePasswordStrength(e.target.value, strengthIndicator);
                    });
                }
                
                // Also add to user creation password field
                const createPasswordInput = document.getElementById('new_password');
                if (createPasswordInput) {
                    const createStrengthIndicator = this.createPasswordStrengthIndicator();
                    createPasswordInput.parentNode.appendChild(createStrengthIndicator);
                    
                    createPasswordInput.addEventListener('input', (e) => {
                        this.updatePasswordStrength(e.target.value, createStrengthIndicator);
                    });
                }
            }
            
            createPasswordStrengthIndicator() {
                const container = document.createElement('div');
                container.className = 'password-strength';
                container.style.cssText = `
                    margin-top: 5px;
                    font-size: 12px;
                `;
                
                const bar = document.createElement('div');
                bar.className = 'password-strength-bar';
                bar.style.cssText = `
                    height: 4px;
                    background: #e5e7eb;
                    border-radius: 2px;
                    margin-bottom: 5px;
                    overflow: hidden;
                `;
                
                const fill = document.createElement('div');
                fill.className = 'password-strength-fill';
                fill.style.cssText = `
                    height: 100%;
                    width: 0%;
                    transition: all 0.3s ease;
                    border-radius: 2px;
                `;
                
                const text = document.createElement('div');
                text.textContent = 'Password strength';
                
                bar.appendChild(fill);
                container.appendChild(bar);
                container.appendChild(text);
                
                return container;
            }
            
            updatePasswordStrength(password, indicator) {
                const fill = indicator.querySelector('.password-strength-fill');
                const text = indicator.querySelector('div:last-child');
                
                let strength = 0;
                let strengthText = 'Weak';
                let color = '#ef4444';
                
                if (password.length >= 8) strength++;
                if (/[a-z]/.test(password)) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/[0-9]/.test(password)) strength++;
                if (/[^a-zA-Z0-9]/.test(password)) strength++;
                
                if (strength >= 4) {
                    strengthText = 'Strong';
                    color = '#22c55e';
                } else if (strength >= 3) {
                    strengthText = 'Medium';
                    color = '#f59e0b';
                }
                
                fill.style.width = (strength / 5 * 100) + '%';
                fill.style.backgroundColor = color;
                text.textContent = `Password strength: ${strengthText}`;
                text.style.color = color;
            }
            
            setupRealTimeUpdates() {
                // Auto-refresh activity feed every 30 seconds
                setInterval(() => {
                    this.refreshActivityFeed();
                }, 30000);
                
                // Auto-save profile changes (draft mode)
                const profileInputs = document.querySelectorAll('#profile-content input[type="text"], #profile-content input[type="email"]');
                profileInputs.forEach(input => {
                    input.addEventListener('change', () => {
                        this.saveDraft(input.name, input.value);
                    });
                });
            }
            
            async refreshActivityFeed() {
                try {
                    const response = await fetch('../../api/user/activity.php?user_id=<?php echo $_SESSION['user_id']; ?>');
                    if (response.ok) {
                        const data = await response.json();
                        if (data.success) {
                            this.updateActivityFeed(data.activities);
                        }
                    }
                } catch (error) {
                    console.warn('Failed to refresh activity feed:', error);
                }
            }
            
            updateActivityFeed(activities) {
                const activityList = document.querySelector('.activity-list');
                if (activityList && activities) {
                    activityList.innerHTML = activities.map(activity => `
                        <div class="activity-item">
                            <div class="activity-icon ${activity.action_type}">
                                <i class="fas fa-${activity.icon}"></i>
                            </div>
                            <div class="activity-details">
                                <h5>${activity.description}</h5>
                                <div class="activity-time">${activity.formatted_time}</div>
                            </div>
                        </div>
                    `).join('');
                }
            }
            
            saveDraft(field, value) {
                try {
                    const drafts = JSON.parse(localStorage.getItem('profile_drafts') || '{}');
                    drafts[field] = value;
                    drafts.timestamp = Date.now();
                    localStorage.setItem('profile_drafts', JSON.stringify(drafts));
                } catch (error) {
                    console.warn('Failed to save draft:', error);
                }
            }
            
            loadDrafts() {
                try {
                    const drafts = JSON.parse(localStorage.getItem('profile_drafts') || '{}');
                    const timestamp = drafts.timestamp;
                    
                    // Only load drafts if they're less than 1 hour old
                    if (timestamp && (Date.now() - timestamp) < 3600000) {
                        Object.keys(drafts).forEach(field => {
                            if (field !== 'timestamp') {
                                const input = document.querySelector(`[name="${field}"]`);
                                if (input && input.value !== drafts[field]) {
                                    input.style.backgroundColor = '#fef3cd';
                                    input.title = 'You have unsaved changes';
                                }
                            }
                        });
                    }
                } catch (error) {
                    console.warn('Failed to load drafts:', error);
                }
            }
            
            isValidEmail(email) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            }
            
            showValidationError(message) {
                const alert = document.createElement('div');
                alert.className = 'alert alert-error';
                alert.innerHTML = `
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>${message}</div>
                `;
                alert.style.cssText = `
                    position: fixed; 
                    top: 20px; 
                    right: 20px; 
                    z-index: 10000; 
                    max-width: 400px;
                    opacity: 0;
                    transition: opacity 0.3s ease;
                `;
                
                document.body.appendChild(alert);
                
                // Fade in
                setTimeout(() => alert.style.opacity = '1', 10);
                
                // Fade out and remove
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        if (document.body.contains(alert)) {
                            document.body.removeChild(alert);
                        }
                    }, 300);
                }, 5000);
            }
            
            showSuccessMessage(message) {
                const alert = document.createElement('div');
                alert.className = 'alert alert-success';
                alert.innerHTML = `
                    <i class="fas fa-check-circle"></i>
                    <div>${message}</div>
                `;
                alert.style.cssText = `
                    position: fixed; 
                    top: 20px; 
                    right: 20px; 
                    z-index: 10000; 
                    max-width: 400px;
                    opacity: 0;
                    transition: opacity 0.3s ease;
                `;
                
                document.body.appendChild(alert);
                
                setTimeout(() => alert.style.opacity = '1', 10);
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        if (document.body.contains(alert)) {
                            document.body.removeChild(alert);
                        }
                    }, 300);
                }, 3000);
            }
        }
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            window.profileManager = new ProfileManager();
            window.profileManager.loadDrafts();
        });
        
        // Handle form submissions with loading states
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    
                    // Re-enable after 10 seconds as fallback
                    setTimeout(() => {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    }, 10000);
                }
            });
        });
        
        // Clear drafts on successful form submission
        window.addEventListener('beforeunload', function() {
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                try {
                    localStorage.removeItem('profile_drafts');
                } catch (error) {
                    console.warn('Failed to clear drafts:', error);
                }
            }
        });
        
        // Handle tab switching via URL parameters
        window.addEventListener('popstate', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab') || 'profile';
            
            const targetBtn = document.querySelector(`[data-tab="${tab}"]`);
            if (targetBtn) {
                targetBtn.click();
            }
        });
        
        // Real-time form validation feedback
        document.querySelectorAll('input[type="email"]').forEach(input => {
            input.addEventListener('blur', function() {
                const email = this.value.trim();
                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    this.style.borderColor = '#ef4444';
                    this.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                } else {
                    this.style.borderColor = '#e5e7eb';
                    this.style.boxShadow = 'none';
                }
            });
        });
        
        // Auto-generate username suggestion
        const firstNameInput = document.getElementById('new_first_name');
        const lastNameInput = document.getElementById('new_last_name');
        const usernameInput = document.getElementById('new_username');
        
        if (firstNameInput && lastNameInput && usernameInput) {
            [firstNameInput, lastNameInput].forEach(input => {
                input.addEventListener('input', function() {
                    const firstName = firstNameInput.value.trim().toLowerCase();
                    const lastName = lastNameInput.value.trim().toLowerCase();
                    
                    if (firstName && lastName && !usernameInput.value) {
                        const suggestion = firstName.charAt(0) + lastName + Math.floor(Math.random() * 100);
                        usernameInput.placeholder = `Suggestion: ${suggestion}`;
                    }
                });
            });
        }
        
        // Confirm password matching indicator
        const confirmPasswordInput = document.getElementById('confirm_password');
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', function() {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = this.value;
                
                if (confirmPassword && newPassword !== confirmPassword) {
                    this.style.borderColor = '#ef4444';
                    this.style.boxShadow = '0 0 0 3px rgba(239, 68, 68, 0.1)';
                } else if (confirmPassword) {
                    this.style.borderColor = '#22c55e';
                    this.style.boxShadow = '0 0 0 3px rgba(34, 197, 94, 0.1)';
                } else {
                    this.style.borderColor = '#e5e7eb';
                    this.style.boxShadow = 'none';
                }
            });
        }
    </script>

    <script src="../../assets/js/main.js"></script>
</body>
</html>