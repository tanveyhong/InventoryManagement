# Profile Caching System

## Overview
The profile page now caches user data both server-side (PHP) and client-side (JavaScript/IndexedDB) so you can access your profile even when offline.

## How It Works

### 1. First Visit (Online)
1. Profile page loads from Firebase
2. Data is cached in `/storage/cache/profile_[hash].json`
3. Data is also cached in browser's IndexedDB
4. Data is stored in sessionStorage for quick access

### 2. Subsequent Visits (Online)
1. Fresh data is loaded from Firebase
2. Cache files are updated with latest data
3. Page loads normally

### 3. Offline Visit (No Internet)
1. Firebase connection fails
2. System reads from PHP cache file
3. Page loads with cached data
4. Yellow banner shows "Offline Mode" warning
5. Some features may be limited

## Cache Locations

### Server-Side Cache (PHP)
- **Location:** `storage/cache/profile_[hash].json`
- **Format:**
```json
{
    "user": {
        "id": "abc123",
        "first_name": "John",
        "last_name": "Doe",
        "email": "john@example.com",
        "username": "johndoe",
        "phone": "+1234567890",
        "role": "admin"
    },
    "cached_at": "2025-10-16 14:30:00",
    "user_id": "abc123"
}
```

### Client-Side Caches

#### 1. IndexedDB (Persistent)
- **Database:** `InventorySystemDB`
- **Store:** `cachedProfiles`
- **Lifetime:** Permanent until cleared
- **Purpose:** Long-term offline access

#### 2. SessionStorage (Temporary)
- **Key:** `profileData`
- **Lifetime:** Until browser tab closes
- **Purpose:** Quick page navigation

## Visual Indicators

### Offline Mode Banner
When using cached data, you'll see:
```
⚠️ Offline Mode: You're viewing cached data. Some features may be limited.
Changes will sync when connection is restored.
```

- **Color:** Yellow/Orange
- **Position:** Top of page content
- **Icon:** Exclamation triangle

### Connectivity Indicator
- **Online:** Green badge (top-right), fades after 3s
- **Offline:** Red badge (top-right), stays visible

## Testing

### Test 1: Normal Usage (Online)
```
1. Load profile page → Data fetched from Firebase
2. Check cache file created: storage/cache/profile_*.json
3. Reload page → Fresh data loaded
```

### Test 2: Offline Mode
```
1. Load profile page once (while online)
2. Turn off WiFi
3. Navigate away (to dashboard)
4. Navigate back to profile
5. See yellow "Offline Mode" banner
6. Profile loads from cache!
```

### Test 3: Cache Verification
```powershell
# Check if cache file exists
dir "c:\Users\senpa\InventorySystem\storage\cache\profile_*.json"

# View cache contents
type "c:\Users\senpa\InventorySystem\storage\cache\profile_*.json"
```

### Test 4: Browser Cache
```javascript
// In browser console
// Check sessionStorage
console.log(JSON.parse(sessionStorage.getItem('profileData')));

// Check IndexedDB
const db = await window.indexedDB.open('InventorySystemDB', 1);
```

## Benefits

### ✅ Seamless Offline Access
- **Before:** "Error: Could not load profile"
- **After:** Profile loads with cached data

### ✅ Faster Page Loads
- Session storage provides instant data
- No waiting for Firebase on navigation

### ✅ Better User Experience
- No errors when connection drops
- Clear visual indication of offline mode
- Data syncs automatically when online

### ✅ Data Persistence
- Cache survives browser restarts
- Works even after WiFi toggle

## Cache Management

### Automatic Updates
- Cache updates every time you load profile online
- Fresh data always overwrites old cache
- Timestamp tracks when data was cached

### Manual Cache Clear
```powershell
# Delete PHP cache
del "c:\Users\senpa\InventorySystem\storage\cache\profile_*.json"

# Or delete all caches
del "c:\Users\senpa\InventorySystem\storage\cache\*.json"
```

```javascript
// Clear browser cache
sessionStorage.removeItem('profileData');

// Clear IndexedDB
window.indexedDB.deleteDatabase('InventorySystemDB');
```

## Limitations in Offline Mode

### ❌ Cannot Update
- Profile updates require server connection
- Changes will be saved locally and synced when online

### ❌ Limited Features
- Activity log may not load
- Store assignments may be outdated
- Permissions may not be current

### ✅ Can View
- Basic profile information
- Cached activity data
- UI and navigation

## Troubleshooting

### Problem: "Not logged in" when offline
**Solution:** You must visit profile page at least once while online to cache data

### Problem: Cached data is old
**Solution:** Load profile while online to update cache

### Problem: Cache file not created
**Solution:** Check `storage/cache/` folder permissions

### Problem: Offline mode banner shows when online
**Solution:** Clear browser cache and reload

## Example Scenarios

### Scenario 1: Commute/Travel
```
1. Check profile at home (online) → Data cached
2. Lose connection on train → Can still view profile
3. Arrive at destination (online) → Cache auto-updates
```

### Scenario 2: Unstable Connection
```
1. Load profile (online) → Data cached
2. WiFi drops intermittently → Profile still accessible
3. Connection restored → Fresh data loads, cache updates
```

### Scenario 3: Network Outage
```
1. Profile loaded earlier (cached)
2. Office network goes down
3. Navigate to profile → Loads from cache
4. See offline mode warning
5. Network restored → Auto-syncs
```

## Configuration

### Cache Expiry (Future Enhancement)
Currently caches never expire. To add expiry:

```php
// In profile.php
$cacheMaxAge = 86400; // 24 hours
if (time() - strtotime($cacheData['cached_at']) > $cacheMaxAge) {
    // Cache expired, force refresh
}
```

### Cache Size Limit
- PHP cache: ~5KB per user
- IndexedDB: Varies by browser (typically MB+)
- SessionStorage: ~5-10MB per origin

## Security

- ✅ Cache files stored server-side
- ✅ Not accessible via web
- ✅ User-specific (hashed user ID)
- ⚠️ Clear cache on logout (implement if needed)

## Next Steps

To add caching to other pages:
1. Copy the cache logic from profile.php
2. Adjust cache file naming
3. Add offline mode banner
4. Test offline functionality

---

**Status:** ✅ Fully Implemented  
**Last Updated:** October 16, 2025  
**Tested:** Windows 11, PHP 8.2, Chrome/Edge/Firefox
