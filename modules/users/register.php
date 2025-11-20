<?php
// User Registration Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Handle JSON API requests (from management interface)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $username = sanitizeInput($input['username'] ?? '');
    $email = sanitizeInput($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $first_name = sanitizeInput($input['first_name'] ?? '');
    $last_name = sanitizeInput($input['last_name'] ?? '');
    
    $errors = [];
    
    // Validation
    if (empty($username) || strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters';
    }
    if (empty($email) || !validateEmail($email)) {
        $errors[] = 'Invalid email format';
    }
    if (empty($password) || strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    if (empty($first_name)) {
        $errors[] = 'First name is required';
    }
    if (empty($last_name)) {
        $errors[] = 'Last name is required';
    }
    
    // Check if username or email already exists
    if (empty($errors)) {
        $existingUser = findUserByUsernameOrEmail($username);
        if ($existingUser) {
            $errors[] = 'Username already exists';
        }
        
        $existingEmail = findUserByUsernameOrEmail($email);
        if ($existingEmail && $existingEmail['id'] !== ($existingUser['id'] ?? null)) {
            $errors[] = 'Email already exists';
        }
    }
    
    if (empty($errors)) {
        $email_norm = strtolower(trim($email));
        
        try {
            require_once '../../sql_db.php';
            $sqlDb = SQLDatabase::getInstance();
            
            // Combine first and last name into full_name for Supabase
            $full_name = trim($first_name . ' ' . $last_name);
            
            // Create user in PostgreSQL (Supabase) with RETURNING
            $user = $sqlDb->fetch(
                "INSERT INTO users (username, email, password_hash, full_name, role, status, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, 'user', 'active', NOW(), NOW()) 
                 RETURNING id",
                [$username, $email_norm, hashPassword($password), $full_name]
            );
            
            if ($user) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'user_id' => $user['id']]);
                exit;
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Failed to create user']);
                exit;
            }
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
        exit;
    }
}

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ../../index.php');
    exit;
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $first_name = sanitizeInput($_POST['first_name'] ?? '');
    $last_name = sanitizeInput($_POST['last_name'] ?? '');
    
    // Validation
    if (empty($username)) {
        $errors[] = 'Username is required';
    } elseif (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!validateEmail($email)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }
    
    if (empty($first_name)) {
        $errors[] = 'First name is required';
    }
    
    if (empty($last_name)) {
        $errors[] = 'Last name is required';
    }
    
    // Check if username or email already exists
    if (empty($errors)) {
        $existingUser = findUserByUsernameOrEmail($username);
        if ($existingUser) {
            $errors[] = 'Username already exists';
        }
        
        $existingEmail = findUserByUsernameOrEmail($email);
        if ($existingEmail && $existingEmail['id'] !== ($existingUser['id'] ?? null)) {
            $errors[] = 'Email already exists';
        }
    }
    
    // Create user if no errors
    if (empty($errors)) {
        // Normalize email to lowercase for consistent lookups
        $email_norm = strtolower(trim($email));

        try {
            require_once '../../sql_db.php';
            $sqlDb = SQLDatabase::getInstance();
            
            // Combine first and last name into full_name for Supabase
            $full_name = trim($first_name . ' ' . $last_name);
            
            // Create user in PostgreSQL (Supabase) with RETURNING
            $createdUser = $sqlDb->fetch(
                "INSERT INTO users (username, email, password_hash, full_name, role, status, created_at, updated_at) 
                 VALUES (?, ?, ?, ?, 'user', 'active', NOW(), NOW()) 
                 RETURNING *",
                [$username, $email_norm, hashPassword($password), $full_name]
            );
            
            if ($createdUser) {
                // Auto-login the newly created user
                session_regenerate_id(true);
                $_SESSION['user_id'] = $createdUser['id'];
                $_SESSION['username'] = $createdUser['username'];
                $_SESSION['email'] = $createdUser['email'];
                $_SESSION['role'] = $createdUser['role'] ?? 'user';
                $_SESSION['login_time'] = time();

                header('Location: ../../index.php');
                exit;
            }

            $success = true;
            addNotification('Registration successful! You can now log in.', 'success');
        } catch (Exception $e) {
            $errors[] = 'Registration failed: ' . $e->getMessage();
        }
    }
}

$page_title = 'Register - Inventory System';
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
            <h2>Register</h2>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    Registration successful! <a href="login.php">Click here to login</a>
                </div>
            <?php else: ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="first_name">First Name:</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="last_name">Last Name:</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password:</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">Register</button>
                    </div>
                </form>
                
                <div class="auth-links">
                    <p>Already have an account? <a href="login.php">Login here</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>