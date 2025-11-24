<?php
// User Logout - PostgreSQL Only
require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../activity_logger.php';

session_start();

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token']) && isset($_SESSION['user_id'])) {
    try {
        // Clear token from PostgreSQL database
        $sqlDb = SQLDatabase::getInstance();
        $sqlDb->execute(
            "UPDATE users SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?",
            [$_SESSION['user_id']]
        );
    } catch (Exception $e) {
        // Ignore errors during logout
    }
    
    // Clear cookies
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
    setcookie('user_id', '', time() - 3600, '/', '', false, true);
}

// Log Activity
if (isset($_SESSION['user_id'])) {
    logActivity('logout', 'User logged out');
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>