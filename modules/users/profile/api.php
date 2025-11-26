<?php
/**
 * Profile Data API - Optimized AJAX Endpoints
 * Provides on-demand data loading for profile sections
 */

header('Content-Type: application/json');
require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../sql_db.php';
require_once '../../../functions.php';
require_once '../../../activity_logger.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Lazy load Firebase only when needed
// $db = getDB(); 
$sqlDb = SQLDatabase::getInstance(); // PostgreSQL - PRIMARY
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// Close session early to prevent locking and improve concurrency
// We only need to read from session, not write (except for the DB cache which is already done in getInstance)
session_write_close();

// Enable caching for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: private, max-age=300'); // Cache for 5 minutes
    header('ETag: "' . md5($userId . $action . time()) . '"');
}

try {
    switch ($action) {
        case 'get_user_info':
            // PostgreSQL only
            $user = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if ($user) {
                // Remove sensitive data
                unset($user['password_hash']);
                echo json_encode(['success' => true, 'data' => $user, 'source' => 'postgresql']);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User not found']);
            }
            break;
            
        case 'get_activities':
            $limit = intval($_GET['limit'] ?? 20);
            $offset = intval($_GET['offset'] ?? 0);
            $targetUserId = $_GET['user_id'] ?? $userId;
            
            // PostgreSQL only - Get current user to check permissions
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $canManageUsers = currentUserHasPermission('can_manage_users') || currentUserHasPermission('can_view_users');
            
            // Check permissions - allow admin or users with manage_users permission
            if ($targetUserId !== $userId && $targetUserId !== 'all' && !$isAdmin && !$canManageUsers) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied - admin or user management permission required']);
                exit;
            }
            
            // If 'all' is requested but user is not admin/manager, restrict to own data
            if ($targetUserId === 'all' && !$isAdmin && !$canManageUsers) {
                $targetUserId = $userId;
            }
            
            // Build PostgreSQL query with JOIN to get user details
            $sql = "SELECT ua.*, u.username, u.full_name, u.role 
                    FROM user_activities ua 
                    LEFT JOIN users u ON ua.user_id = u.id 
                    WHERE ua.deleted_at IS NULL";
            $params = [];
            
            if ($targetUserId !== 'all') {
                // Match by both PostgreSQL id and firebase_id in user_activities table
                $sql .= " AND (ua.user_id = ? OR ua.user_id IN (SELECT id FROM users WHERE firebase_id = ?))";
                $params[] = $targetUserId;
                $params[] = $targetUserId;
            } elseif (!$isAdmin && !$canManageUsers) {
                // Non-admin/non-manager can only see their own
                $sql .= " AND (ua.user_id = ? OR ua.user_id IN (SELECT id FROM users WHERE firebase_id = ?))";
                $params[] = $userId;
                $params[] = $userId;
            }
            
            $sql .= " ORDER BY ua.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $activities = $sqlDb->fetchAll($sql, $params);
            
            // Format user name for display
            foreach ($activities as &$activity) {
                $userName = $activity['username'] ?? 'Unknown';
                if (!empty($activity['full_name'])) {
                    $userName = $activity['full_name'];
                }
                $activity['user_name'] = $userName;
                // Add role for clarity
                if (!empty($activity['role'])) {
                    $activity['user_role'] = ucfirst($activity['role']);
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $activities,
                'has_more' => count($activities) === $limit,
                'source' => 'postgresql'
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
            
            $db = getDB(); // Initialize Firebase only here
            $newActivities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC'], 1);
            
            echo json_encode([
                'success' => true,
                'has_new' => !empty($newActivities),
                'count' => count($newActivities)
            ]);
            break;
            
        case 'get_all_users':
            // Admin or user management permission required - PostgreSQL only
            // Check permission
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $canManageUsers = currentUserHasPermission('can_manage_users') || currentUserHasPermission('can_view_users');
            
            if (!$isAdmin && !$canManageUsers) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied - requires admin or user management permission']);
                exit;
            }
            
            // Check if this is for store access management
            $excludeAdmins = isset($_GET['exclude_admins']) && $_GET['exclude_admins'] === 'true';
            $includeDeleted = isset($_GET['include_deleted']) && $_GET['include_deleted'] === 'true';
            
            // Get all users from PostgreSQL
            $whereClause = $includeDeleted ? "" : "WHERE deleted_at IS NULL";
            // Fetch all columns to ensure we get permissions
            $users = $sqlDb->fetchAll("SELECT * FROM users {$whereClause} ORDER BY username ASC");
            
            // Fetch all user permissions to merge
            $allPermissions = $sqlDb->fetchAll("SELECT user_id, permission, value FROM user_permissions");
            $permissionsMap = [];
            foreach ($allPermissions as $perm) {
                // PostgreSQL returns 't'/'f' for boolean, or 1/0
                $val = $perm['value'];
                $isGranted = ($val === true || $val === 't' || $val === 'true' || $val === 1 || $val === '1');
                $permissionsMap[$perm['user_id']][$perm['permission']] = $isGranted;
            }

            $userList = [];
            foreach ($users as $user) {
                // Remove sensitive data
                unset($user['password_hash']);
                unset($user['remember_token']);
                unset($user['reset_token']);

                $userRole = strtolower($user['role'] ?? 'user');
                
                // Skip admins and managers if requested (for store access management)
                // Since they have access to all stores by default
                if ($excludeAdmins && ($userRole === 'admin' || $userRole === 'manager')) {
                    continue;
                }
                
                // Split full_name into first and last name for compatibility
                $nameParts = explode(' ', trim($user['full_name'] ?? ''), 2);
                $firstName = $nameParts[0] ?? '';
                $lastName = $nameParts[1] ?? '';
                
                // Base formatted data
                $formattedUser = [
                    'id' => $user['id'] ?? $user['firebase_id'] ?? '',
                    'username' => $user['username'] ?? 'Unknown',
                    'email' => $user['email'] ?? '',
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'role' => $user['role'] ?? 'staff',
                    'status' => $user['status'] ?? 'active',
                    'created_at' => $user['created_at'] ?? '',
                    'last_login' => $user['last_login'] ?? '',
                    'deleted_at' => $user['deleted_at'] ?? null,
                    'profile_picture' => $user['profile_picture'] ?? null
                ];

                // Merge with original user data
                $userWithData = array_merge($user, $formattedUser);

                // Apply permissions from user_permissions table
                // This overrides any stale columns in the users table
                if (isset($permissionsMap[$user['id']])) {
                    foreach ($permissionsMap[$user['id']] as $permKey => $permValue) {
                        $userWithData[$permKey] = $permValue;
                    }
                }

                $userList[] = $userWithData;
            }
            
            echo json_encode(['success' => true, 'data' => $userList, 'source' => 'postgresql']);
            break;
        
        case 'get_user':
            // Get specific user by ID - Admin or user management permission required
            $targetUserId = $_GET['user_id'] ?? null;
            
            if (!$targetUserId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }
            
            // Check permission
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $canManageUsers = currentUserHasPermission('can_manage_users') || currentUserHasPermission('can_view_users');
            
            // Allow viewing own profile or if admin/manager
            if ($targetUserId !== $userId && !$isAdmin && !$canManageUsers) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Get user from PostgreSQL
            $user = $sqlDb->fetch("SELECT id, firebase_id, username, email, full_name, role, created_at, last_login, status FROM users WHERE id = ? OR firebase_id = ?", [$targetUserId, $targetUserId]);
            
            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            // Split full_name into first and last name for compatibility
            $nameParts = explode(' ', trim($user['full_name'] ?? ''), 2);
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';
            
            $userData = [
                'id' => $user['id'] ?? $user['firebase_id'] ?? '',
                'username' => $user['username'] ?? 'Unknown',
                'email' => $user['email'] ?? '',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $user['role'] ?? 'staff',
                'status' => $user['status'] ?? 'active',
                'created_at' => $user['created_at'] ?? '',
                'last_login' => $user['last_login'] ?? ''
            ];
            
            echo json_encode(['success' => true, 'data' => $userData, 'source' => 'postgresql']);
            break;
            
        case 'export_activities':
            $format = $_GET['format'] ?? 'csv';
            $targetUserId = $_GET['user_id'] ?? $userId;
            
            // Check permission - PostgreSQL only
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $canManageUsers = ($currentUser['can_manage_users'] ?? false) || ($currentUser['can_view_users'] ?? false);
            
            if ($targetUserId !== $userId && $targetUserId !== 'all' && !$isAdmin && !$canManageUsers) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied - admin or user management permission required']);
                exit;
            }
            
            // If 'all' is requested but user is not admin/manager, restrict to own data
            if ($targetUserId === 'all' && !$isAdmin && !$canManageUsers) {
                $targetUserId = $userId;
            }
            
            // Get activities from PostgreSQL
            $sql = "SELECT * FROM user_activities WHERE deleted_at IS NULL";
            $params = [];
            
            if ($targetUserId !== 'all') {
                $sql .= " AND (user_id = ? OR user_id IN (SELECT id FROM users WHERE firebase_id = ?))";
                $params[] = $targetUserId;
                $params[] = $targetUserId;
            }
            
            $sql .= " ORDER BY created_at DESC";
            $activities = $sqlDb->fetchAll($sql, $params);
            
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="activities_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                $headers = ['Timestamp', 'User', 'Action Type', 'Description', 'IP Address'];
                fputcsv($output, $headers);
                
                foreach ($activities as $act) {
                    $actUser = isset($act['user_id']) ? $sqlDb->fetch("SELECT username FROM users WHERE id = ?", [$act['user_id']]) : null;
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
                    $actUser = null;
                    if (isset($act['user_id'])) {
                        $db = getDB(); // Initialize Firebase only if needed
                        $actUser = $db->read('users', $act['user_id']);
                    }
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
            $db = getDB(); // Initialize Firebase
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
            error_log("Current User ID: " . $userId);
            error_log("Target User ID: " . $targetUserId);
            
            try {
                // Check permission if viewing another user (PostgreSQL)
                if ($targetUserId !== $userId) {
                    $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
                    if (!$currentUser) {
                        $db = getDB(); // Fallback to Firebase
                        $currentUser = $db->read('users', $userId);
                    }
                    $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
                    $canManageUsers = currentUserHasPermission('can_manage_users') || currentUserHasPermission('can_view_users');
                    
                    error_log("Is Admin: " . ($isAdmin ? 'Yes' : 'No'));
                    error_log("Can Manage Users: " . ($canManageUsers ? 'Yes' : 'No'));
                    
                    if (!$isAdmin && !$canManageUsers) {
                        http_response_code(403);
                        echo json_encode(['success' => false, 'error' => 'Permission denied - admin or user management permission required']);
                        exit;
                    }
                }
                
                // Try to get user from PostgreSQL first - check both id and firebase_id
                $user = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$targetUserId, $targetUserId]);
                if (!$user) {
                    $db = getDB(); // Fallback to Firebase
                    $user = $db->read('users', $targetUserId);
                }
                
                error_log("User found: " . ($user ? 'Yes' : 'No'));
                
                if (!$user) {
                    echo json_encode(['success' => false, 'error' => 'User not found']);
                    exit;
                }
                
            } catch (Exception $e) {
                error_log("PostgreSQL error: " . $e->getMessage());
                // Fallback to Firebase
                if ($targetUserId !== $userId) {
                    $db = getDB(); // Fallback to Firebase
                    $currentUser = $db->read('users', $userId);
                    $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
                    $canManageUsers = currentUserHasPermission('can_manage_users') || currentUserHasPermission('can_view_users');
                    
                    if (!$isAdmin && !$canManageUsers) {
                        http_response_code(403);
                        echo json_encode(['success' => false, 'error' => 'Permission denied - admin or user management permission required']);
                        exit;
                    }
                }
                
                $db = getDB(); // Fallback to Firebase
                $user = $db->read('users', $targetUserId);
                error_log("User data: " . json_encode($user));
                
                if (!$user) {
                    echo json_encode(['success' => false, 'error' => 'User not found']);
                    exit;
                }
            }
            
            $role = strtolower(trim($user['role'] ?? 'user'));
            
            // Get user's permissions from user_permissions table (with values)
            $userPerms = $sqlDb->fetchAll(
                "SELECT permission, value FROM user_permissions WHERE user_id = ?",
                [$user['id']]
            );
            
            // Convert to associative array: permission => true/false
            $grantedPerms = [];
            $revokedPerms = [];
            foreach ($userPerms as $p) {
                // Handle PostgreSQL boolean return values (t/f, true/false, 1/0)
                $val = $p['value'];
                $isGranted = ($val === true || $val === 't' || $val === 'true' || $val === 1 || $val === '1');
                
                if ($isGranted) {
                    $grantedPerms[] = $p['permission'];
                } else {
                    $revokedPerms[] = $p['permission'];
                }
            }
            
            // Define default permissions for each role (if no custom permissions set)
            $roleDefaults = [
                'user' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_view_stores' => true
                ],
                'cashier' => [
                    'can_view_reports' => true,
                    'can_view_inventory' => true,
                    'can_use_pos' => true,
                    'can_manage_pos' => true,
                    'can_view_stores' => true,
                    'can_scan_barcodes' => true
                ],
                'warehouse' => [
                    'can_view_inventory' => true,
                    'can_edit_inventory' => true,
                    'can_restock_inventory' => true,
                    'can_manage_stock_transfers' => true,
                    'can_manage_suppliers' => true,
                    'can_manage_purchase_orders' => true,
                    'can_view_audit_logs' => true,
                    'can_scan_barcodes' => true
                ],
                'analyst' => [
                    'can_view_reports' => true,
                    'can_view_forecasting' => true,
                    'can_manage_alerts' => true,
                    'can_view_inventory' => true,
                    'can_view_stores' => true,
                    'can_view_audit_logs' => true
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
                    'can_view_users' => true,
                    'can_manage_suppliers' => true,
                    'can_manage_purchase_orders' => true,
                    'can_manage_stock_transfers' => true,
                    'can_view_forecasting' => true,
                    'can_manage_alerts' => true,
                    'can_view_audit_logs' => true,
                    'can_scan_barcodes' => true
                ],
                'admin' => [] // Admin gets all permissions
            ];
            
            $defaults = $roleDefaults[$role] ?? $roleDefaults['user'];
            
            // Helper function to check if user has permission
            $hasPerm = function($key) use ($grantedPerms, $revokedPerms, $defaults, $role) {
                // Check if explicitly revoked (takes precedence)
                if (in_array($key, $revokedPerms)) {
                    return false;
                }
                
                // Check if explicitly granted
                if (in_array($key, $grantedPerms)) {
                    return true;
                }
                
                // Admin gets all permissions by default (unless explicitly revoked above)
                if ($role === 'admin') {
                    return true;
                }
                
                // Otherwise use role defaults
                return $defaults[$key] ?? false;
            };
            
            // Build permissions object from user_permissions table
            $permissions = [
                'role' => ucfirst($role),
                // Reports
                'can_view_reports' => $hasPerm('can_view_reports'),
                // Inventory (granular)
                'can_view_inventory' => $hasPerm('can_view_inventory'),
                'can_add_inventory' => $hasPerm('can_add_inventory'),
                'can_edit_inventory' => $hasPerm('can_edit_inventory'),
                'can_delete_inventory' => $hasPerm('can_delete_inventory'),
                'can_restock_inventory' => $hasPerm('can_restock_inventory'),
                'can_view_audit_logs' => $hasPerm('can_view_audit_logs'),
                'can_scan_barcodes' => $hasPerm('can_scan_barcodes'),
                // Stores (granular)
                'can_view_stores' => $hasPerm('can_view_stores'),
                'can_add_stores' => $hasPerm('can_add_stores'),
                'can_edit_stores' => $hasPerm('can_edit_stores'),
                'can_delete_stores' => $hasPerm('can_delete_stores'),
                // POS
                'can_use_pos' => $hasPerm('can_use_pos'),
                'can_manage_pos' => $hasPerm('can_manage_pos'),
                // Users
                'can_view_users' => $hasPerm('can_view_users'),
                'can_manage_users' => $hasPerm('can_manage_users'),
                // Supply Chain
                'can_manage_suppliers' => $hasPerm('can_manage_suppliers'),
                'can_manage_purchase_orders' => $hasPerm('can_manage_purchase_orders'),
                'can_send_purchase_orders' => $hasPerm('can_send_purchase_orders'),
                'can_manage_stock_transfers' => $hasPerm('can_manage_stock_transfers'),
                // Analytics
                'can_view_forecasting' => $hasPerm('can_view_forecasting'),
                'can_manage_alerts' => $hasPerm('can_manage_alerts'),
                // Legacy permissions (for backward compatibility)
                'can_manage_inventory' => $hasPerm('can_manage_inventory'),
                'can_manage_stores' => $hasPerm('can_manage_stores')
            ];
            
            error_log("Permissions returned: " . json_encode($permissions));
            echo json_encode(['success' => true, 'data' => $permissions]);
            break;
            
        case 'update_permission':
            // Admin or user management permission required
            // error_log("=== UPDATE_PERMISSION CALLED ===");
            // error_log("Current User ID: $userId");
            
            // Get current user and check permissions - PostgreSQL only
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            
            // Check if user has can_manage_users permission from user_permissions table
            $canManageUsers = false;
            if (!$isAdmin) {
                $permCheck = $sqlDb->fetch(
                    "SELECT 1 FROM user_permissions WHERE user_id = ? AND permission = 'can_manage_users'",
                    [$currentUser['id']]
                );
                $canManageUsers = (bool)$permCheck;
            }
            
            // error_log("Current user role: " . ($currentUser['role'] ?? 'unknown'));
            // error_log("Is Admin: " . ($isAdmin ? 'Yes' : 'No'));
            // error_log("Can Manage Users permission: " . ($canManageUsers ? 'Yes' : 'No'));
            
            if (!$isAdmin && !$canManageUsers) {
                // error_log("Permission denied - user is not admin and does not have can_manage_users");
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied. Admin or user management permission required.']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $targetUserId = $input['user_id'] ?? null;
            $permission = $input['permission'] ?? null;
            $value = $input['value'] ?? false;
            
            // Properly convert value to boolean
            if (is_string($value)) {
                $value = ($value === 'true' || $value === '1' || strtolower($value) === 'on');
            } else {
                $value = (bool)$value;
            }
            
            // error_log("Target User: $targetUserId");
            // error_log("Permission: $permission");
            // error_log("Value: " . ($value ? 'true' : 'false'));
            
            if (!$targetUserId || !$permission) {
                echo json_encode(['success' => false, 'error' => 'Missing user_id or permission']);
                exit;
            }
            
            // Validate permission name to prevent SQL injection
            $allowedPermissions = [
                'can_view_reports', 'can_view_inventory', 'can_add_inventory', 'can_edit_inventory',
                'can_delete_inventory', 'can_view_stores', 'can_add_stores', 'can_edit_stores',
                'can_delete_stores', 'can_view_users', 'can_manage_users', 'can_use_pos',
                'can_view_analytics', 'can_manage_alerts', 'can_manage_inventory',
                'can_manage_pos', 'can_manage_suppliers', 'can_manage_purchase_orders', 'can_send_purchase_orders', 'can_manage_stock_transfers',
                'can_view_forecasting', 'can_restock_inventory', 'can_view_audit_logs', 'can_scan_barcodes'
            ];
            
            if (!in_array($permission, $allowedPermissions)) {
                error_log("Invalid permission name: $permission");
                echo json_encode(['success' => false, 'error' => 'Invalid permission name']);
                exit;
            }
            
            // Get target user from PostgreSQL
            $targetUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$targetUserId, $targetUserId]);
            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Target user not found']);
                exit;
            }
            
            // Get the actual PostgreSQL ID
            $pgUserId = $targetUser['id'];
            
            // error_log("Found target user in PostgreSQL, ID: $pgUserId");
            
            // Update permission in user_permissions table with value column
            // PostgreSQL expects 't'/'f' or 'true'/'false' strings for boolean
            $boolValue = $value ? 't' : 'f';
            
            // error_log("Setting permission value to: $boolValue");
            
            // Check if permission record already exists
            // Use fetchAll to check for duplicates
            $existingRecords = $sqlDb->fetchAll("SELECT id FROM user_permissions WHERE user_id = ? AND permission = ?", [$pgUserId, $permission]);
            
            if (!empty($existingRecords)) {
                // Update ALL existing records for this user/permission combo to ensure consistency
                // This handles cases where duplicates might have been created
                $updated = $sqlDb->execute(
                    "UPDATE user_permissions SET value = ?::boolean WHERE user_id = ? AND permission = ?",
                    [$boolValue, $pgUserId, $permission]
                );
                
                // If there are duplicates, we should probably clean them up, but updating all is safer for now
                if (count($existingRecords) > 1) {
                    // error_log("WARNING: Found " . count($existingRecords) . " duplicate permission records for user $pgUserId, permission $permission");
                }
            } else {
                // Insert new record (for both grant and revoke)
                $updated = $sqlDb->execute(
                    "INSERT INTO user_permissions (user_id, permission, value, granted_by, created_at) VALUES (?, ?, ?::boolean, ?, NOW())",
                    [$pgUserId, $permission, $boolValue, $currentUser['id']]
                );
            }
            
            // error_log("PostgreSQL update result: " . ($updated ? 'success' : 'failed'));
            
            if ($updated) {
                // Log activity
                $action = $value ? 'granted' : 'revoked';
                $permName = str_replace('can_', '', $permission);
                $permName = str_replace('_', ' ', $permName);
                $description = ucfirst($action) . " permission: " . ucfirst($permName) . " for user " . ($targetUser['username'] ?? $targetUserId);
                
                // Use background logging if possible, or just skip if not critical
                // logActivity('permission_updated', $description, [ ... ]);
                // For speed, we'll do a direct insert here instead of loading the full logger if possible, 
                // or just call it but we know we optimized DB connection already.
                
                logActivity('permission_updated', $description, [
                    'target_user_id' => $targetUserId,
                    'target_username' => $targetUser['username'] ?? '',
                    'permission' => $permission,
                    'action' => $action,
                    'value' => $value
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Permission updated successfully',
                    'source' => 'postgresql'
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to update permission in database']);
            }
            break;
            
        case 'assign_role':
            // Admin only - check PostgreSQL first
            try {
                $currentUser = $sqlDb->fetch("SELECT role FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
                if (!$currentUser) {
                    $currentUser = $db->read('users', $userId);
                }
            } catch (Exception $e) {
                $currentUser = $db->read('users', $userId);
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $canManageUsers = currentUserHasPermission('can_manage_users');
            
            if (!$isAdmin && !$canManageUsers) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied. Admin or user management permission required.']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $targetUserId = $input['user_id'] ?? null;
            $role = strtolower($input['role'] ?? '');
            
            error_log("=== ASSIGN_ROLE CALLED ===");
            error_log("Target User: $targetUserId");
            error_log("New Role: $role");
            
            if (!$targetUserId || !$role) {
                echo json_encode(['success' => false, 'error' => 'Missing user_id or role']);
                exit;
            }
            
            // Validate role
            $validRoles = ['user', 'cashier', 'warehouse', 'analyst', 'manager', 'admin'];
            if (!in_array($role, $validRoles)) {
                echo json_encode(['success' => false, 'error' => 'Invalid role. Must be: user, cashier, warehouse, analyst, manager, or admin']);
                exit;
            }
            
            // Try PostgreSQL first
            try {
                // Get target user from PostgreSQL
                $targetUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$targetUserId, $targetUserId]);
                if (!$targetUser) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'error' => 'User not found']);
                    exit;
                }
                
                $targetUserDbId = $targetUser['id'];
                
                // Define role permissions with granular access control
                $rolePermissions = [
                    'user' => [
                        'can_view_reports' => true,
                        'can_view_inventory' => true,
                        'can_view_stores' => true,
                    ],
                    'cashier' => [
                        'can_view_reports' => true,
                        'can_view_inventory' => true,
                        'can_view_stores' => true,
                        'can_use_pos' => true,
                        'can_manage_pos' => true,
                        'can_scan_barcodes' => true,
                    ],
                    'warehouse' => [
                        'can_view_inventory' => true,
                        'can_edit_inventory' => true,
                        'can_restock_inventory' => true,
                        'can_manage_stock_transfers' => true,
                        'can_manage_suppliers' => true,
                        'can_manage_purchase_orders' => true,
                        'can_view_audit_logs' => true,
                        'can_scan_barcodes' => true,
                    ],
                    'analyst' => [
                        'can_view_reports' => true,
                        'can_view_forecasting' => true,
                        'can_manage_alerts' => true,
                        'can_view_inventory' => true,
                        'can_view_stores' => true,
                        'can_view_audit_logs' => true,
                        'can_view_users' => true,
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
                        'can_view_users' => true,
                        'can_manage_suppliers' => true,
                        'can_manage_purchase_orders' => true,
                        'can_send_purchase_orders' => true,
                        'can_manage_stock_transfers' => true,
                        'can_view_forecasting' => true,
                        'can_manage_alerts' => true,
                        'can_restock_inventory' => true,
                        'can_view_audit_logs' => true,
                        'can_scan_barcodes' => true,
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
                        'can_configure_system' => true,
                        'can_manage_suppliers' => true,
                        'can_manage_purchase_orders' => true,
                        'can_send_purchase_orders' => true,
                        'can_manage_stock_transfers' => true,
                        'can_view_forecasting' => true,
                        'can_manage_alerts' => true,
                        'can_restock_inventory' => true,
                        'can_view_audit_logs' => true,
                        'can_scan_barcodes' => true,
                    ]
                ];
                
                $permissions = $rolePermissions[$role] ?? [];
                
                // Update role in users table
                $updated = $sqlDb->execute(
                    "UPDATE users SET role = ? WHERE id = ?",
                    [$role, $targetUserDbId]
                );
                
                if (!$updated) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'error' => 'Failed to update role']);
                    exit;
                }
                
                // Clear existing permissions for this user
                $sqlDb->execute("DELETE FROM user_permissions WHERE user_id = ?", [$targetUserDbId]);
                
                // Insert role-based permissions
                foreach ($permissions as $perm => $value) {
                    if ($value) {
                        $sqlDb->execute(
                            "INSERT INTO user_permissions (user_id, permission, value, granted_by, created_at) VALUES (?, ?, 't', ?, NOW())",
                            [$targetUserDbId, $perm, $currentUser['id']]
                        );
                    }
                }
                
                // Log activity
                $permCount = count($permissions);
                $description = "Assigned " . ucfirst($role) . " role to user " . ($targetUser['username'] ?? $targetUserId) . " ($permCount permissions)";
                logActivity('role_assigned', $description, [
                    'target_user_id' => $targetUserId,
                    'target_username' => $targetUser['username'] ?? '',
                    'role' => $role,
                    'permissions_granted' => $permCount,
                    'permissions' => array_keys($permissions)
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => ucfirst($role) . " role assigned with $permCount permissions",
                    'role' => ucfirst($role),
                    'permissions' => $permissions
                ]);
                
            } catch (Exception $e) {
                error_log("PostgreSQL error in assign_role: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
            break;
            
        case 'get_stores':
            error_log("=== GET_STORES API CALLED ===");
            $targetUserId = $_GET['user_id'] ?? $userId;
            error_log("Current User ID: " . $userId);
            error_log("Target User ID: " . $targetUserId);
            
            // Check permission - PostgreSQL only
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $isManager = (strtolower($currentUser['role'] ?? '') === 'manager');
            $canManageUsers = currentUserHasPermission('can_manage_users') || currentUserHasPermission('can_view_users');
            
            error_log("Is Admin: " . ($isAdmin ? 'Yes' : 'No'));
            error_log("Is Manager: " . ($isManager ? 'Yes' : 'No'));
            error_log("Can Manage Users: " . ($canManageUsers ? 'Yes' : 'No'));
            
            // Only admin/manager or users with manage permission can view other users' stores
            if ($targetUserId !== $userId && !$isAdmin && !$isManager && !$canManageUsers) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied - admin, manager, or user management permission required']);
                exit;
            }
            
            // Get target user to find their PostgreSQL ID and Role
            $targetUser = $sqlDb->fetch("SELECT id, role FROM users WHERE id = ? OR firebase_id = ?", [$targetUserId, $targetUserId]);
            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Target user not found']);
                exit;
            }
            
            // If target user is Admin, they have access to all stores
            $targetUserRole = strtolower(trim($targetUser['role'] ?? ''));
            
            if ($targetUserRole === 'admin') {
                $stores = $sqlDb->fetchAll("SELECT * FROM stores ORDER BY name ASC");
            } else {
                // Get user's store access from PostgreSQL user_store_access table
                $sql = "
                    SELECT s.*, usa.granted_by, usa.created_at as access_granted_at
                    FROM user_store_access usa
                    INNER JOIN stores s ON usa.store_id = s.id
                    WHERE usa.user_id = ?
                    ORDER BY s.name ASC
                ";
                
                $stores = $sqlDb->fetchAll($sql, [$targetUser['id']]);
            }
            
            error_log("Found " . count($stores) . " stores from PostgreSQL");
            
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
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $isManager = (strtolower($currentUser['role'] ?? '') === 'manager');
            
            if (!$isAdmin && !$isManager) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Get target user PostgreSQL ID
            $targetUser = $sqlDb->fetch("SELECT id FROM users WHERE id = ? OR firebase_id = ?", [$targetUserId, $targetUserId]);
            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Target user not found']);
                exit;
            }
            
            // Get stores NOT already assigned to this user
            $sql = "
                SELECT s.*
                FROM stores s
                WHERE s.deleted_at IS NULL
                AND s.id NOT IN (
                    SELECT store_id 
                    FROM user_store_access 
                    WHERE user_id = ?
                )
                ORDER BY s.name ASC
            ";
            
            $availableStores = $sqlDb->fetchAll($sql, [$targetUser['id']]);
            
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
            // Only admin or user managers can add store access for users
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $isManager = (strtolower($currentUser['role'] ?? '') === 'manager');
            $canManageUsers = currentUserHasPermission('can_manage_users');
            
            error_log("=== ADD_STORE_ACCESS CALLED ===");
            error_log("Is Admin: " . ($isAdmin ? 'Yes' : 'No'));
            error_log("Is Manager: " . ($isManager ? 'Yes' : 'No'));
            error_log("Can Manage Users: " . ($canManageUsers ? 'Yes' : 'No'));
            
            if (!$isAdmin && !$isManager && !$canManageUsers) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied - admin, manager, or user management permission required']);
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
            
            error_log("Target User: $targetUserId");
            error_log("Store IDs: " . json_encode($storeIds));
            
            if (!$targetUserId || !$storeIds || !is_array($storeIds) || empty($storeIds)) {
                echo json_encode(['success' => false, 'error' => 'Missing user_id or store_ids']);
                exit;
            }
            
            // Get the target user's database ID (not firebase_id)
            $targetUser = $sqlDb->fetch("SELECT id, username FROM users WHERE id = ? OR firebase_id = ?", [$targetUserId, $targetUserId]);
            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Target user not found']);
                exit;
            }
            $targetUserDbId = $targetUser['id'];
            
            error_log("Target User DB ID: $targetUserDbId");
            
            $successCount = 0;
            $failedStores = [];
            $assignedStores = [];
            
            foreach ($storeIds as $storeId) {
                // Check if store exists in PostgreSQL
                $store = $sqlDb->fetch("SELECT id, name FROM stores WHERE id = ?", [$storeId]);
                if (!$store) {
                    $failedStores[] = "Store $storeId not found";
                    continue;
                }
                
                // Check if access already exists
                $existing = $sqlDb->fetch("SELECT id FROM user_store_access WHERE user_id = ? AND store_id = ?", [$targetUserDbId, $storeId]);
                
                if ($existing) {
                    $failedStores[] = ($store['name'] ?? $storeId) . " (already has access)";
                    continue;
                }
                
                // Insert new store access (use created_at, not granted_at)
                $created = $sqlDb->execute("
                    INSERT INTO user_store_access (user_id, store_id, granted_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ", [$targetUserDbId, $storeId, $currentUser['id']]);
                
                if ($created) {
                    $successCount++;
                    $assignedStores[] = $store['name'] ?? $storeId;
                } else {
                    $failedStores[] = ($store['name'] ?? $storeId) . " (database error)";
                }
            }
            
            if ($successCount > 0) {
                // Log activity for successful assignments
                logActivity(
                    'store_access_granted',
                    "Granted store access to " . ($targetUser['username'] ?? $targetUserId) . " for " . $successCount . " store(s): " . implode(', ', $assignedStores),
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
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to assign any stores: ' . implode(', ', $failedStores),
                    'failed' => $failedStores
                ]);
            }
            break;
            
        case 'remove_store_access':
            error_log("=== REMOVE_STORE_ACCESS API CALLED ===");
            
            try {
                // Only admin or user managers can remove store access - check PostgreSQL first
                $currentUser = $sqlDb->fetch("SELECT role FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
                if (!$currentUser) {
                    $currentUser = $db->read('users', $userId);
                }
                $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
                $isManager = (strtolower($currentUser['role'] ?? '') === 'manager');
                $canManageUsers = currentUserHasPermission('can_manage_users');
                
                error_log("Is Admin: " . ($isAdmin ? 'Yes' : 'No'));
                error_log("Is Manager: " . ($isManager ? 'Yes' : 'No'));
                error_log("Can Manage Users: " . ($canManageUsers ? 'Yes' : 'No'));
                
                if (!$isAdmin && !$isManager && !$canManageUsers) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Permission denied - admin, manager, or user management permission required']);
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
                
                // Get the target user's database ID
                $targetUser = $sqlDb->fetch("SELECT id FROM users WHERE id = ? OR firebase_id = ?", [$targetUserId, $targetUserId]);
                if (!$targetUser) {
                    echo json_encode(['success' => false, 'error' => 'User not found']);
                    exit;
                }
                $targetUserDbId = $targetUser['id'];
                
                // Delete from PostgreSQL
                $deleted = $sqlDb->execute("DELETE FROM user_store_access WHERE user_id = ? AND store_id = ?", [$targetUserDbId, $storeId]);
                
                error_log("PostgreSQL delete operation returned: " . ($deleted ? 'TRUE' : 'FALSE'));
                
                if ($deleted) {
                    // Get store name for logging
                    $store = $sqlDb->fetch("SELECT name FROM stores WHERE id = ?", [$storeId]);
                    $storeName = $store['name'] ?? $storeId;
                    
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
                        'source' => 'postgresql'
                    ]);
                } else {
                    error_log("ERROR: PostgreSQL delete failed");
                    echo json_encode([
                        'success' => false, 
                        'error' => 'Failed to remove store access'
                    ]);
                }
                
            } catch (Exception $e) {
                error_log("PostgreSQL error in remove_store_access: " . $e->getMessage());
                // Fallback to Firebase
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
                        'source' => 'firebase',
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
            }
            break;
            
        case 'update_user':
            // Update user details - Admin or user management permission required
            $input = json_decode(file_get_contents('php://input'), true);
            $targetUserId = $input['user_id'] ?? null;
            
            if (!$targetUserId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }
            
            // Check permission
            $currentUser = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
            if (!$currentUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Current user not found']);
                exit;
            }
            
            $isAdmin = (strtolower($currentUser['role'] ?? '') === 'admin');
            $canManageUsers = ($currentUser['can_manage_users'] ?? false) || ($currentUser['can_view_users'] ?? false);
            
            // Allow updating own profile or if admin/manager
            if ($targetUserId !== $userId && !$isAdmin && !$canManageUsers) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Validate inputs
            $username = trim($input['username'] ?? '');
            $email = trim($input['email'] ?? '');
            $firstName = trim($input['first_name'] ?? '');
            $lastName = trim($input['last_name'] ?? '');
            $role = $input['role'] ?? '';
            $password = $input['password'] ?? '';
            
            if (empty($username)) {
                echo json_encode(['success' => false, 'error' => 'Username is required']);
                exit;
            }
            
            // Check if username exists for other users
            $existingUser = $sqlDb->fetch("SELECT id FROM users WHERE username = ? AND id != ? AND id != ?", [$username, $targetUserId, $targetUserId]);
            if ($existingUser) {
                echo json_encode(['success' => false, 'error' => 'Username already taken']);
                exit;
            }
            
            // Prepare update data
            $fullName = trim($firstName . ' ' . $lastName);
            $updateFields = [
                'username' => $username,
                'email' => $email,
                'full_name' => $fullName,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Only admin can change roles
            if ($isAdmin && !empty($role)) {
                $updateFields['role'] = strtolower($role);
            }
            
            // Handle password update if provided
            if (!empty($password)) {
                if (strlen($password) < 6) {
                    echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters']);
                    exit;
                }
                $updateFields['password_hash'] = hashPassword($password);
            }
            
            // Construct SQL update
            $setClause = [];
            $params = [];
            foreach ($updateFields as $field => $value) {
                $setClause[] = "$field = ?";
                $params[] = $value;
            }
            
            // Add ID to params
            $params[] = $targetUserId;
            $params[] = $targetUserId;
            
            $sql = "UPDATE users SET " . implode(', ', $setClause) . " WHERE id = ? OR firebase_id = ?";
            
            try {
                $updated = $sqlDb->execute($sql, $params);
                
                if ($updated) {
                    logActivity('user_updated', "Updated user profile for $username", $userId);
                    echo json_encode(['success' => true, 'message' => 'User updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Failed to update user']);
                }
            } catch (Exception $e) {
                error_log("Update user error: " . $e->getMessage());
                echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            }
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
