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
$login_probe = null;
$login_found_user = null;

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
    // Normalize lookup key to handle case/whitespace differences
    $lookup = trim(strtolower($username));
    $user = findUserByUsernameOrEmail($lookup);
        
        $auth_ok = false;
        if ($user) {
            // Try common hash fields: password_hash (modern), password (legacy), pass
            $possible_hashes = [
                'password_hash' => $user['password_hash'] ?? null,
                'password' => $user['password'] ?? null,
                'pass' => $user['pass'] ?? null
            ];
            foreach ($possible_hashes as $fieldName => $h) {
                if (!empty($h) && verifyPassword($password, $h)) {
                    $auth_ok = true;
                    break;
                }
            }
        }

        if ($auth_ok) {
            // Check if user is active
            if (isset($user['is_active']) && $user['is_active'] == false) {
                $errors[] = 'Your account has been deactivated. Please contact administrator.';
            } else {
                // Login successful
                // Normalize session user id to Firestore document id when possible
                $db = getDB();
                $sessionUserId = $user['id'];

                // If the returned id looks numeric (legacy SQL), try to find the Firestore doc by email
                if (is_numeric($sessionUserId)) {
                    try {
                        $possible = $db->read('users', $sessionUserId);
                        if ($possible && isset($possible['id'])) {
                            $sessionUserId = $possible['id'];
                        } else {
                            // Try lookup by email
                            $found = $db->readAll('users', [['email', '==', $user['email']]], null, 1);
                            if (!empty($found) && isset($found[0]['id'])) {
                                $sessionUserId = $found[0]['id'];
                            }
                        }
                    } catch (Exception $e) {
                        // ignore and keep original id
                    }
                }

                $_SESSION['user_id'] = $sessionUserId;
                $_SESSION['username'] = $user['username'] ?? ($user['email'] ?? '');
                $_SESSION['email'] = $user['email'] ?? '';
                $_SESSION['role'] = $user['role'] ?? 'user';
                $_SESSION['login_time'] = time();
                
                // Handle remember me
                if ($remember_me) {
                    $token = generateToken();
                    $expires = date('c', strtotime('+30 days'));
                    
                    // Store remember token in Firestore using normalized session id if available
                    updateUserRememberToken($_SESSION['user_id'] ?? $user['id'], $token, $expires);
                    
                    // Set cookie
                    setcookie('remember_token', $token, strtotime('+30 days'), '/', '', false, true);
                }
                
                // Redirect to intended page or dashboard
                $redirect = $_GET['redirect'] ?? '../../index.php';
                header('Location: ' . $redirect);
                exit;
            }
        } else {
            // Probe which hash fields exist and whether they matched (do not log passwords)
            $probe = [];
            if ($user) {
                foreach (['password_hash','password','pass'] as $f) {
                    $probe[$f] = isset($user[$f]) ? (verifyPassword($password, $user[$f]) ? 'match' : 'no-match') : 'missing';
                }
            }
            // Save probe for on-page debug when in DEBUG_MODE
            $login_probe = $probe;
            $login_found_user = $user ? array_filter($user, function($k){ return $k !== 'password_hash' && $k !== 'password' && $k !== 'pass'; }, ARRAY_FILTER_USE_KEY) : null;

            logError('Failed login attempt', ['username' => $username, 'found_user' => $user ? true : false, 'user_id' => $user['id'] ?? null, 'probe' => $probe]);
            $errors[] = 'Invalid username or password';
        }
    }
}

// Check for remember me cookie
if (isset($_COOKIE['remember_token']) && !isLoggedIn()) {
    $user = findUserByRememberToken($_COOKIE['remember_token']);
    
    if ($user) {
        // Normalize session id to Firestore doc id if possible
        $sessionUserId = $user['id'];
        $db = getDB();
        if (is_numeric($sessionUserId)) {
            try {
                $possible = $db->read('users', $sessionUserId);
                if ($possible && isset($possible['id'])) {
                    $sessionUserId = $possible['id'];
                } else {
                    $found = $db->readAll('users', [['email', '==', $user['email']]], null, 1);
                    if (!empty($found) && isset($found[0]['id'])) {
                        $sessionUserId = $found[0]['id'];
                    }
                }
            } catch (Exception $e) {
                // ignore
            }
        }

        $_SESSION['user_id'] = $sessionUserId;
        $_SESSION['username'] = $user['username'] ?? ($user['email'] ?? '');
        $_SESSION['email'] = $user['email'] ?? '';
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
    <?php if (defined('DEBUG_MODE') && DEBUG_MODE && !empty($login_probe)): ?>
        <div style="position:fixed; right:10px; bottom:10px; background:#fff; border:1px solid #ccc; padding:10px; max-width:320px; font-size:12px; z-index:9999;">
            <strong>Login debug</strong>
            <div><em>probe:</em> <?php echo htmlspecialchars(json_encode($login_probe)); ?></div>
            <div><em>found_user:</em> <?php echo htmlspecialchars(json_encode($login_found_user)); ?></div>
        </div>
    <?php endif; ?>
</body>
</html>