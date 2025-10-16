<?php
/**
 * User Update Debug Tool
 * Test user profile updates and data retrieval
 */

require_once 'config.php';
require_once 'db.php';
require_once 'getDB.php';

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Please log in first. <a href='modules/users/login.php'>Login</a>");
}

$db = getDB();
$userId = $_SESSION['user_id'];

echo "<!DOCTYPE html>
<html>
<head>
    <title>User Update Debug</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1000px; margin: 50px auto; padding: 20px; }
        .section { margin: 20px 0; padding: 20px; border: 1px solid #ddd; background: #f9f9f9; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; background: #4CAF50; color: white; border: none; }
    </style>
</head>
<body>
    <h1>User Update Debug Tool</h1>
    <p>Current User ID: <strong>{$userId}</strong></p>
    <hr>";

// Test 1: Read current user data
echo "<div class='section'>";
echo "<h2>Test 1: Read Current User Data</h2>";
$user = $db->read('users', $userId);

if ($user) {
    echo "<p class='success'>✓ Successfully loaded user data</p>";
    echo "<pre>" . json_encode($user, JSON_PRETTY_PRINT) . "</pre>";
} else {
    echo "<p class='error'>✗ Failed to load user data</p>";
    echo "<p>Return value: " . var_export($user, true) . "</p>";
}
echo "</div>";

// Test 2: Update user data
if (isset($_GET['test_update'])) {
    echo "<div class='section'>";
    echo "<h2>Test 2: Update User Data</h2>";
    
    $testData = [
        'first_name' => 'Test_' . rand(100, 999),
        'last_name' => 'User_' . rand(100, 999),
        'email' => 'test' . rand(100, 999) . '@example.com',
        'phone' => '555-' . rand(1000, 9999),
        'updated_at' => date('c')
    ];
    
    echo "<p>Attempting to update with data:</p>";
    echo "<pre>" . json_encode($testData, JSON_PRETTY_PRINT) . "</pre>";
    
    $result = $db->update('users', $userId, $testData);
    
    echo "<p>Update result: " . var_export($result, true) . "</p>";
    
    if ($result) {
        echo "<p class='success'>✓ Update returned TRUE</p>";
        
        // Try to read the data back
        echo "<p>Reading data back...</p>";
        sleep(1); // Wait a second for Firebase to process
        $updatedUser = $db->read('users', $userId);
        
        if ($updatedUser) {
            echo "<p class='success'>✓ Successfully read updated data</p>";
            echo "<pre>" . json_encode($updatedUser, JSON_PRETTY_PRINT) . "</pre>";
            
            // Verify the changes
            $verified = true;
            foreach ($testData as $key => $value) {
                if ($key !== 'updated_at' && (!isset($updatedUser[$key]) || $updatedUser[$key] !== $value)) {
                    echo "<p class='error'>✗ Field '{$key}' not updated correctly. Expected: {$value}, Got: " . ($updatedUser[$key] ?? 'NULL') . "</p>";
                    $verified = false;
                }
            }
            
            if ($verified) {
                echo "<p class='success'>✓ All fields verified successfully!</p>";
            }
        } else {
            echo "<p class='error'>✗ Failed to read updated data</p>";
        }
    } else {
        echo "<p class='error'>✗ Update returned FALSE</p>";
    }
    
    echo "<p><a href='?'>← Back to initial state</a></p>";
    echo "</div>";
}

// Test 3: Check error logs
echo "<div class='section'>";
echo "<h2>Test 3: Recent Error Logs</h2>";
$logFile = 'storage/logs/errors.log';

if (file_exists($logFile)) {
    $logs = file($logFile);
    $recentLogs = array_slice($logs, -20); // Last 20 lines
    
    echo "<p>Last 20 lines from error log:</p>";
    echo "<pre>" . htmlspecialchars(implode('', $recentLogs)) . "</pre>";
} else {
    echo "<p>No error log file found at: {$logFile}</p>";
}
echo "</div>";

// Test 4: Firebase connection test
echo "<div class='section'>";
echo "<h2>Test 4: Firebase Connection</h2>";

try {
    $testRead = $db->read('users', $userId);
    if ($testRead) {
        echo "<p class='success'>✓ Firebase connection working</p>";
    } else {
        echo "<p class='error'>✗ Firebase read returned null/false</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>✗ Firebase error: " . $e->getMessage() . "</p>";
}
echo "</div>";

echo "<div class='section'>";
echo "<h2>Actions</h2>";
echo "<button onclick=\"location.href='?test_update=1'\">Run Update Test</button>";
echo "<button onclick=\"location.href='modules/users/profile.php'\">Go to Profile Page</button>";
echo "<button onclick=\"location.reload()\">Refresh Page</button>";
echo "</div>";

echo "</body></html>";
?>
