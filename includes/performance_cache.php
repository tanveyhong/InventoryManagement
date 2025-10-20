<?php
/**
 * Performance Optimization for Permission System
 * 
 * This file adds caching to reduce Firebase API calls.
 * Include this AFTER functions.php
 */

// Session-based cache for current user's data
if (!function_exists('getCachedUserInfo')) {
    function getCachedUserInfo($userId) {
        // Use session cache for current request
        $cacheKey = "user_info_{$userId}";
        
        if (!isset($_SESSION['_cache'])) {
            $_SESSION['_cache'] = [];
        }
        
        if (isset($_SESSION['_cache'][$cacheKey])) {
            return $_SESSION['_cache'][$cacheKey];
        }
        
        // Fetch from database
        $db = getDB();
        $userInfo = $db->read('users', $userId);
        
        // Cache for this session
        $_SESSION['_cache'][$cacheKey] = $userInfo;
        
        return $userInfo;
    }
}

// Cached version of hasPermission
if (!function_exists('hasPermissionCached')) {
    function hasPermissionCached($userId, $permission) {
        if (empty($userId) || empty($permission)) {
            return false;
        }
        
        // Cache permissions for this user in session
        $cacheKey = "user_permissions_{$userId}";
        
        if (!isset($_SESSION['_cache'])) {
            $_SESSION['_cache'] = [];
        }
        
        // If we haven't cached this user's permissions yet
        if (!isset($_SESSION['_cache'][$cacheKey])) {
            // Get user info (cached)
            $user = getCachedUserInfo($userId);
            
            if (!$user || empty($user['role'])) {
                $_SESSION['_cache'][$cacheKey] = [];
                return false;
            }
            
            $role = strtolower($user['role']);
            
            // Build complete permission set
            $allPermissions = [];
            
            // Admin has everything
            if ($role === 'admin') {
                $allPermissions = [
                    'manage_inventory' => true,
                    'manage_stores' => true,
                    'manage_users' => true,
                    'configure_system' => true,
                    'manage_pos' => true,
                    'view_analytics' => true,
                    'manage_alerts' => true,
                    'view_reports' => true,
                    'view_audit' => true
                ];
            } else {
                // Default role permissions
                $rolePermissions = [
                    'manager' => ['manage_inventory', 'manage_stores', 'manage_pos', 'view_analytics', 'view_reports', 'view_audit'],
                    'user' => ['view_reports']
                ];
                
                $permissions = $rolePermissions[$role] ?? [];
                foreach ($permissions as $perm) {
                    $allPermissions[$perm] = true;
                }
                
                // Apply custom overrides
                $overrides = $user['permission_overrides'] ?? null;
                if (!empty($overrides)) {
                    if (is_string($overrides)) {
                        $overrides = json_decode($overrides, true);
                    }
                    if (is_array($overrides)) {
                        $allPermissions = array_merge($allPermissions, $overrides);
                    }
                }
            }
            
            // Cache the permission set
            $_SESSION['_cache'][$cacheKey] = $allPermissions;
        }
        
        // Check cached permissions
        return isset($_SESSION['_cache'][$cacheKey][$permission]) 
            && $_SESSION['_cache'][$cacheKey][$permission];
    }
}

// Cached version of currentUserHasPermission
if (!function_exists('currentUserHasPermissionCached')) {
    function currentUserHasPermissionCached($permission) {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        return hasPermissionCached($_SESSION['user_id'], $permission);
    }
}

// Cached version of getUserPermissions
if (!function_exists('getUserPermissionsCached')) {
    function getUserPermissionsCached($userId) {
        $cacheKey = "user_permissions_{$userId}";
        
        if (!isset($_SESSION['_cache'])) {
            $_SESSION['_cache'] = [];
        }
        
        // Force cache rebuild if needed
        if (!isset($_SESSION['_cache'][$cacheKey])) {
            hasPermissionCached($userId, 'dummy'); // This will build the cache
        }
        
        $permissionSet = $_SESSION['_cache'][$cacheKey] ?? [];
        
        // Return array of permission keys that are true
        return array_keys(array_filter($permissionSet, function($value) {
            return $value === true;
        }));
    }
}

// Function to clear permission cache (call after updating permissions)
if (!function_exists('clearPermissionCache')) {
    function clearPermissionCache($userId = null) {
        if ($userId) {
            unset($_SESSION['_cache']["user_info_{$userId}"]);
            unset($_SESSION['_cache']["user_permissions_{$userId}"]);
        } else {
            // Clear all caches
            unset($_SESSION['_cache']);
        }
    }
}

// Override the original functions with cached versions
// This allows existing code to benefit without changes
if (!function_exists('hasPermission_original')) {
    // Backup original function
    function hasPermission_original($userId, $permission) {
        return hasPermission($userId, $permission);
    }
}

// Note: To use the cached versions, update your code to call:
// - hasPermissionCached() instead of hasPermission()
// - currentUserHasPermissionCached() instead of currentUserHasPermission()
// - getUserPermissionsCached() instead of getUserPermissions()
