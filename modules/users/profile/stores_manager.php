<?php
/**
 * Store Access Management Module
 * Comprehensive store assignment and access control system
 */

// Enable output buffering and compression for faster page delivery
ob_start();
if (extension_loaded('zlib')) {
    ini_set('zlib.output_compression', 'On');
}

require_once '../../../config.php';
require_once '../../../db.php';
require_once '../../../functions.php';

session_start();

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = getDB();
$currentUserId = $_SESSION['user_id'];

// Get user info for permission checking
$currentUser = $db->read('users', $currentUserId);
$userRole = $currentUser['role'] ?? 'user';
$isAdmin = $userRole === 'admin';
$isManager = $userRole === 'manager' || $isAdmin;

// Handle POST actions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_user_stores':
            if (!$isManager) {
                $message = 'You do not have permission to manage store access';
                $messageType = 'error';
                break;
            }
            
            $userId = $_POST['user_id'] ?? '';
            $storeIds = $_POST['store_ids'] ?? [];
            $storeRoles = $_POST['store_roles'] ?? [];
            
            if (empty($userId)) {
                $message = 'User selection required';
                $messageType = 'error';
                break;
            }
            
            try {
                // Get current assignments
                $existing = $db->readAll('user_stores', [['user_id', '==', $userId]]);
                $existingMap = [];
                foreach ($existing as $e) {
                    $existingMap[(string)$e['store_id']] = $e;
                }
                
                // Build target assignments
                $targetMap = [];
                foreach ($storeIds as $sid) {
                    $targetMap[$sid] = $storeRoles[$sid] ?? 'employee';
                }
                
                // Add new assignments
                foreach ($targetMap as $sid => $role) {
                    if (!isset($existingMap[$sid])) {
                        $db->create('user_stores', [
                            'user_id' => $userId,
                            'store_id' => $sid,
                            'role' => $role,
                            'created_at' => date('c')
                        ]);
                    } else {
                        // Update role if changed
                        if (($existingMap[$sid]['role'] ?? '') !== $role) {
                            $db->update('user_stores', $existingMap[$sid]['id'], [
                                'role' => $role,
                                'updated_at' => date('c')
                            ]);
                        }
                    }
                }
                
                // Remove assignments not in target
                foreach ($existingMap as $sid => $assignment) {
                    if (!isset($targetMap[$sid])) {
                        $db->delete('user_stores', $assignment['id']);
                    }
                }
                
                // Log the action
                $db->create('user_activities', [
                    'user_id' => $currentUserId,
                    'action_type' => 'store_access_updated',
                    'description' => "Updated store access for user {$userId}",
                    'metadata' => json_encode(['user_id' => $userId, 'stores' => $targetMap]),
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                    'created_at' => date('c')
                ]);
                
                $message = 'Store access updated successfully';
                $messageType = 'success';
            } catch (Exception $e) {
                error_log('Update store access error: ' . $e->getMessage());
                $message = 'An error occurred while updating store access';
                $messageType = 'error';
            }
            break;
            
        case 'create_store':
            if (!$isAdmin) {
                $message = 'You do not have permission to create stores';
                $messageType = 'error';
                break;
            }
            
            $storeName = trim($_POST['store_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $manager = trim($_POST['manager'] ?? '');
            
            if (empty($storeName)) {
                $message = 'Store name is required';
                $messageType = 'error';
                break;
            }
            
            try {
                $storeData = [
                    'name' => $storeName,
                    'address' => $address,
                    'phone' => $phone,
                    'manager' => $manager,
                    'active' => true,
                    'created_at' => date('c'),
                    'updated_at' => date('c')
                ];
                
                $storeId = $db->create('stores', $storeData);
                
                if ($storeId) {
                    // Log the action
                    $db->create('user_activities', [
                        'user_id' => $currentUserId,
                        'action_type' => 'store_created',
                        'description' => "Created new store: {$storeName}",
                        'metadata' => json_encode($storeData),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'created_at' => date('c')
                    ]);
                    
                    $message = "Store '{$storeName}' created successfully";
                    $messageType = 'success';
                } else {
                    $message = 'Failed to create store';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                error_log('Create store error: ' . $e->getMessage());
                $message = 'An error occurred while creating store';
                $messageType = 'error';
            }
            break;
            
        case 'update_store':
            if (!$isAdmin) {
                $message = 'You do not have permission to update stores';
                $messageType = 'error';
                break;
            }
            
            $storeId = $_POST['store_id'] ?? '';
            $storeName = trim($_POST['store_name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $manager = trim($_POST['manager'] ?? '');
            $active = isset($_POST['active']);
            
            if (empty($storeId) || empty($storeName)) {
                $message = 'Store ID and name are required';
                $messageType = 'error';
                break;
            }
            
            try {
                $update = [
                    'name' => $storeName,
                    'address' => $address,
                    'phone' => $phone,
                    'manager' => $manager,
                    'active' => $active,
                    'updated_at' => date('c')
                ];
                
                $result = $db->update('stores', $storeId, $update);
                
                if ($result) {
                    // Log the action
                    $db->create('user_activities', [
                        'user_id' => $currentUserId,
                        'action_type' => 'store_updated',
                        'description' => "Updated store: {$storeName}",
                        'metadata' => json_encode($update),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                        'created_at' => date('c')
                    ]);
                    
                    $message = 'Store updated successfully';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update store';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                error_log('Update store error: ' . $e->getMessage());
                $message = 'An error occurred while updating store';
                $messageType = 'error';
            }
            break;
    }
}

// Get stores (limit for performance)
$allStores = [];
try {
    $allStores = $db->readAll('stores', [], ['name', 'ASC'], 100);
} catch (Exception $e) {
    error_log('Fetch stores error: ' . $e->getMessage());
}

// Get users (limit for performance)
$allUsers = [];
try {
    $allUsers = $db->readAll('users', [], ['first_name', 'ASC'], 100);
} catch (Exception $e) {
    error_log('Fetch users error: ' . $e->getMessage());
}

// Get store assignments (only for stores and users we loaded)
$userStoreMap = [];
try {
    // Only load assignments for the stores we're showing
    $storeIds = array_map(function($s) { return $s['id'] ?? ''; }, $allStores);
    $storeIds = array_filter($storeIds);
    
    if (!empty($storeIds)) {
        // Load assignments in chunks if needed
        $allAssignments = $db->readAll('user_stores', [], null, 500);
        foreach ($allAssignments as $a) {
            $uid = $a['user_id'] ?? '';
            $sid = $a['store_id'] ?? '';
            if ($uid && $sid && in_array($sid, $storeIds)) {
                if (!isset($userStoreMap[$uid])) {
                    $userStoreMap[$uid] = [];
                }
                $userStoreMap[$uid][$sid] = $a;
            }
        }
    }
} catch (Exception $e) {
    error_log('Fetch assignments error: ' . $e->getMessage());
}

// Get selected user for editing
$selectedUserId = $_GET['edit_user'] ?? '';
$selectedUser = null;
$selectedUserStores = [];

if (!empty($selectedUserId)) {
    try {
        $selectedUser = $db->read('users', $selectedUserId);
        $selectedUserStores = $userStoreMap[$selectedUserId] ?? [];
    } catch (Exception $e) {
        error_log('Fetch selected user error: ' . $e->getMessage());
    }
}

// Get selected store for editing
$selectedStoreId = $_GET['edit_store'] ?? '';
$selectedStore = null;

if (!empty($selectedStoreId)) {
    try {
        $selectedStore = $db->read('stores', $selectedStoreId);
    } catch (Exception $e) {
        error_log('Fetch selected store error: ' . $e->getMessage());
    }
}

$page_title = 'Store Access Management - Inventory System';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link rel="stylesheet" href="../../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .stores-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .stores-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .stores-header h1 {
            margin: 0 0 10px 0;
            font-size: 2rem;
        }
        
        .section-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        
        .section-card h3 {
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2c3e50;
        }
        
        .stores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .store-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .store-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }
        
        .store-card-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .store-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }
        
        .store-info h4 {
            margin: 0;
            color: #2c3e50;
        }
        
        .store-meta {
            font-size: 13px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .store-details {
            margin: 15px 0;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .store-detail-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .store-detail-item i {
            color: #667eea;
            width: 20px;
        }
        
        .users-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .user-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 15px;
            transition: all 0.3s ease;
        }
        
        .user-card:hover {
            border-color: #667eea;
        }
        
        .user-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .access-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .access-granted { background: #dcfdf7; color: #065f46; }
        .access-denied { background: #fef2f2; color: #991b1b; }
        
        .role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .role-employee { background: #dbeafe; color: #1e40af; }
        .role-manager { background: #fce7f3; color: #be185d; }
        .role-owner { background: #fef3c7; color: #92400e; }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
            font-size: 14px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
        }
        
        .form-group select,
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .checkbox-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }
        
        .checkbox-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            transition: all 0.3s ease;
        }
        
        .checkbox-card:has(input:checked) {
            border-color: #667eea;
            background: #f8fafc;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #f0fdf4;
            border-left: 4px solid #22c55e;
            color: #166534;
        }
        
        .alert-error {
            background: #fef2f2;
            border-left: 4px solid #ef4444;
            color: #991b1b;
        }
        
        @media (max-width: 768px) {
            .stores-grid,
            .users-list {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include '../../../includes/dashboard_header.php'; ?>
    
    <div class="container">
        <div class="stores-container">
            <!-- Header -->
            <div class="stores-header">
                <h1><i class="fas fa-store"></i> Store Access Management</h1>
                <p>Manage store locations and user access control</p>
            </div>
            
            <!-- Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <!-- Edit User Store Access -->
            <?php if ($selectedUser && $isManager): ?>
                <div class="section-card">
                    <h3>
                        <i class="fas fa-user-edit"></i> 
                        Edit Store Access for <?php echo htmlspecialchars(($selectedUser['first_name'] ?? '') . ' ' . ($selectedUser['last_name'] ?? '')); ?>
                    </h3>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_user_stores">
                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($selectedUserId); ?>">
                        
                        <div class="checkbox-grid">
                            <?php foreach ($allStores as $store): ?>
                                <?php 
                                $hasAccess = isset($selectedUserStores[$store['id']]);
                                $currentRole = $hasAccess ? ($selectedUserStores[$store['id']]['role'] ?? 'employee') : 'employee';
                                ?>
                                <div class="checkbox-card">
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="store_ids[]" 
                                               value="<?php echo htmlspecialchars($store['id']); ?>" 
                                               id="store_<?php echo htmlspecialchars($store['id']); ?>"
                                               <?php echo $hasAccess ? 'checked' : ''; ?>>
                                        <label for="store_<?php echo htmlspecialchars($store['id']); ?>" style="margin: 0; font-weight: 600;">
                                            <?php echo htmlspecialchars($store['name']); ?>
                                        </label>
                                    </div>
                                    <div style="margin-left: 28px;">
                                        <label style="font-size: 12px; margin-bottom: 4px;">Role:</label>
                                        <select name="store_roles[<?php echo htmlspecialchars($store['id']); ?>]" style="width: 100%; padding: 6px; font-size: 13px;">
                                            <option value="employee" <?php echo $currentRole === 'employee' ? 'selected' : ''; ?>>Employee</option>
                                            <option value="manager" <?php echo $currentRole === 'manager' ? 'selected' : ''; ?>>Manager</option>
                                            <option value="owner" <?php echo $currentRole === 'owner' ? 'selected' : ''; ?>>Owner</option>
                                        </select>
                                    </div>
                                    <?php if (!empty($store['address'])): ?>
                                        <div style="font-size: 12px; color: #6b7280; margin-top: 8px;">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($store['address']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div style="margin-top: 25px; display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <a href="stores.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- Edit Store -->
            <?php if ($selectedStore && $isAdmin): ?>
                <div class="section-card">
                    <h3><i class="fas fa-store-alt"></i> Edit Store</h3>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_store">
                        <input type="hidden" name="store_id" value="<?php echo htmlspecialchars($selectedStoreId); ?>">
                        
                        <div class="form-group">
                            <label for="store_name">Store Name *</label>
                            <input type="text" name="store_name" id="store_name" 
                                   value="<?php echo htmlspecialchars($selectedStore['name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Address</label>
                            <input type="text" name="address" id="address" 
                                   value="<?php echo htmlspecialchars($selectedStore['address'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone</label>
                            <input type="text" name="phone" id="phone" 
                                   value="<?php echo htmlspecialchars($selectedStore['phone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="manager">Manager</label>
                            <input type="text" name="manager" id="manager" 
                                   value="<?php echo htmlspecialchars($selectedStore['manager'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-item">
                                <input type="checkbox" name="active" value="1" 
                                       <?php echo ($selectedStore['active'] ?? true) ? 'checked' : ''; ?>>
                                Active
                            </label>
                        </div>
                        
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Store
                            </button>
                            <a href="stores.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- Stores List -->
            <div class="section-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="margin: 0;"><i class="fas fa-store-alt"></i> All Stores</h3>
                    <?php if ($isAdmin): ?>
                        <button onclick="document.getElementById('create-store-form').style.display='block'" class="btn btn-success">
                            <i class="fas fa-plus"></i> Add Store
                        </button>
                    <?php endif; ?>
                </div>
                
                <!-- Create Store Form (Hidden) -->
                <?php if ($isAdmin): ?>
                    <div id="create-store-form" style="display: none; background: #f8fafc; padding: 20px; border-radius: 12px; margin-bottom: 20px;">
                        <h4 style="margin: 0 0 15px 0;">Create New Store</h4>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_store">
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="new_store_name">Store Name *</label>
                                    <input type="text" name="store_name" id="new_store_name" required>
                                </div>
                                
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="new_address">Address</label>
                                    <input type="text" name="address" id="new_address">
                                </div>
                                
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="new_phone">Phone</label>
                                    <input type="text" name="phone" id="new_phone">
                                </div>
                                
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="new_manager">Manager</label>
                                    <input type="text" name="manager" id="new_manager">
                                </div>
                            </div>
                            
                            <div style="margin-top: 15px; display: flex; gap: 10px;">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Create Store
                                </button>
                                <button type="button" onclick="document.getElementById('create-store-form').style.display='none'" class="btn btn-secondary">
                                    Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
                
                <div class="stores-grid">
                    <?php foreach ($allStores as $store): ?>
                        <?php 
                        // Count users with access
                        $userCount = 0;
                        foreach ($userStoreMap as $assignments) {
                            if (isset($assignments[$store['id']])) {
                                $userCount++;
                            }
                        }
                        ?>
                        <div class="store-card">
                            <div class="store-card-header">
                                <div class="store-icon">
                                    <i class="fas fa-store"></i>
                                </div>
                                <div class="store-info">
                                    <h4><?php echo htmlspecialchars($store['name']); ?></h4>
                                    <div class="store-meta">
                                        <span class="access-badge <?php echo ($store['active'] ?? true) ? 'access-granted' : 'access-denied'; ?>">
                                            <?php echo ($store['active'] ?? true) ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="store-details">
                                <?php if (!empty($store['address'])): ?>
                                    <div class="store-detail-item">
                                        <i class="fas fa-map-marker-alt"></i>
                                        <span><?php echo htmlspecialchars($store['address']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($store['phone'])): ?>
                                    <div class="store-detail-item">
                                        <i class="fas fa-phone"></i>
                                        <span><?php echo htmlspecialchars($store['phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($store['manager'])): ?>
                                    <div class="store-detail-item">
                                        <i class="fas fa-user-tie"></i>
                                        <span><?php echo htmlspecialchars($store['manager']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="store-detail-item">
                                    <i class="fas fa-users"></i>
                                    <span><?php echo $userCount; ?> user(s) with access</span>
                                </div>
                            </div>
                            
                            <?php if ($isAdmin): ?>
                                <a href="?edit_store=<?php echo htmlspecialchars($store['id']); ?>" class="btn btn-primary" style="width: 100%; justify-content: center;">
                                    <i class="fas fa-edit"></i> Edit Store
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Users List -->
            <?php if ($isManager): ?>
                <div class="section-card">
                    <h3><i class="fas fa-users"></i> User Store Access</h3>
                    <div class="users-list">
                        <?php foreach ($allUsers as $user): ?>
                            <?php 
                            $userStores = $userStoreMap[$user['id']] ?? [];
                            $storeCount = count($userStores);
                            ?>
                            <div class="user-card">
                                <div class="user-card-header">
                                    <div class="user-avatar">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div>
                                        <h4 style="margin: 0; font-size: 14px;"><?php echo htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')); ?></h4>
                                        <div style="font-size: 12px; color: #6b7280; margin-top: 3px;">
                                            <?php echo $storeCount; ?> store(s)
                                        </div>
                                    </div>
                                </div>
                                <a href="?edit_user=<?php echo htmlspecialchars($user['id']); ?>" class="btn btn-primary" style="width: 100%; justify-content: center; font-size: 13px; padding: 8px;">
                                    <i class="fas fa-edit"></i> Manage Access
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="../../../assets/js/main.js"></script>
</body>
</html>
