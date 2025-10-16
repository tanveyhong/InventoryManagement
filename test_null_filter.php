<?php
/**
 * Test NULL Filter Fix
 * Verify that deleted_at == null works correctly
 */

require_once 'config.php';
require_once 'db.php';
require_once 'getDB.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    die("Please log in first.");
}

$db = getDB();
$userId = $_SESSION['user_id'];

echo "<!DOCTYPE html>
<html>
<head>
    <title>NULL Filter Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 30px auto; padding: 20px; }
        .success { color: green; padding: 10px; background: #e8f5e9; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #ffebee; margin: 10px 0; }
        .info { color: blue; padding: 10px; background: #e3f2fd; margin: 10px 0; }
        pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        th { background: #4CAF50; color: white; }
    </style>
</head>
<body>
    <h1>🧪 NULL Filter Test</h1>
    <p>Testing if <code>deleted_at == null</code> filter works correctly</p>
    <hr>";

// Test 1: Get ALL activities (no filter)
echo "<h2>Test 1: All Activities (No Filter)</h2>";
try {
    $allActivities = $db->readAll('user_activities', [], ['created_at', 'DESC']);
    echo "<div class='success'>✓ Found " . count($allActivities) . " total activities</div>";
    
    // Count how many have deleted_at field
    $withDeletedAt = 0;
    $withoutDeletedAt = 0;
    foreach ($allActivities as $act) {
        if (isset($act['deleted_at'])) {
            $withDeletedAt++;
        } else {
            $withoutDeletedAt++;
        }
    }
    
    echo "<div class='info'>";
    echo "Activities WITH deleted_at field: {$withDeletedAt}<br>";
    echo "Activities WITHOUT deleted_at field: {$withoutDeletedAt}";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
}

// Test 2: Get activities where deleted_at == null
echo "<h2>Test 2: Activities Where deleted_at == null</h2>";
echo "<div class='info'>This is what activity_manager.php uses</div>";

try {
    $conditions = [['deleted_at', '==', null]];
    $nonDeletedActivities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC']);
    
    echo "<div class='success'>✓ Found " . count($nonDeletedActivities) . " activities where deleted_at == null</div>";
    
    if (count($nonDeletedActivities) > 0) {
        echo "<p><strong>These should include activities without the deleted_at field!</strong></p>";
        
        echo "<table>";
        echo "<tr><th>#</th><th>Has deleted_at?</th><th>deleted_at Value</th><th>Action</th><th>Description</th></tr>";
        
        foreach (array_slice($nonDeletedActivities, 0, 10) as $idx => $act) {
            $hasField = isset($act['deleted_at']) ? 'YES' : 'NO';
            $fieldValue = $act['deleted_at'] ?? 'N/A (field missing)';
            
            echo "<tr>";
            echo "<td>" . ($idx + 1) . "</td>";
            echo "<td>{$hasField}</td>";
            echo "<td>{$fieldValue}</td>";
            echo "<td>" . htmlspecialchars($act['action_type'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars(substr($act['description'] ?? 'N/A', 0, 50)) . "...</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
}

// Test 3: Get activities for current user where deleted_at == null
echo "<h2>Test 3: Your Activities Where deleted_at == null</h2>";

try {
    $conditions = [
        ['user_id', '==', $userId],
        ['deleted_at', '==', null]
    ];
    $myNonDeletedActivities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC']);
    
    echo "<div class='success'>✓ Found " . count($myNonDeletedActivities) . " of YOUR activities where deleted_at == null</div>";
    
    if (count($myNonDeletedActivities) > 0) {
        echo "<p><strong>This is exactly what you should see in activity_manager.php!</strong></p>";
        
        echo "<table>";
        echo "<tr><th>#</th><th>Action Type</th><th>Description</th><th>Created At</th></tr>";
        
        foreach ($myNonDeletedActivities as $idx => $act) {
            echo "<tr>";
            echo "<td>" . ($idx + 1) . "</td>";
            echo "<td>" . htmlspecialchars($act['action_type'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($act['description'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($act['created_at'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='error'>✗ No activities found for your user!</div>";
        echo "<p>Checking if you have ANY activities...</p>";
        
        $anyActivities = $db->readAll('user_activities', [['user_id', '==', $userId]]);
        if ($anyActivities && count($anyActivities) > 0) {
            echo "<div class='info'>You have " . count($anyActivities) . " total activities, but they all have deleted_at set!</div>";
        } else {
            echo "<div class='error'>You have NO activities at all. Try creating a store first.</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
}

// Test 4: Compare before and after fix
echo "<h2>Test 4: Fix Verification</h2>";
echo "<div class='info'>";
echo "<strong>Expected Behavior:</strong><br>";
echo "• Activities WITHOUT deleted_at field should be included in the 'deleted_at == null' query<br>";
echo "• This allows newly created activities (which don't have deleted_at) to show up<br>";
echo "• Only activities with deleted_at explicitly set to a date should be excluded<br>";
echo "</div>";

echo "<hr>";
echo "<p><a href='modules/users/profile/activity_manager.php'>→ Go to Activity Manager</a></p>";
echo "<p><a href='modules/stores/add.php'>→ Create a Test Store</a></p>";
echo "<p><a href='?refresh=1'>↻ Refresh This Page</a></p>";

echo "</body></html>";
?>
