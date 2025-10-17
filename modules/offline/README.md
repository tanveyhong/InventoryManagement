# Offline Support Module

This module provides offline functionality for the Inventory Management System, allowing users to continue working even when network connectivity is lost.

## Directory Structure

```
modules/offline/
├── offline_storage.js        # IndexedDB storage handler for offline data
├── sync_manager.js           # Synchronization manager for pending changes
├── connectivity_monitor.js   # Network status monitoring and UI indicators
├── conflict_resolver.js      # Conflict resolution for offline/online data conflicts
├── cache_handler.php         # PHP cache handler (legacy)
└── sync.php                  # PHP sync utilities (legacy)
```

## JavaScript Components

### 1. offline_storage.js
**Purpose:** Handles local data storage using IndexedDB

**Key Features:**
- Stores pending updates when offline
- Caches data for offline viewing
- Manages sync status
- Auto-cleanup of synced data

**Global Instance:** `window.profileOfflineStorage`

**Usage:**
```javascript
// Save pending update
await profileOfflineStorage.savePendingUpdate(userId, data);

// Get pending updates
const pending = await profileOfflineStorage.getPendingUpdates();

// Cache data for offline access
await profileOfflineStorage.cacheProfile(userId, profileData);
```

### 2. sync_manager.js
**Purpose:** Manages synchronization of offline changes

**Key Features:**
- Auto-sync every 30 seconds when online
- Retry mechanism (max 3 attempts)
- Event-driven notifications
- Force sync capability

**Global Instance:** `window.profileSyncManager`

**Usage:**
```javascript
// Start automatic synchronization
profileSyncManager.startAutoSync();

// Force immediate sync
await profileSyncManager.forceSync();

// Listen for sync events
profileSyncManager.addEventListener((event, data) => {
    console.log('Sync event:', event, data);
});
```

### 3. connectivity_monitor.js
**Purpose:** Monitors network connectivity and provides visual feedback

**Key Features:**
- Real-time online/offline detection
- Visual connectivity indicator (top-right corner)
- Toast notifications for status changes
- Pending updates counter badge

**Global Instance:** `window.connectivityMonitor`

**Usage:**
```javascript
// Show notification
connectivityMonitor.showNotification('Message', 'success');

// Update pending count
await connectivityMonitor.updatePendingCount();

// Listen for connectivity changes
connectivityMonitor.addEventListener((status) => {
    console.log('Connection status:', status);
});
```

### 4. conflict_resolver.js
**Purpose:** Resolves conflicts between offline and online data

**Key Features:**
- Multiple resolution strategies (timestamp, server-wins, client-wins, manual)
- User-friendly conflict dialog
- Conflict logging for audit
- Smart field merging

**Global Instance:** `window.conflictResolver`

**Usage:**
```javascript
// Detect conflicts
const conflict = await conflictResolver.detectConflict(localData, serverData);

// Resolve conflict
const resolution = await conflictResolver.resolveConflict(conflict);

// Set resolution strategy
conflictResolver.setStrategy('timestamp'); // or 'server-wins', 'client-wins', 'manual'
```

## Integration

### In HTML/PHP Files

Add the following scripts before the closing `</body>` tag:

```html
<!-- Offline Support Scripts -->
<script src="../../offline/offline_storage.js"></script>
<script src="../../offline/sync_manager.js"></script>
<script src="../../offline/connectivity_monitor.js"></script>
<script src="../../offline/conflict_resolver.js"></script>

<script>
// Initialize offline support
document.addEventListener('DOMContentLoaded', function() {
    // Start auto-sync
    profileSyncManager.startAutoSync();
    
    // Update pending count
    connectivityMonitor.updatePendingCount();
    
    // Listen for sync events
    profileSyncManager.addEventListener((event, data) => {
        if (event === 'sync-complete') {
            connectivityMonitor.showNotification('Synced successfully', 'success');
        }
    });
});
</script>
```

### Form Submission Interception

To save form data offline:

```javascript
const form = document.querySelector('form');
form.addEventListener('submit', async function(e) {
    if (!navigator.onLine) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const data = Object.fromEntries(formData);
        
        await profileOfflineStorage.savePendingUpdate(userId, data);
        connectivityMonitor.showNotification('Saved offline', 'success');
    }
});
```

## Configuration

### Sync Settings
Edit `sync_manager.js`:
```javascript
this.retryDelay = 5000;      // Delay between retries (ms)
this.maxRetries = 3;         // Maximum retry attempts
this.syncInterval = 30000;   // Auto-sync interval (ms)
```

