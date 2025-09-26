<?php
// User Profile Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$user = getUserInfo($_SESSION['user_id']);
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $first_name = sanitizeInput($_POST['first_name'] ?? '');
        $last_name = sanitizeInput($_POST['last_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        
        // Validation
        if (empty($first_name)) {
            $errors[] = 'First name is required';
        }
        
        if (empty($last_name)) {
            $errors[] = 'Last name is required';
        }
        
        if (empty($email)) {
            $errors[] = 'Email is required';
        } elseif (!validateEmail($email)) {
            $errors[] = 'Invalid email format';
        }
        
        // Check if email is already taken by another user
        if (empty($errors)) {
            $db = getDB();
            $existingUsers = $db->readAll('users', [['email', '==', $email]], null, 2);
            $emailTaken = false;
            
            foreach ($existingUsers as $existingUser) {
                if ($existingUser['id'] !== $_SESSION['user_id']) {
                    $emailTaken = true;
                    break;
                }
            }
            
            if ($emailTaken) {
                $errors[] = 'Email is already taken by another user';
            }
        }
        
        // Update profile if no errors
        if (empty($errors)) {
            $db = getDB();
            $updateData = [
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'updated_at' => date('c')
            ];
            
            $result = $db->update('users', $_SESSION['user_id'], $updateData);
            
            if ($result) {
                $_SESSION['email'] = $email; // Update session
                $success = true;
                addNotification('Profile updated successfully!', 'success');
                $user = getUserInfo($_SESSION['user_id']); // Refresh user data
            } else {
                $errors[] = 'Failed to update profile. Please try again.';
            }
        }
    }
    
    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validation
        if (empty($current_password)) {
            $errors[] = 'Current password is required';
        } elseif (!verifyPassword($current_password, $user['password_hash'])) {
            $errors[] = 'Current password is incorrect';
        }
        
        if (empty($new_password)) {
            $errors[] = 'New password is required';
        } elseif (strlen($new_password) < 6) {
            $errors[] = 'New password must be at least 6 characters';
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match';
        }
        
        // Update password if no errors
        if (empty($errors)) {
            $db = getDB();
            $hashed_password = hashPassword($new_password);
            $updateData = [
                'password_hash' => $hashed_password,
                'updated_at' => date('c')
            ];
            
            $result = $db->update('users', $_SESSION['user_id'], $updateData);
            
            if ($result) {
                $success = true;
                addNotification('Password changed successfully!', 'success');
            } else {
                $errors[] = 'Failed to change password. Please try again.';
            }
        }
    }
}

$page_title = 'Profile - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
</head>
<body>
    <div class="container">
        <header>
            <h1>User Profile</h1>
            <nav>
                <ul>
                    <li><a href="../../index.php">Dashboard</a></li>
                    <li><a href="../stock/list.php">Stock</a></li>
                    <li><a href="../stores/list.php">Stores</a></li>
                    <li><a href="../reports/dashboard.php">Reports</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </header>

        <main>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php 
            $notifications = getNotifications();
            foreach ($notifications as $notification): 
            ?>
                <div class="alert alert-<?php echo $notification['type']; ?>">
                    <?php echo htmlspecialchars($notification['message']); ?>
                </div>
            <?php endforeach; ?>

            <div class="profile-sections">
                <!-- Profile Information Section -->
                <div class="profile-section">
                    <h3>Profile Information</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="form-group">
                            <label for="username">Username:</label>
                            <input type="text" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
                            <small>Username cannot be changed</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="first_name">First Name:</label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Last Name:</label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email:</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone:</label>
                            <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Update Profile</button>
                        </div>
                    </form>
                </div>

                <!-- Change Password Section -->
                <div class="profile-section">
                    <h3>Change Password</h3>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="current_password">Current Password:</label>
                            <input type="password" id="current_password" name="current_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">New Password:</label>
                            <input type="password" id="new_password" name="new_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password:</label>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Change Password</button>
                        </div>
                    </form>
                </div>

                <!-- Account Information Section -->
                <div class="profile-section">
                    <h3>Account Information</h3>
                    <div class="account-info">
                        <p><strong>Role:</strong> <?php echo htmlspecialchars($user['role'] ?? 'User'); ?></p>
                        <p><strong>Member Since:</strong> <?php echo formatDate($user['created_at'], 'F j, Y'); ?></p>
                        <p><strong>Last Login:</strong> <?php echo $user['last_login'] ? formatDate($user['last_login'], 'F j, Y g:i A') : 'Never'; ?></p>
                        <p><strong>Account Status:</strong> 
                            <span class="status <?php echo $user['active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $user['active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
</body>
</html>