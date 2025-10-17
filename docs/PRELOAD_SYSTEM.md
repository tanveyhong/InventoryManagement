# Dashboard Data Pre-loading System

## Overview

The Dashboard Data Pre-loading System automatically loads **stores and profile data** when users first enter the system, making these critical modules instantly available without any loading delays.

## Focus Areas

### 🏪 Stores Module
- Store list with locations
- Store statistics and analytics
- Instant map rendering
- Fast inventory viewer

### 👤 Profile Module
- User list (for management)
- Activity log
- Permissions and roles
- Instant tab switching

## Features

### ✨ Core Features
- **Parallel Loading**: All APIs called simultaneously for fastest load time
- **localStorage Caching**: 5-minute cache reduces API calls on return visits
- **Progress Indicators**: Animated spinner shows loading status
- **Global Access**: Helper functions make data available to all modules
- **Cache Management**: Automatic expiration and manual invalidation
- **Performance Tracking**: Console logs show load times and cache status

### 📦 Pre-loaded Data

#### For Stores Module:
1. **Store List** (`modules/stores/api/get_stores_with_location.php`)
   - Complete store list with GPS coordinates
   - Used by: Store map, store list, regional dashboard

2. **Store Statistics** (`modules/stores/api/statistics.php`)
   - Store performance metrics
   - Used by: Dashboard cards, analytics, regional view

#### For Profile Module:
3. **Users** (`modules/users/profile/api.php?action=get_all_users`)
   - User list (admin only)
   - Used by: User management, profile viewer

4. **Activities** (`modules/users/profile/api.php?action=get_activities`)
   - Recent activity log (last 50 items)
   - Used by: Activity tab, monitoring

5. **Permissions** (`modules/users/profile/api.php?action=get_permissions`)
   - User permissions and roles
   - Used by: Access control, UI visibility

## Usage in Modules

### Stores Module - Get Pre-loaded Data

```javascript
// modules/stores/list.php or map.php
document.addEventListener('DOMContentLoaded', () => {
    // Get pre-loaded stores - instant!
    const stores = window.getPreloadedStores();
    
    if (stores && stores.length > 0) {
        console.log(`✅ ${stores.length} stores pre-loaded`);
        renderStoreMap(stores);  // <10ms render
        // or
        renderStoreList(stores);
    } else {
        // Fallback to API call
        fetchStores();
    }
    
    // Get store statistics
    const stats = window.getPreloadedData('storeStats');
    if (stats) {
        updateDashboardCards(stats);
    }
});
```

### Profile Module - Get Pre-loaded Data

```javascript
// modules/users/profile.php
document.addEventListener('DOMContentLoaded', () => {
    // Get pre-loaded activities
    const activities = window.getPreloadedActivities();
    if (activities && activities.length > 0) {
        console.log(`✅ ${activities.length} activities pre-loaded`);
        renderActivities(activities);  // Instant render
    }
    
    // Get users list (admin)
    const users = window.getPreloadedUsers();
    if (users && users.length > 0) {
        populateUserDropdown(users);
    }
    
    // Get permissions
    const permissions = window.getPreloadedData('permissions');
    if (permissions) {
        updatePermissionsTab(permissions);
    }
});
```

### Check Cache Status

```javascript
// Check if data is cached
const stores = window.getPreloadedStores();
if (stores.length > 0) {
    console.log('✅ Using pre-loaded stores data');
    // Render immediately without API call
    renderStores(stores);
} else {
    console.log('⏳ Data not yet loaded, fetching...');
    // Fallback to API call
    await fetchStores();
}
```

### Invalidate Cache

```javascript
// After creating/updating/deleting stores or profile data, invalidate cache

// Invalidate stores cache after store operations
fetch('modules/stores/api/store_operations.php', {
    method: 'POST',
    body: JSON.stringify(newStore)
})
.then(() => {
    window.invalidatePreloadCache('stores');
    alert('Store added! Cache cleared.');
});

// Invalidate profile cache after user operations
fetch('modules/users/profile/api.php', {
    method: 'POST',
    body: JSON.stringify(userData)
})
.then(() => {
    window.invalidatePreloadCache('users');
    window.invalidatePreloadCache('activities');
    alert('User updated! Cache cleared.');
});

// Invalidate all caches at once
window.invalidatePreloadCache();  // Force refresh all data on next load
```

