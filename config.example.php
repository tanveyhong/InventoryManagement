<?php
/**
 * Configuration Template
 * 
 * INSTRUCTIONS:
 * 1. Copy this file to config.php
 * 2. Fill in your actual credentials
 * 3. NEVER commit config.php to git
 */

// Database Type
define('DB_TYPE', 'pgsql');
define('DB_DRIVER', 'pgsql');

// ===========================================
// OPTION 1: Local PostgreSQL Configuration
// ===========================================
// define('PG_HOST', 'localhost');
// define('PG_PORT', '5433');
// define('PG_DATABASE', 'inventory_system');
// define('PG_USERNAME', 'your_username');
// define('PG_PASSWORD', 'your_password');

// ===========================================
// OPTION 2: Supabase Cloud Configuration
// ===========================================
define('PG_HOST', 'db.xxxxxxxxxxxxx.supabase.co'); // Replace with your Supabase host
define('PG_PORT', '5432');
define('PG_DATABASE', 'postgres');
define('PG_USERNAME', 'postgres');
define('PG_PASSWORD', 'your-supabase-password'); // Replace with your password
define('PG_SSL_MODE', 'require'); // Required for Supabase

// Supabase Project Details (optional)
define('SUPABASE_URL', 'https://xxxxxxxxxxxxx.supabase.co'); // Replace with your project URL
// define('SUPABASE_ANON_KEY', 'your-anon-key');
// define('SUPABASE_SERVICE_KEY', 'your-service-role-key');

// Firebase Configuration (for backup system)
define('FIREBASE_DATABASE_URL', 'your-firebase-url');
define('FIREBASE_API_KEY', 'your-firebase-api-key'); // Get from Firebase Console
define('FIREBASE_PROJECT_ID', 'your-project-id');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 for development
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
?>
