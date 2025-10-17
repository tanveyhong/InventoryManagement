<?php
/**
 * Clear POS Cache and Session
 * This forces a complete refresh
 */

session_start();

// Clear POS-related session data
unset($_SESSION['pos_store_id']);
unset($_SESSION['pos_cart']);

// Clear all session if needed (uncomment below)
// session_destroy();

// Redirect back to POS with cache-busting parameter
$timestamp = time();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("Location: quick_service.php?nocache=" . $timestamp);
exit;
?>
