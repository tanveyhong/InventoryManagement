<?php
// PostgreSQL-Only Login System
require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';

session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    header('Location: ../../index.php');
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember_me = isset($_POST['remember_me']);
    
    if (empty($username)) {
        $errors[] = 'Username or email is required';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    }
    
    if (empty($errors)) {
        try {
            $sqlDb = SQLDatabase::getInstance();
            
            // First check if user exists (including deleted users)
            $user = $sqlDb->fetch(
                "SELECT * FROM users WHERE (LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?))",
                [$username, $username]
            );
            
            // Check if account was soft deleted
            if ($user && $user['deleted_at'] !== null) {
                $errors[] = 'Your account has been deleted. Please contact an administrator if you believe this is an error.';
            } elseif ($user && password_verify($password, $user['password_hash'])) {
                // Check if user is active
                $status = strtolower($user['status'] ?? 'active');
                if ($status === 'inactive' || $status === 'suspended' || $status === 'banned') {
                    $errors[] = 'Your account has been deactivated. Please contact administrator.';
                } else {
                    // Login successful - use PostgreSQL integer ID
                    $_SESSION['user_id'] = $user['id'];  // PostgreSQL integer ID
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'] ?? 'user';
                    $_SESSION['profile_picture'] = $user['profile_picture'] ?? '';
                    $_SESSION['login_time'] = time();
                    $_SESSION['notifications'] = [];
                    
                    // Update last login
                    $sqlDb->execute(
                        "UPDATE users SET last_login = NOW() WHERE id = ?",
                        [$user['id']]
                    );

                    // Log Activity
                    logActivity('login', 'User logged in', ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
                    
                    // Handle remember me
                    if ($remember_me) {
                        $token = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                        
                        // Store remember token in PostgreSQL
                        $sqlDb->execute(
                            "UPDATE users SET remember_token = ?, remember_token_expires = ? WHERE id = ?",
                            [$token, $expires, $user['id']]
                        );
                        
                        // Set cookie
                        setcookie('remember_token', $token, strtotime('+30 days'), '/', '', false, true);
                        setcookie('user_id', $user['id'], strtotime('+30 days'), '/', '', false, true);
                    }
                    
                    // Redirect to intended page or dashboard
                    $redirect = $_GET['redirect'] ?? '../../index.php';
                    header('Location: ' . $redirect);
                    exit;
                }
            } else {
                $errors[] = 'Invalid username or password';
            }
        } catch (Exception $e) {
            $errorMsg = $e->getMessage();
            // Show user-friendly message for connection issues
            if (stripos($errorMsg, 'internet connection') !== false || 
                stripos($errorMsg, 'host name') !== false) {
                $errors[] = 'Cannot connect to server. Please check your internet connection and try again.';
            } else {
                $errors[] = 'Login failed: ' . $errorMsg;
            }
        }
    }
}

// Check for remember me cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && isset($_COOKIE['user_id'])) {
    try {
        $sqlDb = SQLDatabase::getInstance();
        $user = $sqlDb->fetch(
            "SELECT * FROM users WHERE id = ? AND remember_token = ? AND remember_token_expires > NOW() AND deleted_at IS NULL",
            [$_COOKIE['user_id'], $_COOKIE['remember_token']]
        );
        
        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'] ?? 'user';
            $_SESSION['login_time'] = time();
            $_SESSION['notifications'] = [];
            
            header('Location: ../../index.php');
            exit;
        }
    } catch (Exception $e) {
        // Invalid token, continue to login page
    }
}

$page_title = 'Login - Inventory Management System';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 450px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #2c3e50;
            margin: 0 0 10px 0;
            font-size: 2rem;
        }
        
        .login-header p {
            color: #7f8c8d;
            margin: 0;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s;
            box-sizing: border-box;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin-right: 8px;
        }
        
        .checkbox-group label {
            margin: 0;
            color: #2c3e50;
        }
        
        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
        }
        
        .success-message {
            background: #efe;
            color: #3c3;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #3c3;
        }
        
        .register-link {
            text-align: center;
            margin-top: 20px;
            color: #7f8c8d;
        }
        
        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }

        .forgot-password {
            text-align: right;
            margin-top: -15px;
            margin-bottom: 20px;
            font-size: 0.85rem;
        }
        
        .forgot-password a {
            color: #666;
            text-decoration: none;
        }
        
        .forgot-password a:hover {
            color: #667eea;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-box"></i> Inventory Pro</h1>
            <p>Management System</p>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="error-message">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success-message">
                <p><?php echo htmlspecialchars($success); ?></p>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Email</label>
                <input type="email" id="username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required autofocus placeholder="your.email@example.com">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div style="position: relative;">
                    <input type="password" id="password" name="password" required style="padding-right: 40px;">
                    <button type="button" onclick="togglePassword('password', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: none; cursor: pointer; color: #667eea; padding: 5px;" title="Show/Hide Password">
                        <i class="fas fa-eye" id="password-icon"></i>
                    </button>
                </div>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" id="remember_me" name="remember_me">
                <label for="remember_me">Remember me</label>
            </div>
            
            <div class="forgot-password">
                <a href="forgot_password.php">Forgot Password?</a>
            </div>
            
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>
    </div>
    
    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
