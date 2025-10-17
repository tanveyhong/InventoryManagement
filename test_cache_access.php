<?php
session_start();
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Please log in first");
}

$userId = $_SESSION['user_id'];
$cacheDir = __DIR__ . '/storage/cache';
$cacheFile = $cacheDir . '/profile_' . md5($userId) . '.json';

echo "<h1>Cache Diagnostic Tool</h1>";
echo "<hr>";

echo "<h2>User Session Info</h2>";
echo "User ID: " . htmlspecialchars($userId) . "<br>";
echo "Expected cache file: " . htmlspecialchars($cacheFile) . "<br>";
echo "Cache file MD5: " . md5($userId) . "<br>";
echo "<br>";

echo "<h2>Cache Directory Status</h2>";
echo "Cache directory exists: " . (file_exists($cacheDir) ? "YES" : "NO") . "<br>";
echo "Cache directory writable: " . (is_writable($cacheDir) ? "YES" : "NO") . "<br>";
echo "<br>";

echo "<h2>Cache File Status</h2>";
echo "Cache file exists: " . (file_exists($cacheFile) ? "YES" : "NO") . "<br>";
if (file_exists($cacheFile)) {
    echo "Cache file readable: " . (is_readable($cacheFile) ? "YES" : "NO") . "<br>";
    echo "Cache file size: " . filesize($cacheFile) . " bytes<br>";
    echo "Cache file modified: " . date('Y-m-d H:i:s', filemtime($cacheFile)) . "<br>";
    echo "<br>";
    
    echo "<h2>Cache File Contents</h2>";
    $contents = file_get_contents($cacheFile);
    echo "<pre>" . htmlspecialchars($contents) . "</pre>";
    
    echo "<h2>Parsed Cache Data</h2>";
    $cacheData = json_decode($contents, true);
    if ($cacheData) {
        echo "✓ JSON is valid<br>";
        echo "Has 'user' key: " . (isset($cacheData['user']) ? "YES" : "NO") . "<br>";
        echo "Has 'cached_at' key: " . (isset($cacheData['cached_at']) ? "YES" : "NO") . "<br>";
        echo "Has 'user_id' key: " . (isset($cacheData['user_id']) ? "YES" : "NO") . "<br>";
        
        if (isset($cacheData['user'])) {
            $user = $cacheData['user'];
            echo "<br>User data loaded: " . (!empty($user) ? "YES" : "NO") . "<br>";
            echo "User is array/object: " . (is_array($user) || is_object($user) ? "YES" : "NO") . "<br>";
            
            if (is_array($user)) {
                echo "<br><strong>User Data Preview:</strong><br>";
                echo "ID: " . (isset($user['id']) ? htmlspecialchars($user['id']) : "N/A") . "<br>";
                echo "Username: " . (isset($user['username']) ? htmlspecialchars($user['username']) : "N/A") . "<br>";
                echo "Email: " . (isset($user['email']) ? htmlspecialchars($user['email']) : "N/A") . "<br>";
                echo "First Name: " . (isset($user['first_name']) ? htmlspecialchars($user['first_name']) : "N/A") . "<br>";
                echo "Last Name: " . (isset($user['last_name']) ? htmlspecialchars($user['last_name']) : "N/A") . "<br>";
            }
        }
    } else {
        echo "✗ JSON parse error: " . json_last_error_msg() . "<br>";
    }
} else {
    echo "<br><strong>Cache file does not exist!</strong><br>";
    echo "<br>";
    
    echo "<h2>All Cache Files</h2>";
    $files = glob($cacheDir . '/profile_*.json');
    if (empty($files)) {
        echo "No profile cache files found<br>";
    } else {
        echo "Found " . count($files) . " cache file(s):<br>";
        foreach ($files as $file) {
            echo "- " . basename($file) . " (" . date('Y-m-d H:i:s', filemtime($file)) . ")<br>";
        }
    }
}

echo "<hr>";
echo "<h2>Test Cache Creation</h2>";

// Try to create a test cache manually
$testData = [
    'user' => [
        'id' => $userId,
        'username' => 'TestUser',
        'email' => 'test@example.com',
        'first_name' => 'Test',
        'last_name' => 'User',
        'role' => 'admin'
    ],
    'cached_at' => date('Y-m-d H:i:s'),
    'user_id' => $userId
];

$testFile = $cacheDir . '/test_cache_' . time() . '.json';
$result = file_put_contents($testFile, json_encode($testData, JSON_PRETTY_PRINT));

echo "Test file created: " . ($result !== false ? "YES (" . $result . " bytes)" : "FAILED") . "<br>";
if ($result !== false) {
    echo "Test file path: " . $testFile . "<br>";
    echo "<br><a href='?cleanup=1'>Delete test file</a>";
}

if (isset($_GET['cleanup'])) {
    $testFiles = glob($cacheDir . '/test_cache_*.json');
    foreach ($testFiles as $file) {
        unlink($file);
    }
    echo "<br><strong>Test files cleaned up!</strong>";
    echo "<br><a href='test_cache_access.php'>Refresh</a>";
}

echo "<br><br>";
echo "<a href='modules/users/profile.php'>Go to Profile Page</a>";
?>
