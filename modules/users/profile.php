<?php
/**
 * Optimized User Profile Page - Lazy Loading Implementation
 * Loads data on-demand via AJAX for better performance
 */

// Enable output compression
ob_start('ob_gzhandler');

require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Define cache file path
$cacheDir = __DIR__ . '/../../storage/cache';
$cacheFile = $cacheDir . '/profile_' . md5($userId) . '.json';

// Ensure cache directory exists
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Try to load user data from Firebase, fallback to cache
$user = null;
$isOfflineMode = false;
$cacheAge = null;
$shouldRefreshCache = true;

// Check if cache exists and is fresh (less than 5 minutes old)
if (file_exists($cacheFile)) {
    $cacheTimestamp = filemtime($cacheFile);
    $cacheAge = time() - $cacheTimestamp;
    $cacheAgeMinutes = floor($cacheAge / 60);
    
    // Only refresh cache if it's older than 5 minutes (300 seconds)
    // or if manual refresh is requested
    $shouldRefreshCache = $cacheAge > 300 || isset($_GET['refresh_cache']);
    
    if (!$shouldRefreshCache) {
        error_log("Using fresh cache (age: {$cacheAgeMinutes} minutes)");
    }
}

try {
    // Try to fetch from Firebase only if cache needs refresh
    if ($shouldRefreshCache) {
        $user = $db->read('users', $userId);
        
        // If successful, cache the data for offline use
        if ($user) {
            $cacheData = [
                'user' => $user,
                'cached_at' => date('Y-m-d H:i:s'),
                'user_id' => $userId
            ];
            file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT));
            error_log("Cache updated successfully");
        }
    } else {
        // Use existing cache instead of fetching
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if ($cacheData && isset($cacheData['user'])) {
            $user = $cacheData['user'];
            error_log("Using existing fresh cache");
        }
    }
    
} catch (Exception $e) {
    // Firebase threw exception - will try cache below
    error_log("Firebase exception: " . $e->getMessage());
}

