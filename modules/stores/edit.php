<?php
// Edit Store Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../sql_db.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';

session_start();

if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

// Check permission to edit stores
if (!currentUserHasPermission('can_edit_stores')) {
    $_SESSION['error'] = 'You do not have permission to edit stores';
    header('Location: ../../index.php');
    exit;
}

$db = getDB(); // Firebase fallback
$sqlDb = SQLDatabase::getInstance(); // PostgreSQL - PRIMARY
$store_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$errors = [];
$success = false;

// Get store data from PostgreSQL
try {
    $store = $sqlDb->fetch("SELECT * FROM stores WHERE id = ? AND active = TRUE", [$store_id]);
    if (!$store) {
        addNotification('Store not found', 'error');
        header('Location: list.php');
        exit;
    }
} catch (Exception $e) {
    addNotification('Database error: ' . $e->getMessage(), 'error');
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
    $latitude = sanitizeInput($_POST['latitude'] ?? '');
    $longitude = sanitizeInput($_POST['longitude'] ?? '');
    $store_type = sanitizeInput($_POST['store_type'] ?? 'retail');
    
    // Validation
    if (empty($name)) {
        $errors[] = 'Store name is required';
    }
    
    // Check for duplicate name (excluding current store)
    $existing_store = $db->fetch("SELECT id FROM stores WHERE name = ? AND id != ? AND active = 1", [$name, $store_id]);
    if ($existing_store) {
        $errors[] = 'Store name already exists';
    }

    // Check for duplicate code (excluding current store)
    if (!empty($code)) {
        $existing_store = $db->fetch("SELECT id FROM stores WHERE code = ? AND id != ? AND active = 1", [$code, $store_id]);
        if ($existing_store) {
            $errors[] = 'Store code already exists';
        }
    }

    // Address Validation
    if (empty($address)) {
        $errors[] = 'Address is required';
    }
    if (empty($city)) {
        $errors[] = 'City is required';
    }
    if (empty($state)) {
        $errors[] = 'State/Province is required';
    }
    if (empty($zip_code)) {
        $errors[] = 'ZIP/Postal Code is required';
    }

    // Phone Validation
    if (!empty($phone) && !preg_match('/^[0-9+\-\(\)\s]+$/', $phone)) {
        $errors[] = 'Invalid phone number format';
    }
    
    if (!empty($latitude) && (!is_numeric($latitude) || $latitude < -90 || $latitude > 90)) {
        $errors[] = 'Invalid latitude (must be between -90 and 90)';
    }
    
    if (!empty($longitude) && (!is_numeric($longitude) || $longitude < -180 || $longitude > 180)) {
        $errors[] = 'Invalid longitude (must be between -180 and 180)';
    }
    
    if (!empty($email) && !validateEmail($email)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($errors)) {
        try {
            // Track changes for activity log
            $changes = [];
            if ($store['name'] !== $name) $changes['name'] = ['old' => $store['name'], 'new' => $name];
            if ($store['code'] !== $code) $changes['code'] = ['old' => $store['code'], 'new' => $code];
            if ($store['address'] !== $address) $changes['address'] = ['old' => $store['address'], 'new' => $address];
            if ($store['city'] !== $city) $changes['city'] = ['old' => $store['city'], 'new' => $city];
            if ($store['state'] !== $state) $changes['state'] = ['old' => $store['state'], 'new' => $state];
            if ($store['manager_name'] !== $manager_name) $changes['manager_name'] = ['old' => $store['manager_name'], 'new' => $manager_name];
            
            $sql = "UPDATE stores SET 
                    name = ?, code = ?, address = ?, city = ?, state = ?, zip_code = ?, 
                    phone = ?, email = ?, manager_name = ?,
                    latitude = ?, longitude = ?, store_type = ?,
                    updated_at = NOW() 
                    WHERE id = ?";
            
            $params = [
                $name, $code, $address, $city, $state, $zip_code,
                $phone, $email, $manager_name,
                $latitude ? floatval($latitude) : null, 
                $longitude ? floatval($longitude) : null,
                $store_type,
                $store_id
            ];
            
            $result = $sqlDb->execute($sql, $params);
            
            if ($result) {
                // Log the activity
                logStoreActivity('updated', $store_id, $name, $changes);
                
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
    
    <!-- Leaflet CSS for map preview -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        * {
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding-top: 0; /* Allow dashboard header to be at top */
        }
        
        /* Dashboard header compatibility */
        body > header {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            padding-top: 20px; /* Space after dashboard header */
        }
        
        .page-header {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            animation: slideDown 0.5s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .page-header h1 {
            margin: 0 0 10px 0;
            color: #2d3748;
            font-size: 2em;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-header h1 i {
            color: #667eea;
            font-size: 1.2em;
        }
        
        .page-header p {
            margin: 0;
            color: #718096;
            font-size: 1em;
        }
        
        .header-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            font-size: 14px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #718096 0%, #4a5568 100%);
            box-shadow: 0 4px 15px rgba(113, 128, 150, 0.3);
        }
        
        .btn-outline {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.15);
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            animation: fadeInUp 0.5s ease-out;
            border: 1px solid;
        }
        
        .alert-error {
            background: rgba(254, 226, 226, 0.95);
            color: #c53030;
            border-color: #fc8181;
        }
        
        .alert ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .form-container {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 35px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            animation: fadeInUp 0.6s ease-out 0.1s both;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .form-section {
            margin-bottom: 35px;
            padding-bottom: 30px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .form-section h3 {
            margin: 0 0 20px 0;
            color: #2d3748;
            font-weight: 700;
            font-size: 1.3em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-section h3::before {
            content: '';
            display: inline-block;
            width: 4px;
            height: 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 2px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #4a5568;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .required {
            color: #e53e3e;
            margin-left: 4px;
        }
        
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="number"],
        .form-group input[type="time"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: white;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }
        
        .form-group input:hover,
        .form-group select:hover,
        .form-group textarea:hover {
            border-color: #cbd5e0;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-group small {
            display: block;
            margin-top: 6px;
            color: #718096;
            font-size: 12px;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
            flex-wrap: wrap;
        }
        
        .map-preview {
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border: 2px solid #e2e8f0;
            animation: fadeInUp 0.5s ease-out;
        }
        
        .loading {
            position: relative;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Success state for inputs */
        .form-group input.success {
            border-color: #48bb78;
            background: rgba(72, 187, 120, 0.05);
            animation: successPulse 0.6s ease;
        }
        
        @keyframes successPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
        
        /* Address Search Styles */
        .address-search-container { 
            position: relative; 
            margin-bottom: 25px; 
            padding: 20px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.08) 0%, rgba(118, 75, 162, 0.08) 100%);
            border-radius: 12px;
            border: 2px dashed rgba(102, 126, 234, 0.25);
        }
        
        .address-search-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            color: #2d3748;
        }
        
        .address-search-header i { 
            color: #667eea; 
            font-size: 20px; 
        }
        
        .address-search-header h4 { 
            margin: 0; 
            font-size: 16px; 
            font-weight: 600; 
        }
        
        #address-search {
            padding-right: 40px !important;
            font-size: 15px;
        }
        
        #search-loading {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            animation: spinLoader 1s linear infinite;
        }
        
        @keyframes spinLoader {
            from { transform: translateY(-50%) rotate(0deg); }
            to { transform: translateY(-50%) rotate(360deg); }
        }
        
        #address-results {
            position: absolute;
            top: calc(100% + 5px);
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #667eea;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            max-height: 400px;
            overflow-y: auto;
            z-index: 9999 !important;
        }
        
        .search-helper-text {
            font-size: 13px;
            color: #718096;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .search-helper-text i { 
            color: #667eea; 
        }
        
        /* Notification Styles */
        .notification {
            position: fixed;
            top: 80px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 10000;
            animation: slideInRight 0.3s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            max-width: 400px;
        }
        
        .notification.success {
            background: #48bb78;
            color: white;
        }
        
        .notification.error {
            background: #e53e3e;
            color: white;
        }
        
        @keyframes slideInRight {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(400px); opacity: 0; }
        }
        
        /* Tooltip style */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }
        
        [data-tooltip]:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 8px 12px;
            background: #2d3748;
            color: white;
            font-size: 12px;
            white-space: nowrap;
            border-radius: 8px;
            margin-bottom: 8px;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .page-header,
            .form-container {
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
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
            <div>
                <h1><i class="fas fa-edit"></i> Edit Store: <?php echo htmlspecialchars(htmlspecialchars_decode($store['name'])); ?></h1>
                <p>Update store details and location information</p>
            </div>
            <div class="header-actions">
                <a href="list.php" class="btn btn-outline">
                    <i class="fas fa-list"></i> View All Stores
                </a>
                <a href="map.php" class="btn btn-secondary">
                    <i class="fas fa-map-marked-alt"></i> Store Map
                </a>
            </div>
        </div>

        <main>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <strong><i class="fas fa-exclamation-triangle"></i> Error!</strong>
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
                        <h3><i class="fas fa-info-circle" style="color: #667eea;"></i> Basic Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="name">Store Name: <span class="required">*</span></label>
                                <input type="text" id="name" name="name" 
                                       value="<?php echo htmlspecialchars($_POST['name'] ?? htmlspecialchars_decode($store['name'])); ?>" 
                                       required maxlength="100">
                            </div>
                            
                            <div class="form-group">
                                <label for="code">Store Code:</label>
                                <input type="text" id="code" name="code" 
                                       value="<?php echo htmlspecialchars($_POST['code'] ?? htmlspecialchars_decode($store['code'])); ?>" 
                                       maxlength="20">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="store_type">Store Type: <span class="required">*</span></label>
                                <select id="store_type" name="store_type" required>
                                    <?php $sType = $_POST['store_type'] ?? $store['store_type'] ?? 'retail'; ?>
                                    <option value="retail" <?php echo ($sType === 'retail') ? 'selected' : ''; ?>>Retail Store</option>
                                    <option value="warehouse" <?php echo ($sType === 'warehouse') ? 'selected' : ''; ?>>Warehouse</option>
                                    <option value="distribution" <?php echo ($sType === 'distribution') ? 'selected' : ''; ?>>Distribution Center</option>
                                    <option value="flagship" <?php echo ($sType === 'flagship') ? 'selected' : ''; ?>>Flagship Store</option>
                                    <option value="outlet" <?php echo ($sType === 'outlet') ? 'selected' : ''; ?>>Outlet</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-map-marker-alt" style="color: #667eea;"></i> Address & Location</h3>
                        
                        <!-- Address Search Bar -->
                        <div class="form-group" style="position: relative; overflow: visible;">
                            <label for="address-search">
                                <i class="fas fa-search"></i> Search Address:
                            </label>
                            <div style="position: relative; overflow: visible;">
                                <input type="text" 
                                       id="address-search" 
                                       placeholder="Search address (min 3 chars, e.g., 'Ujong Pasir, Johor' or 'New York, NY')"
                                       style="padding-right: 40px;">
                                <div id="search-loading" style="display: none; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);">
                                    <i class="fas fa-spinner fa-spin" style="color: #667eea;"></i>
                                </div>
                                
                                <!-- Search Results Dropdown -->
                                <div id="address-results" style="display: none;"></div>
                            </div>
                            <small>
                                <i class="fas fa-info-circle"></i> Type at least 3 characters to search. Use ↑↓ arrow keys and Enter to select.
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Address: <span class="required">*</span></label>
                            <input type="text" id="address" name="address" 
                                   value="<?php echo htmlspecialchars($_POST['address'] ?? htmlspecialchars_decode($store['address'])); ?>" 
                                   maxlength="255" placeholder="Street address" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="city">City: <span class="required">*</span></label>
                                <input type="text" id="city" name="city" 
                                       value="<?php echo htmlspecialchars($_POST['city'] ?? htmlspecialchars_decode($store['city'])); ?>" 
                                       maxlength="100" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="state">State/Province: <span class="required">*</span></label>
                                <input type="text" id="state" name="state" 
                                       value="<?php echo htmlspecialchars($_POST['state'] ?? htmlspecialchars_decode($store['state'])); ?>" 
                                       maxlength="50" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="zip_code">ZIP/Postal Code: <span class="required">*</span></label>
                                <input type="text" id="zip_code" name="zip_code" 
                                       value="<?php echo htmlspecialchars($_POST['zip_code'] ?? htmlspecialchars_decode($store['zip_code'])); ?>" 
                                       maxlength="20" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="latitude">Latitude: <small>(Optional)</small></label>
                                <input type="number" id="latitude" name="latitude" step="0.000001" 
                                       value="<?php echo htmlspecialchars($_POST['latitude'] ?? $store['latitude'] ?? ''); ?>" 
                                       placeholder="e.g., 40.7128">
                                <small>Auto-filled when address is geocoded</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="longitude">Longitude: <small>(Optional)</small></label>
                                <input type="number" id="longitude" name="longitude" step="0.000001" 
                                       value="<?php echo htmlspecialchars($_POST['longitude'] ?? $store['longitude'] ?? ''); ?>" 
                                       placeholder="e.g., -74.0060">
                                <small>Auto-filled when address is geocoded</small>
                            </div>
                        </div>
                        
                        <div id="map-preview" class="map-preview" style="display: none; height: 300px; margin-top: 15px; border: 1px solid #ddd; border-radius: 8px;"></div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-address-book" style="color: #667eea;"></i> Contact Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="phone">Phone Number: <small>(Optional)</small></label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? htmlspecialchars_decode($store['phone'])); ?>" 
                                       maxlength="20">
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address: <small>(Optional)</small></label>
                                <input type="email" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? htmlspecialchars_decode($store['email'])); ?>" 
                                       maxlength="100">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="manager_name">Manager Name: <small>(Optional)</small></label>
                            <input type="text" id="manager_name" name="manager_name" 
                                   value="<?php echo htmlspecialchars($_POST['manager_name'] ?? htmlspecialchars_decode($store['manager_name'])); ?>" 
                                   maxlength="100">
                        </div>
                    </div>





                    <div class="form-actions">
                        <button type="submit" class="btn">
                            <i class="fas fa-save"></i> Update Store
                        </button>
                        <a href="list.php" class="btn btn-outline">
                            <i class="fas fa-times-circle"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <!-- Leaflet CSS and JS for map preview -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script src="../../assets/js/main.js"></script>
    <script>
        // Global variables
        let mapPreview = null;
        let marker = null;
        let searchTimeout = null;
        let selectedResultIndex = -1;
        let searchResults = [];
        let addressSearchInput, addressResultsDiv, searchLoadingIcon;
        
        // Wait for DOM to be ready
        document.addEventListener('DOMContentLoaded', function() {
            console.log('✅ Address Search initialized');
            
            // Address Search Functionality - Get elements
            addressSearchInput = document.getElementById('address-search');
            addressResultsDiv = document.getElementById('address-results');
            searchLoadingIcon = document.getElementById('search-loading');
            
            if (!addressSearchInput || !addressResultsDiv || !searchLoadingIcon) {
                console.error('❌ ERROR: Search elements not found!');
                return;
            }
            
            addressSearchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                // Clear previous timeout
                if (searchTimeout) {
                    clearTimeout(searchTimeout);
                }
                
                // Hide results if query is too short
                if (query.length < 3) {
                    addressResultsDiv.style.display = 'none';
                    searchLoadingIcon.style.display = 'none';
                    return;
                }
                
                // Show loading indicator
                searchLoadingIcon.style.display = 'block';
                
                // Wait for user to finish typing (500ms delay)
                searchTimeout = setTimeout(() => {
                    searchAddress(query);
                }, 500);
            });        // Show dropdown when focused
        addressSearchInput.addEventListener('focus', function() {
            const query = this.value.trim();
            if (query.length > 0 && addressResultsDiv.innerHTML !== '') {
                addressResultsDiv.style.display = 'block';
            }
        });
        
        // Keyboard navigation for results
        addressSearchInput.addEventListener('keydown', function(e) {
            const resultItems = addressResultsDiv.querySelectorAll('.result-item');
            
            if (resultItems.length === 0) return;
            
            // Arrow Down
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedResultIndex = Math.min(selectedResultIndex + 1, resultItems.length - 1);
                updateSelectedResult(resultItems);
            }
            // Arrow Up
            else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedResultIndex = Math.max(selectedResultIndex - 1, -1);
                updateSelectedResult(resultItems);
            }
            // Enter
            else if (e.key === 'Enter' && selectedResultIndex >= 0) {
                e.preventDefault();
                if (searchResults[selectedResultIndex]) {
                    selectAddress(searchResults[selectedResultIndex]);
                }
            }
            // Escape
            else if (e.key === 'Escape') {
                e.preventDefault();
                addressResultsDiv.style.display = 'none';
                selectedResultIndex = -1;
            }
        });
        
        function updateSelectedResult(resultItems) {
            resultItems.forEach((item, index) => {
                if (index === selectedResultIndex) {
                    item.style.background = '#f7fafc';
                    item.style.transform = 'translateX(5px)';
                    item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                } else {
                    item.style.background = 'white';
                    item.style.transform = 'translateX(0)';
                }
            });
        }
        
        // Search address using Nominatim API
        async function searchAddress(query) {
            try {
                // Show a "searching..." message immediately
                addressResultsDiv.innerHTML = '<div style="padding: 15px; text-align: center; color: #667eea;"><i class="fas fa-search fa-spin"></i> Searching...</div>';
                addressResultsDiv.style.display = 'block';
                
                // Enhanced search with address priority and Malaysia restriction
                const buildUrl = (searchQuery) => `https://nominatim.openstreetmap.org/search?` +
                    `format=json` +
                    `&q=${encodeURIComponent(searchQuery)}` +
                    `&addressdetails=1` +
                    `&limit=15` + // Increased limit
                    `&dedupe=1` +
                    `&countrycodes=my` + // Restrict to Malaysia
                    `&accept-language=en-US,en;q=0.9,ms;q=0.8`; // Prefer English/Malay
                
                let response = await fetch(buildUrl(query));
                let results = await response.json();
                
                // Helper to check validity
                const isValid = (res) => res && res.length > 0;

                // Fallback Strategy: Smart Recovery
                if (!isValid(results)) {
                    console.log('⚠️ Exact match failed, initiating smart recovery...');
                    
                    // Strategy 1: Smart Segment Filtering
                    // Split by comma and remove parts that look like units/floors
                    if (query.includes(',')) {
                        const parts = query.split(',').map(p => p.trim());
                        const cleanParts = parts.filter(part => {
                            const p = part.toLowerCase();
                            // Filter out unit/floor indicators
                            if (p.match(/^(?:no\.|lot|unit|level|floor|suite|blk|block)\s+/)) return false;
                            if (p.includes('floor') || p.includes('level')) return false;
                            // Filter out short alphanumeric codes (likely unit numbers like 1F-55, B-12-2)
                            // Matches strings that are mostly numbers/letters/dashes/slashes, without spaces (or very few)
                            if (part.match(/^[A-Z0-9\-\/#\.]+$/i) && part.length < 10) return false;
                            return true;
                        });

                        if (cleanParts.length > 0 && cleanParts.length < parts.length) {
                            const cleanQuery = cleanParts.join(', ');
                            console.log('Trying smart cleaned query:', cleanQuery);
                            response = await fetch(buildUrl(cleanQuery));
                            results = await response.json();
                        }
                    }

                    // Strategy 2: Landmark/Building Search (Iterate through parts)
                    if (!isValid(results) && query.includes(',')) {
                        const parts = query.split(',').map(p => p.trim());
                        // Sort parts by length (descending) - assume longer parts are building/street names
                        // But keep original order priority slightly? No, length is usually a good proxy for "Building Name" vs "1F"
                        const sortedParts = [...parts].sort((a, b) => b.length - a.length);
                        
                        for (const part of sortedParts) {
                            // Skip if it looks like a unit/floor
                            if (part.toLowerCase().match(/floor|level|unit|lot|^no\./)) continue;
                            if (part.length < 4) continue; // Skip short parts

                            console.log('Trying major segment:', part);
                            response = await fetch(buildUrl(part + ', Malaysia'));
                            const segmentResults = await response.json();
                            
                            // Only accept if we found something
                            if (isValid(segmentResults)) {
                                results = segmentResults;
                                break;
                            }
                        }
                    }
                    
                    // Strategy 3: Fallback to "Malaysia" append (Last Resort)
                    if (!isValid(results) && !query.toLowerCase().includes('malaysia')) {
                         response = await fetch(buildUrl(query + ', Malaysia'));
                         results = await response.json();
                    }
                }
                
                console.log(`✅ Found ${results.length} results for "${query}"`);
                
                searchLoadingIcon.style.display = 'none';
                
                if (results && results.length > 0) {
                    // Filter out only very broad results
                    const filteredResults = results.filter(r => {
                        // Only exclude continents (countries and regions are OK)
                        if (r.type === 'continent') return false;
                        return true;
                    });
                    
                    // Sort by relevance and importance
                    filteredResults.sort((a, b) => {
                        // Prioritize results with building/house numbers
                        const aHasNumber = a.address && (a.address.house_number || a.address.building);
                        const bHasNumber = b.address && (b.address.house_number || b.address.building);
                        if (aHasNumber && !bHasNumber) return -1;
                        if (!aHasNumber && bHasNumber) return 1;
                        
                        // Then sort by importance (higher = more relevant)
                        return (b.importance || 0) - (a.importance || 0);
                    });
                    
                    displaySearchResults(filteredResults.slice(0, 10)); // Show top 10 results
                } else {
                    console.log('⚠️ No results found');
                    addressResultsDiv.innerHTML = `
                        <div style="padding: 20px; text-align: center; color: #718096;">
                            <i class="fas fa-search" style="font-size: 32px; margin-bottom: 10px; opacity: 0.5;"></i>
                            <div style="font-weight: 600; margin-bottom: 5px;">No addresses found</div>
                            <div style="font-size: 13px;">Try a different search or be more specific</div>
                        </div>
                    `;
                    addressResultsDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('❌ Address search error:', error);
                searchLoadingIcon.style.display = 'none';
                addressResultsDiv.innerHTML = `
                    <div style="padding: 20px; text-align: center; color: #e53e3e;">
                        <i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <div style="font-weight: 600; margin-bottom: 5px;">Search Error</div>
                        <div style="font-size: 13px;">Please check your connection and try again</div>
                    </div>
                `;
                addressResultsDiv.style.display = 'block';
            }
        }
        
        // Display search results
        function displaySearchResults(results) {
            addressResultsDiv.innerHTML = '';
            searchResults = results; // Store for keyboard navigation
            selectedResultIndex = -1; // Reset selection
            
            // Add header showing result count
            const headerDiv = document.createElement('div');
            headerDiv.style.cssText = `
                padding: 8px 16px;
                background: #f8fafc;
                color: #64748b;
                font-weight: 600;
                display: flex;
                align-items: center;
                justify-content: space-between;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border-bottom: 1px solid #e2e8f0;
                border-radius: 12px 12px 0 0;
            `;
            headerDiv.innerHTML = `
                <span>${results.length} location${results.length !== 1 ? 's' : ''} found</span>
                <span style="font-size: 10px; opacity: 0.8;">
                    Use ↑↓ to navigate
                </span>
            `;
            addressResultsDiv.appendChild(headerDiv);
            
            results.forEach((result, index) => {
                const resultItem = document.createElement('div');
                resultItem.className = 'result-item';
                resultItem.style.cssText = `
                    padding: 12px 16px;
                    cursor: pointer;
                    border-bottom: 1px solid #f1f5f9;
                    transition: all 0.15s ease;
                    background: white;
                `;
                
                // Extract key address components
                const addr = result.address || {};
                const houseNumber = addr.house_number || '';
                const road = addr.road || addr.pedestrian || addr.street || addr.address29 || '';
                const suburb = addr.suburb || addr.neighbourhood || '';
                const city = addr.city || addr.town || addr.village || addr.municipality || '';
                const state = addr.state || addr.province || addr.region || '';
                const zip = addr.postcode || '';
                const country = addr.country || '';
                
                // Build main address line (Street + Number)
                let mainLine = '';
                if (result.name) {
                    mainLine = result.name;
                } else if (houseNumber || road) {
                    mainLine = [houseNumber, road].filter(Boolean).join(' ');
                } else if (suburb) {
                    mainLine = suburb;
                } else {
                    mainLine = city || state || country;
                }
                
                // Build secondary address line (City, State, Zip, Country)
                let secondaryParts = [];
                if (suburb && mainLine !== suburb) secondaryParts.push(suburb);
                if (city && mainLine !== city) secondaryParts.push(city);
                if (state) secondaryParts.push(state);
                if (zip) secondaryParts.push(zip);
                if (country) secondaryParts.push(country);
                
                const secondaryLine = secondaryParts.join(', ');
                
                // Determine location type icon
                let typeIcon = 'fa-map-marker-alt';
                let typeColor = '#667eea';
                let typeBg = '#eef2ff';
                
                if (result.type === 'building' || addr.building) {
                    typeIcon = 'fa-building';
                    typeColor = '#48bb78';
                    typeBg = '#f0fff4';
                } else if (result.type === 'house' || result.type === 'residential') {
                    typeIcon = 'fa-home';
                    typeColor = '#4299e1';
                    typeBg = '#ebf8ff';
                } else if (result.type === 'city' || result.type === 'town') {
                    typeIcon = 'fa-city';
                    typeColor = '#ed8936';
                    typeBg = '#fffaf0';
                }
                
                resultItem.innerHTML = `
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <div style="width: 36px; height: 36px; background: ${typeBg}; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class="fas ${typeIcon}" style="color: ${typeColor}; font-size: 16px;"></i>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; color: #1e293b; font-size: 14px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                ${mainLine}
                            </div>
                            <div style="font-size: 12px; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                ${secondaryLine}
                            </div>
                        </div>
                    </div>
                `;
                
                // Hover effects
                resultItem.addEventListener('mouseenter', function() {
                    this.style.background = '#f8fafc';
                });
                
                resultItem.addEventListener('mouseleave', function() {
                    this.style.background = 'white';
                });
                
                // Click to select address
                resultItem.addEventListener('click', function() {
                    selectAddress(result);
                });
                
                // Remove border from last item
                if (index === results.length - 1) {
                    resultItem.style.borderBottom = 'none';
                }
                
                addressResultsDiv.appendChild(resultItem);
            });
            
            addressResultsDiv.style.display = 'block';
        }
        
        // Select and fill address details
        function selectAddress(result) {
            const addr = result.address || {};
            
            // Construct street address from API result
            let streetAddress = addr.road || addr.pedestrian || addr.address29 || addr.suburb || '';
            
            // Fallback if no specific street field found
            if (!streetAddress) {
                streetAddress = result.display_name.split(',')[0];
            }

            // --- INTELLIGENT PREFIX PRESERVATION ---
            // Get the user's original query to find unit/floor/lot info that API missed
            const originalQuery = document.getElementById('address-search').value.trim();
            
            if (originalQuery) {
                // 1. Extract potential prefix parts (Unit, Floor, Lot, etc.)
                // Matches things like "1F-55", "Lot 123", "Unit 5", "Level 2", "No. 8" at the start
                // It stops when it hits a comma or the street name we found
                const prefixMatch = originalQuery.match(/^(.+?)(?:,|\s+(?:Jalan|Lorong|Road|Rd|Ave|Street|St|Persiaran))/i);
                
                if (prefixMatch) {
                    const potentialPrefix = prefixMatch[1].trim();
                    
                    // Only prepend if the API result doesn't already contain this info
                    // and if it looks like a unit number (digits, dashes, slashes, or specific keywords)
                    const isUnitInfo = potentialPrefix.match(/\d/) || 
                                      potentialPrefix.match(/^(?:lot|unit|level|floor|no\.|suite)/i);
                                      
                    if (isUnitInfo && !streetAddress.toLowerCase().includes(potentialPrefix.toLowerCase())) {
                        streetAddress = potentialPrefix + ', ' + streetAddress;
                    }
                }
            }
            // ---------------------------------------

            // Fill address fields
            document.getElementById('address').value = streetAddress;
            document.getElementById('city').value = addr.city || addr.town || addr.village || addr.county || '';
            document.getElementById('state').value = addr.state || addr.region || '';
            document.getElementById('zip_code').value = addr.postcode || '';
            
            // Fill coordinates
            document.getElementById('latitude').value = parseFloat(result.lat).toFixed(6);
            document.getElementById('longitude').value = parseFloat(result.lon).toFixed(6);
            
            // Update search input to show selected address
            addressSearchInput.value = result.display_name;
            
            // Hide results
            addressResultsDiv.style.display = 'none';
            
            // Add success animation to filled fields
            const filledFields = ['address', 'city', 'state', 'zip_code', 'latitude', 'longitude'];
            filledFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field && field.value) {
                    field.classList.add('success');
                    setTimeout(() => field.classList.remove('success'), 2000);
                }
            });
            
            // Show map preview
            showMapPreview(result.lat, result.lon);
            
            // Show success notification
            showNotification('✓ Address selected! All fields have been filled automatically.', 'success');
        }
        
        // Close results when clicking outside
        document.addEventListener('click', function(event) {
            if (!addressSearchInput.contains(event.target) && !addressResultsDiv.contains(event.target)) {
                addressResultsDiv.style.display = 'none';
            }
        });
        
        // Add smooth scroll to errors (moved from separate DOMContentLoaded)
        const errorAlert = document.querySelector('.alert-error');
        if (errorAlert) {
            errorAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        // Add success class animation when inputs are filled
        const inputs = document.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                if (this.value && this.value.trim() !== '') {
                    this.classList.add('success');
                    setTimeout(() => this.classList.remove('success'), 2000);
                }
            });
        });
        
        // Show notification function
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? '#48bb78' : type === 'error' ? '#f56565' : '#ed8936'};
                color: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                z-index: 10000;
                animation: slideInRight 0.3s ease-out;
                max-width: 400px;
            `;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.3s ease-out';
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }
        
        // Add keyframe animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from {
                    transform: translateX(400px);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
            @keyframes slideOutRight {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(400px);
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
        
        // Show map preview with marker
        function showMapPreview(lat, lon) {
            const mapContainer = document.getElementById('map-preview');
            mapContainer.style.display = 'block';
            
            // Initialize map if not already done
            if (!mapPreview) {
                mapPreview = L.map('map-preview').setView([lat, lon], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors',
                    maxZoom: 19
                }).addTo(mapPreview);
            } else {
                mapPreview.setView([lat, lon], 15);
            }
            
            // Remove existing marker
            if (marker) {
                mapPreview.removeLayer(marker);
            }
            
            // Add new marker
            marker = L.marker([lat, lon]).addTo(mapPreview)
                .bindPopup('Store Location')
                .openPopup();
            
            // Allow clicking on map to set coordinates
            mapPreview.on('click', function(e) {
                document.getElementById('latitude').value = e.latlng.lat.toFixed(6);
                document.getElementById('longitude').value = e.latlng.lng.toFixed(6);
                
                if (marker) {
                    mapPreview.removeLayer(marker);
                }
                marker = L.marker([e.latlng.lat, e.latlng.lng]).addTo(mapPreview)
                    .bindPopup('Store Location')
                    .openPopup();
            });
        }
        
        // Update map preview when coordinates are manually entered
        document.getElementById('latitude').addEventListener('change', updateMapFromCoordinates);
        document.getElementById('longitude').addEventListener('change', updateMapFromCoordinates);
        
        function updateMapFromCoordinates() {
            const lat = parseFloat(document.getElementById('latitude').value);
            const lon = parseFloat(document.getElementById('longitude').value);
            
            if (lat && lon && !isNaN(lat) && !isNaN(lon)) {
                showMapPreview(lat, lon);
            }
        }
        
        // Initialize map if coordinates exist
        const initLat = parseFloat(document.getElementById('latitude').value);
        const initLon = parseFloat(document.getElementById('longitude').value);
        if (initLat && initLon && !isNaN(initLat) && !isNaN(initLon)) {
            showMapPreview(initLat, initLon);
        }
        
        console.log('✅ All event listeners attached successfully!');
        
        }); // End of DOMContentLoaded
    </script>
</body>
</html>