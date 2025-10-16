<?php
/**
 * Test Activity Logging System
 * Quick test to verify activity logging works correctly
 */

require_once 'config.php';
require_once 'db.php';
require_once 'activity_logger.php';

session_start();

// Simulate logged in user (change to your actual user ID)
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 'test_user_001'; // Change this to an actual user ID from your database
    $_SESSION['username'] = 'Test User';
}

echo "<!DOCTYPE html>
<html>
<head>
    <title>Activity Logging Test</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: green; padding: 10px; background: #e8f5e9; border: 1px solid green; margin: 10px 0; }
        .error { color: red; padding: 10px; background: #ffebee; border: 1px solid red; margin: 10px 0; }
        .info { color: blue; padding: 10px; background: #e3f2fd; border: 1px solid blue; margin: 10px 0; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>Activity Logging System Test</h1>
    <p>Current User ID: <strong>" . htmlspecialchars($_SESSION['user_id']) . "</strong></p>
    <hr>";

// Test functions
if (isset($_GET['test'])) {
    echo "<h2>Running Tests...</h2>";
    
    switch ($_GET['test']) {
        case 'basic':
            echo "<div class='info'>Testing basic logActivity() function...</div>";
            $result = logActivity('test_action', 'This is a test activity log entry', [
                'test_data' => 'sample value',
                'test_number' => 123
            ]);
            if ($result) {
                echo "<div class='success'>✓ Basic activity logged successfully!</div>";
            } else {
                echo "<div class='error'>✗ Failed to log basic activity</div>";
            }
            break;
            
        case 'store':
            echo "<div class='info'>Testing logStoreActivity() function...</div>";
            $result = logStoreActivity('created', 'store_test_001', 'Test Store', [
                'location' => 'Test City',
                'manager' => 'Test Manager'
            ]);
            if ($result) {
                echo "<div class='success'>✓ Store activity logged successfully!</div>";
            } else {
                echo "<div class='error'>✗ Failed to log store activity</div>";
            }
            break;
            
        case 'profile':
            echo "<div class='info'>Testing logProfileActivity() function...</div>";
            $result = logProfileActivity('updated', $_SESSION['user_id'], [
                'email' => ['old' => 'old@example.com', 'new' => 'new@example.com'],
                'phone' => ['old' => '555-1234', 'new' => '555-5678']
            ]);
            if ($result) {
                echo "<div class='success'>✓ Profile activity logged successfully!</div>";
            } else {
                echo "<div class='error'>✗ Failed to log profile activity</div>";
            }
            break;
            
        case 'product':
            echo "<div class='info'>Testing logProductActivity() function...</div>";
            $result = logProductActivity('updated', 'prod_test_001', 'Test Product', [
                'price' => ['old' => '10.00', 'new' => '12.00'],
                'stock' => ['old' => 100, 'new' => 150]
            ]);
            if ($result) {
                echo "<div class='success'>✓ Product activity logged successfully!</div>";
            } else {
                echo "<div class='error'>✗ Failed to log product activity</div>";
            }
            break;
            
        case 'all':
            echo "<div class='info'>Running all tests...</div>";
            
            $tests = [
                'Basic Activity' => logActivity('test_all', 'Testing all functions'),
                'Store Activity' => logStoreActivity('created', 'store_all_001', 'Test Store All'),
                'Profile Activity' => logProfileActivity('updated', $_SESSION['user_id']),
                'Product Activity' => logProductActivity('created', 'prod_all_001', 'Test Product All')
            ];
            
            foreach ($tests as $name => $result) {
                if ($result) {
                    echo "<div class='success'>✓ {$name}: PASSED</div>";
                } else {
                    echo "<div class='error'>✗ {$name}: FAILED</div>";
                }
            }
            break;
    }
    
    echo "<hr><p><a href='?'>← Back to test menu</a> | <a href='modules/users/profile/activity_manager.php'>View Activity Log →</a></p>";
    
} else {
    echo "
    <h2>Choose a test:</h2>
    <button onclick=\"location.href='?test=basic'\">Test Basic Activity Logging</button><br>
    <button onclick=\"location.href='?test=store'\">Test Store Activity Logging</button><br>
    <button onclick=\"location.href='?test=profile'\">Test Profile Activity Logging</button><br>
    <button onclick=\"location.href='?test=product'\">Test Product Activity Logging</button><br>
    <button onclick=\"location.href='?test=all'\" style=\"background: #4CAF50; color: white;\">Run All Tests</button><br>
    <hr>
    <p><a href='modules/users/profile/activity_manager.php' target='_blank'>Open Activity Manager →</a></p>
    ";
}

echo "</body></html>";
?>
