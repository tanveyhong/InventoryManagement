<?php
/**
 * Permission Helper Functions
 * Easy-to-use functions for checking user permissions throughout the application
 */

/**
 * Check if a user has a specific permission
 * 
 * @param string $userId User ID to check
 * @param string $permission Permission key to check (e.g., 'manage_inventory' or 'can_view_inventory')
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

        // Map legacy permission names to new can_* keys
        $legacyMap = [
            'view_reports' => 'can_view_reports',
            'manage_inventory' => 'can_manage_inventory',
            'manage_users' => 'can_manage_users',
            'manage_stores' => 'can_manage_stores',
            'manage_pos' => 'can_manage_pos',
            'view_analytics' => 'can_view_reports',
            'view_inventory' => 'can_view_inventory'
        ];

        // Normalize permission key to can_* form if possible
        $permKey = $permission;
        if (isset($legacyMap[$permission])) {
            $permKey = $legacyMap[$permission];
        }

        // If explicit can_* field exists on user, use that
        if (isset($user[$permKey])) {
            return (bool)$user[$permKey];
        }

        // If permission overrides stored as JSON, check them
        $overrides = $user['permission_overrides'] ?? null;
        if (!empty($overrides)) {
            if (is_string($overrides)) {
                $overrides = json_decode($overrides, true);
            }
            if (is_array($overrides) && array_key_exists($permKey, $overrides)) {
                return (bool)$overrides[$permKey];
            }
        }

        // Fallback to role defaults for new granular keys
        $roleDefaults = [
            'admin' => [
                'can_view_reports' => true,
                'can_view_inventory' => true,
                'can_add_inventory' => true,
                'can_edit_inventory' => true,
                'can_delete_inventory' => true,
                'can_view_stores' => true,
                'can_add_stores' => true,
                'can_edit_stores' => true,
                'can_delete_stores' => true,
                'can_use_pos' => true,
                'can_manage_pos' => true,
                'can_view_users' => true,
                'can_manage_users' => true
            ],
            'manager' => [
                'can_view_reports' => true,
                'can_view_inventory' => true,
                'can_add_inventory' => true,
                'can_edit_inventory' => true,
                'can_view_stores' => true,
                'can_add_stores' => true,
                'can_edit_stores' => true,
                'can_use_pos' => true,
                'can_view_users' => true
            ],
            'user' => [
                'can_view_reports' => true,
                'can_view_inventory' => true,
                'can_view_stores' => true,
                'can_view_users' => true
            ]
        ];

        return $roleDefaults[$role][$permKey] ?? false;
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
        
        // Define granular permission keys
        $allPermKeys = [
            'can_view_reports',
            'can_view_inventory', 'can_add_inventory', 'can_edit_inventory', 'can_delete_inventory',
            'can_view_stores', 'can_add_stores', 'can_edit_stores', 'can_delete_stores',
            'can_use_pos', 'can_manage_pos',
            'can_view_users', 'can_manage_users'
        ];

        // Role defaults for granular permissions
        $roleDefaults = [
            'admin' => array_fill_keys($allPermKeys, true),
            'manager' => [
                'can_view_reports' => true,
                'can_view_inventory' => true,
                'can_add_inventory' => true,
                'can_edit_inventory' => true,
                'can_delete_inventory' => false,
                'can_view_stores' => true,
                'can_add_stores' => true,
                'can_edit_stores' => true,
                'can_delete_stores' => false,
                'can_use_pos' => true,
                'can_manage_pos' => false,
                'can_view_users' => true,
                'can_manage_users' => false
            ],
            'user' => [
                'can_view_reports' => true,
                'can_view_inventory' => true,
                'can_view_stores' => true,
                'can_view_users' => true
            ]
        ];

        $defaults = $roleDefaults[$role] ?? $roleDefaults['user'];

        $permissions = [];
        foreach ($allPermKeys as $key) {
            if (isset($user[$key])) {
                if ($user[$key]) $permissions[] = $key;
            } else {
                if (!empty($defaults[$key])) $permissions[] = $key;
            }
        }

        // Merge JSON overrides if present
        $overrides = $user['permission_overrides'] ?? null;
        if (!empty($overrides)) {
            if (is_string($overrides)) $overrides = json_decode($overrides, true);
            if (is_array($overrides)) {
                foreach ($overrides as $k => $v) {
                    if ($v && !in_array($k, $permissions)) $permissions[] = $k;
                    if (!$v && in_array($k, $permissions)) {
                        // remove if explicitly disabled
                        $permissions = array_values(array_diff($permissions, [$k]));
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
        'manage_pos' => 'Manage POS',
        'view_analytics' => 'View Analytics',
        'manage_alerts' => 'Manage Alerts',
        // granular keys
        'can_view_reports' => 'View Reports',
        'can_view_inventory' => 'View Inventory',
        'can_add_inventory' => 'Add Inventory',
        'can_edit_inventory' => 'Edit Inventory',
        'can_delete_inventory' => 'Delete Inventory',
        'can_view_stores' => 'View Stores',
        'can_add_stores' => 'Add Stores',
        'can_edit_stores' => 'Edit Stores',
        'can_delete_stores' => 'Delete Stores',
        'can_use_pos' => 'Use POS',
        'can_manage_pos' => 'Manage POS',
        'can_view_users' => 'View Users',
        'can_manage_users' => 'Manage Users'
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
        ],
        // granular permissions
        'can_view_inventory' => [
            'name' => 'View Inventory',
            'description' => 'See inventory lists and item details',
            'icon' => 'eye',
            'category' => 'Inventory'
        ],
        'can_add_inventory' => [
            'name' => 'Add Inventory',
            'description' => 'Add new inventory items',
            'icon' => 'plus-square',
            'category' => 'Inventory'
        ],
        'can_edit_inventory' => [
            'name' => 'Edit Inventory',
            'description' => 'Modify existing inventory items',
            'icon' => 'edit',
            'category' => 'Inventory'
        ],
        'can_delete_inventory' => [
            'name' => 'Delete Inventory',
            'description' => 'Remove inventory items',
            'icon' => 'trash',
            'category' => 'Inventory'
        ],
        'can_view_stores' => [
            'name' => 'View Stores',
            'description' => 'View store locations and assignments',
            'icon' => 'store',
            'category' => 'Stores'
        ],
        'can_add_stores' => [
            'name' => 'Add Stores',
            'description' => 'Create new store locations',
            'icon' => 'plus-square',
            'category' => 'Stores'
        ],
        'can_edit_stores' => [
            'name' => 'Edit Stores',
            'description' => 'Edit store information',
            'icon' => 'edit',
            'category' => 'Stores'
        ],
        'can_delete_stores' => [
            'name' => 'Delete Stores',
            'description' => 'Remove store locations',
            'icon' => 'trash',
            'category' => 'Stores'
        ],
        'can_use_pos' => [
            'name' => 'Use POS',
            'description' => 'Access the point-of-sale terminal',
            'icon' => 'cash-register',
            'category' => 'Sales'
        ],
        'can_manage_pos' => [
            'name' => 'Manage POS',
            'description' => 'Configure and manage POS terminals',
            'icon' => 'cogs',
            'category' => 'Sales'
        ],
        'can_view_users' => [
            'name' => 'View Users',
            'description' => 'See user list and basic profiles',
            'icon' => 'users',
            'category' => 'Administration'
        ],
        'can_manage_users' => [
            'name' => 'Manage Users',
            'description' => 'Create and edit user accounts',
            'icon' => 'user-cog',
            'category' => 'Administration'
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
