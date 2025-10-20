<?php
/**
 * Permission Helper Functions
 * Easy-to-use functions for checking user permissions throughout the application
 */

/**
 * Check if a user has a specific permission
 * 
 * @param string $userId User ID to check
 * @param string $permission Permission key to check (e.g., 'manage_inventory')
 * @return bool True if user has permission, false otherwise
 */
function hasPermission($userId, $permission) {
    global $db;
    
    if (empty($userId)) {
        return false;
    }
    
    try {
        $user = $db->read('users', $userId);
        if (!$user) {
            return false;
        }
        
        $role = strtolower($user['role'] ?? 'user');
        
        // Define default permissions by role
        $rolePermissions = [
            'admin' => [
                'view_reports',
                'manage_inventory',
                'manage_users',
                'manage_stores',
                'configure_system',
                'manage_pos',
                'view_analytics',
                'manage_alerts'
            ],
            'manager' => [
                'view_reports',
                'manage_inventory',
                'manage_stores',
                'manage_pos'
            ],
            'user' => [
                'view_reports'
            ]
        ];
        
        // Check if permission is in role defaults
        if (in_array($permission, $rolePermissions[$role] ?? [])) {
            return true;
        }
        
        // Check custom permission overrides
        $overrides = $user['permission_overrides'] ?? null;
        if (!empty($overrides)) {
            if (is_string($overrides)) {
                $overrides = json_decode($overrides, true);
            }
            if (is_array($overrides) && !empty($overrides[$permission])) {
                return true;
            }
        }
        
        return false;
    } catch (Exception $e) {
        error_log('hasPermission error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if current logged-in user has a specific permission
 * 
 * @param string $permission Permission key to check
 * @return bool True if current user has permission, false otherwise
 */
function currentUserHasPermission($permission) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    return hasPermission($_SESSION['user_id'], $permission);
}

/**
 * Require a specific permission or redirect/die
 * 
 * @param string $permission Permission required
 * @param string $redirectUrl URL to redirect to if permission denied (optional)
 * @param string $errorMessage Custom error message (optional)
 */
function requirePermission($permission, $redirectUrl = null, $errorMessage = null) {
    if (!currentUserHasPermission($permission)) {
        if ($redirectUrl) {
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            $message = $errorMessage ?? "Access Denied: You don't have permission to access this resource.";
            http_response_code(403);
            die($message);
        }
    }
}

/**
 * Check if user has any of the specified permissions
 * 
 * @param string $userId User ID to check
 * @param array $permissions Array of permission keys
 * @return bool True if user has at least one permission
 */
function hasAnyPermission($userId, array $permissions) {
    foreach ($permissions as $permission) {
        if (hasPermission($userId, $permission)) {
            return true;
        }
    }
    return false;
}

/**
 * Check if user has all of the specified permissions
 * 
 * @param string $userId User ID to check
 * @param array $permissions Array of permission keys
 * @return bool True if user has all permissions
 */
function hasAllPermissions($userId, array $permissions) {
    foreach ($permissions as $permission) {
        if (!hasPermission($userId, $permission)) {
            return false;
        }
    }
    return true;
}

/**
 * Get all permissions for a user
 * 
 * @param string $userId User ID
 * @return array Array of permission keys the user has
 */
function getUserPermissions($userId) {
    global $db;
    
    if (empty($userId)) {
        return [];
    }
    
    try {
        $user = $db->read('users', $userId);
        if (!$user) {
            return [];
        }
        
        $role = strtolower($user['role'] ?? 'user');
        
        // Get default permissions for role
        $rolePermissions = [
            'admin' => [
                'view_reports',
                'manage_inventory',
                'manage_users',
                'manage_stores',
                'configure_system',
                'manage_pos',
                'view_analytics',
                'manage_alerts'
            ],
            'manager' => [
                'view_reports',
                'manage_inventory',
                'manage_stores',
                'manage_pos'
            ],
            'user' => [
                'view_reports'
            ]
        ];
        
        $permissions = $rolePermissions[$role] ?? [];
        
        // Add custom overrides
        $overrides = $user['permission_overrides'] ?? null;
        if (!empty($overrides)) {
            if (is_string($overrides)) {
                $overrides = json_decode($overrides, true);
            }
            if (is_array($overrides)) {
                foreach ($overrides as $key => $value) {
                    if ($value && !in_array($key, $permissions)) {
                        $permissions[] = $key;
                    }
                }
            }
        }
        
        return $permissions;
    } catch (Exception $e) {
        error_log('getUserPermissions error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Check if user has a specific role
 * 
 * @param string $userId User ID to check
 * @param string $role Role to check ('user', 'manager', 'admin')
 * @return bool True if user has the role
 */
function hasRole($userId, $role) {
    global $db;
    
    if (empty($userId)) {
        return false;
    }
    
    try {
        $user = $db->read('users', $userId);
        if (!$user) {
            return false;
        }
        
        return strtolower($user['role'] ?? 'user') === strtolower($role);
    } catch (Exception $e) {
        error_log('hasRole error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if current user has a specific role
 * 
 * @param string $role Role to check
 * @return bool True if current user has the role
 */
function currentUserHasRole($role) {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    return hasRole($_SESSION['user_id'], $role);
}

/**
 * Check if user is an admin
 * 
 * @param string $userId User ID to check
 * @return bool True if user is admin
 */
function isAdmin($userId = null) {
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    return hasRole($userId, 'admin');
}

/**
 * Check if user is a manager or admin
 * 
 * @param string $userId User ID to check
 * @return bool True if user is manager or admin
 */
function isManagerOrAdmin($userId = null) {
    $userId = $userId ?? ($_SESSION['user_id'] ?? null);
    return hasRole($userId, 'admin') || hasRole($userId, 'manager');
}

/**
 * Require admin role or redirect/die
 * 
 * @param string $redirectUrl URL to redirect to if not admin (optional)
 */
function requireAdmin($redirectUrl = null) {
    if (!isAdmin()) {
        if ($redirectUrl) {
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            http_response_code(403);
            die('Access Denied: Administrator access required.');
        }
    }
}

/**
 * Require manager or admin role
 * 
 * @param string $redirectUrl URL to redirect to if not manager/admin (optional)
 */
function requireManagerOrAdmin($redirectUrl = null) {
    if (!isManagerOrAdmin()) {
        if ($redirectUrl) {
            header('Location: ' . $redirectUrl);
            exit;
        } else {
            http_response_code(403);
            die('Access Denied: Manager or Administrator access required.');
        }
    }
}

/**
 * Get permission label/name for display
 * 
 * @param string $permissionKey Permission key
 * @return string Human-readable permission name
 */
function getPermissionLabel($permissionKey) {
    $labels = [
        'view_reports' => 'View Reports',
        'manage_inventory' => 'Manage Inventory',
        'manage_users' => 'Manage Users',
        'manage_stores' => 'Manage Stores',
        'configure_system' => 'System Configuration',
        'manage_pos' => 'Manage POS',
        'view_analytics' => 'View Analytics',
        'manage_alerts' => 'Manage Alerts'
    ];
    
    return $labels[$permissionKey] ?? ucwords(str_replace('_', ' ', $permissionKey));
}

/**
 * Get all available permissions with metadata
 * 
 * @return array Array of permissions with names, descriptions, and icons
 */
function getAllAvailablePermissions() {
    return [
        'view_reports' => [
            'name' => 'View Reports',
            'description' => 'Access reporting and analytics dashboards',
            'icon' => 'chart-bar',
            'category' => 'Reports'
        ],
        'manage_inventory' => [
            'name' => 'Manage Inventory',
            'description' => 'Add, edit, and delete inventory items',
            'icon' => 'boxes',
            'category' => 'Inventory'
        ],
        'manage_users' => [
            'name' => 'Manage Users',
            'description' => 'Create, edit, and manage user accounts',
            'icon' => 'users',
            'category' => 'Administration'
        ],
        'manage_stores' => [
            'name' => 'Manage Stores',
            'description' => 'Manage store locations and assignments',
            'icon' => 'store',
            'category' => 'Stores'
        ],
        'configure_system' => [
            'name' => 'System Configuration',
            'description' => 'Access system settings and configurations',
            'icon' => 'cog',
            'category' => 'Administration'
        ],
        'manage_pos' => [
            'name' => 'Manage POS',
            'description' => 'Access and manage point-of-sale terminals',
            'icon' => 'cash-register',
            'category' => 'Sales'
        ],
        'view_analytics' => [
            'name' => 'View Analytics',
            'description' => 'Access advanced analytics and insights',
            'icon' => 'chart-line',
            'category' => 'Reports'
        ],
        'manage_alerts' => [
            'name' => 'Manage Alerts',
            'description' => 'Configure and manage system alerts',
            'icon' => 'bell',
            'category' => 'System'
        ]
    ];
}

/**
 * Log permission change for audit trail
 * 
 * @param string $targetUserId User whose permissions were changed
 * @param string $action Action performed
 * @param array $details Additional details
 * @return bool Success status
 */
function logPermissionChange($targetUserId, $action, $details = []) {
    global $db;
    
    try {
        $db->create('user_activities', [
            'user_id' => $_SESSION['user_id'] ?? 'system',
            'action_type' => 'permission_changed',
            'description' => $action,
            'metadata' => json_encode(array_merge([
                'target_user_id' => $targetUserId
            ], $details)),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'created_at' => date('c')
        ]);
        return true;
    } catch (Exception $e) {
        error_log('logPermissionChange error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check permission and return JSON error if not authorized (for API endpoints)
 * 
 * @param string $permission Permission required
 * @param int $statusCode HTTP status code to return (default 403)
 */
function requirePermissionAPI($permission, $statusCode = 403) {
    if (!currentUserHasPermission($permission)) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Access Denied',
            'message' => "You don't have permission: {$permission}"
        ]);
        exit;
    }
}

/**
 * Generate permission matrix for display
 * 
 * @return array Role-based permission matrix
 */
function getPermissionMatrix() {
    return [
        'admin' => [
            'view_reports' => true,
            'manage_inventory' => true,
            'manage_users' => true,
            'manage_stores' => true,
            'configure_system' => true,
            'manage_pos' => true,
            'view_analytics' => true,
            'manage_alerts' => true
        ],
        'manager' => [
            'view_reports' => true,
            'manage_inventory' => true,
            'manage_users' => false,
            'manage_stores' => true,
            'configure_system' => false,
            'manage_pos' => true,
            'view_analytics' => false,
            'manage_alerts' => false
        ],
        'user' => [
            'view_reports' => true,
            'manage_inventory' => false,
            'manage_users' => false,
            'manage_stores' => false,
            'configure_system' => false,
            'manage_pos' => false,
            'view_analytics' => false,
            'manage_alerts' => false
        ]
    ];
}
