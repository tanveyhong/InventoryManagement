<?php
/**
 * Activity Log Debug Tool
 * Check what activities are actually stored in Firebase
 */

require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../getDB.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Please log in first.");
}

$db = getDB();
$userId = $_SESSION['user_id'];

echo "<!DOCTYPE html>
<html>
<head>
    <title>Activity Log Debug</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 30px auto; padding: 20px; }
        .section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; background: #f9f9f9; }
        .success { color: green; padding: 10px; background: #e8f5e9; }
        .error { color: red; padding: 10px; background: #ffebee; }
        .warning { color: orange; padding: 10px; background: #fff3e0; }
        pre { background: #fff; padding: 15px; border: 1px solid #ddd; overflow-x: auto; max-height: 400px; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; background: white; }
        th, td { padding: 12px; text-align: left; border: 1px solid #ddd; }
        th { background: #4CAF50; color: white; }
        tr:nth-child(even) { background: #f9f9f9; }
        .highlight { background: #ffffcc !important; }
    </style>
</head>
<body>
    <h1>🔍 Activity Log Debug Tool</h1>
    <p>Current User: <strong>{$_SESSION['username']}</strong> (ID: {$userId})</p>
    <hr>";

// Test 1: Get ALL activities (no filters)
echo "<div class='section'>";
echo "<h2>📋 Test 1: All Activities in Firebase</h2>";

try {
    $allActivities = $db->readAll('user_activities', [], ['created_at', 'DESC']);
    
    if ($allActivities && count($allActivities) > 0) {
        echo "<div class='success'>✓ Found " . count($allActivities) . " total activities in Firebase</div>";
        echo "<p>Showing most recent activities:</p>";
        
        echo "<table>";
        echo "<tr><th>#</th><th>User ID</th><th>Username</th><th>Action</th><th>Action Type</th><th>Description</th><th>Created At</th><th>Deleted?</th></tr>";
        
        $count = 0;
        foreach (array_slice($allActivities, 0, 20) as $activity) {
            $count++;
            $isCurrentUser = ($activity['user_id'] ?? '') === $userId;
            $rowClass = $isCurrentUser ? 'highlight' : '';
            
            echo "<tr class='{$rowClass}'>";
            echo "<td>{$count}</td>";
            echo "<td>" . htmlspecialchars($activity['user_id'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($activity['username'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($activity['action'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($activity['action_type'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($activity['description'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($activity['created_at'] ?? 'N/A') . "</td>";
            echo "<td>" . (isset($activity['deleted_at']) ? 'YES' : 'NO') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        if (count($allActivities) > 20) {
            echo "<p><em>Showing first 20 of " . count($allActivities) . " activities</em></p>";
        }
        
    } else {
        echo "<div class='warning'>⚠ No activities found in Firebase database</div>";
        echo "<p>This could mean:</p>";
        echo "<ul>";
        echo "<li>No activities have been logged yet</li>";
        echo "<li>The 'user_activities' collection doesn't exist</li>";
        echo "<li>There's a connection issue with Firebase</li>";
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<div class='error'>✗ Error fetching activities: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</div>";

// Test 2: Get activities for current user only
echo "<div class='section'>";
echo "<h2>👤 Test 2: Your Activities Only</h2>";

try {
    $myActivities = $db->readAll('user_activities', [
        ['user_id', '==', $userId]
    ], ['created_at', 'DESC']);
    
    if ($myActivities && count($myActivities) > 0) {
        echo "<div class='success'>✓ Found " . count($myActivities) . " activities for your user ID</div>";
        
        echo "<table>";
        echo "<tr><th>#</th><th>Action</th><th>Description</th><th>Metadata</th><th>Created At</th></tr>";
        
        $count = 0;
        foreach ($myActivities as $activity) {
            $count++;
            echo "<tr>";
            echo "<td>{$count}</td>";
            echo "<td>" . htmlspecialchars($activity['action_type'] ?? $activity['action'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($activity['description'] ?? 'N/A') . "</td>";
            
            // Show metadata
            $metadata = '';
            if (isset($activity['metadata'])) {
                if (is_string($activity['metadata'])) {
                    $metadata = $activity['metadata'];
                } else {
                    $metadata = json_encode($activity['metadata']);
                }
            }
            echo "<td><small>" . htmlspecialchars(substr($metadata, 0, 100)) . "</small></td>";
            echo "<td>" . htmlspecialchars($activity['created_at'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
    } else {
        echo "<div class='warning'>⚠ No activities found for your user ID: {$userId}</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</div>";

// Test 3: Get non-deleted activities (what activity_manager.php uses)
echo "<div class='section'>";
echo "<h2>🔍 Test 3: Non-Deleted Activities (Activity Manager Query)</h2>";

try {
    $conditions = [
        ['user_id', '==', $userId],
        ['deleted_at', '==', null]
    ];
    
    $nonDeletedActivities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC']);
    
    if ($nonDeletedActivities && count($nonDeletedActivities) > 0) {
        echo "<div class='success'>✓ Found " . count($nonDeletedActivities) . " non-deleted activities</div>";
        echo "<p>This is what activity_manager.php should display:</p>";
        
        echo "<table>";
        echo "<tr><th>#</th><th>Action Type</th><th>Description</th><th>Created</th></tr>";
        
        foreach ($nonDeletedActivities as $idx => $activity) {
            echo "<tr>";
            echo "<td>" . ($idx + 1) . "</td>";
            echo "<td>" . htmlspecialchars($activity['action_type'] ?? $activity['action'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($activity['description'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($activity['created_at'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='warning'>⚠ No non-deleted activities found</div>";
        echo "<p>Checking if there are deleted activities...</p>";
        
        $deletedCheck = $db->readAll('user_activities', [['user_id', '==', $userId]]);
        if ($deletedCheck && count($deletedCheck) > 0) {
            $deletedCount = 0;
            foreach ($deletedCheck as $act) {
                if (isset($act['deleted_at'])) {
                    $deletedCount++;
                }
            }
            echo "<p>Found {$deletedCount} deleted activities out of " . count($deletedCheck) . " total</p>";
        }
    }
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</div>";

// Test 4: Sample activity data structure
echo "<div class='section'>";
echo "<h2>📄 Test 4: Latest Activity Data Structure</h2>";

try {
    $latest = $db->readAll('user_activities', [['user_id', '==', $userId]], ['created_at', 'DESC'], 1);
    
    if ($latest && count($latest) > 0) {
        echo "<p>Raw data structure of your most recent activity:</p>";
        echo "<pre>" . json_encode($latest[0], JSON_PRETTY_PRINT) . "</pre>";
    } else {
        echo "<p>No activities to show structure</p>";
    }
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</div>";

// Test 5: Firebase connection test
echo "<div class='section'>";
echo "<h2>🔌 Test 5: Firebase Connection Test</h2>";

try {
    // Try to read from a collection
    $testRead = $db->readAll('user_activities', [], null, 1);
    echo "<div class='success'>✓ Firebase connection working</div>";
    echo "<p>Successfully queried user_activities collection</p>";
} catch (Exception $e) {
    echo "<div class='error'>✗ Firebase connection failed: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</div>";

echo "<hr>";
echo "<p><a href='../../../modules/stores/add.php'>← Create New Store</a> | ";
echo "<a href='activity_manager.php'>View Activity Manager</a> | ";
echo "<a href='?refresh=1'>Refresh This Page</a></p>";

echo "</body></html>";
?>