## Cache Configuration

### Cache TTL (Time To Live)
- **Default**: 5 minutes (300,000ms)
- **Location**: `index.php`, `DataPreloader` class, `this.CACHE_TTL`
- **Modify**: Change `this.CACHE_TTL = 5 * 60 * 1000;` to desired duration

### Cache Key Prefix
- **Default**: `inv_preload_`
- **localStorage Key**: `inv_preload_data`
- **Location**: `index.php`, `DataPreloader` class, `this.CACHE_PREFIX`

### Clear All Cache Manually

```javascript
// Open browser console and run:
localStorage.removeItem('inv_preload_data');
location.reload();
```

## Performance Metrics

### Typical Load Times
- **First Load** (no cache): 200-500ms
- **Cached Load**: <10ms
- **Cache Age**: Shown in console (`Cache hit (age: 45.2s)`)

### Console Logging
```
🚀 Pre-loading stores & profile data...
📦 Using cached stores & profile data

OR

🚀 Pre-loading stores & profile data...
✓ Stores loaded (15 items) in 120.45ms
✓ Store statistics loaded in 110.88ms
✓ Users loaded (8 items) in 95.22ms
✓ Activities loaded (50 items) in 78.33ms
✓ Permissions loaded in 65.12ms
✅ Stores & profile data pre-loaded in 450.23ms
💾 Data saved to localStorage
```

## Architecture

### Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    User Enters Dashboard                     │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│           DOMContentLoaded Event Fires                       │
│  - Initialize DataPreloader                                  │
│  - Register global helper functions                          │
│  - Call preloadAll()                                         │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│              Check localStorage Cache                        │
│  - Key: inv_preload_data                                     │
│  - Check timestamp (< 5 min = valid)                         │
└────┬───────────────────────────────────────────────┬─────────┘
     │                                               │
     │ Cache Hit                            Cache Miss
     │                                               │
     ▼                                               ▼
┌──────────────────────────────┐   ┌────────────────────────────────────┐
│   Load from localStorage      │   │   Fetch from APIs (Parallel)       │
│   - Instant (<10ms)           │   │   STORES MODULE:                   │
│   - Show "from cache"         │   │   - preloadStores()                │
└──────────────┬────────────────┘   │   - preloadStoreStats()            │
               │                    │                                    │
               │                    │   PROFILE MODULE:                  │
               │                    │   - preloadUsers()                 │
               │                    │   - preloadActivities()            │
               │                    │   - preloadPermissions()           │
               │                    └────────────┬───────────────────────┘
               │                                 │
               │                                 ▼
               │                    ┌────────────────────────────────────┐
               │                    │   Save to localStorage              │
               │                    │   - Store with timestamp            │
               │                    └────────────┬───────────────────────┘
               │                                 │
               └─────────────┬───────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│        Stores & Profile Data Available Globally              │
