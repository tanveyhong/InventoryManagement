<?php
// Edit Store Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

$db = getDB();
$store_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$errors = [];
$success = false;

// Get store data
$store = $db->fetch("SELECT * FROM stores WHERE id = ? AND active = 1", [$store_id]);

if (!$store) {
    addNotification('Store not found', 'error');
    header('Location: list.php');
    exit;
}

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
        $existing_store = $db->fetch("SELECT id FROM stores WHERE code = ? AND id != ? AND active = 1", [$code, $store_id]);
        if ($existing_store) {
            $errors[] = 'Store code already exists';
        }
    }
    
    if (!empty($email) && !validateEmail($email)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($errors)) {
        $existing_store = $db->fetch("SELECT id FROM stores WHERE name = ? AND id != ? AND active = 1", [$name, $store_id]);
        if ($existing_store) {
            $errors[] = 'Store name already exists';
        }
    }
    
    if (empty($errors)) {
        try {
            $sql = "UPDATE stores SET name = ?, code = ?, address = ?, city = ?, state = ?, zip_code = ?, 
                    phone = ?, email = ?, manager_name = ?, description = ?, updated_at = NOW() WHERE id = ?";
            
            $params = [
                $name, $code, $address, $city, $state, $zip_code,
                $phone, $email, $manager_name, $description, $store_id
            ];
            
            $result = $db->query($sql, $params);
            
            if ($result) {
                addNotification('Store updated successfully!', 'success');
                header('Location: list.php');
                exit;
            } else {
                $errors[] = 'Failed to update store. Please try again.';
            }
            
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$page_title = 'Edit Store - Inventory System';
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
    <?php 
    $header_title = "Edit Store";
    $header_subtitle = "Update store details and information.";
    $header_icon = "fas fa-edit";
    $show_compact_toggle = false;
    $header_stats = [];
    include '../../includes/dashboard_header.php'; 
    ?>
    <div class="container">

        <!-- Page header -->
        <div class="page-header">
            <div class="header-left">
                <div class="header-icon"><i class="<?php echo htmlspecialchars($header_icon ?? 'fas fa-edit'); ?>"></i></div>
                <div class="header-text">
                    <h1><?php echo htmlspecialchars($header_title ?? 'Edit Store'); ?>: <?php echo htmlspecialchars($store['name']); ?></h1>
                    <p><?php echo htmlspecialchars($header_subtitle ?? 'Update store details and information.'); ?></p>
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
                                       value="<?php echo htmlspecialchars($_POST['name'] ?? $store['name']); ?>" 
                                       required maxlength="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="code">Store Code:</label>
                                <input type="text" id="code" name="code" 
                                       value="<?php echo htmlspecialchars($_POST['code'] ?? $store['code']); ?>" 
                                       maxlength="20">
                            </div>
                        </div>
                    </div>

                    <!-- Rest of form fields similar to add.php, but with store data pre-filled -->
                    <div class="form-section">
                        <h3>Address Information</h3>
                        
                        <div class="form-group">
                            <label for="address">Address:</label>
                            <input type="text" id="address" name="address" 
                                   value="<?php echo htmlspecialchars($_POST['address'] ?? $store['address']); ?>" 
                                   maxlength="255">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="city">City:</label>
                                <input type="text" id="city" name="city" 
                                       value="<?php echo htmlspecialchars($_POST['city'] ?? $store['city']); ?>" 
                                       maxlength="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="state">State/Province:</label>
                                <input type="text" id="state" name="state" 
                                       value="<?php echo htmlspecialchars($_POST['state'] ?? $store['state']); ?>" 
                                       maxlength="50">
                            </div>
                            
                            <div class="form-group">
                                <label for="zip_code">ZIP/Postal Code:</label>
                                <input type="text" id="zip_code" name="zip_code" 
                                       value="<?php echo htmlspecialchars($_POST['zip_code'] ?? $store['zip_code']); ?>" 
                                       maxlength="20">
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Update Store</button>
                        <a href="list.php" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script src="../../assets/js/main.js"></script>
</body>
</html>