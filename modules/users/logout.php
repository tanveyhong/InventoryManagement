<?php
// User Logout
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Clear remember me cookie if exists
if (isset($_COOKIE['remember_token'])) {
    // Clear token from database
    if (isLoggedIn()) {
        clearUserRememberToken($_SESSION['user_id']);
    }
    
    // Clear cookie
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

// Destroy session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit;
?>