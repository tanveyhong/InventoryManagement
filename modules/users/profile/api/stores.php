<?php
/**
 * Stores API - Optimized Backend Endpoint
 * Handles all store access management requests with caching
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
$userRole = $currentUser['role'] ?? 'user';
$isAdmin = $userRole === 'admin';
$isManager = $userRole === 'manager' || $isAdmin;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list_stores':
            // Cache stores list (5 min TTL)
            $cacheKey = "stores_list_all";
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5($cacheKey) . '.json';
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
                echo file_get_contents($cacheFile);
                exit;
            }
            
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 50)));
            
            // Fetch all stores
            $stores = $db->readAll('stores', [['active', '==', true]], ['name', 'ASC'], 200);
            
            // Pagination
            $total = count($stores);
            $totalPages = ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            $stores = array_slice($stores, $offset, $perPage);
            
            // Simplify store data
            $stores = array_map(function($s) {
                return [
                    'id' => $s['id'] ?? '',
                    'name' => $s['name'] ?? '',
                    'address' => $s['address'] ?? '',
                    'phone' => $s['phone'] ?? '',
                    'has_pos' => $s['has_pos'] ?? false,
                    'manager' => $s['manager'] ?? ''
                ];
            }, $stores);
            
            $response = [
                'success' => true,
                'data' => $stores,
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
            
        case 'list_users':
            // Cache users list (5 min TTL)
            $cacheKey = "store_users_list";
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5($cacheKey) . '.json';
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
                echo file_get_contents($cacheFile);
                exit;
            }
            
            $users = $db->readAll('users', [], ['first_name', 'ASC'], 200);
            
            // Simplify user data
            $users = array_map(function($u) {
                return [
                    'id' => $u['id'] ?? '',
                    'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                    'email' => $u['email'] ?? '',
                    'role' => $u['role'] ?? 'user'
                ];
            }, $users);
            
            $response = ['success' => true, 'data' => $users];
            
            // Cache response
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0755, true);
            }
            file_put_contents($cacheFile, json_encode($response));
            
            echo json_encode($response);
            break;
            
        case 'get_user_stores':
            $userId = $_GET['user_id'] ?? '';
            
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }
            
            // Cache user stores (2 min TTL)
            $cacheKey = "user_stores_{$userId}";
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5($cacheKey) . '.json';
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 120) {
                echo file_get_contents($cacheFile);
                exit;
            }
            
            // Get user-store assignments
            $userStores = $db->readAll('user_stores', [['user_id', '==', $userId]]);
            
            // Get store details
            $storeIds = array_column($userStores, 'store_id');
            $stores = [];
            
            if (!empty($storeIds)) {
                $allStores = $db->readAll('stores', [['active', '==', true]], ['name', 'ASC']);
                foreach ($allStores as $store) {
                    $sid = $store['id'] ?? '';
                    if (in_array($sid, $storeIds)) {
                        // Find role for this store
                        $roleInStore = 'employee';
                        foreach ($userStores as $us) {
                            if ($us['store_id'] === $sid) {
                                $roleInStore = $us['role'] ?? 'employee';
                                break;
                            }
                        }
                        
                        $stores[] = [
                            'id' => $sid,
                            'name' => $store['name'] ?? '',
                            'address' => $store['address'] ?? '',
                            'role' => $roleInStore
                        ];
                    }
                }
            }
            
            $response = [
                'success' => true,
                'data' => $stores,
                'count' => count($stores)
            ];
            
            // Cache response
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0755, true);
            }
            file_put_contents($cacheFile, json_encode($response));
            
            echo json_encode($response);
            break;
            
        case 'update_user_stores':
            if (!$isManager) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Manager access required']);
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $userId = $_POST['user_id'] ?? '';
            $storeIds = json_decode($_POST['store_ids'] ?? '[]', true);
            $storeRoles = json_decode($_POST['store_roles'] ?? '{}', true);
            
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }
            
            // Get current assignments
            $existing = $db->readAll('user_stores', [['user_id', '==', $userId]]);
            $existingMap = [];
            foreach ($existing as $e) {
                $existingMap[(string)$e['store_id']] = $e;
            }
            
            // Build target assignments
            $targetMap = [];
            foreach ($storeIds as $sid) {
                $targetMap[$sid] = $storeRoles[$sid] ?? 'employee';
            }
            
            $added = 0;
            $updated = 0;
            $removed = 0;
            
            // Add or update assignments
            foreach ($targetMap as $sid => $role) {
                if (!isset($existingMap[$sid])) {
                    $db->create('user_stores', [
                        'user_id' => $userId,
                        'store_id' => $sid,
                        'role' => $role,
                        'created_at' => date('c')
                    ]);
                    $added++;
                } else {
                    // Update role if changed
                    if (($existingMap[$sid]['role'] ?? '') !== $role) {
                        $db->update('user_stores', $existingMap[$sid]['id'], [
                            'role' => $role,
                            'updated_at' => date('c')
                        ]);
                        $updated++;
                    }
                }
            }
            
            // Remove assignments not in target
            foreach ($existingMap as $sid => $assignment) {
                if (!isset($targetMap[$sid])) {
                    $db->delete('user_stores', $assignment['id']);
                    $removed++;
                }
            }
            
            // Clear cache
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5("user_stores_{$userId}") . '.json';
            @unlink($cacheFile);
            
            // Log action
            $db->create('user_activities', [
                'user_id' => $currentUserId,
                'action_type' => 'store_access_changed',
                'description' => "Updated store access for user {$userId} (added: {$added}, updated: {$updated}, removed: {$removed})",
                'metadata' => json_encode(['user_id' => $userId, 'added' => $added, 'updated' => $updated, 'removed' => $removed]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('c')
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => "Store access updated successfully",
                'stats' => ['added' => $added, 'updated' => $updated, 'removed' => $removed]
            ]);
            break;
            
        case 'stats':
            $userId = $_GET['user_id'] ?? '';
            
            if (empty($userId)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'User ID required']);
                exit;
            }
            
            // Cache stats (5 min TTL)
            $cacheKey = "store_stats_{$userId}";
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5($cacheKey) . '.json';
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
                echo file_get_contents($cacheFile);
                exit;
            }
            
            $userStores = $db->readAll('user_stores', [['user_id', '==', $userId]]);
            
            $stats = [
                'total_stores' => count($userStores),
                'by_role' => [],
                'stores_with_pos' => 0
            ];
            
            // Count by role
            foreach ($userStores as $us) {
                $role = $us['role'] ?? 'employee';
                $stats['by_role'][$role] = ($stats['by_role'][$role] ?? 0) + 1;
                
                // Check if store has POS
                $store = $db->read('stores', $us['store_id']);
                if ($store && ($store['has_pos'] ?? false)) {
                    $stats['stores_with_pos']++;
                }
            }
            
            $response = ['success' => true, 'data' => $stats];
            
            // Cache response
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0755, true);
            }
            file_put_contents($cacheFile, json_encode($response));
            
            echo json_encode($response);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Stores API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error', 'message' => $e->getMessage()]);
}
