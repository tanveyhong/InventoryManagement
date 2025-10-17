# Profile Offline Support Module

## Overview
The Profile Offline Support Module enables users to update their profiles even when offline. Changes are stored locally and automatically synchronized when connectivity is restored, ensuring seamless user experience regardless of network conditions.

## Architecture

### Components

#### 1. **Offline Storage Handler** (`offline_storage.js`)
- Uses IndexedDB for local data persistence
- Stores pending profile updates with metadata
- Caches profile data for offline viewing
- Manages sync status and retry counts

**Key Features:**
- Automatic database initialization
- Pending updates queue
- Profile data caching
- Cleanup of synced updates

**Methods:**
```javascript
// Save a pending update
await profileOfflineStorage.savePendingUpdate(userId, updateData);

// Get all pending updates
const pending = await profileOfflineStorage.getPendingUpdates(userId);

// Mark update as synced
await profileOfflineStorage.markAsSynced(updateId);

// Cache profile for offline access
await profileOfflineStorage.cacheProfile(userId, profileData);

// Get pending count
const count = await profileOfflineStorage.getPendingCount(userId);
```

#### 2. **Sync Manager** (`sync_manager.js`)
- Monitors online status
- Auto-syncs every 30 seconds when online
- Handles retry logic for failed syncs
- Provides sync event notifications

**Key Features:**
- Automatic synchronization
- Retry mechanism (max 3 retries)
- Event-driven notifications
- Force sync capability

**Methods:**
```javascript
// Start automatic sync
profileSyncManager.startAutoSync();

// Force immediate sync
await profileSyncManager.forceSync();

// Listen for sync events
profileSyncManager.addEventListener((event, data) => {
    if (event === 'sync-complete') {
        console.log('Synced:', data);
    }
});

// Get sync status
const status = profileSyncManager.getStatus();
```

#### 3. **Connectivity Monitor** (`connectivity_monitor.js`)
- Real-time online/offline detection
- Visual connectivity indicator
- Toast notifications for status changes
- Pending updates badge

**Key Features:**
- Auto-detects connection changes
- Visual feedback (top-right indicator)
- Temporary notifications
- Pending count display

**Methods:**
```javascript
// Show notification
connectivityMonitor.showNotification('Message', 'success');

// Update pending count badge
await connectivityMonitor.updatePendingCount();

// Listen for connectivity changes
connectivityMonitor.addEventListener((status, isOnline) => {
    console.log('Status changed:', status);
});

// Get current status
const status = connectivityMonitor.getStatus();
```

#### 4. **Conflict Resolver** (`conflict_resolver.js`)
- Detects conflicts between offline and online changes
- Multiple resolution strategies
- User-guided conflict resolution
- Conflict logging for audit

**Key Features:**
- Timestamp-based resolution (default)
- Server-wins strategy
- Client-wins strategy
- Manual resolution with UI dialog

**Methods:**
```javascript
// Detect conflict
const conflict = await conflictResolver.detectConflict(localUpdate, serverData);

// Resolve conflict
const resolution = await conflictResolver.resolveConflict(conflict);

// Set resolution strategy
conflictResolver.setStrategy('timestamp'); // timestamp, server-wins, client-wins, manual

// Get conflict log
const log = conflictResolver.getConflictLog();
```

## How It Works

### Normal Flow (Online)
1. User updates profile
2. Form submits to server normally
3. Server processes and returns response
4. UI updates with success message

### Offline Flow
1. **User goes offline** → Connectivity monitor shows "Offline" indicator
2. **User updates profile** → Form submission intercepted by JavaScript
3. **Data saved locally** → Stored in IndexedDB with timestamp
4. **User sees confirmation** → "Changes saved offline" message
5. **Pending badge appears** → Shows count of pending updates
6. **Connection restored** → Connectivity monitor detects online status
7. **Auto-sync triggered** → Sync manager processes pending updates
8. **Server updated** → Changes posted to server via AJAX
9. **Success confirmation** → "Synced successfully" notification
10. **Cleanup** → Synced updates marked and removed from queue

### Conflict Resolution Flow
1. **Conflict detected** → Local timestamp older than server timestamp
2. **Resolution strategy applied:**
   - **Timestamp** (default): Use most recent version
   - **Server-wins**: Always use server data
   - **Client-wins**: Always use local data
   - **Manual**: Show dialog for user to choose
3. **Resolution executed** → Chosen version saved to server
4. **Conflict logged** → Stored for audit purposes

## Database Schema

### IndexedDB Stores

#### `pendingProfileUpdates`
```javascript
{
    id: (auto-increment),
    userId: "user123",
    data: {
        first_name: "John",
        last_name: "Doe",
        email: "john@example.com",
        ...
    },
    timestamp: "2025-10-16T12:00:00Z",
    synced: false,
    retryCount: 0,
    syncedAt: null
}
```

#### `cachedProfiles`
```javascript
{
    userId: "user123",
    data: {
        id: "user123",
        first_name: "John",
        last_name: "Doe",
        ...
    },
    lastUpdated: "2025-10-16T12:00:00Z"
}
```

## Visual Indicators

