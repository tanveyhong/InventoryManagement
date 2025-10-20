<?php
// Add New Store Page
require_once '../../config.php';
require_once '../../db.php';
require_once '../../functions.php';
require_once '../../activity_logger.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: ../users/login.php');
    exit;
}

// Check permission to manage stores
requirePermission('manage_stores', '../../index.php');

$db = getDB();
$errors = [];
$success = false;

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
    $latitude = sanitizeInput($_POST['latitude'] ?? '');
    $longitude = sanitizeInput($_POST['longitude'] ?? '');
    $store_type = sanitizeInput($_POST['store_type'] ?? 'retail');
    $region_id = sanitizeInput($_POST['region_id'] ?? '');
    $opening_hours = sanitizeInput($_POST['opening_hours'] ?? '');
    $closing_hours = sanitizeInput($_POST['closing_hours'] ?? '');
    $square_footage = sanitizeInput($_POST['square_footage'] ?? '');
    $max_capacity = sanitizeInput($_POST['max_capacity'] ?? '');
    
    // POS Integration fields
    $has_pos = isset($_POST['has_pos']) ? 1 : 0;
    $pos_terminal_id = sanitizeInput($_POST['pos_terminal_id'] ?? '');
    $pos_type = sanitizeInput($_POST['pos_type'] ?? 'quick_service');
    $pos_notes = sanitizeInput($_POST['pos_notes'] ?? '');
    
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
                'latitude' => $latitude ? floatval($latitude) : null,
                'longitude' => $longitude ? floatval($longitude) : null,
                'store_type' => $store_type,
                'region_id' => $region_id,
                'opening_hours' => $opening_hours,
                'closing_hours' => $closing_hours,
                'square_footage' => $square_footage ? intval($square_footage) : null,
                'max_capacity' => $max_capacity ? intval($max_capacity) : null,
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'active' => 1,
                'status' => 'active',
                // POS Integration
                'has_pos' => $has_pos,
                'pos_terminal_id' => $pos_terminal_id,
                'pos_type' => $pos_type,
                'pos_notes' => $pos_notes,
                'pos_enabled_at' => $has_pos ? date('c') : null
            ];
            $result = $db->create('stores', $storeData);
            if ($result) {
                // If POS is enabled, also sync to SQL database for POS usage
                if ($has_pos) {
                    try {
                        require_once '../../sql_db.php';
                        $sqlDb = getSQLDB();
                        
                        $sqlDb->execute(
                            "INSERT OR REPLACE INTO stores (name, code, address, city, phone, manager, firebase_id, has_pos, pos_terminal_id, pos_type, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))",
                            [
                                $name, 
                                $code, 
                                $address, 
                                $city, 
                                $phone, 
                                $manager_name, 
                                $result, 
                                1, 
                                $pos_terminal_id, 
                                $pos_type
                            ]
                        );
                        error_log("Store synced to SQL for POS: {$name} (Firebase ID: {$result})");
                    } catch (Exception $e) {
                        error_log("Failed to sync store to SQL: " . $e->getMessage());
                        // Don't fail the whole operation if SQL sync fails
                    }
                }
                
                // Log the activity
                error_log("Store created with ID: {$result}, Name: {$name}, User: {$_SESSION['user_id']}");
                
                $activityResult = logStoreActivity('created', $result, $name);
                error_log("Activity logging result: " . ($activityResult ? 'SUCCESS' : 'FAILED'));
                
                addNotification('Store created successfully!' . ($has_pos ? ' POS integration enabled.' : ''), 'success');
                header('Location: list.php');
                exit;
            } else {
                $errors[] = 'Failed to create store. Please try again.';
            }
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
            if (function_exists('debugLog')) {
                debugLog('Store creation error', ['error' => $e->getMessage(), 'user_id' => $_SESSION['user_id']]);
            }
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
        
        #geocode-btn {
            white-space: nowrap;
        }
        
        #geocode-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
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
    $header_title = "Add New Store";
    $header_subtitle = "Create a new store location and assign details.";
    $header_icon = "fas fa-store-alt";
    $show_compact_toggle = false;
    $header_stats = [];
    include '../../includes/dashboard_header.php'; 
    ?>
    
    <div class="container">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-store-alt"></i> Add New Store</h1>
                <p>Create a new store location with complete details and map coordinates</p>
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
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="store_type">Store Type: <span class="required">*</span></label>
                                <select id="store_type" name="store_type" required>
                                    <option value="retail" <?php echo (($_POST['store_type'] ?? 'retail') === 'retail') ? 'selected' : ''; ?>>Retail Store</option>
                                    <option value="warehouse" <?php echo (($_POST['store_type'] ?? '') === 'warehouse') ? 'selected' : ''; ?>>Warehouse</option>
                                    <option value="distribution" <?php echo (($_POST['store_type'] ?? '') === 'distribution') ? 'selected' : ''; ?>>Distribution Center</option>
                                    <option value="flagship" <?php echo (($_POST['store_type'] ?? '') === 'flagship') ? 'selected' : ''; ?>>Flagship Store</option>
                                    <option value="outlet" <?php echo (($_POST['store_type'] ?? '') === 'outlet') ? 'selected' : ''; ?>>Outlet</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="region_id">Region:</label>
                                <select id="region_id" name="region_id">
                                    <option value="">Select Region</option>
                                    <?php
                                    $regions = $db->readAll('regions', [['active', '==', 1]]);
                                    foreach ($regions as $region):
                                    ?>
                                        <option value="<?php echo htmlspecialchars($region['id']); ?>" 
                                                <?php echo (($_POST['region_id'] ?? '') === $region['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($region['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small>Assign store to a region for reporting</small>
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
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="latitude">Latitude:</label>
                                <input type="number" id="latitude" name="latitude" step="0.000001" 
                                       value="<?php echo htmlspecialchars($_POST['latitude'] ?? ''); ?>" 
                                       placeholder="e.g., 40.7128">
                                <small>Auto-filled when address is geocoded</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="longitude">Longitude:</label>
                                <input type="number" id="longitude" name="longitude" step="0.000001" 
                                       value="<?php echo htmlspecialchars($_POST['longitude'] ?? ''); ?>" 
                                       placeholder="e.g., -74.0060">
                                <small>Auto-filled when address is geocoded</small>
                            </div>
                            
                            <div class="form-group" style="display: flex; align-items: flex-end;">
                                <button type="button" id="geocode-btn" class="btn btn-secondary" onclick="geocodeAddress()">
                                    <i class="fas fa-map-marker-alt"></i> Find Coordinates
                                </button>
                            </div>
                        </div>
                        
                        <div id="map-preview" class="map-preview" style="display: none; height: 300px; margin-top: 15px; border: 1px solid #ddd; border-radius: 8px;"></div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-address-book" style="color: #667eea;"></i> Contact Information</h3>
                        
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
                        <h3><i class="fas fa-clock" style="color: #667eea;"></i> Operating Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="opening_hours">Opening Hours:</label>
                                <input type="time" id="opening_hours" name="opening_hours" 
                                       value="<?php echo htmlspecialchars($_POST['opening_hours'] ?? '09:00'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="closing_hours">Closing Hours:</label>
                                <input type="time" id="closing_hours" name="closing_hours" 
                                       value="<?php echo htmlspecialchars($_POST['closing_hours'] ?? '18:00'); ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="square_footage">Square Footage:</label>
                                <input type="number" id="square_footage" name="square_footage" 
                                       value="<?php echo htmlspecialchars($_POST['square_footage'] ?? ''); ?>" 
                                       min="0" placeholder="e.g., 5000">
                                <small>Store area in square feet</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="max_capacity">Maximum Capacity:</label>
                                <input type="number" id="max_capacity" name="max_capacity" 
                                       value="<?php echo htmlspecialchars($_POST['max_capacity'] ?? ''); ?>" 
                                       min="0" placeholder="e.g., 100">
                                <small>Maximum customer capacity</small>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-cash-register" style="color: #667eea;"></i> POS Integration</h3>
                        
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" id="has_pos" name="has_pos" value="1" 
                                       <?php echo (isset($_POST['has_pos']) && $_POST['has_pos']) ? 'checked' : ''; ?>
                                       style="width: auto;">
                                <span><i class="fas fa-link"></i> Link POS System to this store</span>
                            </label>
                            <small>Enable if this store location has a Point of Sale (POS) system connected to our inventory</small>
                        </div>
                        
                        <div id="pos_details" style="<?php echo (isset($_POST['has_pos']) && $_POST['has_pos']) ? '' : 'display: none;'; ?> background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 15px;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="pos_terminal_id">POS Terminal ID:</label>
                                    <input type="text" id="pos_terminal_id" name="pos_terminal_id" 
                                           value="<?php echo htmlspecialchars($_POST['pos_terminal_id'] ?? ''); ?>" 
                                           placeholder="e.g., TERM-001">
                                    <small>Unique identifier for the POS terminal</small>
                                </div>
                                
                                <div class="form-group">
                                    <label for="pos_type">POS System Type:</label>
                                    <select id="pos_type" name="pos_type">
                                        <option value="quick_service" <?php echo (($_POST['pos_type'] ?? '') === 'quick_service') ? 'selected' : ''; ?>>Quick Service POS</option>
                                        <option value="integrated" <?php echo (($_POST['pos_type'] ?? '') === 'integrated') ? 'selected' : ''; ?>>Integrated System</option>
                                        <option value="third_party" <?php echo (($_POST['pos_type'] ?? '') === 'third_party') ? 'selected' : ''; ?>>Third Party POS</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="pos_notes">POS Integration Notes:</label>
                                <textarea id="pos_notes" name="pos_notes" rows="3" 
                                          placeholder="Any special notes about the POS integration..."><?php echo htmlspecialchars($_POST['pos_notes'] ?? ''); ?></textarea>
                            </div>
                            
                            <div style="background: white; padding: 15px; border-radius: 6px; border-left: 4px solid #667eea;">
                                <p style="margin: 0; font-size: 14px; color: #555;">
                                    <i class="fas fa-info-circle" style="color: #667eea;"></i> 
                                    <strong>Benefits:</strong> Sales from this POS will automatically update inventory, track transactions, and appear in reports.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3><i class="fas fa-sticky-note" style="color: #667eea;"></i> Additional Information</h3>
                        
                        <div class="form-group">
                            <label for="description">Description:</label>
                            <textarea id="description" name="description" rows="4" 
                                      maxlength="500" placeholder="Optional description or notes about the store"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn">
                            <i class="fas fa-plus-circle"></i> Create Store
                        </button>
                        <button type="submit" name="demo_add" class="btn btn-secondary">
                            <i class="fas fa-magic"></i> Quick Demo Add
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
            
            // POS Integration Toggle
            const hasPosCheckbox = document.getElementById('has_pos');
            const posDetailsDiv = document.getElementById('pos_details');
            
            if (hasPosCheckbox && posDetailsDiv) {
                hasPosCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        posDetailsDiv.style.display = 'block';
                    } else {
                        posDetailsDiv.style.display = 'none';
                    }
                });
            }
            
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
                
                // Enhanced search with address priority
                const url = `https://nominatim.openstreetmap.org/search?` +
                    `format=json` +
                    `&q=${encodeURIComponent(query)}` +
                    `&addressdetails=1` +
                    `&limit=10` + // Get more results
                    `&dedupe=1`; // Remove duplicate results
                
                const response = await fetch(url);
                const results = await response.json();
                
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
                    
                    displaySearchResults(filteredResults.slice(0, 8)); // Show top 8 results
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
                padding: 10px 16px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                font-weight: 600;
                display: flex;
                align-items: center;
                justify-content: space-between;
                font-size: 13px;
                border-radius: 12px 12px 0 0;
            `;
            headerDiv.innerHTML = `
                <span><i class="fas fa-map-marker-alt"></i> ${results.length} location${results.length !== 1 ? 's' : ''} found</span>
                <span style="font-size: 11px; opacity: 0.9;">
                    <i class="fas fa-keyboard"></i> ↑↓ Enter
                </span>
            `;
            addressResultsDiv.appendChild(headerDiv);
            
            results.forEach((result, index) => {
                const resultItem = document.createElement('div');
                resultItem.className = 'result-item';
                resultItem.style.cssText = `
                    padding: 10px 14px;
                    cursor: pointer;
                    border-bottom: 1px solid #f0f0f0;
                    transition: all 0.15s ease;
                    background: white;
                `;
                
                // Extract key address components
                const addr = result.address || {};
                const mainAddress = addr.road || addr.pedestrian || addr.address29 || addr.suburb || '';
                const city = addr.city || addr.town || addr.village || addr.municipality || '';
                const state = addr.state || addr.province || addr.region || '';
                const country = addr.country || '';
                const zip = addr.postcode || '';
                
                // Build compact address string
                let compactAddr = [];
                if (mainAddress) compactAddr.push(mainAddress);
                if (city) compactAddr.push(city);
                if (state) compactAddr.push(state);
                if (zip) compactAddr.push(zip);
                
                // If no structured address, use display name
                const displayText = compactAddr.length > 0 ? compactAddr.join(', ') : result.display_name;
                
                // Determine location type icon
                let typeIcon = 'fa-map-marker-alt';
                let typeColor = '#667eea';
                if (result.type === 'building' || addr.building) {
                    typeIcon = 'fa-building';
                    typeColor = '#48bb78';
                } else if (result.type === 'city' || result.type === 'town') {
                    typeIcon = 'fa-city';
                    typeColor = '#ed8936';
                } else if (result.type === 'administrative') {
                    typeIcon = 'fa-map';
                    typeColor = '#9f7aea';
                }
                
                resultItem.innerHTML = `
                    <div style="display: flex; gap: 10px; align-items: start;">
                        <div style="margin-top: 2px;">
                            <i class="fas ${typeIcon}" style="color: ${typeColor}; font-size: 14px;"></i>
                        </div>
                        <div style="flex: 1; min-width: 0;">
                            <div style="font-weight: 600; color: #2d3748; font-size: 13px; margin-bottom: 4px; line-height: 1.4;">
                                ${displayText}
                            </div>
                            ${country ? `
                                <div style="font-size: 11px; color: #667eea; margin-bottom: 3px;">
                                    <i class="fas fa-globe"></i> ${country}
                                </div>
                            ` : ''}
                            <div style="font-size: 11px; color: #a0aec0; font-family: 'Courier New', monospace;">
                                ${parseFloat(result.lat).toFixed(4)}, ${parseFloat(result.lon).toFixed(4)}
                            </div>
                        </div>
                        <div style="color: #cbd5e0; font-size: 11px; white-space: nowrap;">
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </div>
                `;
                
                // Hover effects
                resultItem.addEventListener('mouseenter', function() {
                    this.style.background = '#f7fafc';
                    this.style.paddingLeft = '18px';
                    this.querySelector('.fa-chevron-right').style.color = '#667eea';
                });
                
                resultItem.addEventListener('mouseleave', function() {
                    this.style.background = 'white';
                    this.style.paddingLeft = '14px';
                    this.querySelector('.fa-chevron-right').style.color = '#cbd5e0';
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
            
            // Fill address fields
            document.getElementById('address').value = addr.road || addr.pedestrian || addr.address29 || '';
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
        
        // Geocode address using Nominatim API (OpenStreetMap)
        async function geocodeAddress() {
            const address = document.getElementById('address').value;
            const city = document.getElementById('city').value;
            const state = document.getElementById('state').value;
            const zipCode = document.getElementById('zip_code').value;
            
            if (!address && !city) {
                showNotification('Please enter at least an address or city to geocode.', 'warning');
                return;
            }
            
            const fullAddress = [address, city, state, zipCode].filter(Boolean).join(', ');
            const geocodeBtn = document.getElementById('geocode-btn');
            geocodeBtn.disabled = true;
            geocodeBtn.classList.add('loading');
            geocodeBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Geocoding...';
            
            try {
                const response = await fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(fullAddress)}&limit=1`);
                const data = await response.json();
                
                if (data && data.length > 0) {
                    const location = data[0];
                    const latInput = document.getElementById('latitude');
                    const lonInput = document.getElementById('longitude');
                    
                    latInput.value = location.lat;
                    lonInput.value = location.lon;
                    
                    // Add success animation
                    latInput.classList.add('success');
                    lonInput.classList.add('success');
                    setTimeout(() => {
                        latInput.classList.remove('success');
                        lonInput.classList.remove('success');
                    }, 2000);
                    
                    // Show map preview
                    showMapPreview(location.lat, location.lon);
                    
                    showNotification('✓ Location found! Coordinates added successfully.', 'success');
                } else {
                    showNotification('Location not found. Please check the address or enter coordinates manually.', 'error');
                }
            } catch (error) {
                console.error('Geocoding error:', error);
                showNotification('Error geocoding address. Please try again later.', 'error');
            } finally {
                geocodeBtn.disabled = false;
                geocodeBtn.classList.remove('loading');
                geocodeBtn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Find Coordinates';
            }
        }
        
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
        
        console.log('✅ All event listeners attached successfully!');
        
        }); // End of DOMContentLoaded
    </script>
</body>
</html>