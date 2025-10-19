<?php
/**
 * Permissions API - Optimized Backend Endpoint
 * Handles all permission-related requests with caching
 */

header('Content-Type: application/json');
require_once '../../../../config.php';
require_once '../../../../db.php';
require_once '../../../../functions.php';

session_start();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$currentUserId = $_SESSION['user_id'];
$currentUser = $db->read('users', $currentUserId);
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

// Only admins can manage permissions
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list_users':
            // Cache user list (5 min TTL)
            $cacheKey = "permission_users_list";
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5($cacheKey) . '.json';
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
                echo file_get_contents($cacheFile);
                exit;
            }
            
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
            $search = $_GET['search'] ?? '';
            
            // Fetch all users
            $users = $db->readAll('users', [], ['first_name', 'ASC'], 200);
            
            // Filter by search
            if (!empty($search)) {
                $search = strtolower($search);
                $users = array_filter($users, function($u) use ($search) {
                    $name = strtolower(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                    $email = strtolower($u['email'] ?? '');
                    return strpos($name, $search) !== false || strpos($email, $search) !== false;
                });
                $users = array_values($users);
            }
            
            // Pagination
            $total = count($users);
            $totalPages = ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            $users = array_slice($users, $offset, $perPage);
            
            // Simplify user data
            $users = array_map(function($u) {
                return [
                    'id' => $u['id'] ?? '',
                    'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                    'email' => $u['email'] ?? '',
                    'role' => $u['role'] ?? 'user',
                    'role_id' => $u['role_id'] ?? null,
                    'active' => $u['active'] ?? true
                ];
            }, $users);
            
            $response = [
                'success' => true,
                'data' => $users,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages
                ]
            ];
            
            // Cache response
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0755, true);
            }
            file_put_contents($cacheFile, json_encode($response));
            
            echo json_encode($response);
            break;
            
        case 'get_user_permissions':
            $userId = $_GET['user_id'] ?? '';
            
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }
            
            // Cache user permissions (2 min TTL)
            $cacheKey = "user_permissions_{$userId}";
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5($cacheKey) . '.json';
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 120) {
                echo file_get_contents($cacheFile);
                exit;
            }
            
            $user = $db->read('users', $userId);
            
            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Get permissions based on role
            $permissions = [
                'role' => $user['role'] ?? 'user',
                'role_id' => $user['role_id'] ?? null,
                'permissions' => []
            ];
            
            $roleStr = strtolower($user['role'] ?? 'user');
            
            // Define permissions by role
            switch ($roleStr) {
                case 'admin':
                    $permissions['permissions'] = [
                        'view_reports' => true,
                        'manage_inventory' => true,
                        'manage_users' => true,
                        'manage_stores' => true,
                        'configure_system' => true,
                        'manage_pos' => true
                    ];
                    break;
                    
                case 'manager':
                    $permissions['permissions'] = [
                        'view_reports' => true,
                        'manage_inventory' => true,
                        'manage_users' => false,
                        'manage_stores' => true,
                        'configure_system' => false,
                        'manage_pos' => true
                    ];
                    break;
                    
                case 'user':
                default:
                    $permissions['permissions'] = [
                        'view_reports' => true,
                        'manage_inventory' => false,
                        'manage_users' => false,
                        'manage_stores' => false,
                        'configure_system' => false,
                        'manage_pos' => false
                    ];
            }
            
            // Apply overrides
            if (!empty($user['permission_overrides']) && is_array($user['permission_overrides'])) {
                $permissions['permissions'] = array_merge(
                    $permissions['permissions'],
                    $user['permission_overrides']
                );
            }
            
            $response = ['success' => true, 'data' => $permissions];
            
            // Cache response
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0755, true);
            }
            file_put_contents($cacheFile, json_encode($response));
            
            echo json_encode($response);
            break;
            
        case 'update_role':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $userId = $_POST['user_id'] ?? '';
            $role = $_POST['role'] ?? '';
            
            if (empty($userId) || empty($role)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID and role required']);
                exit;
            }
            
            // Validate role
            $validRoles = ['user', 'manager', 'admin'];
            if (!in_array(strtolower($role), $validRoles)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid role']);
                exit;
            }
            
            // Update user role
            $result = $db->update('users', $userId, [
                'role' => strtolower($role),
                'updated_at' => date('c')
            ]);
            
            if ($result) {
                // Clear cache
                $cachePattern = __DIR__ . '/../../storage/cache/*';
                foreach (glob($cachePattern) as $file) {
                    if (strpos($file, 'permission') !== false || strpos($file, $userId) !== false) {
                        @unlink($file);
                    }
                }
                
                // Log action
                $db->create('user_activities', [
                    'user_id' => $currentUserId,
                    'action_type' => 'permission_changed',
                    'description' => "Changed role to {$role} for user {$userId}",
                    'metadata' => json_encode(['user_id' => $userId, 'new_role' => $role]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'created_at' => date('c')
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to update role']);
            }
            break;
            
        case 'update_permissions':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $userId = $_POST['user_id'] ?? '';
            $permissions = json_decode($_POST['permissions'] ?? '{}', true);
            
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }
            
            // Update permission overrides
            $result = $db->update('users', $userId, [
                'permission_overrides' => $permissions,
                'updated_at' => date('c')
            ]);
            
            if ($result) {
                // Clear cache
                $cacheFile = __DIR__ . '/../../storage/cache/' . md5("user_permissions_{$userId}") . '.json';
                @unlink($cacheFile);
                
                // Log action
                $db->create('user_activities', [
                    'user_id' => $currentUserId,
                    'action_type' => 'permission_changed',
                    'description' => "Updated custom permissions for user {$userId}",
                    'metadata' => json_encode(['user_id' => $userId, 'permissions' => $permissions]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'created_at' => date('c')
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Permissions updated successfully']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to update permissions']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Permissions API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error', 'message' => $e->getMessage()]);
}
