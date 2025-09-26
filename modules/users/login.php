<?php
// User Login Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ../../index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    if (empty($username)) {
        $errors[] = 'Username is required';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (empty($errors)) {
        $user = findUserByUsernameOrEmail($username);
        
        if ($user && isset($user['password_hash']) && verifyPassword($password, $user['password_hash'])) {
            // Check if user is active
            if (isset($user['is_active']) && $user['is_active'] == false) {
                $errors[] = 'Your account has been deactivated. Please contact administrator.';
            } else {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'] ?? 'user';
                $_SESSION['login_time'] = time();
                
                // Handle remember me
                if ($remember_me) {
                    $token = generateToken();
                    $expires = date('c', strtotime('+30 days'));
                    
                    // Store remember token in Firestore
                    updateUserRememberToken($user['id'], $token, $expires);
                    
                    // Set cookie
                    setcookie('remember_token', $token, strtotime('+30 days'), '/', '', false, true);
                }
                
                // Redirect to intended page or dashboard
                $redirect = $_GET['redirect'] ?? '../../index.php';
                header('Location: ' . $redirect);
                exit;
            }
        } else {
            $errors[] = 'Invalid username or password';
        }
    }
}

// Check for remember me cookie
if (isset($_COOKIE['remember_token']) && !isLoggedIn()) {
    $user = findUserByRememberToken($_COOKIE['remember_token']);
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'] ?? 'user';
        $_SESSION['login_time'] = time();
        
        header('Location: ../../index.php');
        exit;
    }
}

$page_title = 'Login - Inventory System';
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
    <div class="auth-container">
        <div class="auth-form">
            <h2>Login to Inventory System</h2>
            
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
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username or Email:</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div class="form-group checkbox-group">
                    <label>
                        <input type="checkbox" name="remember_me" value="1">
                        Remember me for 30 days
                    </label>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
            </form>
            
            <div class="auth-links">
                <p>Don't have an account? <a href="register.php">Register here</a></p>
                <p><a href="#" onclick="alert('Password reset feature coming soon!')">Forgot Password?</a></p>
            </div>
        </div>
    </div>
</body>
</html>