│  - window.dataPreloader.cache                                │
│  - window.getPreloadedStores()                               │
│  - window.getPreloadedUsers()                                │
│  - window.getPreloadedActivities()                           │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│      Stores & Profile Modules Use Pre-loaded Data            │
│  - No API calls needed                                       │
│  - Instant rendering                                         │
│  - Better UX                                                 │
└─────────────────────────────────────────────────────────────┘
```

## Integration Example

### Before (Slow - Each module loads separately)

```javascript
// modules/stores/list.php
document.addEventListener('DOMContentLoaded', async () => {
    // Load stores when page loads
    const response = await fetch('api/get_stores_with_location.php');
    const data = await response.json();
    renderStores(data.stores);  // 300-500ms delay
});
```

### After (Fast - Use pre-loaded data)

```javascript
// modules/stores/list.php
document.addEventListener('DOMContentLoaded', () => {
    // Get pre-loaded stores
    const stores = window.getPreloadedStores();
    
    if (stores.length > 0) {
        // Instant render - data already loaded
        renderStores(stores);  // <10ms
        console.log('✅ Using pre-loaded stores');
    } else {
        // Fallback if preloader not ready
        console.warn('Preloader not ready, fetching...');
        fetchStores();
    }
});
```

## Error Handling

The system gracefully handles errors:
- **API Failures**: Individual promises use `Promise.allSettled()` - one failure doesn't stop others
- **localStorage Errors**: Falls back to API if localStorage unavailable
- **Expired Cache**: Automatically removed and fresh data fetched
- **Missing Data**: Modules should check if data exists before using

```javascript
// Always check if data is available
const stores = window.getPreloadedStores();
if (stores && stores.length > 0) {
    // Use pre-loaded data
} else {
    // Fallback to API call
}
```

## Benefits

### User Experience
- ⚡ **Instant Module Loading**: No waiting for data when navigating
- 🔄 **Seamless Navigation**: Switch between modules without delays
- 📊 **Better Perceived Performance**: Data ready before user clicks

### Technical Benefits
- 🚀 **Reduced API Calls**: localStorage cache reduces server load
- ⏱️ **Parallel Loading**: All APIs called simultaneously
- 💾 **Efficient Caching**: 5-minute TTL balances freshness and performance
- 🔧 **Easy Integration**: Simple global functions for any module

### Performance Gains
- **First Visit**: 200-500ms (parallel loading vs sequential)
- **Return Visits**: <10ms (localStorage cache)
- **Module Navigation**: 0ms (data already in memory)
- **API Load**: 50-70% reduction (cache hits)

## Maintenance

### Update Cache TTL
```javascript
// In index.php, DataPreloader constructor
this.CACHE_TTL = 10 * 60 * 1000; // Change to 10 minutes
```

### Add New Data Type
```javascript
// 1. Add to cache object
this.cache = {
    stores: null,
    users: null,
    products: null  // NEW
};

// 2. Add preload method
async preloadProducts() {
    const response = await fetch('api/get_products.php');
    this.cache.products = await response.json();
}

// 3. Add to preloadAll()
const promises = [
    this.preloadStores(),
    this.preloadUsers(),
    this.preloadProducts()  // NEW
];

// 4. Add helper function
window.getPreloadedProducts = function() {
    return window.dataPreloader.getCachedProducts();
};
```

### Monitor Performance
```javascript
// Check console for load times
// Open DevTools > Console
// Look for:
// - "🚀 Starting data pre-load..."
// - "✅ All data pre-loaded in Xms"
// - Individual load times per API
```

## Troubleshooting

### Data Not Loading
1. **Check console**: Look for error messages
2. **Check localStorage**: `localStorage.getItem('inv_preload_data')`
3. **Clear cache**: `localStorage.clear()` then reload
4. **Check APIs**: Verify endpoints return data correctly

### Old Data Showing
1. **Invalidate cache**: `window.invalidatePreloadCache()`
2. **Wait for TTL**: Cache expires after 5 minutes
3. **Hard refresh**: Ctrl+Shift+R (clears all caches)

### Performance Issues
1. **Reduce cache TTL**: Lower if data changes frequently
2. **Increase cache TTL**: Higher if data rarely changes
3. **Remove unused data**: Don't pre-load data you don't need
4. **Check API performance**: Slow APIs affect pre-load time

## Future Enhancements

- [ ] Add cache versioning (invalidate on app updates)
- [ ] Add partial cache updates (refresh only stale data)
- [ ] Add background refresh (update cache while using app)
- [ ] Add IndexedDB fallback (for larger datasets)
- [ ] Add cache compression (reduce localStorage size)
- [ ] Add service worker integration (offline support)

## Support

For questions or issues with the pre-loading system:
1. Check console logs for error messages
2. Review this documentation
3. Test with cache disabled: `localStorage.clear()`
4. Contact development team

---

**Last Updated**: December 2024
**Version**: 1.0.0
**Status**: Production Ready ✅
