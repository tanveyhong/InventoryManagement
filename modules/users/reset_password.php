<?php
// Reset Password - Set New Password
require_once '../../config.php';
require_once '../../sql_db.php';

session_start();

$errors = [];
$success = '';
$token = $_GET['token'] ?? '';
$validToken = false;
$user = null;

// Verify token
if (!empty($token)) {
    try {
        $sqlDb = SQLDatabase::getInstance();
        $user = $sqlDb->fetch(
            "SELECT id, username, email FROM users WHERE reset_token = ? AND reset_token_expires > NOW() AND deleted_at IS NULL",
            [$token]
        );
        
        if ($user) {
            $validToken = true;
        } else {
            $errors[] = 'Invalid or expired reset link. Please request a new password reset.';
        }
    } catch (Exception $e) {
        $errors[] = 'Error: ' . $e->getMessage();
    }
} else {
    $errors[] = 'No reset token provided.';
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    if (empty($confirm_password)) {
        $errors[] = 'Please confirm your password';
    }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }
    
    if (empty($errors)) {
        try {
            $sqlDb = SQLDatabase::getInstance();
            
            // Hash new password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Update password and clear reset token
            $sqlDb->execute(
                "UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?",
                [$password_hash, $user['id']]
            );
            
            $success = 'Password has been reset successfully. You can now login with your new password.';
            $validToken = false; // Prevent form from showing again
            
        } catch (Exception $e) {
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Inventory Management System</title>
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
        
        .reset-container {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 450px;
        }
        
        .reset-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .reset-header h1 {
            color: #2c3e50;
            margin: 0 0 10px 0;
            font-size: 2rem;
        }
        
        .reset-header p {
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
            font-weight: 600;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-reset {
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
        
        .btn-reset:hover {
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
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #7f8c8d;
        }
        
        .login-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        
        .password-strength {
            font-size: 0.85rem;
            margin-top: 5px;
            color: #7f8c8d;
        }
        
        .password-strength.weak { color: #e74c3c; }
        .password-strength.medium { color: #f39c12; }
        .password-strength.strong { color: #27ae60; }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-header">
            <h1><i class="fas fa-lock"></i> Reset Password</h1>
            <p>Enter your new password</p>
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
            <div class="login-link">
                <a href="login.php"><i class="fas fa-sign-in-alt"></i> Go to Login</a>
            </div>
        <?php elseif ($validToken): ?>
            <form method="POST" action="" id="resetForm">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <div class="form-group">
                    <label for="password"><i class="fas fa-key"></i> New Password</label>
                    <div style="position: relative;">
                        <input type="password" id="password" name="password" required autofocus minlength="6" style="padding-right: 40px; width: 100%;">
                        <button type="button" onclick="togglePasswordVisibility('password', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: none; cursor: pointer; color: #667eea; padding: 5px;" title="Show/Hide Password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="strengthText"></div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password"><i class="fas fa-check-circle"></i> Confirm Password</label>
                    <div style="position: relative;">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6" style="padding-right: 40px; width: 100%;">
                        <button type="button" onclick="togglePasswordVisibility('confirm_password', this)" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); border: none; background: none; cursor: pointer; color: #667eea; padding: 5px;" title="Show/Hide Password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-reset">
                    <i class="fas fa-save"></i> Reset Password
                </button>
            </form>
        <?php else: ?>
            <div class="login-link">
                <a href="forgot_password.php"><i class="fas fa-arrow-left"></i> Request New Reset Link</a>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Toggle password visibility
        function togglePasswordVisibility(inputId, button) {
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
        
        // Password strength indicator
        const password = document.getElementById('password');
        const strengthText = document.getElementById('strengthText');
        
        if (password && strengthText) {
            password.addEventListener('input', function() {
                const value = this.value;
                let strength = 0;
                
                if (value.length >= 6) strength++;
                if (value.length >= 10) strength++;
                if (/[a-z]/.test(value) && /[A-Z]/.test(value)) strength++;
                if (/\d/.test(value)) strength++;
                if (/[^a-zA-Z0-9]/.test(value)) strength++;
                
                strengthText.className = 'password-strength';
                if (strength <= 2) {
                    strengthText.textContent = 'Weak password';
                    strengthText.classList.add('weak');
                } else if (strength <= 3) {
                    strengthText.textContent = 'Medium password';
                    strengthText.classList.add('medium');
                } else {
                    strengthText.textContent = 'Strong password';
                    strengthText.classList.add('strong');
                }
            });
        }
        
        // Confirm password match
        const confirmPassword = document.getElementById('confirm_password');
        const form = document.getElementById('resetForm');
        
        if (form) {
            form.addEventListener('submit', function(e) {
                if (password.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    confirmPassword.focus();
                }
            });
        }
    </script>
</body>
</html>