### Connectivity Indicator
- **Location**: Top-right corner (fixed position)
- **Online**: Green badge with WiFi icon → Fades out after 3 seconds
- **Offline**: Red badge with "Offline" message → Stays visible

### Pending Updates Badge
- **Location**: Near connectivity indicator
- **Display**: Orange badge showing count (e.g., "2 pending updates")
- **Visibility**: Only shown when there are pending updates

### Notifications
- **Location**: Top-right, below indicators
- **Types**:
  - **Success** (green): Sync completed
  - **Warning** (orange): Offline mode activated
  - **Error** (red): Sync failed
  - **Info** (blue): General information
- **Duration**: Auto-dismiss after 5 seconds

## Configuration

### Sync Settings
```javascript
// In sync_manager.js
const retryDelay = 5000;      // 5 seconds between retries
const maxRetries = 3;         // Maximum retry attempts
const syncInterval = 30000;   // Auto-sync every 30 seconds
```

### Conflict Resolution
```javascript
// Change strategy
conflictResolver.setStrategy('timestamp');  // Default
conflictResolver.setStrategy('server-wins'); // Server always wins
conflictResolver.setStrategy('client-wins'); // Client always wins
conflictResolver.setStrategy('manual');      // Ask user to choose
```

## Testing

### Manual Testing Steps

1. **Test Offline Save:**
   ```
   1. Open profile page
   2. Disconnect network (DevTools → Network → Offline)
   3. Update profile (change name, email, etc.)
   4. Click "Update Profile"
   5. Verify "Changes saved offline" message appears
   6. Verify pending badge shows "1 pending update"
   ```

2. **Test Auto-Sync:**
   ```
   1. With pending updates, reconnect network
   2. Wait up to 30 seconds
   3. Verify "Synced successfully" notification appears
   4. Verify pending badge disappears
   5. Refresh page and confirm changes persisted
   ```

3. **Test Force Sync:**
   ```
   1. Open browser console
   2. Run: profileSyncManager.forceSync()
   3. Verify immediate sync attempt
   ```

4. **Test Conflict Resolution:**
   ```
   1. Go offline
   2. Update profile (e.g., change first name to "John")
   3. In another tab/browser (online), update same profile
   4. Go online in first tab
   5. Sync will detect conflict
   6. Verify resolution based on strategy
   ```

5. **Test Connectivity Indicator:**
   ```
   1. Toggle network on/off
   2. Verify indicator changes color and message
   3. Verify notifications appear
   ```

### Browser Console Testing
```javascript
// Check pending updates
const pending = await profileOfflineStorage.getPendingUpdates();
console.log('Pending:', pending);

// Force sync
await profileSyncManager.forceSync();

// Check sync status
console.log('Status:', profileSyncManager.getStatus());

// View conflict log
console.log('Conflicts:', conflictResolver.getConflictLog());
```

## Browser Compatibility

- **Chrome**: ✅ Full support
- **Firefox**: ✅ Full support
- **Edge**: ✅ Full support
- **Safari**: ✅ Full support (iOS 10+)
- **Opera**: ✅ Full support

**Requirements:**
- IndexedDB support
- Service Workers (optional, for enhanced offline)
- ES6+ JavaScript

## Performance

### Storage Limits
- **IndexedDB**: Varies by browser (typically 50% of available disk space)
- **Recommended**: Keep pending updates under 100 items
- **Auto-cleanup**: Synced updates deleted after 5 seconds

### Network Usage
- **Sync frequency**: Every 30 seconds when online
- **Minimal bandwidth**: Only pending updates sent
- **Optimized**: Batch operations when possible

## Troubleshooting

### Issue: Pending updates not syncing
**Solution:**
1. Check network connectivity
2. Open console: `profileSyncManager.getStatus()`
3. Force sync: `profileSyncManager.forceSync()`
4. Check for errors in console

### Issue: Conflict dialog not showing
**Solution:**
1. Verify strategy: `conflictResolver.getStrategy()`
2. Set to manual: `conflictResolver.setStrategy('manual')`

### Issue: IndexedDB not working
**Solution:**
1. Clear browser data
2. Check browser compatibility
3. Verify no private/incognito mode (some browsers restrict IndexedDB)

### Issue: Offline indicator not showing
**Solution:**
1. Refresh page
2. Check console for errors
3. Verify scripts loaded: `typeof connectivityMonitor`

## Future Enhancements

- [ ] Service Worker for true offline caching
- [ ] Background sync API integration
- [ ] Field-level conflict resolution (merge individual fields)
- [ ] Offline analytics tracking
- [ ] Progressive Web App (PWA) support
- [ ] Multi-tab synchronization
- [ ] Compressed storage for large datasets

## Security Considerations

- ✅ All data stored in IndexedDB is client-side only
- ✅ Encrypted HTTPS required for production
- ✅ Session-based authentication maintained
- ✅ No sensitive data (passwords) stored offline
- ⚠️ Clear storage on logout (implement if needed)

## Support

For issues or questions about the offline support module:
1. Check browser console for errors
2. Review conflict log: `conflictResolver.getConflictLog()`
3. Test in different browser
4. Contact development team

---

**Version:** 1.0.0  
**Last Updated:** October 16, 2025  
**Author:** Inventory System Development Team
