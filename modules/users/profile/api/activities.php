<?php
/**
 * Activities API - Optimized Backend Endpoint
 * Handles all activity-related requests with caching and pagination
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
            $userId = $_GET['user_id'] ?? $currentUserId;
            $actionType = $_GET['action_type'] ?? '';
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo = $_GET['date_to'] ?? '';
            
            // Permission check
            if ($userId !== $currentUserId && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Build cache key
            $cacheKey = "activities_{$userId}_{$page}_{$perPage}_{$actionType}_{$dateFrom}_{$dateTo}";
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5($cacheKey) . '.json';
            
            // Check cache (2 min TTL)
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 120) {
                echo file_get_contents($cacheFile);
                exit;
            }
            
            // Build query conditions
            $conditions = [['deleted_at', '==', null]];
            if (!empty($userId) && $userId !== 'all') {
                $conditions[] = ['user_id', '==', $userId];
            }
            
            // Fetch with limit
            $limit = $perPage * $page + 20; // Fetch a bit extra
            $activities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC'], $limit);
            
            // Apply filters
            if (!empty($actionType)) {
                $activities = array_filter($activities, function($act) use ($actionType) {
                    return ($act['action_type'] ?? '') === $actionType;
                });
                $activities = array_values($activities);
            }
            
            if (!empty($dateFrom)) {
                $fromTimestamp = strtotime($dateFrom);
                $activities = array_filter($activities, function($act) use ($fromTimestamp) {
                    $actTime = strtotime($act['created_at'] ?? '');
                    return $actTime >= $fromTimestamp;
                });
                $activities = array_values($activities);
            }
            
            if (!empty($dateTo)) {
                $toTimestamp = strtotime($dateTo . ' 23:59:59');
                $activities = array_filter($activities, function($act) use ($toTimestamp) {
                    $actTime = strtotime($act['created_at'] ?? '');
                    return $actTime <= $toTimestamp;
                });
                $activities = array_values($activities);
            }
            
            // Pagination
            $total = count($activities);
            $totalPages = ceil($total / $perPage);
            $offset = ($page - 1) * $perPage;
            $activities = array_slice($activities, $offset, $perPage);
            
            // Enrich with user info (batch load)
            $userIds = array_unique(array_column($activities, 'user_id'));
            $userCache = [];
            
            if (!empty($userIds)) {
                $users = $db->readAll('users', [], [], 200);
                foreach ($users as $u) {
                    if (isset($u['id']) && in_array($u['id'], $userIds)) {
                        $userCache[$u['id']] = [
                            'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                            'email' => $u['email'] ?? '',
                            'role' => $u['role'] ?? 'user'
                        ];
                    }
                }
            }
            
            foreach ($activities as &$act) {
                $uid = $act['user_id'] ?? '';
                $act['user'] = $userCache[$uid] ?? ['name' => 'Unknown', 'email' => '', 'role' => 'user'];
            }
            
            $response = [
                'success' => true,
                'data' => $activities,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'has_next' => $page < $totalPages,
                    'has_prev' => $page > 1
                ]
            ];
            
            // Cache response
            if (!is_dir(dirname($cacheFile))) {
                mkdir(dirname($cacheFile), 0755, true);
            }
            file_put_contents($cacheFile, json_encode($response));
            
            echo json_encode($response);
            break;
            
        case 'stats':
            $userId = $_GET['user_id'] ?? $currentUserId;
            
            // Permission check
            if ($userId !== $currentUserId && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Cache stats (5 min TTL)
            $cacheKey = "activity_stats_{$userId}";
            $cacheFile = __DIR__ . '/../../storage/cache/' . md5($cacheKey) . '.json';
            
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 300) {
                echo file_get_contents($cacheFile);
                exit;
            }
            
            // Count activities
            $activities = $db->readAll('user_activities', [
                ['user_id', '==', $userId],
                ['deleted_at', '==', null]
            ]);
            
            // Calculate stats
            $stats = [
                'total' => count($activities),
                'by_type' => [],
                'recent_count' => 0,
                'today_count' => 0
            ];
            
            $today = strtotime('today');
            $weekAgo = strtotime('-7 days');
            
            foreach ($activities as $act) {
                $type = $act['action_type'] ?? 'unknown';
                $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
                
                $actTime = strtotime($act['created_at'] ?? '');
                if ($actTime >= $today) {
                    $stats['today_count']++;
                }
                if ($actTime >= $weekAgo) {
                    $stats['recent_count']++;
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
            
        case 'clear':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit;
            }
            
            $userId = $_POST['user_id'] ?? $currentUserId;
            
            // Permission check
            if ($userId !== $currentUserId && !$isAdmin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
            
            // Soft delete activities
            $activities = $db->readAll('user_activities', [['user_id', '==', $userId]]);
            $count = 0;
            
            foreach ($activities as $act) {
                if (isset($act['id'])) {
                    $db->update('user_activities', $act['id'], ['deleted_at' => date('c')]);
                    $count++;
                }
            }
            
            // Clear cache
            $pattern = __DIR__ . '/../../storage/cache/*activities*' . md5($userId) . '*.json';
            foreach (glob($pattern) as $file) {
                @unlink($file);
            }
            
            // Log action
            $db->create('user_activities', [
                'user_id' => $currentUserId,
                'action_type' => 'activity_cleared',
                'description' => "Cleared {$count} activities",
                'metadata' => json_encode(['target_user' => $userId, 'count' => $count]),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'created_at' => date('c')
            ]);
            
            echo json_encode(['success' => true, 'count' => $count, 'message' => "Cleared {$count} activities"]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Activities API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error', 'message' => $e->getMessage()]);
}
