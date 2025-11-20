<?php
/**
 * Interactive Email Setup Helper
 * Helps configure and test email settings
 */

echo "=== Email Configuration Helper ===\n\n";

$configFile = __DIR__ . '/email_config.php';

// Check if config file exists
if (!file_exists($configFile)) {
    echo "❌ email_config.php not found!\n";
    exit(1);
}

// Load current config
$config = require $configFile;

echo "Current Configuration:\n";
echo str_repeat('-', 50) . "\n";
echo "Host: " . $config['smtp']['host'] . "\n";
echo "Port: " . $config['smtp']['port'] . "\n";
echo "Encryption: " . $config['smtp']['encryption'] . "\n";
echo "Username: " . $config['smtp']['username'] . "\n";
echo "Password: " . (($config['smtp']['password'] === 'your-app-password') ? '⚠️  DEFAULT (NOT CONFIGURED)' : '✓ Set (****' . substr($config['smtp']['password'], -4) . ')') . "\n";
echo "From Address: " . $config['from']['address'] . "\n";
echo str_repeat('-', 50) . "\n\n";

// Check for default values
$needsSetup = false;
$issues = [];

if ($config['smtp']['username'] === 'your-email@gmail.com') {
    $issues[] = "Username is still set to default 'your-email@gmail.com'";
    $needsSetup = true;
}

if ($config['smtp']['password'] === 'your-app-password') {
    $issues[] = "Password is still set to default 'your-app-password'";
    $needsSetup = true;
}

if ($config['from']['address'] === 'noreply@inventorysystem.com' && $config['smtp']['host'] === 'smtp.gmail.com') {
    $issues[] = "From address should match your Gmail address";
    $needsSetup = true;
}

if (!empty($issues)) {
    echo "⚠️  Configuration Issues Found:\n";
    foreach ($issues as $issue) {
        echo "   - $issue\n";
    }
    echo "\n";
}

if ($needsSetup) {
    echo "📝 SETUP REQUIRED\n\n";
    echo "For Gmail (Recommended):\n";
    echo "1. Go to: https://myaccount.google.com/apppasswords\n";
    echo "2. Sign in to your Google Account\n";
    echo "3. Click 'Select app' -> Choose 'Mail'\n";
    echo "4. Click 'Select device' -> Choose 'Windows Computer' or 'Other'\n";
    echo "5. Click 'Generate'\n";
    echo "6. Copy the 16-character password (without spaces)\n\n";
    
    echo "Then update email_config.php:\n";
    echo "   'username' => 'youremail@gmail.com',\n";
    echo "   'password' => 'abcd efgh ijkl mnop',  // The 16-char App Password\n\n";
    echo "   'from' => [\n";
    echo "       'address' => 'youremail@gmail.com',  // Same as username\n";
    echo "       ...\n";
    echo "   ]\n\n";
    
    echo "⚠️  IMPORTANT: Use App Password, NOT your regular Gmail password!\n\n";
} else {
    echo "✓ Configuration looks good!\n\n";
    
    // Offer to test
    echo "Would you like to test the email? (y/n): ";
    $response = trim(fgets(STDIN));
    
    if (strtolower($response) === 'y') {
        echo "\nEnter email address to send test to: ";
        $testEmail = trim(fgets(STDIN));
        
        if (filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            echo "\nTesting email to: $testEmail\n";
            echo "Please wait...\n\n";
            
            require_once __DIR__ . '/email_helper.php';
            
            $subject = "Test Email - Inventory System";
            $body = '<h1>✅ Email Test Successful!</h1><p>Your email configuration is working correctly.</p>';
            
            if (sendEmail($testEmail, $subject, $body)) {
                echo "✅ SUCCESS! Email sent.\n";
                echo "Check your inbox at: $testEmail\n";
            } else {
                echo "❌ FAILED!\n";
                $error = $_SESSION['email_error'] ?? 'Unknown error';
                echo "Error: $error\n\n";
                
                // Provide specific help based on error
                if (strpos($error, 'authenticate') !== false) {
                    echo "Authentication Failed - Possible causes:\n";
                    echo "1. Wrong username or password\n";
                    echo "2. Using regular password instead of App Password\n";
                    echo "3. App Password not generated yet\n";
                    echo "4. Two-factor authentication not enabled on Gmail\n\n";
                    echo "Solution:\n";
                    echo "- Generate App Password at: https://myaccount.google.com/apppasswords\n";
                    echo "- Make sure 2-Factor Auth is enabled on your Google account first\n";
                } elseif (strpos($error, 'connect') !== false) {
                    echo "Connection Failed - Possible causes:\n";
                    echo "1. Firewall blocking port " . $config['smtp']['port'] . "\n";
                    echo "2. Wrong SMTP host\n";
                    echo "3. Internet connection issue\n";
                }
            }
        } else {
            echo "Invalid email address.\n";
        }
    }
}
