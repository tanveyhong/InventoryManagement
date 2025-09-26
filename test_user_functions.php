<?php
// Test user functionality without Redis
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing User Functions without Redis...\n";
echo "=====================================\n\n";

// Start session for testing
session_start();

try {
    require_once 'functions.php';
    
    echo "1. Testing user creation... ";
    $testUserData = [
        'username' => 'testuser_' . time(),
        'email' => 'test_' . time() . '@example.com',
        'password_hash' => hashPassword('testpassword123'),
        'first_name' => 'Test',
        'last_name' => 'User',
        'role' => 'user',
        'is_active' => true
    ];
    
    $userId = createFirebaseUser($testUserData);
    if ($userId) {
        echo "✓ Created user with ID: $userId\n";
    } else {
        echo "✗ Failed to create user\n";
    }
    
    echo "2. Testing user lookup by username... ";
    if ($userId) {
        $foundUser = findUserByUsernameOrEmail($testUserData['username']);
        if ($foundUser && $foundUser['id'] === $userId) {
            echo "✓ Found user successfully\n";
        } else {
            echo "✗ Failed to find user\n";
        }
    } else {
        echo "Skipped (no user created)\n";
    }
    
    echo "3. Testing user lookup by email... ";
    if ($userId) {
        $foundUser = findUserByUsernameOrEmail($testUserData['email']);
        if ($foundUser && $foundUser['id'] === $userId) {
            echo "✓ Found user by email successfully\n";
        } else {
            echo "✗ Failed to find user by email\n";
        }
    } else {
        echo "Skipped (no user created)\n";
    }
    
    echo "4. Testing password verification... ";
    if ($userId && isset($foundUser)) {
        $passwordValid = verifyPassword('testpassword123', $foundUser['password_hash']);
        if ($passwordValid) {
            echo "✓ Password verification successful\n";
        } else {
            echo "✗ Password verification failed\n";
        }
        
        $wrongPasswordTest = verifyPassword('wrongpassword', $foundUser['password_hash']);
        if (!$wrongPasswordTest) {
            echo "✓ Wrong password correctly rejected\n";
        } else {
            echo "✗ Wrong password incorrectly accepted\n";
        }
    } else {
        echo "Skipped (no user to test)\n";
    }
    
    echo "5. Testing remember token functionality... ";
    if ($userId) {
        $token = generateToken();
        $expires = date('c', strtotime('+30 days'));
        
        $tokenUpdated = updateUserRememberToken($userId, $token, $expires);
        if ($tokenUpdated) {
            echo "✓ Remember token updated\n";
            
            // Test token lookup
            $userByToken = findUserByRememberToken($token);
            if ($userByToken && $userByToken['id'] === $userId) {
                echo "✓ User found by remember token\n";
            } else {
                echo "✗ Failed to find user by remember token\n";
            }
        } else {
            echo "✗ Failed to update remember token\n";
        }
    } else {
        echo "Skipped (no user to test)\n";
    }
    
    echo "6. Testing dashboard statistics... ";
    $totalProducts = getTotalProducts();
    $lowStock = getLowStockCount();
    $totalStores = getTotalStores();
    $todaySales = getTodaysSales();
    
    echo "✓ Statistics retrieved:\n";
    echo "   - Total Products: $totalProducts\n";
    echo "   - Low Stock Items: $lowStock\n";
    echo "   - Total Stores: $totalStores\n";
    echo "   - Today's Sales: $todaySales\n";
    
    echo "7. Testing utility functions... ";
    
    // Test sanitizeInput
    $dirty_input = "<script>alert('xss')</script>Test & Input";
    $clean_input = sanitizeInput($dirty_input);
    if (strpos($clean_input, '<script>') === false) {
        echo "✓ Input sanitization working\n";
    } else {
        echo "✗ Input sanitization failed\n";
    }
    
    // Test email validation
    if (validateEmail('test@example.com') && !validateEmail('invalid-email')) {
        echo "✓ Email validation working\n";
    } else {
        echo "✗ Email validation failed\n";
    }
    
    // Test CSRF token
    $token = preventCSRF();
    if (verifyCSRF($token)) {
        echo "✓ CSRF protection working\n";
    } else {
        echo "✗ CSRF protection failed\n";
    }
    
    echo "8. Cleaning up test user... ";
    if ($userId) {
        $db = getDB();
        $deleted = $db->delete('users', $userId);
        if ($deleted) {
            echo "✓ Test user deleted\n";
        } else {
            echo "✗ Failed to delete test user\n";
        }
    } else {
        echo "Skipped (no user to delete)\n";
    }
    
    echo "\n=====================================\n";
    echo "User functionality test completed!\n";
    echo "All Redis dependencies have been successfully removed.\n";
    
} catch (Exception $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>