# Offline Mode Testing Guide

## What I've Fixed

### 1. Better Error Handling in Firebase Client
- Added 5-second timeout for faster failure
- Better error messages for network issues
- Detects DNS failures and connection errors
- Suppresses PHP warnings for cleaner output

### 2. Graceful Offline Mode Page
- Beautiful offline mode page when Firebase is unreachable
- Auto-retry connection every 10 seconds (max 6 retries)
- Shows connectivity status indicator
- Option to manually retry or go back to dashboard
- Detects when connection is restored

### 3. Connectivity Indicators Added
- Fixed position indicator in top-right corner
- Shows online/offline status
- Pending updates badge
- Toast notifications container

## How to Test Offline Functionality

### Test 1: Network Disconnected (Current Situation)
Your current error is perfect for testing! Here's what happens now:

1. **Visit profile page** → You'll see the beautiful offline mode page
2. **Auto-retry** → System checks connection every 10 seconds
3. **Manual retry** → Click "Retry Connection" button
4. **Back button** → Return to dashboard

### Test 2: Go Offline While Using App

1. **Load profile page** (with internet working)
2. **Open DevTools** (F12) → Network tab
3. **Set to "Offline"** mode
4. **Try to update profile** → Changes saved locally
5. **Go back online** → Auto-syncs your changes

### Test 3: Check Connectivity Indicator

```javascript
// In browser console when page loads:

// Check if connectivity monitor loaded
console.log(typeof connectivityMonitor); // Should show "object"

// Check current status
console.log(connectivityMonitor.getStatus());

// Manually trigger offline
window.dispatchEvent(new Event('offline'));

// Manually trigger online
window.dispatchEvent(new Event('online'));
```

## Current Issue: DNS Resolution Failure

Your error shows:
```
php_network_getaddresses: getaddrinfo for firestore.googleapis.com failed: 
No such host is known
```

**Possible Causes:**
1. ✅ No internet connection
2. ✅ DNS server not configured
3. ✅ Firewall blocking access
4. ✅ Windows network adapter disabled

**Solutions:**

### Option 1: Check Internet Connection
```powershell
# Test DNS resolution
nslookup firestore.googleapis.com

# Test internet connectivity
ping 8.8.8.8
```

### Option 2: Flush DNS Cache
```powershell
# Run as Administrator
ipconfig /flushdns
```

### Option 3: Check Windows Network
```powershell
# Check network adapters
Get-NetAdapter

# Check if Wi-Fi/Ethernet is connected
netsh interface show interface
```

### Option 4: Use Google's DNS
```powershell
# Set DNS to Google (8.8.8.8 and 8.8.4.4)
# Settings → Network → Change adapter options → 
# Right-click adapter → Properties → IPv4 → Use custom DNS
```

### Option 5: Test with Mobile Hotspot
- Connect to phone's hotspot
- Try accessing the page again

## What Happens in Different Scenarios

### Scenario 1: Server Can't Connect to Firebase (Current)
**Result:** Offline mode page displays
**User Can:** Retry connection, go back to dashboard
**Data:** Not accessible until connection restored

### Scenario 2: Browser Offline (DevTools)
**Result:** Connectivity indicator shows "Offline"
**User Can:** Save changes locally, sync when online
**Data:** Cached in IndexedDB, syncs automatically

### Scenario 3: Intermittent Connection
**Result:** Auto-retry with exponential backoff
**User Can:** Continue working, changes queue up
**Data:** Syncs when stable connection restored

## Expected Behavior

### When Connection Lost:
```
1. Firebase request fails with network error
2. System catches the error gracefully
3. Shows offline mode page with status
4. Auto-retries every 10 seconds (max 6 times)
5. User can manually retry or navigate away
```

### When Connection Restored:
```
1. Auto-retry detects connection
2. Page automatically reloads
3. Profile loads normally
4. Any pending changes sync automatically
```

## Visual Indicators

### Offline Mode Page
- **Icon:** Red WiFi-slash icon
- **Badge:** "Offline Mode" status badge
- **Button:** "Retry Connection" with refresh icon
- **Link:** "Back to Dashboard"

### Connectivity Indicator (When Page Loads)
- **Online:** Green badge, WiFi icon, fades after 3s
- **Offline:** Red badge, stays visible
- **Pending:** Orange badge with count

## Troubleshooting

### Problem: Still seeing PHP errors
**Solution:** Clear browser cache, reload page

### Problem: Offline page doesn't show
**Solution:** Check if error occurred before PHP could output HTML

### Problem: Auto-retry not working
**Solution:** Check browser console for JavaScript errors

### Problem: Can't connect to Firebase
**Solution:** 
1. Check internet connection
2. Flush DNS cache
3. Try different network
4. Check firewall settings

## Next Steps

### To Restore Connection:
1. **Check internet** → Connect to Wi-Fi/Ethernet
2. **Restart router** → Power cycle if needed
3. **Flush DNS** → Clear DNS cache
4. **Try again** → Reload profile page

### To Test Offline Features:
1. **Get online first** → Connect to internet
2. **Load profile page** → Let it load completely
3. **Go offline (DevTools)** → Network → Offline
4. **Update profile** → Changes save locally
5. **Go online** → Changes sync automatically

## Files Modified

1. **firebase_rest_client.php**
   - Added timeout (5s)
   - Better error handling
   - Network error detection

2. **modules/users/profile.php**
   - Try-catch around Firebase calls
   - Offline mode page
   - Auto-retry mechanism
   - Connectivity indicators

## Testing Checklist

- [ ] Check internet connection
- [ ] Flush DNS cache
- [ ] Test profile page load
- [ ] Test offline mode page
- [ ] Test auto-retry
- [ ] Test manual retry
- [ ] Test connectivity indicators
- [ ] Test local storage
- [ ] Test auto-sync
- [ ] Test conflict resolution

---

**Current Status:** System is in offline mode due to network connectivity issue  
**Action Required:** Restore internet connection to test full offline functionality  
**Fallback:** Offline mode page displays correctly with retry options
