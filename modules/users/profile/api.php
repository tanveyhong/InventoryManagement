<?php
/**
 * Profile Data API - Optimized AJAX Endpoints
 * Provides on-demand data loading for profile sections
 */

header('Content-Type: application/json');
require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

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
                    'role' => $user['role'] ?? 'user'
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
            $user = $db->read('users', $userId);
            
            // Initialize permissions
            $permissions = [
                'role' => 'Unknown',
                'can_view_reports' => false,
                'can_manage_inventory' => false,
                'can_manage_users' => false,
                'can_manage_stores' => false,
                'can_configure_system' => false
            ];
            
            // Check if user has direct role field or role_id
            if (!empty($user['role']) && is_string($user['role'])) {
                // Direct role assignment
                $roleStr = strtolower($user['role']);
                $permissions['role'] = ucfirst($roleStr);
                
                // Set permissions based on role
                if ($roleStr === 'admin') {
                    $permissions['can_view_reports'] = true;
                    $permissions['can_manage_inventory'] = true;
                    $permissions['can_manage_users'] = true;
                    $permissions['can_manage_stores'] = true;
                    $permissions['can_configure_system'] = true;
                } elseif ($roleStr === 'manager') {
                    $permissions['can_view_reports'] = true;
                    $permissions['can_manage_inventory'] = true;
                    $permissions['can_manage_stores'] = true;
                } elseif ($roleStr === 'user') {
                    $permissions['can_view_reports'] = true;
                }
            } elseif (!empty($user['role_id'])) {
                // Role ID reference
                $role = $db->read('roles', $user['role_id']);
                if ($role) {
                    $permissions['role'] = $role['role_name'] ?? 'Unknown';
                    $permissions['can_view_reports'] = $role['can_view_reports'] ?? false;
                    $permissions['can_manage_inventory'] = $role['can_manage_inventory'] ?? false;
                    $permissions['can_manage_users'] = $role['can_manage_users'] ?? false;
                    $permissions['can_manage_stores'] = $role['can_manage_stores'] ?? false;
                    $permissions['can_configure_system'] = $role['can_configure_system'] ?? false;
                }
            }
            
            echo json_encode(['success' => true, 'data' => $permissions]);
            break;
            
        case 'get_stores':
            $userStores = $db->readAll('user_stores', [
                ['user_id', '==', $userId]
            ]);
            
            $storeIds = array_column($userStores, 'store_id');
            $stores = [];
            
            // Batch load stores
            if (!empty($storeIds)) {
                $allStores = $db->readAll('stores', [['active', '==', true]]);
                foreach ($allStores as $store) {
                    if (in_array($store['id'], $storeIds)) {
                        $stores[] = $store;
                    }
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $stores,
                'count' => count($stores)
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
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log('Profile API error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
