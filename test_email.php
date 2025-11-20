<?php
/**
 * Email Configuration Test
 * Tests SMTP connection and email sending
 */

require_once __DIR__ . '/email_helper.php';

echo "=== Email Configuration Test ===\n\n";

// Load config
$config = require __DIR__ . '/email_config.php';

echo "Configuration loaded:\n";
echo "- Host: " . $config['smtp']['host'] . "\n";
echo "- Port: " . $config['smtp']['port'] . "\n";
echo "- Encryption: " . $config['smtp']['encryption'] . "\n";
echo "- Username: " . $config['smtp']['username'] . "\n";
echo "- Password: " . (empty($config['smtp']['password']) ? 'NOT SET' : '****' . substr($config['smtp']['password'], -4)) . "\n";
echo "- From: " . $config['from']['address'] . "\n\n";

// Check if credentials are still default
if ($config['smtp']['username'] === 'your-email@gmail.com' || 
    $config['smtp']['password'] === 'your-app-password') {
    echo "⚠️  WARNING: Email configuration still has default values!\n";
    echo "Please update email_config.php with your actual SMTP credentials.\n\n";
    echo "For Gmail:\n";
    echo "1. Go to: https://myaccount.google.com/apppasswords\n";
    echo "2. Generate an App Password\n";
    echo "3. Update email_config.php with your Gmail address and App Password\n\n";
    exit(1);
}

// Prompt for test email
echo "Enter email address to send test email to: ";
$testEmail = trim(fgets(STDIN));

if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email address.\n";
    exit(1);
}

echo "\nSending test email to: $testEmail\n";
echo "Please wait...\n\n";

// Send test email
$subject = "Test Email - Inventory System";
$body = '<h1>Test Email</h1><p>If you received this email, your email configuration is working correctly!</p>';
$altBody = "Test Email\n\nIf you received this email, your email configuration is working correctly!";

if (sendEmail($testEmail, $subject, $body, $altBody)) {
    echo "✅ SUCCESS! Test email sent successfully.\n";
    echo "Check your inbox (and spam folder) at: $testEmail\n";
} else {
    echo "❌ FAILED! Could not send test email.\n";
    echo "Error: " . ($_SESSION['email_error'] ?? 'Unknown error') . "\n\n";
    echo "Common issues:\n";
    echo "1. Wrong username or password\n";
    echo "2. Gmail: Need to use App Password (not regular password)\n";
    echo "3. Firewall blocking port " . $config['smtp']['port'] . "\n";
    echo "4. 'Less secure app access' disabled (Gmail)\n";
    echo "5. Two-factor authentication not enabled (required for App Passwords)\n";
}
