<?php
// Application Configuration
define('APP_NAME', 'Inventory Management System');
define('APP_VERSION', '1.0.0');
define('APP_TIMEZONE', 'America/New_York');

// Database Configuration
// Change DB_TYPE to switch between 'sqlite', 'pgsql', or 'mysql'
define('DB_TYPE', 'pgsql'); // Options: 'sqlite', 'pgsql', 'mysql'

// SQLite Configuration (Local Development)
define('DB_HOST', 'localhost');
define('DB_PORT', 5432);
define('DB_NAME', __DIR__ . '/storage/database.sqlite'); // SQLite database file (absolute path)
define('DB_USERNAME', 'user');
define('DB_PASSWORD', 'password');
define('DB_CHARSET', 'utf8');
define('DB_DRIVER', DB_TYPE); // Will be 'sqlite', 'pgsql', or 'mysql'

// PostgreSQL Configuration (Production - Multi-user)
// Configured for local PostgreSQL server
define('PG_HOST', 'localhost');
define('PG_PORT', '5433'); // Custom port
define('PG_DATABASE', 'inventory_system');
define('PG_USERNAME', 'inventory_user'); // PostgreSQL user with database access
define('PG_PASSWORD', 'SecurePassword123!'); // PostgreSQL password

// MySQL Configuration (Alternative to PostgreSQL)
// Uncomment if using MySQL instead of PostgreSQL
define('MYSQL_HOST', 'localhost');
define('MYSQL_PORT', '3306');
define('MYSQL_DATABASE', 'inventory_system');
define('MYSQL_USERNAME', 'inventory_user');
define('MYSQL_PASSWORD', 'your_secure_password');

// Security Configuration
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds
define('HASH_ALGO', 'sha256');
define('ENCRYPT_KEY', 'your-secret-key-here'); // Change this in production

// File Upload Configuration
define('UPLOAD_MAX_SIZE', 5242880); // 5MB in bytes
define('UPLOAD_ALLOWED_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf']);
define('UPLOAD_PATH', 'storage/uploads/');

// Stock Management Configuration
define('LOW_STOCK_THRESHOLD', 10);
define('EXPIRY_ALERT_DAYS', 30);

// POS Integration Configuration
define('POS_API_ENDPOINT', 'https://your-pos-system.com/api/');
define('POS_API_KEY', 'your-pos-api-key-here');

// Email Configuration (for alerts)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'your-email@gmail.com');
define('SMTP_PASSWORD', 'your-email-password');
define('SMTP_FROM_EMAIL', 'noreply@inventorysystem.com');
define('SMTP_FROM_NAME', 'Inventory System');

// Cache Configuration
define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 hour

// Debug Configuration
define('DEBUG_MODE', true); // Set to false in production
define('LOG_ERRORS', true);
define('ERROR_LOG_PATH', __DIR__ . '/storage/logs/errors.log');

// Set timezone
date_default_timezone_set(APP_TIMEZONE);

// Error reporting based on debug mode
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL); // Enable all error reporting for logging
    ini_set('display_errors', 0);
}

// ---- Safe error logging setup ----
$logDir = __DIR__ . '/storage/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/errors.log');

// Custom error handler
function customErrorHandler($severity, $message, $file, $line) {
    if (LOG_ERRORS) {
        $log_message = date('[Y-m-d H:i:s] ') . "Error: $message in $file on line $line\n";
        error_log($log_message, 3, ERROR_LOG_PATH);
    }
    
    if (DEBUG_MODE) {
        echo "<strong>Error:</strong> $message in <strong>$file</strong> on line <strong>$line</strong><br>";
    }
}

set_error_handler('customErrorHandler');
?>