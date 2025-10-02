<?php
/**
 * User Activity Management Module
 * Comprehensive activity tracking, filtering, and reporting system
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

// Handle POST actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'clear_activity':
            $targetUserId = $_POST['user_id'] ?? $currentUserId;
            
            // Permission check
            if ($targetUserId !== $currentUserId && !$isAdmin) {
                $message = 'You do not have permission to clear other users\' activity';
                $messageType = 'error';
                break;
            }
            
            try {
                $activities = $db->readAll('user_activities', [['user_id', '==', $targetUserId]]);
                $count = 0;
                foreach ($activities as $act) {
                    if (isset($act['id'])) {
                        $db->update('user_activities', $act['id'], ['deleted_at' => date('c')]);
                        $count++;
                    }
                }
                
                // Log the action
                $db->create('user_activities', [
                    'user_id' => $currentUserId,
                    'action_type' => 'activity_cleared',
                    'description' => "Cleared {$count} activity entries",
                    'metadata' => json_encode(['target_user' => $targetUserId, 'count' => $count]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'created_at' => date('c')
                ]);
                
                $message = "Successfully cleared {$count} activity entries";
                $messageType = 'success';
            } catch (Exception $e) {
                error_log('Clear activity error: ' . $e->getMessage());
                $message = 'Failed to clear activity';
                $messageType = 'error';
            }
            break;
            
        case 'export_activity':
            $userId = $_POST['user_id'] ?? $currentUserId;
            $format = $_POST['format'] ?? 'csv';
            
            try {
                $activities = $db->readAll('user_activities', [
                    ['user_id', '==', $userId],
                    ['deleted_at', '==', null]
                ], ['created_at', 'DESC']);
                
                if ($format === 'csv') {
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="activity_' . $userId . '_' . date('Y-m-d') . '.csv"');
                    
                    $output = fopen('php://output', 'w');
                    fputcsv($output, ['Timestamp', 'Action Type', 'Description', 'IP Address', 'User Agent']);
                    
                    foreach ($activities as $act) {
                        fputcsv($output, [
                            $act['created_at'] ?? '',
                            $act['action_type'] ?? '',
                            $act['description'] ?? '',
                            $act['ip_address'] ?? '',
                            $act['user_agent'] ?? ''
                        ]);
                    }
                    
                    fclose($output);
                    exit;
                } elseif ($format === 'json') {
                    header('Content-Type: application/json');
                    header('Content-Disposition: attachment; filename="activity_' . $userId . '_' . date('Y-m-d') . '.json"');
                    echo json_encode($activities, JSON_PRETTY_PRINT);
                    exit;
                }
            } catch (Exception $e) {
                error_log('Export activity error: ' . $e->getMessage());
                $message = 'Failed to export activity';
                $messageType = 'error';
            }
            break;
    }
}

// Get filter parameters
$filterUser = $_GET['user'] ?? $currentUserId;
$filterAction = $_GET['action'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$perPage = 50;

// Build filter conditions
$conditions = [['deleted_at', '==', null]];

if (!$isAdmin) {
    // Non-admin users can only see their own activity
    $conditions[] = ['user_id', '==', $currentUserId];
    $filterUser = $currentUserId;
} else {
    if (!empty($filterUser) && $filterUser !== 'all') {
        $conditions[] = ['user_id', '==', $filterUser];
    }
}

// Fetch activities
try {
    $allActivities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC']);
    
    // Apply additional filters
    $filteredActivities = $allActivities;
    
    if (!empty($filterAction)) {
        $filteredActivities = array_filter($filteredActivities, function($act) use ($filterAction) {
            return ($act['action_type'] ?? '') === $filterAction;
        });
    }
    
    if (!empty($filterDateFrom)) {
        $filteredActivities = array_filter($filteredActivities, function($act) use ($filterDateFrom) {
            return strtotime($act['created_at'] ?? '') >= strtotime($filterDateFrom);
        });
    }
    
    if (!empty($filterDateTo)) {
        $filteredActivities = array_filter($filteredActivities, function($act) use ($filterDateTo) {
            return strtotime($act['created_at'] ?? '') <= strtotime($filterDateTo . ' 23:59:59');
        });
    }
    
    $totalActivities = count($filteredActivities);
    $totalPages = ceil($totalActivities / $perPage);
    $page = max(1, min($page, $totalPages ?: 1));
    
    // Paginate
    $offset = ($page - 1) * $perPage;
    $activities = array_slice($filteredActivities, $offset, $perPage);
    
    // Get user info for activities
    $userCache = [];
    foreach ($activities as &$act) {
        $uid = $act['user_id'] ?? '';
        if ($uid && !isset($userCache[$uid])) {
            $user = $db->read('users', $uid);
            $userCache[$uid] = $user ? ($user['first_name'] . ' ' . $user['last_name']) : 'Unknown User';
        }
        $act['user_name'] = $userCache[$uid] ?? 'Unknown User';
    }
    
} catch (Exception $e) {
    error_log('Fetch activities error: ' . $e->getMessage());
    $activities = [];
    $totalActivities = 0;
    $totalPages = 1;
}

// Get users for filter dropdown (admin only, limit to 100 for performance)
$allUsers = [];
if ($isAdmin) {
    try {
        $allUsers = $db->readAll('users', [], ['first_name', 'ASC'], 100);
    } catch (Exception $e) {
        error_log('Fetch users error: ' . $e->getMessage());
    }
}

// Get unique action types for filter
$actionTypes = ['login', 'logout', 'profile_updated', 'password_changed', 'user_created', 
                'permission_changed', 'store_access_updated', 'activity_cleared', 
                'inventory_added', 'inventory_updated', 'inventory_deleted'];

$page_title = 'Activity Management - Inventory System';
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
        .activity-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .activity-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .activity-header h1 {
            margin: 0 0 10px 0;
            font-size: 2rem;
        }
        
        .filters-section {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }
        
        .filter-group select,
        .filter-group input {
            padding: 10px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
        }
        
        .activity-table-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .activity-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .activity-table thead {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .activity-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        .activity-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .activity-table tbody tr:hover {
            background: #f8fafc;
        }
        
        .action-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .action-login { background: #dcfdf7; color: #065f46; }
        .action-logout { background: #fee2e2; color: #991b1b; }
        .action-profile_updated { background: #dbeafe; color: #1e40af; }
        .action-password_changed { background: #fef3c7; color: #92400e; }
        .action-user_created { background: #dcfdf7; color: #065f46; }
        .action-permission_changed { background: #fae8ff; color: #86198f; }
        .action-store_access_updated { background: #f3e8ff; color: #6b21a8; }
        .action-activity_cleared { background: #fecaca; color: #b91c1c; }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 20px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            text-decoration: none;
            color: #374151;
            font-weight: 600;
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
            .activity-table-container {
                overflow-x: auto;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <div class="activity-container">
            <!-- Header -->
            <div class="activity-header">
                <h1><i class="fas fa-history"></i> Activity Management</h1>
                <p>Track and monitor all system activities</p>
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
                <div class="stat-card">
                    <div class="stat-value"><?php echo number_format($totalActivities); ?></div>
                    <div class="stat-label">Total Activities</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($activities); ?></div>
                    <div class="stat-label">Current Page</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo $totalPages; ?></div>
                    <div class="stat-label">Total Pages</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-section">
                <h3 style="margin: 0 0 15px 0;"><i class="fas fa-filter"></i> Filters</h3>
                <form method="GET" action="">
                    <div class="filters-grid">
                        <?php if ($isAdmin): ?>
                        <div class="filter-group">
                            <label for="user">User</label>
                            <select name="user" id="user">
                                <option value="all" <?php echo $filterUser === 'all' ? 'selected' : ''; ?>>All Users</option>
                                <?php foreach ($allUsers as $u): ?>
                                    <option value="<?php echo htmlspecialchars($u['id']); ?>" 
                                            <?php echo $filterUser === $u['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <div class="filter-group">
                            <label for="action">Action Type</label>
                            <select name="action" id="action">
                                <option value="">All Actions</option>
                                <?php foreach ($actionTypes as $at): ?>
                                    <option value="<?php echo htmlspecialchars($at); ?>" 
                                            <?php echo $filterAction === $at ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $at))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="date_from">Date From</label>
                            <input type="date" name="date_from" id="date_from" 
                                   value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="date_to">Date To</label>
                            <input type="date" name="date_to" id="date_to" 
                                   value="<?php echo htmlspecialchars($filterDateTo); ?>">
                        </div>
                    </div>
                    
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <a href="activity.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                        
                        <!-- Export Actions -->
                        <form method="POST" action="" style="display: inline;">
                            <input type="hidden" name="action" value="export_activity">
                            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($filterUser); ?>">
                            <input type="hidden" name="format" value="csv">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </button>
                        </form>
                        
                        <form method="POST" action="" style="display: inline;" 
                              onsubmit="return confirm('Clear all activity for this user?');">
                            <input type="hidden" name="action" value="clear_activity">
                            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($filterUser); ?>">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash"></i> Clear Activity
                            </button>
                        </form>
                    </div>
                </form>
            </div>
            
            <!-- Activity Table -->
            <div class="activity-table-container">
                <table class="activity-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <?php if ($isAdmin): ?>
                            <th>User</th>
                            <?php endif; ?>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP Address</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activities)): ?>
                            <tr>
                                <td colspan="<?php echo $isAdmin ? '6' : '5'; ?>" style="text-align: center; padding: 40px;">
                                    <i class="fas fa-info-circle" style="font-size: 2rem; color: #6b7280; margin-bottom: 10px;"></i>
                                    <p style="color: #6b7280; margin: 0;">No activities found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activities as $act): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        $time = strtotime($act['created_at'] ?? '');
                                        echo date('Y-m-d H:i:s', $time);
                                        ?>
                                    </td>
                                    <?php if ($isAdmin): ?>
                                    <td><?php echo htmlspecialchars($act['user_name'] ?? 'Unknown'); ?></td>
                                    <?php endif; ?>
                                    <td>
                                        <span class="action-badge action-<?php echo htmlspecialchars($act['action_type'] ?? 'default'); ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $act['action_type'] ?? 'Unknown'))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($act['description'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($act['ip_address'] ?? '—'); ?></td>
                                    <td>
                                        <?php if (!empty($act['metadata'])): ?>
                                            <details>
                                                <summary style="cursor: pointer; color: #667eea;">View</summary>
                                                <pre style="font-size: 11px; margin: 5px 0; overflow-x: auto;"><?php 
                                                    $meta = json_decode($act['metadata'], true);
                                                    echo htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT));
                                                ?></pre>
                                            </details>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&user=<?php echo urlencode($filterUser); ?>&action=<?php echo urlencode($filterAction); ?>&date_from=<?php echo urlencode($filterDateFrom); ?>&date_to=<?php echo urlencode($filterDateTo); ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="active"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?>&user=<?php echo urlencode($filterUser); ?>&action=<?php echo urlencode($filterAction); ?>&date_from=<?php echo urlencode($filterDateFrom); ?>&date_to=<?php echo urlencode($filterDateTo); ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&user=<?php echo urlencode($filterUser); ?>&action=<?php echo urlencode($filterAction); ?>&date_from=<?php echo urlencode($filterDateFrom); ?>&date_to=<?php echo urlencode($filterDateTo); ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="../../../assets/js/main.js"></script>
</body>
</html>
