<?php
/**
 * Optimized User Profile Page - PostgreSQL Fast Loading
 */

require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Load user data from PostgreSQL (fast, no caching needed)
$user = null;
try {
    $sqlDb = SQLDatabase::getInstance();
    $user = $sqlDb->fetch("SELECT * FROM users WHERE id = ? OR firebase_id = ?", [$userId, $userId]);
    
    if (!$user) {
        // Fallback to Firebase
        $db = getDB();
        $user = $db->read('users', $userId);
    }
} catch (Exception $e) {
    error_log("Error loading user profile: " . $e->getMessage());
    // Fallback to Firebase
    try {
        $db = getDB();
        $user = $db->read('users', $userId);
    } catch (Exception $e2) {
        error_log("Firebase also failed: " . $e2->getMessage());
    }
}

// If still no user data, show error
if (!$user) {
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - Profile</title>
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
            .error-container {
                background: white;
                border-radius: 16px;
                padding: 40px;
                max-width: 500px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            .error-icon {
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
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h1>Unable to Load Profile</h1>
            <p>We couldn\'t load your profile data. Please try again.</p>
            <button class="retry-btn" onclick="location.reload()">
                <i class="fas fa-sync-alt"></i> Retry
            </button>
            <br><br>
            <a href="../../index.php" style="color: #667eea; text-decoration: none;">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </body>
    </html>';
    exit;
}

// User data loaded successfully
error_log("User profile loaded successfully for: " . ($user['username'] ?? $userId));

$pageTitle = 'My Profile';

// Get role info
$role = ['role_name' => 'User'];
$userRole = strtolower($user['role'] ?? 'user');
$isAdmin = ($userRole === 'admin');
$isManager = ($userRole === 'manager');

// Check if user has direct role field (string) or role_id (reference)
if (!empty($user['role']) && is_string($user['role'])) {
    // Direct role assignment (e.g., 'admin', 'manager', 'user')
    $role['role_name'] = ucfirst(strtolower($user['role']));
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB(); // Firebase for write operations (will migrate later)
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
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Inventory System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Dashboard Header Styles removed from here to fix HTML structure -->
    
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
            flex-wrap: wrap;
            overflow: visible;
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
            position: relative;
            overflow: hidden;
        }
        
        .avatar-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: white;
            border: 3px solid #667eea;
            color: #667eea;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .avatar-upload-btn:hover {
            background: #667eea;
            color: white;
            transform: scale(1.1);
        }
        
        .profile-info {
            flex-grow: 1;
            min-width: 250px;
            overflow: visible;
        }
        
        .profile-name {
            font-size: 32px;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 5px;
            overflow-wrap: break-word;
            word-break: break-word;
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
            overflow: visible;
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
        
        /* Statistics Dashboard */
        .stats-dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .stat-info {
            flex: 1;
        }
        
        .stat-label {
            font-size: 13px;
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
        }
        
        .stat-value.small {
            font-size: 16px;
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
        
        /* Password Input with Toggle */
        .password-input-wrapper {
            position: relative;
        }
        
        .password-input-wrapper .form-input {
            padding-right: 45px;
        }
        
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6b7280;
            cursor: pointer;
            padding: 8px;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
        
        /* Password Strength Meter */
        .password-strength {
            margin-top: 12px;
        }
        
        .strength-bar {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        
        .strength-fill {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 3px;
        }
        
        .strength-fill.weak {
            width: 25%;
            background: #ef4444;
        }
        
        .strength-fill.fair {
            width: 50%;
            background: #f59e0b;
        }
        
        .strength-fill.good {
            width: 75%;
            background: #10b981;
        }
        
        .strength-fill.strong {
            width: 100%;
            background: #059669;
        }
        
        .strength-text {
            font-size: 13px;
            font-weight: 600;
        }
        
        .strength-text.weak { color: #ef4444; }
        .strength-text.fair { color: #f59e0b; }
        .strength-text.good { color: #10b981; }
        .strength-text.strong { color: #059669; }
        
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
        
        .stores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }
        
        .store-card {
            padding: 20px;
            background: white;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .store-card:hover {
            border-color: #667eea;
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.15);
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
    <?php include '../../includes/dashboard_header.php'; ?>

    <!-- Loading Overlay removed -->
    
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
        
        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType ?>">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : ($messageType === 'info' ? 'info-circle' : 'exclamation-circle') ?>"></i>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <div class="profile-header">
            <div class="profile-avatar" id="profileAvatar">
                <?php if (!empty($user['profile_picture'])): ?>
                    <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                <?php else: ?>
                    <?php
                    // Get initials from full_name or fallback to first/last name
                    $fullName = $user['full_name'] ?? ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '');
                    $nameParts = array_filter(explode(' ', trim($fullName)));
                    $initials = 'N1';
                    if (count($nameParts) >= 2) {
                        $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[count($nameParts)-1], 0, 1));
                    } elseif (count($nameParts) === 1) {
                        $initials = strtoupper(substr($nameParts[0], 0, 2));
                    }
                    echo $initials;
                    ?>
                <?php endif; ?>
                <input type="file" id="avatarUpload" accept="image/*" style="display: none;">
                <button class="avatar-upload-btn" onclick="document.getElementById('avatarUpload').click()" title="Change profile picture">
                    <i class="fas fa-camera"></i>
                </button>
            </div>
            <div class="profile-info">
                <h1 class="profile-name"><?= htmlspecialchars($user['full_name'] ?? ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></h1>
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
                <!-- Cache refresh button removed -->
            </div>
        </div>
        
        <!-- Profile Statistics Dashboard -->
        <div class="stats-dashboard">
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                    <i class="fas fa-history"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Total Activities</div>
                    <div class="stat-value" id="stat-activities">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <i class="fas fa-store"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Stores Access</div>
                    <div class="stat-value" id="stat-stores">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <i class="fas fa-sign-in-alt"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Last Login</div>
                    <div class="stat-value" id="stat-last-login">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="stat-info">
                    <div class="stat-label">Account Age</div>
                    <div class="stat-value" id="stat-account-age">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="tabs">
            <div class="tab-buttons">
                <button class="tab-button active" data-tab="profile">
                    <i class="fas fa-user"></i> Profile Info
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
            
            <!-- Security Tab -->
            <div class="tab-content" id="tab-security">
                <form method="POST" action="" id="passwordChangeForm">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label class="form-label">Current Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" name="current_password" id="currentPassword" class="form-input" required>
                            <button type="button" class="password-toggle" onclick="togglePassword('currentPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" name="new_password" id="newPassword" class="form-input" required minlength="8">
                            <button type="button" class="password-toggle" onclick="togglePassword('newPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="passwordStrength">
                            <div class="strength-bar">
                                <div class="strength-fill" id="strengthFill"></div>
                            </div>
                            <div class="strength-text" id="strengthText">Enter a password</div>
                        </div>
                        <small style="color: #6b7280; display: block; margin-top: 8px;">
                            Password must contain: 
                            <span id="req-length" style="color: #ef4444;">✗ 8+ characters</span>
                            <span id="req-uppercase" style="color: #ef4444;">✗ Uppercase</span>
                            <span id="req-lowercase" style="color: #ef4444;">✗ Lowercase</span>
                            <span id="req-number" style="color: #ef4444;">✗ Number</span>
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" name="confirm_password" id="confirmPassword" class="form-input" required minlength="8">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small id="passwordMatch" style="display: none;"></small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="submitPasswordBtn">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                </form>
            </div>
        </div>
        </div> <!-- Close container -->
    </div> <!-- Close dashboard-content -->
    
    <script>
        // User role data
        const userRole = <?= json_encode($userRole, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const isAdmin = <?= json_encode($isAdmin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const isManager = <?= json_encode($isManager, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        
        // Tab switching (no lazy loading - all data loaded on page load)
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                
                // Update active states
                document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById('tab-' + tabName).classList.add('active');
            });
        });
        
        // Utility: Debounce function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // Activity Management Variables
        let activityOffset = 0;
        const activityLimit = 50; // Load more at once for pre-loading
        let currentActivityUserId = null;
        let allActivitiesCache = []; // Cache all loaded activities
        let filteredActivitiesIndex = null; // Indexed filtered results
        let currentFilter = ''; // Current filter value
        let loadingActivities = false; // Prevent duplicate requests
        let abortController = null; // For cancelling requests
        let visibleActivitiesCount = 20; // Virtual scrolling - show only 20 at a time
        
        // Optimized render with virtual scrolling
        function renderActivities() {
            const container = document.getElementById('activity-content');
            if (!container) return;
            
            const startTime = performance.now();
            
            // Get filter values
            const searchTerm = (document.getElementById('activity-search')?.value || '').toLowerCase().trim();
            const filterType = (document.getElementById('activity-filter')?.value || '').toLowerCase();
            
            // Fast filter using single pass
            let filteredActivities;
            if (!searchTerm && !filterType) {
                filteredActivities = allActivitiesCache;
            } else {
                filteredActivities = [];
                for (let i = 0; i < allActivitiesCache.length; i++) {
                    const activity = allActivitiesCache[i];
                    
                    // Date range filter
                    if (window.activityDateFilter) {
                        const activityDate = new Date(activity.created_at);
                        const fromDate = window.activityDateFilter.from ? new Date(window.activityDateFilter.from) : null;
                        const toDate = window.activityDateFilter.to ? new Date(window.activityDateFilter.to + 'T23:59:59') : null;
                        
                        if (fromDate && activityDate < fromDate) continue;
                        if (toDate && activityDate > toDate) continue;
                    }
                    
                    // Type filter (fastest check first)
                    if (filterType && !(activity.action_type || '').toLowerCase().includes(filterType)) {
                        continue;
                    }
                    
                    // Search filter
                    if (searchTerm) {
                        const desc = (activity.description || '').toLowerCase();
                        const type = (activity.action_type || '').toLowerCase();
                        const user = (activity.user_name || '').toLowerCase();
                        
                        if (!desc.includes(searchTerm) && !type.includes(searchTerm) && !user.includes(searchTerm)) {
                            continue;
                        }
                    }
                    
                    filteredActivities.push(activity);
                }
            }
            
            // Clear old items
            const oldItems = container.querySelectorAll('.activity-item');
            oldItems.forEach(item => item.remove());
            
            // Remove old count/no results
            const oldCount = container.querySelector('.filter-count');
            if (oldCount) oldCount.remove();
            
            // Render activities (virtual scrolling - only render visible ones)
            if (filteredActivities.length > 0) {
                const loadMoreBtn = document.getElementById('load-more-activities');
                const fragment = document.createDocumentFragment();
                
                // Render only first batch for performance
                const renderCount = Math.min(visibleActivitiesCount, filteredActivities.length);
                
                for (let i = 0; i < renderCount; i++) {
                    const activity = filteredActivities[i];
                    const item = document.createElement('div');
                    item.className = 'activity-item';
                    
                    // Show username for admin viewing all users
                    const userBadge = (isAdmin && activity.user_name) ? `
                        <span style="background: #667eea; color: white; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-left: 10px;">
                            ${escapeHtml(activity.user_name)}
                        </span>
                    ` : '';
                    
                    item.innerHTML = `
                        <div class="activity-icon">
                            <i class="fas fa-${getActivityIcon(activity.action_type || activity.activity_type)}"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title">
                                ${escapeHtml(activity.description || activity.action_type || activity.activity_type)}
                                ${userBadge}
                            </div>
                            <div class="activity-meta">
                                ${formatDate(activity.created_at)} • ${escapeHtml(activity.ip_address || 'N/A')}
                            </div>
                        </div>
                    `;
                    
                    fragment.appendChild(item);
                }
                
                // Insert before load more button or append
                if (loadMoreBtn) {
                    container.insertBefore(fragment, loadMoreBtn);
                } else {
                    container.appendChild(fragment);
                }
                
                // Show filter count
                if (searchTerm || filterType) {
                    const countDiv = document.createElement('div');
                    countDiv.className = 'filter-count';
                    countDiv.style.cssText = 'padding: 10px 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px; margin-bottom: 15px; text-align: center; color: white; font-weight: 600; box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);';
                    countDiv.innerHTML = `<i class="fas fa-filter"></i> Showing ${renderCount} of ${filteredActivities.length} matching activities`;
                    
                    const firstItem = container.querySelector('.activity-item');
                    if (firstItem) {
                        container.insertBefore(countDiv, firstItem);
                    }
                    
                    // Add "show more" if there are more filtered results
                    if (filteredActivities.length > renderCount) {
                        const showMoreBtn = document.createElement('button');
                        showMoreBtn.className = 'btn load-more-btn';
                        showMoreBtn.innerHTML = `<i class="fas fa-chevron-down"></i> Show ${Math.min(20, filteredActivities.length - renderCount)} More (${filteredActivities.length - renderCount} remaining)`;
                        showMoreBtn.onclick = () => {
                            visibleActivitiesCount += 20;
                            renderActivities();
                        };
                        container.appendChild(showMoreBtn);
                    }
                } else if (allActivitiesCache.length > renderCount) {
                    // Not filtered, but showing partial results
                    const countDiv = document.createElement('div');
                    countDiv.className = 'filter-count';
                    countDiv.style.cssText = 'padding: 8px 12px; background: #f3f4f6; border-radius: 6px; margin-bottom: 12px; text-align: center; color: #6b7280; font-size: 13px;';
                    countDiv.innerHTML = `Showing ${renderCount} of ${allActivitiesCache.length} activities`;
                    
                    const firstItem = container.querySelector('.activity-item');
                    if (firstItem) {
                        container.insertBefore(countDiv, firstItem);
                    }
                }
            } else if (allActivitiesCache.length > 0) {
                // Has activities but filtered out
                const noResults = document.createElement('div');
                noResults.innerHTML = `
                    <div class="empty-state" style="padding: 40px 20px;">
                        <i class="fas fa-search"></i>
                        <h3>No Matching Activities</h3>
                        <p>Try adjusting your search or filter</p>
                        <button onclick="document.getElementById('activity-search').value=''; document.getElementById('activity-filter').value=''; renderActivities();" class="btn btn-primary" style="margin-top: 15px;">
                            <i class="fas fa-times-circle"></i> Clear Filters
                        </button>
                    </div>
                `;
                container.appendChild(noResults);
            }
            
            const endTime = performance.now();
            console.log(`Rendered ${filteredActivities.length} activities in ${(endTime - startTime).toFixed(2)}ms`);
        }
        
        // Add Activity Manager Toolbar
        function addActivityManagerToolbar() {
            const container = document.getElementById('activity-content');
            if (!container) return;
            
            // Check if toolbar already exists
            if (container.querySelector('.activity-manager-toolbar')) {
                console.log('Toolbar already exists');
                return;
            }
            
            let toolbarHTML = '';
            if (isAdmin || isManager) {
                // Load user list for admin
                let userSelectHTML = '';
                if (isAdmin) {
                    userSelectHTML = `
                        <select id="activity-user-filter" class="form-input" style="width: auto; padding: 8px 12px; min-width: 180px; background: white; color: #1f2937;">
                            <option value="all">All Users</option>
                            <option value="<?= $_SESSION['user_id'] ?>">My Activities</option>
                        </select>
                    `;
                }
                
                toolbarHTML = `
                    <div class="activity-manager-toolbar">
                        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px; margin-bottom: 20px; color: white; box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);">
                            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                <i class="fas fa-chart-line" style="font-size: 28px;"></i>
                                <div>
                                    <h3 style="margin: 0; font-size: 18px; font-weight: 600;">Activity Manager</h3>
                                    <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 13px;">Monitor, filter, and analyze user activities</p>
                                </div>
                            </div>
                            
                            ${isAdmin ? `
                            <div style="background: rgba(255,255,255,0.15); padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                                    <i class="fas fa-user-circle"></i> View Activities For:
                                </label>
                                ${userSelectHTML}
                            </div>
                            ` : ''}
                            
                            <!-- Date Range Filter -->
                            <div style="background: rgba(255,255,255,0.15); padding: 12px; border-radius: 8px; margin-bottom: 15px;">
                                <label style="display: block; margin-bottom: 8px; font-weight: 600; font-size: 13px;">
                                    <i class="fas fa-calendar-alt"></i> Date Range:
                                </label>
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <input type="date" id="activity-date-from" class="form-input" style="flex: 1; min-width: 150px; padding: 8px 12px; background: white; color: #1f2937;">
                                    <span style="display: flex; align-items: center; font-weight: 600;">to</span>
                                    <input type="date" id="activity-date-to" class="form-input" style="flex: 1; min-width: 150px; padding: 8px 12px; background: white; color: #1f2937;">
                                    <button onclick="applyDateFilter()" class="btn" style="background: rgba(255,255,255,0.25); color: white; padding: 8px 16px; border: 1px solid rgba(255,255,255,0.3);">
                                        <i class="fas fa-filter"></i> Apply
                                    </button>
                                    <button onclick="clearDateFilter()" class="btn" style="background: rgba(255,255,255,0.15); color: white; padding: 8px 16px;">
                                        <i class="fas fa-times"></i> Clear
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Quick Stats -->
                            <div id="activity-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 15px;">
                                <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700;" id="stat-total-activities">-</div>
                                    <div style="font-size: 12px; opacity: 0.9;">Total Activities</div>
                                </div>
                                <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700;" id="stat-today-activities">-</div>
                                    <div style="font-size: 12px; opacity: 0.9;">Today</div>
                                </div>
                                <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700;" id="stat-this-week">-</div>
                                    <div style="font-size: 12px; opacity: 0.9;">This Week</div>
                                </div>
                                <div style="background: rgba(255,255,255,0.2); padding: 12px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700;" id="stat-unique-types">-</div>
                                    <div style="font-size: 12px; opacity: 0.9;">Activity Types</div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                    <button onclick="exportActivities('csv')" class="btn" style="background: #10b981; color: white; padding: 8px 16px; font-size: 14px;">
                                        <i class="fas fa-file-csv"></i> Export CSV
                                    </button>
                                    <button onclick="exportActivities('json')" class="btn" style="background: #3b82f6; color: white; padding: 8px 16px; font-size: 14px;">
                                        <i class="fas fa-file-code"></i> Export JSON
                                    </button>
                                    <button onclick="exportActivities('pdf')" class="btn" style="background: #f59e0b; color: white; padding: 8px 16px; font-size: 14px;">
                                        <i class="fas fa-file-pdf"></i> Export PDF
                                    </button>
                                    ${isAdmin ? `
                                    <button onclick="if(confirm('⚠️ This will permanently clear all activity history for the selected user. Are you sure?')) clearActivities()" class="btn" style="background: #ef4444; color: white; padding: 8px 16px; font-size: 14px;">
                                        <i class="fas fa-trash-alt"></i> Clear History
                                    </button>
                                    <button onclick="showActivityAnalytics()" class="btn" style="background: #8b5cf6; color: white; padding: 8px 16px; font-size: 14px;">
                                        <i class="fas fa-chart-pie"></i> Analytics
                                    </button>` : ''}
                                </div>
                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <input type="text" id="activity-search" placeholder="🔍 Search activities..." class="form-input" style="width: 220px; padding: 8px 12px; font-size: 14px;">
                                    <select id="activity-filter" class="form-input" style="width: auto; padding: 8px 12px;">
                                        <option value="">📋 All Types</option>
                                        <option value="login">🔐 Login</option>
                                        <option value="logout">🚪 Logout</option>
                                        <option value="create">➕ Create</option>
                                        <option value="update">✏️ Update</option>
                                        <option value="delete">🗑️ Delete</option>
                                        <option value="store">🏪 Store</option>
                                        <option value="product">📦 Product</option>
                                        <option value="user">👤 User</option>
                                    </select>
                                    <button onclick="loadActivities(false, true)" class="btn" style="background: #6b7280; color: white; padding: 8px 16px; font-size: 14px;" title="Refresh activities">
                                        <i class="fas fa-sync-alt"></i> Refresh
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                container.insertAdjacentHTML('afterbegin', toolbarHTML);
                
                // Setup event listeners for search and filter
                setTimeout(() => {
                    const searchInput = document.getElementById('activity-search');
                    const filterSelect = document.getElementById('activity-filter');
                    
                    let rafId = null;
                    
                    if (searchInput) {
                        searchInput.addEventListener('input', () => {
                            if (rafId) cancelAnimationFrame(rafId);
                            rafId = requestAnimationFrame(() => {
                                visibleActivitiesCount = 20;
                                renderActivities();
                            });
                        });
                    }
                    
                    if (filterSelect) {
                        filterSelect.addEventListener('change', () => {
                            visibleActivitiesCount = 20;
                            renderActivities();
                        });
                    }
                    
                    // Load users for admin dropdown
                    if (isAdmin) {
                        fetch('profile/api.php?action=get_all_users')
                            .then(r => r.json())
                            .then(result => {
                                if (result.success) {
                                    const select = document.getElementById('activity-user-filter');
                                    if (select) {
                                        // Clear existing options except first
                                        while (select.options.length > 1) {
                                            select.remove(1);
                                        }
                                        
                                        result.data.forEach(u => {
                                            if (u.id !== '<?= $_SESSION['user_id'] ?>') {
                                                const opt = document.createElement('option');
                                                opt.value = u.id;
                                                opt.textContent = u.username + ' (' + u.role + ')';
                                                select.appendChild(opt);
                                            }
                                        });
                                        
                                        // Remove old listener to prevent duplicates (cloning)
                                        const newSelect = select.cloneNode(true);
                                        select.parentNode.replaceChild(newSelect, select);
                                        
                                        newSelect.addEventListener('change', () => {
                                            loadActivities(false, true);
                                        });
                                    }
                                }
                            });
                    }
                }, 100);
            }
        }
        
        async function loadActivities(append = false, skipCache = false) {
            // Prevent duplicate simultaneous requests
            if (loadingActivities && !skipCache) {
                console.log('Already loading activities, skipping...');
                return;
            }
            
            try {
                loadingActivities = true;
                
                // Check if we need to load (only if not appending and not forced)
                if (!append && !skipCache) {
                    // Cache check removed
                }
                
                if (!append) {
                    activityOffset = 0;
                    allActivitiesCache = [];
                }
                
                // Cancel any pending request
                if (abortController) {
                    abortController.abort();
                }
                abortController = new AbortController();
                
                // Get selected user if admin
                const userFilter = isAdmin ? (document.getElementById('activity-user-filter')?.value || 'all') : null;
                const userParam = userFilter ? `&user_id=${userFilter}` : '';
                
                // Show loading indicator
                if (!append) {
                    document.getElementById('activity-loading').style.display = 'block';
                    document.getElementById('activity-content').style.display = 'none';
                }
                
                const response = await fetch(
                    `profile/api.php?action=get_activities&limit=${activityLimit}&offset=${activityOffset}${userParam}`,
                    { signal: abortController.signal }
                );
                
                // Check for HTTP errors
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                document.getElementById('activity-loading').style.display = 'none';
                document.getElementById('activity-content').style.display = 'block';
                
                if (data.success && data.data.length > 0) {
                    // Add to cache
                    if (append) {
                        allActivitiesCache = allActivitiesCache.concat(data.data);
                    } else {
                        allActivitiesCache = data.data;
                        
                        // LocalStorage cache removed
                    }
                    
                    const container = document.getElementById('activity-content');
                    
                    if (!append) {
                        // Add Activity Manager toolbar
                        addActivityManagerToolbar();
                    }
                    
                    // Render activities (will be filtered client-side)
                    renderActivities();
                    updateActivityStats();
                    
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
                            document.getElementById('activity-content').appendChild(loadMoreBtn);
                        }
                    } else {
                        const loadMoreBtn = document.getElementById('load-more-activities');
                        if (loadMoreBtn) loadMoreBtn.remove();
                    }
                    
                    // Add search and filter listeners (optimized with requestAnimationFrame)
                    if (!append) {
                        const searchInput = document.getElementById('activity-search');
                        const filterSelect = document.getElementById('activity-filter');
                        
                        let rafId = null;
                        
                        if (searchInput) {
                            // Use requestAnimationFrame for smooth 60fps filtering
                            searchInput.addEventListener('input', () => {
                                if (rafId) cancelAnimationFrame(rafId);
                                rafId = requestAnimationFrame(() => {
                                    visibleActivitiesCount = 20; // Reset visible count on filter
                                    renderActivities();
                                });
                            });
                        }
                        
                        if (filterSelect) {
                            filterSelect.addEventListener('change', () => {
                                visibleActivitiesCount = 20; // Reset visible count on filter
                                renderActivities();
                            });
                        }
                    }
                } else if (!append) {
                    // Show toolbar even when no activities
                    addActivityManagerToolbar();
                    
                    // Add empty state after toolbar
                    const container = document.getElementById('activity-content');
                    const emptyState = document.createElement('div');
                    emptyState.className = 'empty-state';
                    emptyState.innerHTML = `
                        <i class="fas fa-history"></i>
                        <h3>No Activity Yet</h3>
                        <p>Your activity history will appear here</p>
                    `;
                    container.appendChild(emptyState);
                    
                    // Update stats to show zeros
                    if (document.getElementById('stat-total-activities')) {
                        document.getElementById('stat-total-activities').textContent = '0';
                        document.getElementById('stat-today-activities').textContent = '0';
                        document.getElementById('stat-this-week').textContent = '0';
                        document.getElementById('stat-unique-types').textContent = '0';
                    }
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    console.log('Request cancelled');
                    return;
                }
                console.error('Error loading activities:', error);
                document.getElementById('activity-loading').style.display = 'none';
                document.getElementById('activity-content').innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        Failed to load activities. Please try again.
                    </div>
                `;
                document.getElementById('activity-content').style.display = 'block';
            } finally {
                loadingActivities = false;
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
                        { 
                            key: 'can_view_reports', 
                            name: 'View Reports', 
                            icon: 'chart-bar', 
                            desc: 'Access and view system reports',
                            details: 'View sales reports, inventory reports, and analytics dashboards'
                        },
                        { 
                            key: 'can_manage_inventory', 
                            name: 'Manage Inventory', 
                            icon: 'boxes', 
                            desc: 'Add, edit, and delete inventory items',
                            details: 'Create new products, update stock levels, adjust inventory, and manage product information'
                        },
                        { 
                            key: 'can_manage_users', 
                            name: 'Manage Users', 
                            icon: 'users', 
                            desc: 'Create and manage user accounts',
                            details: 'Add new users, modify user roles, view activity logs, and manage user permissions'
                        },
                        { 
                            key: 'can_manage_stores', 
                            name: 'Manage Stores', 
                            icon: 'store', 
                            desc: 'Add and configure store locations',
                            details: 'Create new stores, edit store details, manage store inventory, and configure POS integration'
                        },
                        { 
                            key: 'can_configure_system', 
                            name: 'System Configuration', 
                            icon: 'cog', 
                            desc: 'Access system settings and configuration',
                            details: 'Modify system settings, configure integrations, manage API keys, and access admin panel'
                        }
                    ];
                    
                    // Add role information banner
                    let roleHTML = `
                        <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); padding: 20px; border-radius: 12px; margin-bottom: 25px; color: white;">
                            <div style="display: flex; align-items: center; gap: 15px;">
                                <div style="width: 60px; height: 60px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-shield-alt" style="font-size: 28px;"></i>
                                </div>
                                <div style="flex: 1;">
                                    <h3 style="margin: 0 0 8px 0; font-size: 24px;">Your Role: ${perms.role}</h3>
                                    <p style="margin: 0; opacity: 0.95; font-size: 14px;">Your permissions and access level are displayed below</p>
                                </div>
                                ${perms.role === 'Admin' ? `
                                <a href="../users/roles.php" style="background: white; color: #f5576c; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 8px;">
                                    <i class="fas fa-users-cog"></i> Manage All Users
                                </a>` : ''}
                            </div>
                        </div>
                    `;
                    
                    // Add permissions summary
                    const grantedCount = permissionsList.filter(p => perms[p.key]).length;
                    const summaryHTML = `
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">
                            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 20px; border-radius: 12px; color: white;">
                                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Total Permissions</div>
                                <div style="font-size: 32px; font-weight: 700;">${grantedCount}/${permissionsList.length}</div>
                            </div>
                            <div style="background: ${grantedCount === permissionsList.length ? 'linear-gradient(135deg, #43e97b 0%, #38f9d7 100%)' : 'linear-gradient(135deg, #fa709a 0%, #fee140 100%)'}; padding: 20px; border-radius: 12px; color: white;">
                                <div style="font-size: 14px; opacity: 0.9; margin-bottom: 5px;">Access Level</div>
                                <div style="font-size: 24px; font-weight: 700;">${grantedCount === permissionsList.length ? 'Full Access' : 'Limited Access'}</div>
                            </div>
                        </div>
                    `;
                    
                    container.innerHTML = roleHTML + summaryHTML + '<div class="permission-grid"></div>';
                    const grid = container.querySelector('.permission-grid');
                    
                    permissionsList.forEach(perm => {
                        const granted = perms[perm.key] || false;
                        const card = document.createElement('div');
                        card.className = `permission-card ${granted ? 'granted' : 'denied'}`;
                        card.title = perm.details; // Add tooltip
                        card.innerHTML = `
                            <div class="permission-icon">
                                <i class="fas fa-${perm.icon}"></i>
                            </div>
                            <div class="permission-info">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px;">
                                    <h4 style="margin: 0;">${perm.name}</h4>
                                    <span style="padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; ${granted ? 'background: #d4edda; color: #155724;' : 'background: #f8d7da; color: #721c24;'}">
                                        ${granted ? '<i class="fas fa-check"></i> Granted' : '<i class="fas fa-times"></i> Denied'}
                                    </span>
                                </div>
                                <p style="margin: 0 0 10px 0; font-size: 14px; color: #6b7280;">${perm.desc}</p>
                                <small style="font-size: 12px; color: #9ca3af; display: block;">${perm.details}</small>
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
                // Cache check removed
                
                console.log('Loading fresh stores data');
                const response = await fetch('profile/api.php?action=get_stores');
                const data = await response.json();
                
                // Cache storage removed
                
                document.getElementById('stores-loading').style.display = 'none';
                document.getElementById('stores-content').style.display = 'block';
                
                renderStores(data);
                
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
        
        function renderStores(data) {
            const container = document.getElementById('stores-content');
            
            if (data.success && data.data.length > 0) {
                    // Add statistics banner
                    const statsHTML = `
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin-bottom: 20px;">
                            <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); padding: 20px; border-radius: 12px; color: white;">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-store" style="font-size: 24px;"></i>
                                    </div>
                                    <div>
                                        <div style="font-size: 14px; opacity: 0.9;">Assigned Stores</div>
                                        <div style="font-size: 32px; font-weight: 700;">${data.data.length}</div>
                                    </div>
                                </div>
                            </div>
                            <div style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); padding: 20px; border-radius: 12px; color: white;">
                                <div style="display: flex; align-items: center; gap: 15px;">
                                    <div style="width: 50px; height: 50px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                        <i class="fas fa-boxes" style="font-size: 24px;"></i>
                                    </div>
                                    <div>
                                        <div style="font-size: 14px; opacity: 0.9;">Total Products</div>
                                        <div style="font-size: 32px; font-weight: 700;" id="total-products-count">
                                            <i class="fas fa-spinner fa-spin"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Add management toolbar
                    let toolbarHTML = `
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h4 style="margin: 0 0 5px 0; color: #2d3748;">Your Store Access</h4>
                                <p style="margin: 0; font-size: 14px; color: #6b7280;">Manage stores you have access to</p>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button onclick="refreshStores()" class="btn" style="background: #6b7280; color: white; padding: 8px 16px; font-size: 14px;">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                                ${isAdmin || isManager ? `
                                <a href="../stores/list.php" class="btn" style="background: #3b82f6; color: white; padding: 8px 16px; font-size: 14px; text-decoration: none;">
                                    <i class="fas fa-list"></i> All Stores
                                </a>
                                <button onclick="showAddStoreModal()" class="btn" style="background: #10b981; color: white; padding: 8px 16px; font-size: 14px; border: none; cursor: pointer;">
                                    <i class="fas fa-plus"></i> Assign Store
                                </button>` : ''}
                            </div>
                        </div>
                    `;
                    
                    container.innerHTML = statsHTML + toolbarHTML + '<div class="stores-grid"></div>';
                    const storesGrid = container.querySelector('.stores-grid');
                    
                    // Calculate total products
                    let totalProducts = 0;
                    
                    data.data.forEach(store => {
                        // Add to product count if available
                        if (store.product_count) {
                            totalProducts += parseInt(store.product_count);
                        }
                        
                        const card = document.createElement('div');
                        card.className = 'store-card';
                        card.innerHTML = `
                            <div class="store-info">
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                    <h4 style="margin: 0;">${escapeHtml(store.store_name)}</h4>
                                    ${store.active ? '<span style="padding: 4px 10px; background: #d4edda; color: #155724; border-radius: 12px; font-size: 12px; font-weight: 600;"><i class="fas fa-check-circle"></i> Active</span>' : '<span style="padding: 4px 10px; background: #f8d7da; color: #721c24; border-radius: 12px; font-size: 12px; font-weight: 600;"><i class="fas fa-times-circle"></i> Inactive</span>'}
                                </div>
                                <div class="store-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(store.city || 'N/A')}, ${escapeHtml(store.state || 'N/A')}</span>
                                    ${store.phone ? `<span><i class="fas fa-phone"></i> ${escapeHtml(store.phone)}</span>` : ''}
                                    ${store.product_count ? `<span><i class="fas fa-boxes"></i> ${store.product_count} products</span>` : ''}
                                </div>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <a href="../stores/profile.php?id=${store.id}" class="btn btn-primary" style="text-decoration: none;">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                ${isAdmin || isManager ? `
                                <a href="../stores/edit.php?id=${store.id}" class="btn" style="background: #3b82f6; color: white; text-decoration: none;">
                                    <i class="fas fa-edit"></i> Edit
                                </a>` : ''}
                            </div>
                        `;
                        storesGrid.appendChild(card);
                    });
                    
                    // Update total products count
                    if (totalProducts > 0) {
                        document.getElementById('total-products-count').textContent = totalProducts.toLocaleString();
                    } else {
                        document.getElementById('total-products-count').textContent = 'N/A';
                    }
            } else {
                container.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-store"></i>
                        <h3>No Store Access</h3>
                        <p>You don't have access to any stores yet</p>
                        ${isAdmin || isManager ? `
                        <button onclick="showAddStoreModal()" class="btn btn-primary" style="margin-top: 20px;">
                            <i class="fas fa-plus"></i> Assign Store Access
                        </button>` : ''}
                    </div>
                `;
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
            isOffline: false
        };
        
        // SessionStorage cache removed
        
        // Wait for all components to be ready
        setTimeout(async () => {
            if (!window.profileOfflineStorage || !window.profileSyncManager || !window.connectivityMonitor) {
                console.error('Offline support components not loaded');
                return;
            }
            
            // Cache profile in IndexedDB removed
            try {
                // Cache removed
                console.log('Profile cache disabled');
            } catch (error) {
                console.error('Failed to cache profile:', error);
            }
            
            // Start auto-sync
            window.profileSyncManager.startAutoSync();
            
            // Update pending count badge
            await window.connectivityMonitor.updatePendingCount();
            
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
                            const userId = '<?php echo $userId; ?>';
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
    
    // Manual cache refresh function removed
    
    // Background auto-refresh removed
    let autoRefreshInterval = null;
    
    // Global user ID for API calls
    const userId = '<?php echo $userId; ?>';
    console.log('Global userId initialized:', userId);
    
    function startAutoRefresh() {
        // Auto-refresh disabled
    }
    
    function stopAutoRefresh() {
        // Auto-refresh disabled
    }
    
    // Load profile statistics
    async function loadProfileStatistics() {
        try {
            const userId = '<?php echo $userId; ?>';
            const createdAt = '<?php echo $user['created_at'] ?? ''; ?>';
            
            // Load activities count
            if (document.getElementById('stat-activities')) {
                const activitiesResponse = await fetch(`profile/api.php?action=get_activities&limit=1000`);
                const activitiesData = await activitiesResponse.json();
                const totalActivities = activitiesData.data ? activitiesData.data.length : 0;
                document.getElementById('stat-activities').textContent = totalActivities.toLocaleString();
            }
            
            // Load stores count
            if (document.getElementById('stat-stores')) {
                const storesResponse = await fetch(`profile/api.php?action=get_stores`);
                const storesData = await storesResponse.json();
                const totalStores = storesData.data ? storesData.data.length : 0;
                document.getElementById('stat-stores').textContent = totalStores.toLocaleString();
            }
            
            // Calculate account age
            if (document.getElementById('stat-account-age') && createdAt) {
                const created = new Date(createdAt);
                const now = new Date();
                const diffTime = Math.abs(now - created);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                
                let ageText = '';
                if (diffDays < 30) {
                    ageText = diffDays + ' days';
                } else if (diffDays < 365) {
                    const months = Math.floor(diffDays / 30);
                    ageText = months + ' month' + (months > 1 ? 's' : '');
                } else {
                    const years = Math.floor(diffDays / 365);
                    ageText = years + ' year' + (years > 1 ? 's' : '');
                }
                document.getElementById('stat-account-age').innerHTML = 
                    `<span style="font-size: 24px;">${ageText}</span>`;
            }
            
            // Get last login from activities
            if (document.getElementById('stat-last-login')) {
                const activitiesResponse = await fetch(`profile/api.php?action=get_activities&limit=50`);
                const activitiesData = await activitiesResponse.json();
                
                if (activitiesData.data && activitiesData.data.length > 0) {
                    // Find the most recent login activity
                    const loginActivity = activitiesData.data.find(a => a.action_type === 'login');
                    if (loginActivity && loginActivity.created_at) {
                        const lastLogin = new Date(loginActivity.created_at);
                        const now = new Date();
                        const diffMs = now - lastLogin;
                        const diffMins = Math.floor(diffMs / 60000);
                        const diffHours = Math.floor(diffMs / 3600000);
                        const diffDays = Math.floor(diffMs / 86400000);
                        
                        let timeText = '';
                        if (diffMins < 1) {
                            timeText = 'Just now';
                        } else if (diffMins < 60) {
                            timeText = diffMins + 'm ago';
                        } else if (diffHours < 24) {
                            timeText = diffHours + 'h ago';
                        } else {
                            timeText = diffDays + 'd ago';
                        }
                        
                        document.getElementById('stat-last-login').innerHTML = 
                            `<span style="font-size: 20px;">${timeText}</span>`;
                    } else {
                        document.getElementById('stat-last-login').innerHTML = 
                            `<span style="font-size: 18px;">No data</span>`;
                    }
                } else {
                    document.getElementById('stat-last-login').innerHTML = 
                        `<span style="font-size: 18px;">No data</span>`;
                }
            }
            
        } catch (error) {
            console.error('Error loading statistics:', error);
            // Set error states
            ['stat-activities', 'stat-stores', 'stat-last-login', 'stat-account-age'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.innerHTML = '<span style="font-size: 18px; color: #ef4444;">Error</span>';
            });
        }
    }
    
    // Handle avatar upload
    async function handleAvatarUpload(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        // Validate file type
        if (!file.type.startsWith('image/')) {
            alert('Please select an image file (JPG, PNG, GIF)');
            return;
        }
        
        // Validate file size (max 2MB)
        if (file.size > 2 * 1024 * 1024) {
            alert('Image size must be less than 2MB');
            return;
        }
        
        // Show loading state
        const avatar = document.getElementById('profileAvatar');
        const originalContent = avatar.innerHTML;
        avatar.innerHTML = '<i class="fas fa-spinner fa-spin" style="font-size: 48px;"></i>';
        
        try {
            // Create FormData
            const formData = new FormData();
            formData.append('avatar', file);
            formData.append('action', 'upload_avatar');
            
            // Upload to server
            const response = await fetch('profile/api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Update avatar display
                avatar.innerHTML = `<img src="${data.url}?t=${Date.now()}" alt="Profile" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                    <input type="file" id="avatarUpload" accept="image/*" style="display: none;">
                    <button class="avatar-upload-btn" onclick="document.getElementById('avatarUpload').click()" title="Change profile picture">
                        <i class="fas fa-camera"></i>
                    </button>`;
                
                // Re-attach event listener
                document.getElementById('avatarUpload').addEventListener('change', handleAvatarUpload);
                
                // Show success message
                showNotification('Profile picture updated successfully!', 'success');
            } else {
                throw new Error(data.error || 'Upload failed');
            }
        } catch (error) {
            console.error('Avatar upload error:', error);
            avatar.innerHTML = originalContent;
            alert('Failed to upload profile picture: ' + error.message);
        }
    }
    
    // Show notification
    function showNotification(message, type = 'info') {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.style.cssText = 'position: fixed; top: 90px; right: 20px; z-index: 10000; animation: slideIn 0.3s ease;';
        alertDiv.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i>
            ${message}
        `;
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => alertDiv.remove(), 300);
        }, 3000);
    }
    
    // Toggle password visibility
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const button = input.parentElement.querySelector('.password-toggle i');
        
        if (input.type === 'password') {
            input.type = 'text';
            button.classList.remove('fa-eye');
            button.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            button.classList.remove('fa-eye-slash');
            button.classList.add('fa-eye');
        }
    }
    
    // Check password strength
    function checkPasswordStrength(password) {
        let strength = 0;
        const requirements = {
            length: password.length >= 8,
            uppercase: /[A-Z]/.test(password),
            lowercase: /[a-z]/.test(password),
            number: /[0-9]/.test(password),
            special: /[^A-Za-z0-9]/.test(password)
        };
        
        // Update requirement indicators
        document.getElementById('req-length').innerHTML = requirements.length ? 
            '<span style="color: #10b981;">✓ 8+ characters</span>' : 
            '<span style="color: #ef4444;">✗ 8+ characters</span>';
        document.getElementById('req-uppercase').innerHTML = requirements.uppercase ? 
            '<span style="color: #10b981;">✓ Uppercase</span>' : 
            '<span style="color: #ef4444;">✗ Uppercase</span>';
        document.getElementById('req-lowercase').innerHTML = requirements.lowercase ? 
            '<span style="color: #10b981;">✓ Lowercase</span>' : 
            '<span style="color: #ef4444;">✗ Lowercase</span>';
        document.getElementById('req-number').innerHTML = requirements.number ? 
            '<span style="color: #10b981;">✓ Number</span>' : 
            '<span style="color: #ef4444;">✗ Number</span>';
        
        // Calculate strength
        Object.values(requirements).forEach(met => { if (met) strength++; });
        
        const strengthFill = document.getElementById('strengthFill');
        const strengthText = document.getElementById('strengthText');
        
        // Remove all classes
        strengthFill.classList.remove('weak', 'fair', 'good', 'strong');
        strengthText.classList.remove('weak', 'fair', 'good', 'strong');
        
        if (password.length === 0) {
            strengthText.textContent = 'Enter a password';
            return;
        }
        
        if (strength <= 2) {
            strengthFill.classList.add('weak');
            strengthText.classList.add('weak');
            strengthText.textContent = 'Weak password';
        } else if (strength === 3) {
            strengthFill.classList.add('fair');
            strengthText.classList.add('fair');
            strengthText.textContent = 'Fair password';
        } else if (strength === 4) {
            strengthFill.classList.add('good');
            strengthText.classList.add('good');
            strengthText.textContent = 'Good password';
        } else {
            strengthFill.classList.add('strong');
            strengthText.classList.add('strong');
            strengthText.textContent = 'Strong password';
        }
    }
    
    // Check if passwords match
    function checkPasswordMatch() {
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        const matchIndicator = document.getElementById('passwordMatch');
        
        if (confirmPassword.length === 0) {
            matchIndicator.style.display = 'none';
            return;
        }
        
        matchIndicator.style.display = 'block';
        if (newPassword === confirmPassword) {
            matchIndicator.style.color = '#10b981';
            matchIndicator.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
        } else {
            matchIndicator.style.color = '#ef4444';
            matchIndicator.innerHTML = '<i class="fas fa-times-circle"></i> Passwords do not match';
        }
    }
    
    // Refresh stores data
    async function refreshStores() {
        // Cache clear removed
        
        // Show loading
        document.getElementById('stores-content').style.display = 'none';
        document.getElementById('stores-loading').style.display = 'block';
        
        // Reload
        await loadStores();
        
        showNotification('Stores data refreshed successfully!', 'success');
    }
    
    // Show modal to assign store access
    async function showAddStoreModal() {
        console.log('=== Opening Add Store Modal ===');
        
        // Show loading modal immediately to prevent delay perception
        const loadingModal = document.createElement('div');
        loadingModal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            backdrop-filter: blur(4px);
        `;
        loadingModal.innerHTML = `
            <div style="background: white; padding: 40px; border-radius: 16px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
                <i class="fas fa-spinner fa-spin" style="font-size: 32px; color: #10b981; margin-bottom: 16px;"></i>
                <div style="font-size: 16px; color: #6b7280; font-weight: 500;">Loading available stores...</div>
            </div>
        `;
        loadingModal.className = 'modal-wrapper';
        document.body.appendChild(loadingModal);
        
        // Fetch available stores (stores user doesn't have access to yet)
        try {
            console.time('API: get_available_stores');
            const response = await fetch('profile/api.php?action=get_available_stores<?php echo isset($viewingUserId) ? "&user_id={$viewingUserId}" : ""; ?>');
            console.timeEnd('API: get_available_stores');
            
            const data = await response.json();
            console.log('Available stores response:', data);
            
            // Remove loading modal
            loadingModal.remove();
            
            if (!data.success) {
                showNotification(data.error || 'Failed to load stores', 'error');
                return;
            }
            
            const availableStores = data.data || [];
            console.log('Available stores count:', availableStores.length);
            
            if (availableStores.length === 0) {
                showNotification('User already has access to all stores', 'info');
                return;
            }
            
            // Create modal with improved design
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.6);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10000;
                backdrop-filter: blur(4px);
                animation: fadeIn 0.2s ease-out;
            `;
            
            const modalContent = document.createElement('div');
            modalContent.style.cssText = `
                background: white;
                padding: 0;
                border-radius: 16px;
                max-width: 600px;
                width: 90%;
                max-height: 85vh;
                overflow: hidden;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                animation: slideUp 0.3s ease-out;
            `;
            
            modalContent.innerHTML = `
                <div style="padding: 24px; border-bottom: 1px solid #e5e7eb;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h3 style="margin: 0; font-size: 20px; color: #111827;">
                                <i class="fas fa-store" style="color: #10b981; margin-right: 8px;"></i>
                                Assign Store Access
                            </h3>
                            <p style="color: #6b7280; margin: 8px 0 0 0; font-size: 14px;">
                                Select one or more stores to grant access
                            </p>
                        </div>
                        <button onclick="this.closest('.modal-wrapper').remove()" style="
                            background: none;
                            border: none;
                            font-size: 24px;
                            color: #6b7280;
                            cursor: pointer;
                            padding: 8px;
                            line-height: 1;
                            border-radius: 8px;
                            transition: all 0.2s;
                        " onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='none'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div style="padding: 20px; max-height: 50vh; overflow-y: auto;">
                    <div id="store-search" style="margin-bottom: 16px;">
                        <input type="text" id="store-search-input" placeholder="Search stores..." style="
                            width: 100%;
                            padding: 12px 16px;
                            border: 2px solid #e5e7eb;
                            border-radius: 10px;
                            font-size: 14px;
                            transition: border-color 0.2s;
                        " onfocus="this.style.borderColor='#10b981'" onblur="this.style.borderColor='#e5e7eb'">
                    </div>
                    <div id="store-count" style="margin-bottom: 12px; font-size: 13px; color: #6b7280;">
                        <span id="selected-count">0</span> of <span id="total-count">0</span> stores selected
                    </div>
                    <div id="store-list"></div>
                </div>
                
                <div style="padding: 20px; border-top: 1px solid #e5e7eb; background: #f9fafb; display: flex; gap: 12px; justify-content: flex-end;">
                    <button onclick="this.closest('.modal-wrapper').remove()" style="
                        padding: 10px 20px;
                        background: white;
                        color: #6b7280;
                        border: 2px solid #e5e7eb;
                        border-radius: 10px;
                        font-weight: 600;
                        cursor: pointer;
                        transition: all 0.2s;
                    " onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='white'">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button onclick="assignSelectedStores(event)" id="assign-btn" style="
                        padding: 10px 20px;
                        background: #10b981;
                        color: white;
                        border: none;
                        border-radius: 10px;
                        font-weight: 600;
                        cursor: pointer;
                        transition: all 0.2s;
                    " onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
                        <i class="fas fa-plus"></i> Assign Selected
                    </button>
                </div>
            `;
            
            modal.appendChild(modalContent);
            modal.className = 'modal-wrapper';
            document.body.appendChild(modal);
            
            // Add CSS animations
            const style = document.createElement('style');
            style.textContent = `
                @keyframes fadeIn {
                    from { opacity: 0; }
                    to { opacity: 1; }
                }
                @keyframes slideUp {
                    from { transform: translateY(20px); opacity: 0; }
                    to { transform: translateY(0); opacity: 1; }
                }
            `;
            document.head.appendChild(style);
            
            // Populate store list with improved design
            const storeList = document.getElementById('store-list');
            const totalCount = document.getElementById('total-count');
            const selectedCount = document.getElementById('selected-count');
            totalCount.textContent = availableStores.length;
            
            availableStores.forEach(store => {
                const storeItem = document.createElement('label');
                storeItem.className = 'store-item';
                storeItem.style.cssText = `
                    display: flex;
                    align-items: center;
                    padding: 14px 16px;
                    background: white;
                    border: 2px solid #e5e7eb;
                    border-radius: 12px;
                    margin-bottom: 10px;
                    cursor: pointer;
                    transition: all 0.2s;
                `;
                
                storeItem.innerHTML = `
                    <input type="checkbox" value="${store.id}" style="
                        width: 20px;
                        height: 20px;
                        margin-right: 14px;
                        cursor: pointer;
                        accent-color: #10b981;
                    ">
                    <div style="flex: 1;">
                        <div style="font-weight: 600; color: #111827; margin-bottom: 4px; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-store" style="color: #10b981; font-size: 14px;"></i>
                            ${store.name}
                        </div>
                        ${store.address ? `<div style="font-size: 13px; color: #6b7280;">
                            <i class="fas fa-map-marker-alt" style="width: 14px;"></i> ${store.address}
                        </div>` : ''}
                    </div>
                `;
                
                // Hover effects
                storeItem.onmouseenter = () => {
                    storeItem.style.borderColor = '#10b981';
                    storeItem.style.background = '#f0fdf4';
                    storeItem.style.transform = 'translateX(4px)';
                };
                storeItem.onmouseleave = () => {
                    const checkbox = storeItem.querySelector('input[type="checkbox"]');
                    if (!checkbox.checked) {
                        storeItem.style.borderColor = '#e5e7eb';
                        storeItem.style.background = 'white';
                    }
                    storeItem.style.transform = 'translateX(0)';
                };
                
                // Update selected count
                const checkbox = storeItem.querySelector('input[type="checkbox"]');
                checkbox.onchange = () => {
                    const checkedCount = document.querySelectorAll('#store-list input[type="checkbox"]:checked').length;
                    selectedCount.textContent = checkedCount;
                    
                    if (checkbox.checked) {
                        storeItem.style.borderColor = '#10b981';
                        storeItem.style.background = '#f0fdf4';
                    } else {
                        storeItem.style.borderColor = '#e5e7eb';
                        storeItem.style.background = 'white';
                    }
                };
                
                storeList.appendChild(storeItem);
            });
            
            // Add search functionality
            const searchInput = document.getElementById('store-search-input');
            searchInput.addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase();
                const storeItems = storeList.querySelectorAll('.store-item');
                
                storeItems.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        item.style.display = 'flex';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
            
            // Close on outside click
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
            
        } catch (error) {
            console.error('=== Error Loading Stores ===');
            console.error('Error details:', error);
            console.error('Stack trace:', error.stack);
            
            // Remove loading modal if it exists
            const existingLoadingModal = document.querySelector('.modal-wrapper');
            if (existingLoadingModal) {
                existingLoadingModal.remove();
            }
            
            showNotification('Failed to load stores: ' + error.message, 'error');
        }
    }
    
    // Assign selected stores to user
    async function assignSelectedStores(event) {
        console.log('=== Starting Store Assignment ===');
        console.log('Current userId:', userId);
        
        const checkboxes = document.querySelectorAll('#store-list input[type="checkbox"]:checked');
        const storeIds = Array.from(checkboxes).map(cb => cb.value);
        
        console.log('Selected stores:', storeIds);
        
        if (storeIds.length === 0) {
            console.warn('No stores selected');
            showNotification('Please select at least one store', 'warning');
            return;
        }
        
        // Disable button to prevent double submission
        const assignBtn = event ? event.target : document.getElementById('assign-btn');
        assignBtn.disabled = true;
        assignBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Assigning...';
        
        try {
            let successCount = 0;
            let failCount = 0;
            let failedStores = [];
            
            // Process each store assignment
            for (const storeId of storeIds) {
                console.log(`\n--- Processing store: ${storeId} ---`);
                
                const requestData = {
                    user_id: userId,
                    store_id: storeId
                };
                
                console.log('Request data:', requestData);
                
                try {
                    const response = await fetch('profile/api.php?action=add_store_access', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(requestData)
                    });
                    
                    console.log('Response status:', response.status);
                    console.log('Response OK:', response.ok);
                    
                    const responseText = await response.text();
                    console.log('Raw response:', responseText);
                    
                    let data;
                    try {
                        data = JSON.parse(responseText);
                        console.log('Parsed response:', data);
                    } catch (parseError) {
                        console.error('JSON parse error:', parseError);
                        console.error('Response was:', responseText);
                        throw new Error('Invalid JSON response: ' + responseText.substring(0, 100));
                    }
                    
                    if (data.success) {
                        console.log('✓ Store assigned successfully');
                        successCount++;
                    } else {
                        console.error('✗ Assignment failed:', data.error);
                        failCount++;
                        // Get store name from checkbox label
                        const checkbox = document.querySelector(`input[value="${storeId}"]`);
                        const storeName = checkbox?.parentElement?.textContent.trim() || storeId;
                        failedStores.push(`${storeName}: ${data.error || 'Unknown error'}`);
                    }
                } catch (err) {
                    failCount++;
                    console.error(`✗ Exception assigning store ${storeId}:`, err);
                    const checkbox = document.querySelector(`input[value="${storeId}"]`);
                    const storeName = checkbox?.parentElement?.textContent.trim() || storeId;
                    failedStores.push(`${storeName}: ${err.message}`);
                }
            }
            
            console.log('\n=== Assignment Summary ===');
            console.log('Success:', successCount);
            console.log('Failed:', failCount);
            console.log('Failed stores:', failedStores);
            
            // Show detailed results
            if (successCount > 0 && failCount === 0) {
                showNotification(`✓ Successfully assigned ${successCount} store(s)`, 'success');
                document.querySelector('.modal-wrapper').remove();
                await refreshStores();
            } else if (successCount > 0 && failCount > 0) {
                const message = `Assigned ${successCount} store(s). Failed: ${failCount}\n${failedStores.join('\n')}`;
                showNotification(message, 'warning');
                // Refresh to show successfully added stores
                await refreshStores();
                // Keep modal open to show which stores failed
            } else {
                const message = `Failed to assign all stores:\n${failedStores.join('\n')}`;
                showNotification(message, 'error');
            }
        } catch (error) {
            console.error('Error assigning stores:', error);
            showNotification('Failed to assign stores: ' + error.message, 'error');
        } finally {
            // Re-enable button
            assignBtn.disabled = false;
            assignBtn.innerHTML = '<i class="fas fa-plus"></i> Assign Selected';
        }
    }
    
    // Start auto-refresh when page loads
    document.addEventListener('DOMContentLoaded', () => {
        console.log('=== Profile Page Loading ===');
        
        // Load statistics immediately
        loadProfileStatistics();
        
        // Setup avatar upload
        const avatarUpload = document.getElementById('avatarUpload');
        if (avatarUpload) {
            avatarUpload.addEventListener('change', handleAvatarUpload);
        }
        
        // Setup password strength checker
        const newPasswordInput = document.getElementById('newPassword');
        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', (e) => {
                checkPasswordStrength(e.target.value);
                checkPasswordMatch();
            });
        }
        
        // Setup password match checker
        const confirmPasswordInput = document.getElementById('confirmPassword');
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        }
        
        // Profile page now only contains personal info - no admin features to load
        console.log('Profile page initialized - personal view only');
        
        startAutoRefresh();
        
        // Stop/start auto-refresh based on online status
        window.addEventListener('online', startAutoRefresh);
        window.addEventListener('offline', stopAutoRefresh);
        
        // Verify refreshCache is globally accessible
        console.log('=== Checking global functions ===');
        console.log('window.refreshCache exists:', typeof window.refreshCache);
        console.log('typeof window.refreshCache:', typeof window.refreshCache);
    });
    
    // Activity Management Functions
    async function exportActivities(format) {
        try {
            const userFilter = isAdmin ? (document.getElementById('activity-user-filter')?.value || 'all') : '';
            const userParam = userFilter ? `&user_id=${userFilter}` : '';
            
            if (format === 'pdf') {
                // Open in new window for PDF printing
                const url = `profile/api.php?action=export_activities&format=pdf${userParam}`;
                window.open(url, '_blank');
                showNotification('Opening PDF report...', 'info');
            } else {
                const response = await fetch(`profile/api.php?action=export_activities&format=${format}${userParam}`);
                const blob = await response.blob();
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `activities_${new Date().toISOString().split('T')[0]}.${format}`;
                document.body.appendChild(a);
                a.click();
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                showNotification(`Activities exported as ${format.toUpperCase()}`, 'success');
            }
        } catch (error) {
            console.error('Export failed:', error);
            alert('Failed to export activities');
        }
    }
    
    async function clearActivities() {
        try {
            const userFilter = isAdmin ? (document.getElementById('activity-user-filter')?.value || 'all') : '';
            
            const response = await fetch('profile/api.php?action=clear_activities', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ user_id: userFilter })
            });
            const data = await response.json();
            if (data.success) {
                alert(data.message || 'Activity history cleared successfully');
                loadActivities(); // Reload activities
            } else {
                alert('Failed to clear activities: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Clear failed:', error);
            alert('Failed to clear activities');
        }
    }
    
    // New Activity Manager Functions
    function updateActivityStats() {
        if (!allActivitiesCache || allActivitiesCache.length === 0) return;
        
        const now = new Date();
        const today = now.toDateString();
        const oneWeekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
        
        let todayCount = 0;
        let weekCount = 0;
        const uniqueTypes = new Set();
        
        allActivitiesCache.forEach(activity => {
            const activityDate = new Date(activity.created_at);
            uniqueTypes.add(activity.action_type || activity.activity_type);
            
            if (activityDate.toDateString() === today) {
                todayCount++;
            }
            if (activityDate >= oneWeekAgo) {
                weekCount++;
            }
        });
        
        document.getElementById('stat-total-activities').textContent = allActivitiesCache.length.toLocaleString();
        document.getElementById('stat-today-activities').textContent = todayCount.toLocaleString();
        document.getElementById('stat-this-week').textContent = weekCount.toLocaleString();
        document.getElementById('stat-unique-types').textContent = uniqueTypes.size.toLocaleString();
    }
    
    function applyDateFilter() {
        const dateFrom = document.getElementById('activity-date-from').value;
        const dateTo = document.getElementById('activity-date-to').value;
        
        if (!dateFrom && !dateTo) {
            showNotification('Please select at least one date', 'warning');
            return;
        }
        
        // Store filter in variable for renderActivities to use
        window.activityDateFilter = { from: dateFrom, to: dateTo };
        
        // Re-render with date filter
        visibleActivitiesCount = 20;
        renderActivities();
        
        showNotification('Date filter applied', 'success');
    }
    
    function clearDateFilter() {
        document.getElementById('activity-date-from').value = '';
        document.getElementById('activity-date-to').value = '';
        window.activityDateFilter = null;
        
        visibleActivitiesCount = 20;
        renderActivities();
        
        showNotification('Date filter cleared', 'info');
    }
    
    function showActivityAnalytics() {
        if (!allActivitiesCache || allActivitiesCache.length === 0) {
            showNotification('No activity data to analyze', 'warning');
            return;
        }
        
        // Create analytics modal
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s;
        `;
        
        // Calculate analytics
        const typeCount = {};
        const hourCount = {};
        const dayCount = {};
        
        allActivitiesCache.forEach(activity => {
            const type = activity.action_type || activity.activity_type || 'unknown';
            typeCount[type] = (typeCount[type] || 0) + 1;
            
            const date = new Date(activity.created_at);
            const hour = date.getHours();
            const day = date.toDateString();
            
            hourCount[hour] = (hourCount[hour] || 0) + 1;
            dayCount[day] = (dayCount[day] || 0) + 1;
        });
        
        // Get top 10 activity types
        const topTypes = Object.entries(typeCount)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 10);
        
        // Get most active hours
        const topHours = Object.entries(hourCount)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 5);
        
        // Get most active days
        const topDays = Object.entries(dayCount)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 7);
        
        modal.innerHTML = `
            <div style="background: white; border-radius: 16px; padding: 30px; max-width: 900px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.4); width: 90%;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                    <div>
                        <h2 style="margin: 0; color: #1f2937; font-size: 24px;">
                            <i class="fas fa-chart-pie" style="color: #8b5cf6;"></i> Activity Analytics
                        </h2>
                        <p style="margin: 5px 0 0 0; color: #6b7280;">Insights and patterns from ${allActivitiesCache.length.toLocaleString()} activities</p>
                    </div>
                    <button onclick="this.closest('div').parentElement.remove()" style="background: #ef4444; color: white; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-size: 18px;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                    <!-- Top Activity Types -->
                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; color: white;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-fire"></i> Top Activity Types
                        </h3>
                        ${topTypes.map(([type, count]) => `
                            <div style="background: rgba(255,255,255,0.15); padding: 10px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-weight: 500;">${escapeHtml(type)}</span>
                                <span style="background: rgba(255,255,255,0.25); padding: 4px 10px; border-radius: 12px; font-weight: 700;">${count}</span>
                            </div>
                        `).join('')}
                    </div>
                    
                    <!-- Most Active Hours -->
                    <div style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); border-radius: 12px; padding: 20px; color: white;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-clock"></i> Peak Activity Hours
                        </h3>
                        ${topHours.map(([hour, count]) => {
                            const displayHour = hour == 0 ? '12 AM' : hour < 12 ? hour + ' AM' : hour == 12 ? '12 PM' : (hour - 12) + ' PM';
                            return `
                            <div style="background: rgba(255,255,255,0.15); padding: 10px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-weight: 500;">${displayHour}</span>
                                <span style="background: rgba(255,255,255,0.25); padding: 4px 10px; border-radius: 12px; font-weight: 700;">${count}</span>
                            </div>
                        `}).join('')}
                    </div>
                    
                    <!-- Most Active Days -->
                    <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 12px; padding: 20px; color: white;">
                        <h3 style="margin: 0 0 15px 0; font-size: 16px; display: flex; align-items: center; gap: 10px;">
                            <i class="fas fa-calendar-week"></i> Most Active Days
                        </h3>
                        ${topDays.map(([day, count]) => `
                            <div style="background: rgba(255,255,255,0.15); padding: 10px; border-radius: 8px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-weight: 500; font-size: 13px;">${new Date(day).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</span>
                                <span style="background: rgba(255,255,255,0.25); padding: 4px 10px; border-radius: 12px; font-weight: 700;">${count}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #f3f4f6; border-radius: 10px; text-align: center;">
                    <button onclick="exportActivities('csv')" class="btn" style="background: #10b981; color: white; margin: 0 5px;">
                        <i class="fas fa-download"></i> Export Data
                    </button>
                    <button onclick="this.closest('div').parentElement.parentElement.remove()" class="btn" style="background: #6b7280; color: white; margin: 0 5px;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.remove();
        });
    }
    
    // Update stats when activities load
    const originalLoadActivities = loadActivities;
    loadActivities = async function(...args) {
        await originalLoadActivities.apply(this, args);
        updateActivityStats();
    };
    
    </script>
</body>
</html>