### Conflict Resolution Strategy
Default is timestamp-based. Change in your initialization code:
```javascript
conflictResolver.setStrategy('server-wins'); // Always prefer server data
conflictResolver.setStrategy('client-wins'); // Always prefer local data
conflictResolver.setStrategy('manual');      // Ask user to choose
```

## Database Schema

### IndexedDB Stores

#### pendingProfileUpdates
```javascript
{
    id: (auto-increment),
    userId: "abc123",
    data: { /* update fields */ },
    timestamp: "2025-10-16T12:00:00Z",
    synced: false,
    retryCount: 0
}
```

#### cachedProfiles
```javascript
{
    userId: "abc123",
    data: { /* profile data */ },
    lastUpdated: "2025-10-16T12:00:00Z"
}
```

## Visual Indicators

### Connectivity Indicator
- **Position:** Fixed top-right (70px from top, 20px from right)
- **States:**
  - ✅ **Online:** Green badge with WiFi icon (auto-hides after 3s)
  - ❌ **Offline:** Red badge with "Offline" message (stays visible)

### Pending Updates Badge
- **Position:** Fixed top-right (75px from top, 180px from right)
- **Display:** Orange badge with count (e.g., "2 pending updates")
- **Visibility:** Only shown when pending updates exist

### Toast Notifications
- **Position:** Top-right, below connectivity indicator
- **Types:** Success (green), Warning (orange), Error (red), Info (blue)
- **Duration:** Auto-dismiss after 5 seconds

## Browser Compatibility

| Browser | Minimum Version | Status |
|---------|----------------|--------|
| Chrome  | 24+            | ✅ Full Support |
| Firefox | 16+            | ✅ Full Support |
| Safari  | 10+            | ✅ Full Support |
| Edge    | 12+            | ✅ Full Support |
| Opera   | 15+            | ✅ Full Support |

**Requirements:**
- IndexedDB API
- ES6+ JavaScript (Promise, async/await, arrow functions)
- Fetch API

## Testing

### Manual Testing

1. **Offline Mode:**
   - Open DevTools (F12) → Network tab
   - Set throttling to "Offline"
   - Submit a form
   - Verify offline indicator appears
   - Check pending badge shows count

2. **Auto-Sync:**
   - Set network back to "Online"
   - Wait up to 30 seconds
   - Verify "Synced successfully" notification
   - Confirm pending badge disappears

3. **Force Sync:**
   ```javascript
   // In browser console
   await profileSyncManager.forceSync();
   ```

### Debugging

```javascript
// Check pending updates
const pending = await profileOfflineStorage.getPendingUpdates();
console.log('Pending:', pending);

// Check sync status
console.log('Sync status:', profileSyncManager.getStatus());

// View conflict log
console.log('Conflicts:', conflictResolver.getConflictLog());

// Check connectivity
console.log('Online:', connectivityMonitor.getStatus());
```

## Performance

- **Storage:** IndexedDB (typically 50% of available disk)
- **Sync frequency:** Every 30 seconds (configurable)
- **Network usage:** Minimal, only pending updates
- **Memory:** ~2-5MB for typical usage

## Security

- ✅ Client-side only (no server storage of offline data)
- ✅ Session-based authentication maintained
- ✅ HTTPS required in production
- ✅ No sensitive data (passwords) stored offline
- ⚠️ Consider clearing storage on logout

## Troubleshooting

**Problem:** Pending updates not syncing
- **Solution:** Check `profileSyncManager.getStatus()`, ensure online, try force sync

**Problem:** IndexedDB not working
- **Solution:** Check browser compatibility, clear browser data, avoid private mode

**Problem:** Conflict dialog not showing
- **Solution:** Verify strategy is set to 'manual', check console for errors

**Problem:** Visual indicators not appearing
- **Solution:** Ensure scripts loaded in correct order, check for JavaScript errors

## Files Currently Using Offline Support

- `modules/users/profile.php` - User profile page with offline edit support

## Future Modules to Support

- [ ] Stock management (add/edit stock offline)
- [ ] Store management (create/edit stores offline)
- [ ] POS terminal (offline transactions)
- [ ] Reports (offline report generation)

## Version History

- **v1.0.0** (Oct 16, 2025) - Initial release
  - Profile offline support
  - Basic sync and conflict resolution
  - Visual indicators and notifications

## Support

For issues or questions:
1. Check browser console for errors
2. Review conflict log: `conflictResolver.getConflictLog()`
3. Test in different browser
4. Contact development team

---

**Last Updated:** October 16, 2025  
**Maintained By:** Inventory System Development Team
