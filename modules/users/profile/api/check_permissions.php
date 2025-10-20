<?php
/**
 * Debug endpoint: returns current user's role and permissions
 * Use only for troubleshooting. Remove or protect in production.
 */

header('Content-Type: application/json');
require_once '../../../../config.php';
require_once '../../../../functions.php';

session_start();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$userId = $_SESSION['user_id'];
$db = getDB();
$user = $db->read('users', $userId);

$permsToCheck = [
    'view_reports', 'manage_inventory', 'manage_users', 'manage_stores',
    'configure_system', 'manage_pos', 'view_analytics', 'manage_alerts', 'view_inventory'
];

$result = [
    'success' => true,
    'user_id' => $userId,
    'username' => $user['username'] ?? null,
    'role' => $user['role'] ?? null,
    'permissions' => []
];

foreach ($permsToCheck as $p) {
    $result['permissions'][$p] = hasPermission($userId, $p);
}

echo json_encode($result, JSON_PRETTY_PRINT);
