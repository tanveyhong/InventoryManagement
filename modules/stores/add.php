<?php
// Add New Store Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    if (isset($_POST['demo_add'])) {
        // Demo data for quick testing
        $name = 'Demo Store ' . rand(1000,9999);
        $code = 'DEMO' . rand(100,999);
        $address = '123 Demo Street';
        $city = 'Demo City';
        $state = 'Demo State';
        $zip_code = '12345';
        $phone = '555-1234';
        $email = 'demo' . rand(100,999) . '@example.com';
        $manager_name = 'Demo Manager';
        $description = 'This is a demo store added for testing.';
        $errors = [];
    } else {
    exit;
    }
}

$db = getDB();
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name'] ?? '');
    $code = sanitizeInput($_POST['code'] ?? '');
    $address = sanitizeInput($_POST['address'] ?? '');
    $city = sanitizeInput($_POST['city'] ?? '');
    $state = sanitizeInput($_POST['state'] ?? '');
    $zip_code = sanitizeInput($_POST['zip_code'] ?? '');
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    $manager_name = sanitizeInput($_POST['manager_name'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    
    // Validation
    if (empty($name)) {
        $errors[] = 'Store name is required';
    }
    
    if (!empty($code)) {
        // Check if store code already exists (Firebase)
        $existing_store = $db->readAll('stores', [['code', '==', $code], ['active', '==', 1]]);
        if (!empty($existing_store)) {
            $errors[] = 'Store code already exists';
        }
    }
    
    if (!empty($email) && !validateEmail($email)) {
        $errors[] = 'Invalid email format';
    }
    
    // Check if store name already exists
    if (empty($errors)) {
        $existing_store = $db->readAll('stores', [['name', '==', $name], ['active', '==', 1]]);
        if (!empty($existing_store)) {
            $errors[] = 'Store name already exists';
        }
    }
    
    // Create store if no errors
    if (empty($errors)) {
        try {
            // Prepare store data for Firebase
            $storeData = [
                'name' => $name,
                'code' => $code,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'zip_code' => $zip_code,
                'phone' => $phone,
                'email' => $email,
                'manager_name' => $manager_name,
                'description' => $description,
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'active' => 1
            ];
            $result = $db->create('stores', $storeData);
            if ($result) {
                addNotification('Store created successfully!', 'success');
                header('Location: list.php');
                exit;
            } else {
                $errors[] = 'Failed to create store. Please try again.';
            }
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
            debugLog('Store creation error', ['error' => $e->getMessage(), 'user_id' => $_SESSION['user_id']]);
        }
    }
}

$page_title = 'Add New Store - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <?php 
        $header_title = "Add New Store";
        $header_subtitle = "Create a new store location and assign details.";
        $header_icon = "fas fa-store";
        $show_compact_toggle = false;
        $header_stats = [];
        include '../../includes/dashboard_header.php'; 
        ?>

        <!-- Page header -->
        <div class="page-header">
            <div class="header-left">
                <div class="header-icon"><i class="<?php echo htmlspecialchars($header_icon ?? 'fas fa-store'); ?>"></i></div>
                <div class="header-text">
                    <h1><?php echo htmlspecialchars($header_title ?? 'Add New Store'); ?></h1>
                    <p><?php echo htmlspecialchars($header_subtitle ?? 'Create a new store location and assign details.'); ?></p>
                </div>
            </div>
            <?php if (!empty($show_compact_toggle)): ?>
            <div class="header-actions">
                <button class="btn-compact-toggle" onclick="toggleCompactView()">
                    <i class="fas fa-compress"></i>
                    <span>Compact View</span>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($header_stats)): ?>
        <div class="stats-grid">
            <?php foreach ($header_stats as $stat): ?>
            <div class="stat-card">
                <div class="stat-card-inner">
                    <div class="stat-icon-wrapper">
                        <div class="stat-icon <?php echo htmlspecialchars($stat['type'] ?? 'primary'); ?>">
                            <i class="<?php echo htmlspecialchars($stat['icon'] ?? 'fas fa-info'); ?>"></i>
                        </div>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo htmlspecialchars($stat['value']); ?></div>
                        <div class="stat-label"><?php echo htmlspecialchars($stat['label']); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <main>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <form method="POST" action="" class="store-form">
                    <div class="form-section">
                        <h3>Basic Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name">Store Name: <span class="required">*</span></label>
                                <input type="text" id="name" name="name" 
                                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                                       required maxlength="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="code">Store Code:</label>
                                <input type="text" id="code" name="code" 
                                       value="<?php echo htmlspecialchars($_POST['code'] ?? ''); ?>" 
                                       maxlength="20" placeholder="e.g., ST001">
                                <small>Unique identifier for the store (optional)</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Address Information</h3>
                        
                        <div class="form-group">
                            <label for="address">Address:</label>
                            <input type="text" id="address" name="address" 
                                   value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" 
                                   maxlength="255" placeholder="Street address">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="city">City:</label>
                                <input type="text" id="city" name="city" 
                                       value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>" 
                                       maxlength="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="state">State/Province:</label>
                                <input type="text" id="state" name="state" 
                                       value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>" 
                                       maxlength="50">
                            </div>
                            
                            <div class="form-group">
                                <label for="zip_code">ZIP/Postal Code:</label>
                                <input type="text" id="zip_code" name="zip_code" 
                                       value="<?php echo htmlspecialchars($_POST['zip_code'] ?? ''); ?>" 
                                       maxlength="20">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Contact Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">Phone Number:</label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" 
                                       maxlength="20">
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address:</label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                       maxlength="100">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="manager_name">Manager Name:</label>
                            <input type="text" id="manager_name" name="manager_name" 
                                   value="<?php echo htmlspecialchars($_POST['manager_name'] ?? ''); ?>" 
                                   maxlength="100">
                        </div>
                    </div>

                    <div class="form-section">
                        <h3>Additional Information</h3>
                        
                        <div class="form-group">
                            <label for="description">Description:</label>
                            <textarea id="description" name="description" rows="4" 
                                      maxlength="500" placeholder="Optional description or notes about the store"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Create Store</button>
                        <button type="submit" name="demo_add" class="btn btn-secondary" style="margin-left:10px;">
                            <i class="fas fa-magic"></i> Demo Add Store
                        </button>
                        <a href="list.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
    <script>
        // Auto-generate store code based on name
        document.getElementById('name').addEventListener('input', function() {
            const name = this.value;
            const codeField = document.getElementById('code');
            
            if (!codeField.value && name) {
                const code = name.substring(0, 3).toUpperCase().replace(/[^A-Z]/g, '') + 
                           String(Math.floor(Math.random() * 100)).padStart(2, '0');
                codeField.value = code;
            }
        });
    </script>
</body>
</html>