<?php
/**
 * Production Configuration
 * Uses Environment Variables for security
 */

// Database Type
define('DB_TYPE', 'pgsql');
define('DB_DRIVER', 'pgsql');

// Supabase PostgreSQL Connection
// Use port 6543 (Transaction Pooler) to support IPv4 environments (like Render/Docker)
define('PG_HOST', getenv('PG_HOST') ?: '');
define('PG_PORT', getenv('PG_PORT') ?: '');
define('PG_DATABASE', getenv('PG_DATABASE') ?: '');
define('PG_USERNAME', getenv('PG_USERNAME') ?: ''); // Pooler often requires [user].[project]
define('PG_PASSWORD', getenv('PG_PASSWORD') ?: '');

// SSL is REQUIRED for Supabase
define('PG_SSL_MODE', 'require');

// Supabase Project Details
define('SUPABASE_URL', getenv('SUPABASE_URL') ?: '');

// Firebase Configuration
define('FIREBASE_DATABASE_URL', getenv('FIREBASE_DATABASE_URL') ?: '');
define('FIREBASE_API_KEY', getenv('FIREBASE_API_KEY') ?: '');
define('FIREBASE_PROJECT_ID', getenv('FIREBASE_PROJECT_ID') ?: '');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 when using HTTPS

// Timezone
date_default_timezone_set('Asia/Kuala_Lumpur');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php://stderr'); // Log to Docker output
?>
