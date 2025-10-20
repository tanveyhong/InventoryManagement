<?php
/**
 * Permission Indicator Component
 * Displays visual indicators for user permissions
 * Performance optimized with caching
 */

// Get current user permissions
$currentUserId = $_SESSION['user_id'] ?? null;
if (!$currentUserId) return;

// Use cached permissions
$cacheKey = "perm_indicator_{$currentUserId}";
if (!isset($_SESSION['_cache'][$cacheKey])) {
    $userPermissions = getUserPermissions($currentUserId);
    $currentUser = getUserInfo($currentUserId);
    $_SESSION['_cache'][$cacheKey] = [
        'permissions' => $userPermissions,
        'user' => $currentUser,
        'role' => $currentUser['role'] ?? 'user'
    ];
} else {
    $cached = $_SESSION['_cache'][$cacheKey];
    $userPermissions = $cached['permissions'];
    $currentUser = $cached['user'];
    $userRole = $cached['role'];
}

$userRole = $userRole ?? ($currentUser['role'] ?? 'user');

// Permission categories for display
$permissionCategories = [
    'Inventory' => [
        'manage_inventory' => ['icon' => 'boxes', 'label' => 'Manage Inventory'],
        'view_inventory' => ['icon' => 'eye', 'label' => 'View Inventory']
    ],
    'Stores' => [
        'manage_stores' => ['icon' => 'store', 'label' => 'Manage Stores']
    ],
    'Users' => [
        'manage_users' => ['icon' => 'users', 'label' => 'Manage Users']
    ],
    'System' => [
        'configure_system' => ['icon' => 'cog', 'label' => 'Configure System'],
        'view_reports' => ['icon' => 'chart-bar', 'label' => 'View Reports'],
        'manage_pos' => ['icon' => 'cash-register', 'label' => 'Manage POS']
    ]
];
?>

<style>
.permission-indicator {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 9999;
}

.permission-toggle-btn {
    background: rgba(102, 126, 234, 0.9);
    color: white;
    border: none;
    border-radius: 50%;
    width: 45px;
    height: 45px;
    font-size: 1.1rem;
    cursor: pointer;
    box-shadow: 0 2px 10px rgba(102, 126, 234, 0.3);
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.permission-toggle-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.5);
    background: rgba(102, 126, 234, 1);
}

.permission-panel {
    position: absolute;
    bottom: 75px;
    right: 0;
    background: white;
    border-radius: 15px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
    width: 350px;
    max-height: 500px;
    overflow-y: auto;
    display: none;
    animation: slideUp 0.3s ease;
}

.permission-panel.active {
    display: block;
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.permission-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 15px 15px 0 0;
}

.permission-header h3 {
    margin: 0 0 10px 0;
    font-size: 1.2rem;
}

.permission-role-badge {
    display: inline-block;
    padding: 5px 12px;
    background: rgba(255,255,255,0.2);
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.permission-body {
    padding: 20px;
}

.permission-category {
    margin-bottom: 20px;
}

.permission-category h4 {
    font-size: 0.9rem;
    color: #7f8c8d;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0 0 10px 0;
    font-weight: 600;
}

.permission-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 8px;
    border-left: 3px solid #e0e0e0;
}

.permission-item.granted {
    border-left-color: #27ae60;
    background: #e8f5e9;
}

.permission-item.denied {
    border-left-color: #e74c3c;
    background: #ffebee;
    opacity: 0.6;
}

.permission-icon {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}

.permission-item.granted .permission-icon {
    background: #27ae60;
    color: white;
}

.permission-item.denied .permission-icon {
    background: #e74c3c;
    color: white;
}

.permission-label {
    flex: 1;
    font-size: 0.9rem;
    color: #2c3e50;
    font-weight: 500;
}

.permission-status {
    font-size: 0.75rem;
    padding: 3px 8px;
    border-radius: 10px;
    font-weight: 600;
}

.permission-item.granted .permission-status {
    background: #27ae60;
    color: white;
}

.permission-item.denied .permission-status {
    background: #e74c3c;
    color: white;
}

.permission-footer {
    padding: 15px 20px;
    background: #f8f9fa;
    border-radius: 0 0 15px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.permission-footer a {
    color: #667eea;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9rem;
}

.permission-footer a:hover {
    text-decoration: underline;
}

@media (max-width: 768px) {
    .permission-panel {
        width: 90vw;
        right: 5vw;
    }
    
    .permission-indicator {
        right: 5vw;
    }
}
</style>

<div class="permission-indicator">
    <button class="permission-toggle-btn" onclick="togglePermissionPanel()" title="View Your Permissions">
        <i class="fas fa-info-circle"></i>
    </button>
    
    <div class="permission-panel" id="permissionPanel">
        <div class="permission-header">
            <h3><i class="fas fa-user-shield"></i> Your Permissions</h3>
            <span class="permission-role-badge">Role: <?php echo htmlspecialchars(ucfirst($userRole)); ?></span>
        </div>
        
        <div class="permission-body">
            <?php foreach ($permissionCategories as $category => $permissions): ?>
                <div class="permission-category">
                    <h4><?php echo htmlspecialchars($category); ?></h4>
                    <?php foreach ($permissions as $permKey => $permData): ?>
                        <?php 
                        $hasPermission = in_array($permKey, $userPermissions);
                        $itemClass = $hasPermission ? 'granted' : 'denied';
                        ?>
                        <div class="permission-item <?php echo $itemClass; ?>">
                            <div class="permission-icon">
                                <i class="fas fa-<?php echo htmlspecialchars($permData['icon']); ?>"></i>
                            </div>
                            <span class="permission-label">
                                <?php echo htmlspecialchars($permData['label']); ?>
                            </span>
                            <span class="permission-status">
                                <?php echo $hasPermission ? 'Granted' : 'Denied'; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="permission-footer">
            <div style="font-size: 0.85rem; color: #7f8c8d;">
                Total: <strong><?php echo count($userPermissions); ?></strong> permissions
            </div>
            <?php if (hasPermission($currentUserId, 'manage_users') || $userRole === 'admin'): ?>
                <a href="/InventorySystem/modules/users/roles.php">
                    <i class="fas fa-cog"></i> Manage Permissions
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function togglePermissionPanel() {
    const panel = document.getElementById('permissionPanel');
    panel.classList.toggle('active');
}

// Close panel when clicking outside
document.addEventListener('click', function(event) {
    const indicator = document.querySelector('.permission-indicator');
    const panel = document.getElementById('permissionPanel');
    
    if (!indicator.contains(event.target) && panel.classList.contains('active')) {
        panel.classList.remove('active');
    }
});

// Show notification if permission denied
window.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const error = urlParams.get('error');
    
    if (error === 'permission_denied') {
        alert('⛔ Access Denied\n\nYou don\'t have permission to access that resource.\nClick the shield icon to view your permissions.');
        togglePermissionPanel();
    }
});
</script>
