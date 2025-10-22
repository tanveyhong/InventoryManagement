<?php
/**
 * Profile Data API - Optimized AJAX Endpoints
 * Provides on-demand data loading for profile sections
 */

header('Content-Type: application/json');
require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';
require_once '../../../activity_logger.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// Enable caching for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: private, max-age=300'); // Cache for 5 minutes
    header('ETag: "' . md5($userId . $action . time()) . '"');
}

try {
    switch ($action) {
        case 'get_user_info':
            $user = $db->read('users', $userId);
            if ($user) {
                // Remove sensitive data
                unset($user['password_hash']);
                echo json_encode(['success' => true, 'data' => $user]);
            } else {
                echo json_encode(['success' => false, 'error' => 'User not found']);
            }
            break;
            
        case 'get_activities':
            $limit = intval($_GET['limit'] ?? 20);
            $offset = intval($_GET['offset'] ?? 0);
            $targetUserId = $_GET['user_id'] ?? $userId; // Allow admin to view any user's activities
            
            // Check permission - only admin can view other users' activities
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            // If requesting another user's activities, must be admin
            if ($targetUserId !== $userId && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Build query
            $conditions = [['deleted_at', '==', null]];
            
            // If specific user requested or not admin, filter by user
            if ($targetUserId !== 'all') {
                $conditions[] = ['user_id', '==', $targetUserId];
            } elseif (!$isAdmin) {
                // Non-admins can only see their own activities
                $conditions[] = ['user_id', '==', $userId];
            }
            
            // Optimized query with limit
            $activities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC'], $limit + $offset);
            
            // Handle database errors
            if ($activities === false) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to retrieve activities from database'
                ]);
                exit;
            }
            
            // Ensure activities is an array
            if (!is_array($activities)) {
                $activities = [];
            }
            
            // Apply offset manually
            $activities = array_slice($activities, $offset, $limit);
            
            // Enrich activities with user info for admin view
            if ($isAdmin && $targetUserId === 'all') {
                foreach ($activities as &$activity) {
                    if (!empty($activity['user_id'])) {
                        $activityUser = $db->read('users', $activity['user_id']);
                        $activity['user_name'] = $activityUser ? ($activityUser['username'] ?? 'Unknown') : 'Unknown';
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $activities,
                'has_more' => count($activities) === $limit
            ]);
            break;
            
        case 'check_new_activities':
            // Check if there are new activities since a given timestamp
            $since = $_GET['since'] ?? null;
            
            if (!$since) {
                echo json_encode(['success' => false, 'error' => 'Missing timestamp']);
                exit;
            }
            
            // Get current user's activities created after the timestamp
            $conditions = [
                ['deleted_at', '==', null],
                ['user_id', '==', $userId],
                ['created_at', '>', $since]
            ];
            
            $newActivities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC'], 1);
            
            echo json_encode([
                'success' => true,
                'has_new' => !empty($newActivities),
                'count' => count($newActivities)
            ]);
            break;
            
        case 'get_all_users':
            // Admin only - get list of users for activity filtering
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $users = $db->readAll('users', []);
            $userList = [];
            foreach ($users as $user) {
                $userList[] = [
                    'id' => $user['id'] ?? '',
                    'username' => $user['username'] ?? 'Unknown',
                    'email' => $user['email'] ?? '',
                    'first_name' => $user['first_name'] ?? '',
                    'last_name' => $user['last_name'] ?? '',
                    'role' => $user['role'] ?? 'staff',
                    'created_at' => $user['created_at'] ?? '',
                    'last_login' => $user['last_login'] ?? ''
                ];
            }
            
            echo json_encode(['success' => true, 'data' => $userList]);
            break;
            
        case 'export_activities':
            $format = $_GET['format'] ?? 'csv';
            $targetUserId = $_GET['user_id'] ?? $userId;
            
            // Check permission
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            if ($targetUserId !== $userId && $targetUserId !== 'all' && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Get activities
            $conditions = [['deleted_at', '==', null]];
            if ($targetUserId !== 'all') {
                $conditions[] = ['user_id', '==', $targetUserId];
            }
            
            $activities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC']);
            
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="activities_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                $headers = ['Timestamp', 'User', 'Action Type', 'Description', 'IP Address'];
                fputcsv($output, $headers);
                
                foreach ($activities as $act) {
                    $actUser = isset($act['user_id']) ? $db->read('users', $act['user_id']) : null;
                    fputcsv($output, [
                        $act['created_at'] ?? '',
                        $actUser['username'] ?? 'Unknown',
                        $act['action_type'] ?? '',
                        $act['description'] ?? '',
                        $act['ip_address'] ?? ''
                    ]);
                }
                fclose($output);
                exit;
            } elseif ($format === 'pdf') {
                // Simple HTML-based PDF (browser will handle conversion)
                header('Content-Type: text/html');
                header('Content-Disposition: inline; filename="activities_' . date('Y-m-d') . '.html"');
                
                echo '<!DOCTYPE html>
                <html>
                <head>
                    <title>Activity Report - ' . date('Y-m-d') . '</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 40px; }
                        h1 { color: #667eea; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th { background: #667eea; color: white; padding: 10px; text-align: left; }
                        td { padding: 8px; border-bottom: 1px solid #ddd; }
                        tr:hover { background: #f5f5f5; }
                        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                    </style>
                    <script>
                        window.onload = function() {
                            // Auto-print for PDF conversion
                            setTimeout(function() { window.print(); }, 500);
                        }
                    </script>
                </head>
                <body>
                    <h1>Activity Report</h1>
                    <p><strong>Generated:</strong> ' . date('Y-m-d H:i:s') . '</p>
                    <p><strong>Total Activities:</strong> ' . count($activities) . '</p>
                    <table>
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>';
                
                foreach ($activities as $act) {
                    $actUser = isset($act['user_id']) ? $db->read('users', $act['user_id']) : null;
                    echo '<tr>
                        <td>' . htmlspecialchars($act['created_at'] ?? '') . '</td>
                        <td>' . htmlspecialchars($actUser['username'] ?? 'Unknown') . '</td>
                        <td>' . htmlspecialchars($act['action_type'] ?? '') . '</td>
                        <td>' . htmlspecialchars($act['description'] ?? '') . '</td>
                        <td>' . htmlspecialchars($act['ip_address'] ?? '') . '</td>
                    </tr>';
                }
                
                echo '</tbody>
                    </table>
                    <div class="footer">
                        <p>Inventory Management System - Activity Report</p>
                        <p>Use browser Print to PDF feature to save this report</p>
                    </div>
                </body>
                </html>';
                exit;
            } else {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="activities_' . date('Y-m-d') . '.json"');
                echo json_encode($activities, JSON_PRETTY_PRINT);
                exit;
            }
            break;
            
        case 'clear_activities':
            // Admin only for now
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            $targetUserId = $data['user_id'] ?? $userId;
            
            // Clear activities by soft delete
            $activities = $db->readAll('user_activities', [['user_id', '==', $targetUserId]]);
            $count = 0;
            foreach ($activities as $act) {
                if (isset($act['id'])) {
                    $db->update('user_activities', $act['id'], ['deleted_at' => date('c')]);
                    $count++;
                }
            }
            
            echo json_encode(['success' => true, 'message' => "Cleared {$count} activities"]);
            break;
            
        case 'get_permissions':
            // Allow admin to view other users' permissions
            $targetUserId = $_GET['user_id'] ?? $userId;
            
            error_log("=== GET_PERMISSIONS API CALLED ===");
            error_log("Target User ID: " . $targetUserId);
            
            // Check permission if viewing another user
            if ($targetUserId !== $userId) {
                $currentUser = $db->read('users', $userId);
                $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
                
                if (!$isAdmin) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Permission denied']);
                    exit;
                }
            }
            
            $user = $db->read('users', $targetUserId);
            error_log("User data: " . json_encode($user));
            
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            $role = strtolower($user['role'] ?? 'user');
            
            // Define default permissions for each role (if fields don't exist in DB)
            $roleDefaults = [
                'user' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_view_stores' => true
                ],
                'cashier' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_use_pos' => true
                ],
                'manager' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_add_inventory' => true,
                    'can_edit_inventory' => true,
                    'can_view_stores' => true,
                    'can_add_stores' => true,
                    'can_edit_stores' => true,
                    'can_use_pos' => true,
                    'can_view_users' => true
                ],
                'admin' => [] // Admin gets all permissions
            ];
            
            $defaults = $roleDefaults[$role] ?? $roleDefaults['user'];
            
            // Helper function to get permission value with role-based default
            $getPerm = function($key) use ($user, $defaults, $role) {
                if (isset($user[$key])) {
                    return (bool)$user[$key];
                }
                // If admin and permission not set, default to true
                if ($role === 'admin') {
                    return true;
                }
                // Otherwise use role defaults
                return $defaults[$key] ?? false;
            };
            
            // Read ALL permissions directly from user record (granular permissions)
            $permissions = [
                'role' => ucfirst($role),
                // Reports
                'can_view_reports' => $getPerm('can_view_reports'),
                // Inventory (granular)
                'can_view_inventory' => $getPerm('can_view_inventory'),
                'can_add_inventory' => $getPerm('can_add_inventory'),
                'can_edit_inventory' => $getPerm('can_edit_inventory'),
                'can_delete_inventory' => $getPerm('can_delete_inventory'),
                // Stores (granular)
                'can_view_stores' => $getPerm('can_view_stores'),
                'can_add_stores' => $getPerm('can_add_stores'),
                'can_edit_stores' => $getPerm('can_edit_stores'),
                'can_delete_stores' => $getPerm('can_delete_stores'),
                // POS
                'can_use_pos' => $getPerm('can_use_pos'),
                'can_manage_pos' => $getPerm('can_manage_pos'),
                // Users
                'can_view_users' => $getPerm('can_view_users'),
                'can_manage_users' => $getPerm('can_manage_users'),
                // System
                'can_configure_system' => $getPerm('can_configure_system'),
                // Legacy permissions (for backward compatibility)
                'can_manage_inventory' => $getPerm('can_manage_inventory'),
                'can_manage_stores' => $getPerm('can_manage_stores')
            ];
            
            error_log("Permissions returned: " . json_encode($permissions));
            echo json_encode(['success' => true, 'data' => $permissions]);
            break;
            
        case 'update_permission':
            // Admin only
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied. Admin access required.']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $targetUserId = $input['user_id'] ?? null;
            $permission = $input['permission'] ?? null;
            $value = $input['value'] ?? false;
            
            if (!$targetUserId || !$permission) {
                echo json_encode(['success' => false, 'error' => 'Missing user_id or permission']);
                exit;
            }
            
            // Read target user
            $targetUser = $db->read('users', $targetUserId);
            if (!$targetUser) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Update permission
            $targetUser[$permission] = $value;
            $updated = $db->update('users', $targetUserId, [$permission => $value]);
            
            if ($updated) {
                // Log activity
                $action = $value ? 'granted' : 'revoked';
                $permName = str_replace('can_', '', $permission);
                $permName = str_replace('_', ' ', $permName);
                $description = ucfirst($action) . " permission: " . ucfirst($permName) . " for user " . ($targetUser['username'] ?? $targetUserId);
                
                logActivity('permission_updated', $description, [
                    'target_user_id' => $targetUserId,
                    'target_username' => $targetUser['username'] ?? '',
                    'permission' => $permission,
                    'action' => $action,
                    'value' => $value
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Permission updated successfully'
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update permission']);
            }
            break;
            
        case 'assign_role':
            // Admin only
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied. Admin access required.']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $targetUserId = $input['user_id'] ?? null;
            $role = strtolower($input['role'] ?? '');
            
            if (!$targetUserId || !$role) {
                echo json_encode(['success' => false, 'error' => 'Missing user_id or role']);
                exit;
            }
            
            // Validate role
            $validRoles = ['user', 'cashier', 'manager', 'admin'];
            if (!in_array($role, $validRoles)) {
                echo json_encode(['success' => false, 'error' => 'Invalid role. Must be: user, cashier, manager, or admin']);
                exit;
            }
            
            // Read target user
            $targetUser = $db->read('users', $targetUserId);
            if (!$targetUser) {
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Define role permissions with granular access control
            $rolePermissions = [
                'user' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_add_inventory' => false,
                    'can_edit_inventory' => false,
                    'can_delete_inventory' => false,
                    'can_view_stores' => true,
                    'can_add_stores' => false,
                    'can_edit_stores' => false,
                    'can_delete_stores' => false,
                    'can_use_pos' => false,
                    'can_manage_pos' => false,
                    'can_view_users' => true,
                    'can_manage_users' => false,
                    'can_configure_system' => false
                ],
                'cashier' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_add_inventory' => false,
                    'can_edit_inventory' => false,
                    'can_delete_inventory' => false,
                    'can_view_stores' => true,
                    'can_add_stores' => false,
                    'can_edit_stores' => false,
                    'can_delete_stores' => false,
                    'can_use_pos' => true,
                    'can_manage_pos' => false,
                    'can_view_users' => false,
                    'can_manage_users' => false,
                    'can_configure_system' => false
                ],
                'manager' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_add_inventory' => true,
                    'can_edit_inventory' => true,
                    'can_delete_inventory' => false,
                    'can_view_stores' => true,
                    'can_add_stores' => true,
                    'can_edit_stores' => true,
                    'can_delete_stores' => false,
                    'can_use_pos' => true,
                    'can_manage_pos' => false,
                    'can_view_users' => true,
                    'can_manage_users' => false,
                    'can_configure_system' => false
                ],
                'admin' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_add_inventory' => true,
                    'can_edit_inventory' => true,
                    'can_delete_inventory' => true,
                    'can_view_stores' => true,
                    'can_add_stores' => true,
                    'can_edit_stores' => true,
                    'can_delete_stores' => true,
                    'can_use_pos' => true,
                    'can_manage_pos' => true,
                    'can_view_users' => true,
                    'can_manage_users' => true,
                    'can_configure_system' => true
                ]
            ];
            
            $permissions = $rolePermissions[$role];
            
            // Update role and all permissions
            $updateData = array_merge(['role' => ucfirst($role)], $permissions);
            $updated = $db->update('users', $targetUserId, $updateData);
            
            if ($updated) {
                // Count permissions
                $permCount = count(array_filter($permissions));
                
                // Log activity
                $description = "Assigned " . ucfirst($role) . " role to user " . ($targetUser['username'] ?? $targetUserId) . " ($permCount permissions)";
                logActivity('role_assigned', $description, [
                    'target_user_id' => $targetUserId,
                    'target_username' => $targetUser['username'] ?? '',
                    'role' => $role,
                    'permissions_granted' => $permCount,
                    'permissions' => $permissions
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => ucfirst($role) . " role assigned with $permCount permissions",
                    'role' => ucfirst($role),
                    'permissions' => $permissions
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to update role']);
            }
            break;
            
        case 'get_stores':
            error_log("=== GET_STORES API CALLED ===");
            $targetUserId = $_GET['user_id'] ?? $userId;
            error_log("Target User ID: " . $targetUserId);
            
            // Check permission
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $isManager = (strtolower($currentUser['role'] ?? '') === 'manager');
            
            // Only admin/manager can view other users' stores
            if ($targetUserId !== $userId && !$isAdmin && !$isManager) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Get user's store assignments
            $userStoresData = $db->readAll('user_stores') ?? [];
            error_log("Total user_stores records: " . count($userStoresData));
            
            $userStoreAccess = [];
            
            foreach ($userStoresData as $id => $access) {
                if (($access['user_id'] ?? '') === $targetUserId) {
                    $userStoreAccess[$access['store_id'] ?? ''] = $access;
                    error_log("Found store access - ID: $id, Store: " . ($access['store_id'] ?? 'unknown'));
                }
            }
            
            error_log("User has access to " . count($userStoreAccess) . " stores");
            
            $storeIds = array_keys($userStoreAccess);
            $stores = [];
            
            // Load all active stores
            $allStores = $db->readAll('stores') ?? [];
            
            foreach ($allStores as $storeId => $store) {
                if (($store['active'] ?? true) && in_array($storeId, $storeIds)) {
                    $store['id'] = $storeId;
                    $store['permissions'] = $userStoreAccess[$storeId]['permissions'] ?? [];
                    $stores[] = $store;
                    error_log("Added store: " . ($store['store_name'] ?? $storeId));
                }
            }
            
            error_log("Returning " . count($stores) . " stores");
            
            echo json_encode([
                'success' => true,
                'data' => $stores,
                'count' => count($stores)
            ]);
            break;
            
        case 'get_available_stores':
            // Get all active stores for assignment
            $targetUserId = $_GET['user_id'] ?? $userId;
            
            // Check permission
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $isManager = (strtolower($currentUser['role'] ?? '') === 'manager');
            
            if (!$isAdmin && !$isManager) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Get user's current store assignments
            $userStoresData = $db->readAll('user_stores') ?? [];
            $assignedStoreIds = [];
            
            foreach ($userStoresData as $id => $access) {
                if (($access['user_id'] ?? '') === $targetUserId) {
                    $assignedStoreIds[] = $access['store_id'] ?? '';
                }
            }
            
            // Get all active stores
            $allStores = $db->readAll('stores') ?? [];
            $availableStores = [];
            
            foreach ($allStores as $storeId => $store) {
                if (($store['active'] ?? true) && !in_array($storeId, $assignedStoreIds)) {
                    $store['id'] = $storeId;
                    $availableStores[] = $store;
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $availableStores,
                'count' => count($availableStores)
            ]);
            break;
            
        case 'get_stats':
            // Quick stats without heavy queries
            $stats = [
                'total_activities' => 0,
                'stores_count' => 0,
                'last_login' => null
            ];
            
            // Count activities (with simple caching)
            $cacheKey = "user_stats_{$userId}";
            $cacheFile = __DIR__ . '/../storage/cache_' . md5($cacheKey) . '.json';
            $cached = null;
            
            // Check file cache (5 min TTL)
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
                $cached = json_decode(file_get_contents($cacheFile), true);
            }
            
            if ($cached === null) {
                $activities = $db->readAll('user_activities', [
                    ['user_id', '==', $userId],
                    ['deleted_at', '==', null]
                ]);
                $stats['total_activities'] = count($activities);
                
                $userStores = $db->readAll('user_stores', [['user_id', '==', $userId]]);
                $stats['stores_count'] = count($userStores);
                
                $user = $db->read('users', $userId);
                $stats['last_login'] = $user['last_login'] ?? null;
                
                // Cache to file
                file_put_contents($cacheFile, json_encode($stats));
            } else {
                $stats = $cached;
            }
            
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        case 'upload_avatar':
            // Handle avatar upload
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
                exit;
            }
            
            $file = $_FILES['avatar'];
            
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($file['type'], $allowedTypes)) {
                echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed']);
                exit;
            }
            
            // Validate file size (2MB max)
            if ($file['size'] > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'error' => 'File size must be less than 2MB']);
                exit;
            }
            
            // Create uploads directory if it doesn't exist
            $uploadDir = __DIR__ . '/../../../storage/uploads/avatars/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = $userId . '_' . time() . '.' . $extension;
            $uploadPath = $uploadDir . $filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Update user record with avatar path
                $avatarUrl = '../storage/uploads/avatars/' . $filename;
                
                $updated = $db->update('users', $userId, [
                    'profile_picture' => $avatarUrl,
                    'updated_at' => date('c')
                ]);
                
                if ($updated) {
                    echo json_encode([
                        'success' => true,
                        'url' => $avatarUrl,
                        'message' => 'Avatar uploaded successfully'
                    ]);
                } else {
                    // Clean up uploaded file if database update fails
                    unlink($uploadPath);
                    echo json_encode(['success' => false, 'error' => 'Failed to update profile']);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to save file']);
            }
            break;
            
        case 'add_store_access':
            // Only admin can add store access for users
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $targetUserId = $input['user_id'] ?? null;
            $storeIds = $input['store_ids'] ?? ($input['store_id'] ? [$input['store_id']] : null);
            
            if (!$targetUserId || !$storeIds || !is_array($storeIds) || empty($storeIds)) {
                echo json_encode(['success' => false, 'error' => 'Missing user_id or store_ids']);
                exit;
            }
            
            $successCount = 0;
            $failedStores = [];
            $assignedStores = [];
            
            foreach ($storeIds as $storeId) {
                // Check if store exists
                $store = $db->read('stores', $storeId);
                if (!$store) {
                    $failedStores[] = "Store $storeId not found";
                    continue;
                }
                
                // Check if access already exists
                $allUserStores = $db->readAll('user_stores') ?? [];
                $exists = false;
                foreach ($allUserStores as $record) {
                    if (($record['user_id'] ?? '') === $targetUserId && ($record['store_id'] ?? '') === $storeId) {
                        $exists = true;
                        break;
                    }
                }
                
                if ($exists) {
                    $failedStores[] = ($store['store_name'] ?? $storeId) . " (already has access)";
                    continue;
                }
                
                // Create user_store access
                $accessId = uniqid('us_', true);
                $created = $db->create('user_stores', [
                    'user_id' => $targetUserId,
                    'store_id' => $storeId,
                    'assigned_by' => $userId,
                    'assigned_at' => date('c'),
                    'created_at' => date('c')
                ], $accessId);
                
                if ($created) {
                    $successCount++;
                    $assignedStores[] = $store['store_name'] ?? $store['name'] ?? $storeId;
                } else {
                    $failedStores[] = ($store['store_name'] ?? $store['name'] ?? $storeId) . " (database error)";
                }
            }
            
            if ($successCount > 0) {
                // Log activity for successful assignments
                logActivity(
                    'store_access_granted',
                    "Granted store access to user for " . $successCount . " store(s): " . implode(', ', $assignedStores),
                    [
                        'target_user' => $targetUserId,
                        'stores_assigned' => $assignedStores,
                        'count' => $successCount
                    ]
                );
            }
            
            if ($successCount === count($storeIds)) {
                echo json_encode([
                    'success' => true,
                    'message' => "Successfully assigned access to $successCount store(s)",
                    'count' => $successCount
                ]);
            } elseif ($successCount > 0) {
                echo json_encode([
                    'success' => true,
                    'message' => "Assigned $successCount of " . count($storeIds) . " stores. Some failed: " . implode(', ', $failedStores),
                    'count' => $successCount,
                    'failed' => $failedStores
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to assign any stores: ' . implode(', ', $failedStores),
                    'failed' => $failedStores
                ]);
            }
            break;
            
        case 'remove_store_access':
            error_log("=== REMOVE_STORE_ACCESS API CALLED ===");
            // Only admin can remove store access
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $targetUserId = $input['user_id'] ?? null;
            $storeId = $input['store_id'] ?? null;
            
            error_log("Target User ID: " . $targetUserId);
            error_log("Store ID to remove: " . $storeId);
            
            if (!$targetUserId || !$storeId) {
                echo json_encode(['success' => false, 'error' => 'Missing user_id or store_id']);
                exit;
            }
            
            // Find the user_store record - Get ALL to debug
            $allUserStores = $db->readAll('user_stores') ?? [];
            error_log("Total user_stores in database: " . count($allUserStores));
            
            // Find matching records
            $matchingRecords = [];
            foreach ($allUserStores as $id => $record) {
                if (($record['user_id'] ?? '') === $targetUserId && ($record['store_id'] ?? '') === $storeId) {
                    $matchingRecords[$id] = $record;
                    error_log("Found matching record - ID: $id");
                }
            }
            
            error_log("Found " . count($matchingRecords) . " matching records");
            
            if (empty($matchingRecords)) {
                error_log("ERROR: Store access not found!");
                echo json_encode([
                    'success' => false, 
                    'error' => 'Store access not found',
                    'debug' => [
                        'total_records' => count($allUserStores),
                        'target_user' => $targetUserId,
                        'target_store' => $storeId
                    ]
                ]);
                exit;
            }
            
            // Get the first matching record
            $userStoreId = array_keys($matchingRecords)[0];
            error_log("Attempting to delete user_store ID: " . $userStoreId);
            
            // Delete the access
            $deleted = $db->delete('user_stores', $userStoreId);
            
            error_log("Delete operation returned: " . ($deleted ? 'TRUE' : 'FALSE'));
            
            // Verify deletion by checking if record still exists
            $stillExists = $db->read('user_stores', $userStoreId);
            error_log("After delete, record still exists: " . ($stillExists ? 'YES' : 'NO'));
            
            if ($deleted && !$stillExists) {
                $store = $db->read('stores', $storeId);
                $storeName = $store['store_name'] ?? $storeId;
                
                error_log("SUCCESS: Deleted store access for: " . $storeName);
                
                // Log activity
                logActivity(
                    'store_access_revoked',
                    "Revoked store access from user for store: {$storeName}",
                    [
                        'target_user' => $targetUserId,
                        'store_id' => $storeId,
                        'store_name' => $storeName
                    ]
                );
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Store access removed successfully',
                    'debug' => [
                        'deleted_id' => $userStoreId,
                        'verified' => !$stillExists
                    ]
                ]);
            } else {
                error_log("ERROR: Delete returned true but record still exists or delete failed");
                echo json_encode([
                    'success' => false, 
                    'error' => 'Failed to remove store access - verification failed',
                    'debug' => [
                        'delete_returned' => $deleted,
                        'still_exists' => $stillExists ? true : false,
                        'attempted_id' => $userStoreId
                    ]
                ]);
            }
            break;
            
        case 'get_all_stores_for_management':
            // Only admin can access this
            $currentUser = $db->read('users', $userId);
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            if (!$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Get all stores
            $allStores = $db->readAll('stores', [['active', '==', true]]);
            
            // Get target user's current store access
            $targetUserId = $_GET['user_id'] ?? $userId;
            $userStores = $db->readAll('user_stores', [['user_id', '==', $targetUserId]]);
            $assignedStoreIds = array_column($userStores, 'store_id');
            
            $storesData = [];
            foreach ($allStores as $storeId => $store) {
                $storesData[] = [
                    'id' => $storeId,
                    'store_name' => $store['store_name'] ?? 'Unknown',
                    'city' => $store['city'] ?? '',
                    'state' => $store['state'] ?? '',
                    'has_access' => in_array($storeId, $assignedStoreIds)
                ];
            }
            
            echo json_encode([
                'success' => true,
                'data' => $storesData
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('Profile API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