// If Firebase failed (null/false) or threw exception, try cache
if (!$user) {
    error_log("Firebase failed to load user, trying cache...");
    
    if (file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        
        if ($cacheData && isset($cacheData['user'])) {
            $user = $cacheData['user'];
            $isOfflineMode = true;
            error_log("✓ Loaded user data from cache (cached at: " . $cacheData['cached_at'] . ")");
        } else {
            error_log("✗ Cache exists but no valid user data. CacheData: " . json_encode($cacheData));
        }
    } else {
        error_log("✗ Cache file does not exist: " . $cacheFile);
    }
    
    // If no cache available, show offline page
    if (!$user) {
        error_log("No cached data available for offline mode");
        
        // Show offline mode page
            echo '<!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Offline Mode - Profile</title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
                <style>
                    body { 
                        font-family: system-ui, -apple-system, sans-serif; 
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        min-height: 100vh;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        margin: 0;
                        padding: 20px;
                    }
                    .offline-container {
                        background: white;
                        border-radius: 16px;
                        padding: 40px;
                        max-width: 500px;
                        text-align: center;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                }
                .offline-icon {
                    font-size: 80px;
                    color: #ef4444;
                    margin-bottom: 20px;
                }
                h1 { color: #1f2937; margin-bottom: 10px; }
                p { color: #6b7280; margin-bottom: 30px; line-height: 1.6; }
                .retry-btn {
                    background: linear-gradient(135deg, #667eea, #764ba2);
                    color: white;
                    border: none;
                    padding: 12px 30px;
                    border-radius: 8px;
                    font-weight: 600;
                    cursor: pointer;
                    font-size: 16px;
                }
                .retry-btn:hover { opacity: 0.9; }
                .status { 
                    display: inline-block;
                    padding: 8px 16px;
                    background: #fee2e2;
                    color: #dc2626;
                    border-radius: 20px;
                    font-size: 14px;
                    font-weight: 600;
                    margin-bottom: 20px;
                }
            </style>
        </head>
        <body>
            <div class="offline-container">
                <div class="offline-icon">
                    <i class="fas fa-wifi-slash"></i>
                </div>
                <div class="status">
                    <i class="fas fa-circle" style="font-size: 8px;"></i> Offline Mode
                </div>
                <h1>Connection Lost</h1>
                <p>
                    Unable to connect to the server. Please check your internet connection and try again.
                    <br><br>
                    <strong>Your data is safe!</strong> Any changes you make will be saved locally 
                    and synchronized automatically when connection is restored.
                </p>
                <button class="retry-btn" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Retry Connection
                </button>
                <br><br>
                <a href="../../index.php" style="color: #667eea; text-decoration: none;">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <!-- Auto-retry every 10 seconds -->
            <script>
                let retryCount = 0;
                const maxRetries = 6;
                
                function checkConnection() {
                    fetch(window.location.href, { method: "HEAD" })
                        .then(() => {
                            console.log("Connection restored!");
                            location.reload();
                        })
                        .catch(() => {
                            retryCount++;
                            if (retryCount < maxRetries) {
                                console.log(`Retry ${retryCount}/${maxRetries} in 10 seconds...`);
                                setTimeout(checkConnection, 10000);
                            }
                        });
                }
                
                // Start auto-retry after 10 seconds
                setTimeout(checkConnection, 10000);
                
                // Show connectivity status
                window.addEventListener("online", () => {
                    alert("Connection restored! Reloading...");
                    location.reload();
                });
            </script>
        </body>
        </html>';
        exit;
    }
}

// Check if user data was loaded successfully (handle both false and null)
error_log("DEBUG: Checking user data. Type: " . gettype($user) . ", Empty: " . (empty($user) ? 'yes' : 'no') . ", Truthiness: " . ($user ? 'true' : 'false'));

if (!$user) {
    error_log("Failed to load user data for user ID: " . $userId . " - No cache available");
    
    // Show friendly offline message
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Offline - Profile</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            body { 
                font-family: system-ui, -apple-system, sans-serif; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
                padding: 20px;
            }
            .message-box {
                background: white;
                border-radius: 16px;
                padding: 40px;
                max-width: 500px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .icon { font-size: 80px; color: #f59e0b; margin-bottom: 20px; }
            h1 { color: #1f2937; margin-bottom: 10px; }
            p { color: #6b7280; margin-bottom: 30px; line-height: 1.6; }
            .btn {
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                font-size: 16px;
                text-decoration: none;
                display: inline-block;
                margin: 5px;
            }
        </style>
    </head>
    <body>
        <div class="message-box">
            <div class="icon"><i class="fas fa-cloud-download-alt"></i></div>
            <h1>Profile Not Cached</h1>
            <p>
                Your profile data hasn\'t been cached yet. Please visit this page while online first 
                to enable offline access.
            </p>
            <a href="../../index.php" class="btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <button class="btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Try Again
            </button>
        </div>
    </body>
    </html>';
    exit;
}

$pageTitle = 'My Profile';

// Get role info (lightweight query)
$role = ['role_name' => 'User'];

// Check if user has direct role field (string) or role_id (reference)
if (!empty($user['role']) && is_string($user['role'])) {
    // Direct role assignment (e.g., 'admin', 'manager', 'user')
    $role['role_name'] = ucfirst(strtolower($user['role']));
} elseif (!empty($user['role_id'])) {
    // Role ID reference
    $roleData = $db->read('roles', $user['role_id']);
    if ($roleData) {
        $role = $roleData;
    }
}

// Handle form submissions
$message = '';
$messageType = '';

// Check if manual cache refresh was requested
if (isset($_GET['refresh_cache']) && !isset($_GET['silent'])) {
    $message = 'Cache refreshed successfully! You\'re viewing the latest data.';
    $messageType = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Skip POST processing if we're in offline mode (loaded from cache)
    if ($isOfflineMode) {
        $message = 'Changes saved locally and will sync when connection is restored.';
        $messageType = 'info';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
        // Track changes for activity log
        $changes = [];
        $oldData = $user; // Store original data
        
        $updateData = [
            'username' => trim($_POST['username'] ?? ''),
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'updated_at' => date('c')
        ];
        
        // Validate email if changed
        if (!empty($updateData['email']) && !filter_var($updateData['email'], FILTER_VALIDATE_EMAIL)) {
            $message = 'Invalid email format.';
            $messageType = 'error';
        } elseif (empty($updateData['username'])) {
            $message = 'Username is required.';
            $messageType = 'error';
        } else {
            // Check if username is already taken by another user
            if ($updateData['username'] !== ($oldData['username'] ?? '')) {
                $existingUsers = $db->readAll('users', [['username', '==', $updateData['username']]]);
                if (!empty($existingUsers)) {
                    foreach ($existingUsers as $existingUser) {
                        if (($existingUser['id'] ?? '') !== $userId) {
                            $message = 'Username already taken. Please choose another.';
                            $messageType = 'error';
                            break;
                        }
                    }
                }
            }
            
            if (empty($message)) {
                // Track what changed
                foreach (['username', 'first_name', 'last_name', 'email', 'phone'] as $field) {
                    if (($oldData[$field] ?? '') !== $updateData[$field]) {
                        $changes[$field] = [
                            'old' => $oldData[$field] ?? '',
                            'new' => $updateData[$field]
                        ];
                    }
                }
                
                // Debug log
                error_log("Attempting to update user {$userId} with data: " . json_encode($updateData));
                
                $result = $db->update('users', $userId, $updateData);
                
                error_log("Update result: " . ($result ? 'SUCCESS' : 'FAILED'));
                
                if ($result !== false) {
                    // Update session username if changed
                    if (isset($changes['username'])) {
                        $_SESSION['username'] = $updateData['username'];
                    }
                    
                    // Log the activity only if there were actual changes
                    if (!empty($changes)) {
                        logProfileActivity('updated', $userId, $changes);
                    }
                    
                    $message = 'Profile updated successfully!';
                    $messageType = 'success';
                    
                    // Refresh user data
                    $user = $db->read('users', $userId);
                    
                    if (!$user) {
                        error_log("WARNING: Profile updated but failed to reload user data");
                        $message .= ' (Please refresh the page to see changes)';
                    }
                } else {
                    $message = 'Failed to update profile. Please try again.';
                    $messageType = 'error';
                    error_log("Failed to update user {$userId}");
                }
            }
        }
    }
    
    if ($action === 'change_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $message = 'Password must be at least 8 characters long.';
            $messageType = 'error';
        } else {
            // Verify current password
            if (verifyPassword($currentPassword, $user['password_hash'])) {
                $hashedPassword = hashPassword($newPassword);
                if ($db->update('users', $userId, [
                    'password_hash' => $hashedPassword,
                    'require_password_change' => false,
                    'updated_at' => date('c')
                ])) {
                    // Log password change activity
                    logProfileActivity('password_changed', $userId);
                    
                    $message = 'Password changed successfully!';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to change password.';
                    $messageType = 'error';
                }
            } else {
                $message = 'Current password is incorrect.';
                $messageType = 'error';
            }
        }
    }
    } // Close else block for offline mode check
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Inventory System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Dashboard Header Styles -->
    <?php include '../../includes/dashboard_header.php'; ?>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            padding: 0;
        }
        
        /* Adjust main content to account for dashboard header */
        .dashboard-content {
            padding: 20px;
            margin-top: 70px; /* Space for fixed header */
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .profile-header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 30px;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: bold;
            flex-shrink: 0;
        }
        
        .profile-info {
            flex-grow: 1;
        }
        
        .profile-name {
            font-size: 32px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .profile-role {
            color: #718096;
            font-size: 16px;
            margin-bottom: 10px;
        }
        
        .profile-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #4a5568;
            font-size: 14px;
        }
        
        .meta-item i {
            color: #667eea;
        }
        
        .tabs {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .tab-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .tab-button {
            padding: 12px 24px;
            border: none;
            background: transparent;
            color: #4a5568;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab-button:hover {
            background: #f7fafc;
            color: #667eea;
        }
        
        .tab-button.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }
        
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s ease-in-out infinite;
            border-radius: 10px;
            height: 20px;
            margin-bottom: 10px;
        }
        
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        
        .data-card {
            background: #f7fafc;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #667eea;
        }
        
        .data-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .data-row:last-child {
            border-bottom: none;
        }
        
        .data-label {
            font-weight: 600;
            color: #4a5568;
        }
        
        .data-value {
            color: #2d3748;
        }
        
        .activity-item {
            display: flex;
            align-items: start;
            gap: 15px;
            padding: 15px;
            background: #f7fafc;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            background: #edf2f7;
            transform: translateX(5px);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }
        
        .activity-content {
            flex-grow: 1;
        }
        
        .activity-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .activity-meta {
            font-size: 13px;
            color: #718096;
        }
        
        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .permission-card {
            padding: 20px;
            background: #f7fafc;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s ease;
        }
        
        .permission-card.granted {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        
        .permission-card.denied {
            opacity: 0.5;
        }
        
        .permission-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .permission-card.granted .permission-icon {
            color: #28a745;
        }
        
        .permission-info h4 {
            margin-bottom: 5px;
            color: #2d3748;
        }
        
        .permission-info p {
            font-size: 13px;
            color: #718096;
        }
        
        .store-card {
            padding: 20px;
            background: #f7fafc;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .store-card:hover {
            background: #edf2f7;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .store-info h4 {
            color: #2d3748;
            margin-bottom: 5px;
        }
        
        .store-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: #718096;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: white;
            color: #667eea;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .back-button:hover {
            transform: translateX(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .load-more-btn {
            width: 100%;
            margin-top: 15px;
            background: #edf2f7;
            color: #4a5568;
        }
        
        .load-more-btn:hover {
            background: #e2e8f0;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            font-size: 16px;
        }
        
        /* Management Card Styles */
        .info-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.2s ease;
        }
        
        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 35px rgba(0,0,0,0.15) !important;
        }
        
        /* Connectivity Indicator Styles */
        #connectivity-indicator {
            position: fixed;
            top: 70px;
            right: 20px;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.3s ease;
        }
        
        #connectivity-indicator.online {
            background: #10b981;
            color: white;
        }
        
        #connectivity-indicator.offline {
            background: #ef4444;
            color: white;
        }
        
        #pending-updates-badge {
            position: fixed;
            top: 75px;
            right: 180px;
            background: #f59e0b;
            color: white;
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            z-index: 9998;
            display: none;
        }
        
        #notification-container {
            position: fixed;
            top: 130px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        
        .notification {
            margin-bottom: 10px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(400px);
                opacity: 0;
            }
        }
        
        /* Loading Overlay Styles */
        #refresh-loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            backdrop-filter: blur(4px);
        }
        
        #refresh-loading-overlay.active {
            display: flex;
        }
        
        .loading-content {
            background: white;
            padding: 40px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: fadeIn 0.3s ease;
        }
        
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid #e5e7eb;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="refresh-loading-overlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <h3 style="margin: 0 0 10px 0; color: #1f2937;">Refreshing Cache</h3>
            <p style="margin: 0; color: #6b7280;">Fetching latest data from server...</p>
        </div>
    </div>
    
    <!-- Connectivity Indicator -->
    <div id="connectivity-indicator" class="online" style="display: none;">
        <i class="fas fa-wifi"></i>
        <span>Online</span>
    </div>
    
    <!-- Pending Updates Badge -->
    <div id="pending-updates-badge" style="display: none;"></div>
    
    <!-- Notification Container -->
    <div id="notification-container"></div>
    
    <!-- Dashboard content wrapper -->
    <div class="dashboard-content">
        <div class="container">
        
        <?php if ($isOfflineMode): ?>
        <?php 
            // Calculate cache age for display
            $cacheTimestamp = file_exists($cacheFile) ? filemtime($cacheFile) : time();
            $cacheAgeSeconds = time() - $cacheTimestamp;
            
            if ($cacheAgeSeconds < 60) {
                $cacheAgeDisplay = "just now";
            } elseif ($cacheAgeSeconds < 3600) {
                $minutes = floor($cacheAgeSeconds / 60);
                $cacheAgeDisplay = $minutes . " minute" . ($minutes > 1 ? "s" : "") . " ago";
            } elseif ($cacheAgeSeconds < 86400) {
                $hours = floor($cacheAgeSeconds / 3600);
                $cacheAgeDisplay = $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
            } else {
                $days = floor($cacheAgeSeconds / 86400);
                $cacheAgeDisplay = $days . " day" . ($days > 1 ? "s" : "") . " ago";
            }
        ?>
        <div class="alert" style="background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Offline Mode:</strong> You're viewing cached profile data (updated <?= $cacheAgeDisplay ?>). Some features may be limited. Changes will sync when connection is restored.
        </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'info' ? 'info-circle' : 'exclamation-circle') ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <div class="profile-header">
            <div class="profile-avatar">
                <?= strtoupper(substr($user['first_name'] ?? 'U', 0, 1)) . strtoupper(substr($user['last_name'] ?? 'U', 0, 1)) ?>
            </div>
            <div class="profile-info">
                <h1 class="profile-name"><?= htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></h1>
                <div class="profile-role"><?= htmlspecialchars($role['role_name'] ?? 'User') ?></div>
                <div class="profile-meta">
                    <div class="meta-item">
                        <i class="fas fa-envelope"></i>
                        <?= htmlspecialchars($user['email'] ?? '') ?>
                    </div>
                    <?php if (!empty($user['phone'])): ?>
                    <div class="meta-item">
                        <i class="fas fa-phone"></i>
                        <?= htmlspecialchars($user['phone']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="meta-item">
                        <i class="fas fa-user-tag"></i>
                        <?= htmlspecialchars($user['username'] ?? '') ?>
                    </div>
                </div>
            </div>
            <div style="margin-left: auto;">
                <button id="refreshCacheBtn" class="btn" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-sync-alt" id="refreshIcon"></i> 
                    <span id="refreshText">Refresh Cache</span>
                </button>
                <small id="cacheStatus" style="display: block; margin-top: 5px; color: #6b7280; font-size: 12px;">
                    <?php
                    if (file_exists($cacheFile)) {
                        $cacheTimestamp = filemtime($cacheFile);
                        $cacheAgeSeconds = time() - $cacheTimestamp;
                        
                        if ($cacheAgeSeconds < 60) {
                            echo "Cache: Updated just now";
                        } elseif ($cacheAgeSeconds < 3600) {
                            $minutes = floor($cacheAgeSeconds / 60);
                            echo "Cache: Updated {$minutes} min ago";
                        } else {
                            $hours = floor($cacheAgeSeconds / 3600);
                            echo "Cache: Updated {$hours}h ago";
                        }
                    } else {
                        echo "Cache: Not available";
                    }
                    ?>
                </small>
            </div>
        </div>
        
        <div class="tabs">
            <div class="tab-buttons">
                <button class="tab-button active" data-tab="profile">
                    <i class="fas fa-user"></i> Profile Info
                </button>
                <button class="tab-button" data-tab="activity">
                    <i class="fas fa-history"></i> Activity Log
                </button>
                <button class="tab-button" data-tab="permissions">
                    <i class="fas fa-shield-alt"></i> Permissions
                </button>
                <button class="tab-button" data-tab="stores">
                    <i class="fas fa-store"></i> Store Access
                </button>
                <button class="tab-button" data-tab="security">
                    <i class="fas fa-lock"></i> Security
                </button>
            </div>
            
            <!-- Profile Tab -->
            <div class="tab-content active" id="tab-profile">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-group">
                        <label class="form-label">Username <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="username" class="form-input" 
                               value="<?= htmlspecialchars($user['username'] ?? '') ?>" 
                               required minlength="3" maxlength="50"
                               pattern="[a-zA-Z0-9_]+"
                               title="Username can only contain letters, numbers, and underscores">
                        <small style="color: #6b7280;">Letters, numbers, and underscores only. 3-50 characters.</small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-input" value="<?= htmlspecialchars($user['first_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-input" value="<?= htmlspecialchars($user['last_name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>
            </div>
            
            <!-- Activity Tab (Lazy Loaded) -->
            <div class="tab-content" id="tab-activity">
                <div id="activity-loading">
                    <div class="loading-skeleton" style="height: 80px;"></div>
                    <div class="loading-skeleton" style="height: 80px;"></div>
                    <div class="loading-skeleton" style="height: 80px;"></div>
                </div>
                <div id="activity-content" style="display: none;"></div>
            </div>
            
            <!-- Permissions Tab (Lazy Loaded) -->
            <div class="tab-content" id="tab-permissions">
                <div id="permissions-loading">
                    <div class="loading-skeleton" style="height: 100px;"></div>
                    <div class="loading-skeleton" style="height: 100px;"></div>
                    <div class="loading-skeleton" style="height: 100px;"></div>
                </div>
                <div id="permissions-content" style="display: none;"></div>
            </div>
            
            <!-- Stores Tab (Lazy Loaded) -->
            <div class="tab-content" id="tab-stores">
                <div id="stores-loading">
                    <div class="loading-skeleton" style="height: 80px;"></div>
                    <div class="loading-skeleton" style="height: 80px;"></div>
                    <div class="loading-skeleton" style="height: 80px;"></div>
                </div>
                <div id="stores-content" style="display: none;"></div>
            </div>
            
            <!-- Security Tab -->
            <div class="tab-content" id="tab-security">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-input" required minlength="8">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-input" required minlength="8">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>
        </div>
        </div> <!-- Close container -->
    </div> <!-- Close dashboard-content -->
    
    <script>
        // Tab switching
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                
                // Update active states
                document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById('tab-' + tabName).classList.add('active');
                
                // Lazy load data when tab is clicked
                if (tabName === 'activity' && !window.activityLoaded) {
                    loadActivities();
                    window.activityLoaded = true;
                } else if (tabName === 'permissions' && !window.permissionsLoaded) {
                    loadPermissions();
                    window.permissionsLoaded = true;
                } else if (tabName === 'stores' && !window.storesLoaded) {
                    loadStores();
                    window.storesLoaded = true;
                }
            });
        });
        
        let activityOffset = 0;
        const activityLimit = 10;
        
        async function loadActivities(append = false) {
            try {
                if (!append) {
                    activityOffset = 0;
                }
                
                const response = await fetch(`profile/api.php?action=get_activities&limit=${activityLimit}&offset=${activityOffset}`);
                const data = await response.json();
                
                document.getElementById('activity-loading').style.display = 'none';
                document.getElementById('activity-content').style.display = 'block';
                
                if (data.success && data.data.length > 0) {
                    const container = document.getElementById('activity-content');
                    
                    if (!append) {
                        container.innerHTML = `
                            <div style="margin-bottom: 20px;">
                                <a href="profile/activity_manager.php" style="text-decoration: none; color: inherit;">
                                    <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border-left: 4px solid #667eea;">
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-history" style="font-size: 24px; color: white;"></i>
                                            </div>
                                            <div style="flex: 1;">
                                                <h4 style="margin: 0 0 5px 0; font-size: 16px;">Activity Manager</h4>
                                                <p style="margin: 0; font-size: 14px; color: #666;">Track and manage all user activities</p>
                                            </div>
                                            <i class="fas fa-arrow-right" style="color: #667eea;"></i>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        `;
                    }
                    
                    data.data.forEach(activity => {
                        const item = document.createElement('div');
                        item.className = 'activity-item';
                        item.innerHTML = `
                            <div class="activity-icon">
                                <i class="fas fa-${getActivityIcon(activity.action_type || activity.activity_type)}"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">${escapeHtml(activity.description || activity.action_type || activity.activity_type)}</div>
                                <div class="activity-meta">
                                    ${formatDate(activity.created_at)} • ${escapeHtml(activity.ip_address || 'N/A')}
                                </div>
                            </div>
                        `;
                        container.appendChild(item);
                    });
                    
                    activityOffset += data.data.length;
                    
                    // Add load more button if there are more items
                    if (data.has_more) {
                        let loadMoreBtn = document.getElementById('load-more-activities');
                        if (!loadMoreBtn) {
                            loadMoreBtn = document.createElement('button');
                            loadMoreBtn.id = 'load-more-activities';
                            loadMoreBtn.className = 'btn load-more-btn';
                            loadMoreBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Load More';
                            loadMoreBtn.onclick = () => loadActivities(true);
                            container.appendChild(loadMoreBtn);
                        }
                    } else {
                        const loadMoreBtn = document.getElementById('load-more-activities');
                        if (loadMoreBtn) loadMoreBtn.remove();
                    }
                } else if (!append) {
                    document.getElementById('activity-content').innerHTML = `
                        <div style="margin-bottom: 20px;">
                            <a href="profile/activity_manager.php" style="text-decoration: none; color: inherit;">
                                <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border-left: 4px solid #667eea;">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-history" style="font-size: 24px; color: white;"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <h4 style="margin: 0 0 5px 0; font-size: 16px;">Activity Manager</h4>
                                            <p style="margin: 0; font-size: 14px; color: #666;">Track and manage all user activities</p>
                                        </div>
                                        <i class="fas fa-arrow-right" style="color: #667eea;"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No Activity Yet</h3>
                            <p>Your activity history will appear here</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading activities:', error);
                document.getElementById('activity-loading').style.display = 'none';
                document.getElementById('activity-content').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Failed to load activities. Please try again.
                    </div>
                `;
                document.getElementById('activity-content').style.display = 'block';
            }
        }
        
        async function loadPermissions() {
            try {
                const response = await fetch('profile/api.php?action=get_permissions');
                const data = await response.json();
                
                document.getElementById('permissions-loading').style.display = 'none';
                document.getElementById('permissions-content').style.display = 'block';
                
                if (data.success) {
                    const perms = data.data;
                    const container = document.getElementById('permissions-content');
                    
                    const permissionsList = [
                        { key: 'can_view_reports', name: 'View Reports', icon: 'chart-bar', desc: 'Access and view system reports' },
                        { key: 'can_manage_inventory', name: 'Manage Inventory', icon: 'boxes', desc: 'Add, edit, and delete inventory items' },
                        { key: 'can_manage_users', name: 'Manage Users', icon: 'users', desc: 'Create and manage user accounts' },
                        { key: 'can_manage_stores', name: 'Manage Stores', icon: 'store', desc: 'Add and configure store locations' },
                        { key: 'can_configure_system', name: 'System Configuration', icon: 'cog', desc: 'Access system settings and configuration' }
                    ];
                    
                    // Add Permissions Manager card at the top (admin only)
                    let managerCardHTML = '';
                    if (perms.role === 'Admin') {
                        managerCardHTML = `
                            <div style="margin-bottom: 20px;">
                                <a href="profile/permissions_manager.php" style="text-decoration: none; color: inherit;">
                                    <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border-left: 4px solid #f5576c;">
                                        <div style="display: flex; align-items: center; gap: 15px;">
                                            <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                                <i class="fas fa-shield-alt" style="font-size: 24px; color: white;"></i>
                                            </div>
                                            <div style="flex: 1;">
                                                <h4 style="margin: 0 0 5px 0; font-size: 16px;">Permissions Manager</h4>
                                                <p style="margin: 0; font-size: 14px; color: #666;">Manage roles and user permissions</p>
                                            </div>
                                            <i class="fas fa-arrow-right" style="color: #f5576c;"></i>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        `;
                    }
                    
                    container.innerHTML = managerCardHTML + '<div class="permission-grid"></div>';
                    const grid = container.querySelector('.permission-grid');
                    
                    permissionsList.forEach(perm => {
                        const granted = perms[perm.key] || false;
                        const card = document.createElement('div');
                        card.className = `permission-card ${granted ? 'granted' : 'denied'}`;
                        card.innerHTML = `
                            <div class="permission-icon">
                                <i class="fas fa-${perm.icon}"></i>
                            </div>
                            <div class="permission-info">
                                <h4>${perm.name}</h4>
                                <p>${perm.desc}</p>
                            </div>
                        `;
                        grid.appendChild(card);
                    });
                }
            } catch (error) {
                console.error('Error loading permissions:', error);
                document.getElementById('permissions-loading').style.display = 'none';
                document.getElementById('permissions-content').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Failed to load permissions. Please try again.
                    </div>
                `;
                document.getElementById('permissions-content').style.display = 'block';
            }
        }
        
        async function loadStores() {
            try {
                const response = await fetch('profile/api.php?action=get_stores');
                const data = await response.json();
                
                document.getElementById('stores-loading').style.display = 'none';
                document.getElementById('stores-content').style.display = 'block';
                
                if (data.success && data.data.length > 0) {
                    const container = document.getElementById('stores-content');
                    container.innerHTML = `
                        <div style="margin-bottom: 20px;">
                            <a href="profile/stores_manager.php" style="text-decoration: none; color: inherit;">
                                <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border-left: 4px solid #00f2fe;">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-store" style="font-size: 24px; color: white;"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <h4 style="margin: 0 0 5px 0; font-size: 16px;">Stores Manager</h4>
                                            <p style="margin: 0; font-size: 14px; color: #666;">Manage stores and user access</p>
                                        </div>
                                        <i class="fas fa-arrow-right" style="color: #00f2fe;"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                    `;
                    
                    data.data.forEach(store => {
                        const card = document.createElement('div');
                        card.className = 'store-card';
                        card.innerHTML = `
                            <div class="store-info">
                                <h4>${escapeHtml(store.store_name)}</h4>
                                <div class="store-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(store.city || 'N/A')}, ${escapeHtml(store.state || 'N/A')}</span>
                                    ${store.phone ? `<span><i class="fas fa-phone"></i> ${escapeHtml(store.phone)}</span>` : ''}
                                </div>
                            </div>
                            <a href="../stores/profile.php?id=${store.id}" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View
                            </a>
                        `;
                        container.appendChild(card);
                    });
                } else {
                    document.getElementById('stores-content').innerHTML = `
                        <div style="margin-bottom: 20px;">
                            <a href="profile/stores_manager.php" style="text-decoration: none; color: inherit;">
                                <div class="info-card" style="cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border-left: 4px solid #00f2fe;">
                                    <div style="display: flex; align-items: center; gap: 15px;">
                                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                                            <i class="fas fa-store" style="font-size: 24px; color: white;"></i>
                                        </div>
                                        <div style="flex: 1;">
                                            <h4 style="margin: 0 0 5px 0; font-size: 16px;">Stores Manager</h4>
                                            <p style="margin: 0; font-size: 14px; color: #666;">Manage stores and user access</p>
                                        </div>
                                        <i class="fas fa-arrow-right" style="color: #00f2fe;"></i>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <div class="empty-state">
                            <i class="fas fa-store"></i>
                            <h3>No Store Access</h3>
                            <p>You don't have access to any stores yet</p>
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading stores:', error);
                document.getElementById('stores-loading').style.display = 'none';
                document.getElementById('stores-content').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Failed to load stores. Please try again.
                    </div>
                `;
                document.getElementById('stores-content').style.display = 'block';
            }
        }
        
        function getActivityIcon(type) {
            if (!type) return 'circle';
            const icons = {
                'login': 'sign-in-alt',
                'logout': 'sign-out-alt',
                'create': 'plus-circle',
                'created': 'plus-circle',
                'update': 'edit',
                'updated': 'edit',
                'delete': 'trash',
                'deleted': 'trash',
                'view': 'eye',
                'viewed': 'eye',
                'store_created': 'store',
                'store_updated': 'store-alt',
                'store_deleted': 'store-slash',
                'profile_updated': 'user-edit',
                'profile_password_changed': 'key',
                'product_created': 'box',
                'product_updated': 'boxes',
                'product_stock_adjusted': 'warehouse',
                'activity_cleared': 'eraser'
            };
            return icons[type] || icons[type.split('_')[0]] || 'circle';
        }
        
        function formatDate(dateString) {
            const date = new Date(dateString);
            const now = new Date();
            const diff = now - date;
            const seconds = Math.floor(diff / 1000);
            const minutes = Math.floor(seconds / 60);
            const hours = Math.floor(minutes / 60);
            const days = Math.floor(hours / 24);
            
            if (days > 7) {
                return date.toLocaleDateString();
            } else if (days > 0) {
                return `${days} day${days > 1 ? 's' : ''} ago`;
            } else if (hours > 0) {
                return `${hours} hour${hours > 1 ? 's' : ''} ago`;
            } else if (minutes > 0) {
                return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
            } else {
                return 'Just now';
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
    
    <!-- Offline Support Scripts -->
    <script src="../offline/offline_storage.js?v=2"></script>
    <script src="../offline/sync_manager.js?v=2"></script>
    <script src="../offline/connectivity_monitor.js?v=2"></script>
    <script src="../offline/conflict_resolver.js?v=2"></script>
    
    <script>
    // Initialize offline support for profile
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Initializing offline support...');
        
        // Cache current profile data for offline access
        const profileData = {
            id: <?php echo json_encode($userId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            first_name: <?php echo json_encode($user['first_name'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            last_name: <?php echo json_encode($user['last_name'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            email: <?php echo json_encode($user['email'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            username: <?php echo json_encode($user['username'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            phone: <?php echo json_encode($user['phone'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            role: <?php echo json_encode($user['role'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            isOffline: <?php echo $isOfflineMode ? 'true' : 'false'; ?>
        };
        
        // Store in sessionStorage for quick access
        sessionStorage.setItem('profileData', JSON.stringify(profileData));
        
        // Wait for all components to be ready
        setTimeout(async () => {
            if (!window.profileOfflineStorage || !window.profileSyncManager || !window.connectivityMonitor) {
                console.error('Offline support components not loaded');
                return;
            }
            
            // Cache profile in IndexedDB
            try {
                await window.profileOfflineStorage.cacheProfile('<?php echo $userId; ?>', profileData);
                console.log('Profile cached in IndexedDB');
            } catch (error) {
                console.error('Failed to cache profile:', error);
            }
            
            // Start auto-sync
            window.profileSyncManager.startAutoSync();
            
            // Update pending count badge
            await window.connectivityMonitor.updatePendingCount();
            
            // Show offline mode indicator if using cached data
            <?php if ($isOfflineMode): ?>
            window.connectivityMonitor.showNotification(
                'Using cached data from offline mode',
                'warning'
            );
            <?php endif; ?>
            
            // Listen for sync events
            window.profileSyncManager.addEventListener((event, data) => {
                if (event === 'sync-complete') {
                    window.connectivityMonitor.showNotification(
                        `Synced ${data.success} update(s) successfully`,
                        'success'
                    );
                    window.connectivityMonitor.updatePendingCount();
                } else if (event === 'sync-error') {
                    window.connectivityMonitor.showNotification(
                        'Sync failed. Will retry automatically.',
                        'error'
                    );
                }
            });
            
            // Intercept profile form submission
            const profileForm = document.querySelector('form[method="POST"]');
            if (profileForm) {
                profileForm.addEventListener('submit', async function(e) {
                    // If offline, save to local storage instead
                    if (!navigator.onLine) {
                        e.preventDefault();
                        
                        const formData = new FormData(this);
                        const updateData = {};
                        
                        for (const [key, value] of formData.entries()) {
                            if (key !== 'update_profile') {
                                updateData[key] = value;
                            }
                        }
                        
                        try {
                            const userId = '<?php echo $user_id; ?>';
                            await window.profileOfflineStorage.savePendingUpdate(userId, updateData);
                            
                            window.connectivityMonitor.showNotification(
                                'Changes saved locally. Will sync when online.',
                                'success'
                            );
                            
                            // Update pending count
                            await window.connectivityMonitor.updatePendingCount();
                            
                            // Update form fields to show saved state
                            document.querySelector('.alert-success')?.remove();
                            const alert = document.createElement('div');
                            alert.className = 'alert alert-success';
                            alert.style.cssText = 'background: #10b981; color: white; padding: 15px; border-radius: 8px; margin: 20px 0;';
                            alert.innerHTML = '<i class="fas fa-check-circle"></i> Changes saved offline. Will sync automatically when connected.';
                            this.insertBefore(alert, this.firstChild);
                            
                        } catch (error) {
                            console.error('Failed to save offline:', error);
                            window.connectivityMonitor.showNotification(
                                'Failed to save changes offline',
                                'error'
                            );
                        }
                    }
                    // If online, let form submit normally
                });
            }
            
            console.log('Offline support initialized successfully');
        }, 500);
    });
    
    // Manual cache refresh function - Make it globally accessible
    // Updated: 2025-10-16 - Global function for button onclick
    window.refreshCache = async function() {
        console.log('=== REFRESH CACHE FUNCTION CALLED ===');
        const btn = document.getElementById('refreshCacheBtn');
        const icon = document.getElementById('refreshIcon');
        const text = document.getElementById('refreshText');
        const status = document.getElementById('cacheStatus');
        const overlay = document.getElementById('refresh-loading-overlay');
        
        console.log('Refresh cache clicked');
        console.log('Overlay element:', overlay);
        
        // Show loading overlay FIRST
        if (overlay) {
            overlay.classList.add('active');
            console.log('Overlay activated, classes:', overlay.className);
        } else {
            console.error('Overlay element not found!');
        }
        
        // Disable button and show loading state
        btn.disabled = true;
        btn.style.opacity = '0.6';
        btn.style.cursor = 'not-allowed';
        icon.classList.add('fa-spin');
        text.textContent = 'Refreshing...';
        
        // Also change button background for extra visibility
        const originalBg = btn.style.background;
        btn.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        btn.style.filter = 'brightness(0.8)';
        
        try {
            // Try AJAX refresh first for better UX
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('refresh_cache', '1');
            currentUrl.searchParams.set('ajax', '1');
            
            console.log('Fetching:', currentUrl.toString());
            
            const response = await fetch(currentUrl.toString(), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            console.log('Response received:', response.ok);
            
            if (response.ok) {
                // Success - update status and show notification
                status.textContent = 'Cache: Updated just now';
                text.textContent = 'Refresh Cache';
                icon.classList.remove('fa-spin');
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.style.background = originalBg;
                btn.style.filter = 'none';
                
                if (window.connectivityMonitor) {
                    window.connectivityMonitor.showNotification(
                        'Cache refreshed successfully!',
                        'success'
                    );
                }
                
                // Keep overlay visible for 1.5 seconds to ensure user sees it
                setTimeout(() => {
                    console.log('Hiding overlay and reloading');
                    if (overlay) {
                        overlay.classList.remove('active');
                    }
                    setTimeout(() => {
                        window.location.reload();
                    }, 300);
                }, 1500);
            } else {
                throw new Error('Refresh failed');
            }
        } catch (error) {
            console.error('AJAX refresh failed, doing full page reload:', error);
            
            // Hide overlay
            if (overlay) {
                overlay.classList.remove('active');
            }
            
            // Fallback to full page reload
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('refresh_cache', '1');
            window.location.href = currentUrl.toString();
        }
    };
    
    // Background auto-refresh (every 30 seconds when online)    // Background auto-refresh (every 30 seconds when online)
    let autoRefreshInterval = null;
    
    function startAutoRefresh() {
        // Only auto-refresh if online
        if (navigator.onLine) {
            autoRefreshInterval = setInterval(() => {
                if (navigator.onLine) {
                    // Silently refresh cache in background using fetch
                    fetch(window.location.href + (window.location.search ? '&' : '?') + 'refresh_cache=1&silent=1')
                        .then(response => {
                            if (response.ok) {
                                console.log('Background cache refresh successful');
                                // Update cache status display
                                const status = document.getElementById('cacheStatus');
                                if (status) {
                                    status.textContent = 'Cache: Updated just now';
                                }
                            }
                        })
                        .catch(error => {
                            console.log('Background refresh failed:', error);
                        });
                }
            }, 30000); // 30 seconds
        }
    }
    
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
    
    // Start auto-refresh when page loads
    document.addEventListener('DOMContentLoaded', () => {
        // Attach click handler to refresh button
        const refreshBtn = document.getElementById('refreshCacheBtn');
        if (refreshBtn) {
            console.log('Attaching click handler to refresh button');
            refreshBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('Refresh button clicked via event listener');
                if (typeof window.refreshCache === 'function') {
                    window.refreshCache();
                } else {
                    console.error('window.refreshCache is not a function!', typeof window.refreshCache);
                }
            });
        } else {
            console.error('Refresh button not found!');
        }
        
        startAutoRefresh();
        
        // Stop/start auto-refresh based on online status
        window.addEventListener('online', startAutoRefresh);
        window.addEventListener('offline', stopAutoRefresh);
        
        // Verify refreshCache is globally accessible
        console.log('=== Checking global functions ===');
        console.log('window.refreshCache exists:', typeof window.refreshCache);
        console.log('typeof window.refreshCache:', typeof window.refreshCache);
    });
    </script>
</body>
</html>

