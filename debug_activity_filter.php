<?php
/**
 * Debug Activity Filter Issue
 */

require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

session_start();

if (!isLoggedIn()) {
    die('Please log in first');
}

$db = getDB();
$currentUserId = $_SESSION['user_id'];

// Get user info
$currentUser = $db->read('users', $currentUserId);
$isAdmin = ($currentUser['role'] ?? '') === 'admin';

echo "<h2>Activity Filter Debug</h2>";
echo "<p>Current User ID: $currentUserId</p>";
echo "<p>Is Admin: " . ($isAdmin ? 'YES' : 'NO') . "</p>";
echo "<hr>";

// Simulate filter with 'all'
$filterUser = 'all';

echo "<h3>Test Case: Filter User = 'all'</h3>";
echo "<p>Filter User Value: '$filterUser'</p>";
echo "<p>!empty(\$filterUser): " . (!empty($filterUser) ? 'TRUE' : 'FALSE') . "</p>";
echo "<p>\$filterUser !== 'all': " . ($filterUser !== 'all' ? 'TRUE' : 'FALSE') . "</p>";
echo "<p>Combined condition: " . ((!empty($filterUser) && $filterUser !== 'all') ? 'TRUE (WILL ADD FILTER)' : 'FALSE (NO FILTER)') . "</p>";
echo "<hr>";

// Build conditions like the actual code
$conditions = [['deleted_at', '==', null]];

if (!$isAdmin) {
    echo "<p>Not admin - forcing user filter to current user</p>";
    $conditions[] = ['user_id', '==', $currentUserId];
    $filterUser = $currentUserId;
} else {
    echo "<p>Is admin - checking filter condition</p>";
    if (!empty($filterUser) && $filterUser !== 'all') {
        echo "<p style='color: red;'>Adding user_id filter for: $filterUser</p>";
        $conditions[] = ['user_id', '==', $filterUser];
    } else {
        echo "<p style='color: green;'>NOT adding user_id filter - should show all users</p>";
    }
}

echo "<h3>Final Conditions:</h3>";
echo "<pre>";
print_r($conditions);
echo "</pre>";

// Fetch with conditions
try {
    $allActivities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC']);
    
    echo "<h3>Results:</h3>";
    echo "<p>Total activities found: " . count($allActivities) . "</p>";
    
    if (count($allActivities) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>User ID</th><th>Action</th><th>Description</th><th>Created At</th></tr>";
        
        $count = 0;
        foreach ($allActivities as $act) {
            if ($count >= 10) {
                echo "<tr><td colspan='5'>... and " . (count($allActivities) - 10) . " more</td></tr>";
                break;
            }
            
            echo "<tr>";
            echo "<td>" . ($act['id'] ?? 'N/A') . "</td>";
            echo "<td>" . ($act['user_id'] ?? 'N/A') . "</td>";
            echo "<td>" . ($act['action_type'] ?? $act['action'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars(substr($act['description'] ?? '', 0, 50)) . "</td>";
            echo "<td>" . ($act['created_at'] ?? 'N/A') . "</td>";
            echo "</tr>";
            
            $count++;
        }
        
        echo "</table>";
        
        // Show unique user IDs
        $userIds = array_unique(array_map(function($act) {
            return $act['user_id'] ?? 'N/A';
        }, $allActivities));
        
        echo "<h3>Unique User IDs in Results:</h3>";
        echo "<pre>";
        print_r(array_values($userIds));
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>No activities found!</p>";
        
        // Try without any user filter
        echo "<h3>Testing without user filter:</h3>";
        $testConditions = [['deleted_at', '==', null]];
        $testActivities = $db->readAll('user_activities', $testConditions, ['created_at', 'DESC']);
        echo "<p>Activities without user filter: " . count($testActivities) . "</p>";
        
        if (count($testActivities) > 0) {
            $testUserIds = array_unique(array_map(function($act) {
                return $act['user_id'] ?? 'N/A';
            }, $testActivities));
            echo "<p>User IDs found: " . implode(', ', $testUserIds) . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>";
    echo $e->getTraceAsString();
    echo "</pre>";
}
?>
