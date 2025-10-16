# Activity Logging Troubleshooting Guide

## Issue
Activities are not displaying in `profile/activity_manager.php` after creating a new store.

## Debug Steps

### Step 1: Check if Activities Are Being Logged
Navigate to: **`modules/users/profile/debug_activities.php`**

This page will show you:
- ✅ **Test 1**: All activities in Firebase (total count)
- 👤 **Test 2**: Your activities only  
- 🔍 **Test 3**: Non-deleted activities (what activity_manager.php queries)
- 📄 **Test 4**: Data structure of latest activity
- 🔌 **Test 5**: Firebase connection status

**What to look for:**
1. Are there ANY activities in Firebase? (Test 1)
2. Do you see activities for your user ID? (Test 2)
3. Are activities marked as deleted? (Test 3)
4. Does the data structure look correct? (Test 4)

### Step 2: Check Error Logs
Navigate to: **`storage/logs/errors.log`**

Look for entries like:
```
logActivity called: action=store_created, user=xxx, desc=Created new store: XXX
Activity data prepared: {...}
Firebase create result: xxx
Store created with ID: xxx, Name: xxx, User: xxx
Activity logging result: SUCCESS/FAILED
```

**Expected Flow:**
1. Store is created → logs "Store created with ID"
2. logStoreActivity is called → logs "logActivity called"
3. Activity data is prepared → logs "Activity data prepared"
4. Firebase saves the data → logs "Firebase create result"
5. Function returns → logs "Activity logging result: SUCCESS"

### Step 3: Verify Session User ID
Check if `$_SESSION['user_id']` is set:

```php
// In any PHP file
session_start();
echo "User ID: " . ($_SESSION['user_id'] ?? 'NOT SET');
```

**Common Issue:** If user_id is not in session, activities won't be logged.

### Step 4: Test Activity Logging Directly
Navigate to: **`test_activity_logging.php`**

Click "Run All Tests" and verify all tests pass:
- ✅ Basic Activity
- ✅ Store Activity  
- ✅ Profile Activity
- ✅ Product Activity

If tests FAIL → There's an issue with Firebase connection or activity_logger.php
If tests PASS → The issue is with the integration in add.php

### Step 5: Check Activity Manager Query
The activity_manager.php uses this query:

```php
$conditions = [
    ['user_id', '==', $userId],
    ['deleted_at', '==', null]
];
$activities = $db->readAll('user_activities', $conditions, ['created_at', 'DESC']);
```

**Possible Issues:**
1. **User ID mismatch** - Activity logged with different user_id
2. **deleted_at field** - Activities have deleted_at set (even if null)
3. **Collection name** - Using wrong collection name
4. **Permissions** - Non-admin users can only see their own activities

## Common Solutions

### Solution 1: Missing Session
```php
// At the top of add.php (should already be there)
session_start();

// Verify user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Please log in");
}
```

### Solution 2: Firebase Connection Issue
Check `firebase_config.php` and verify credentials are correct.

Test connection:
```php
$db = getDB();
$test = $db->readAll('user_activities', [], null, 1);
if ($test === false || $test === null) {
    echo "Firebase connection failed";
}
```

### Solution 3: Array to String Conversion Error (FIXED)
This was fixed by updating `firebase_rest_client.php` to handle arrays:
```php
if (is_array($value)) {
    $formatted[$key] = ['stringValue' => json_encode($value)];
}
```

### Solution 4: Deleted Activities
Check if activities have `deleted_at` field set:
```php
// This will exclude deleted activities
$conditions = [['deleted_at', '==', null]];
```

## Quick Test Checklist

- [ ] Navigate to `test_activity_logging.php` and run all tests
- [ ] Create a test store and check error logs immediately
- [ ] Go to `modules/users/profile/debug_activities.php` and verify activity appears
- [ ] Check `activity_manager.php` to see if it displays
- [ ] Verify you're logged in as the same user who created the store
- [ ] Check `storage/logs/errors.log` for any error messages

## Expected Data Structure

A properly logged activity should look like:
```json
{
    "id": "auto-generated-id",
    "user_id": "user_xxx",
    "username": "John Doe",
    "action": "store_created",
    "action_type": "store_created",
    "description": "Created new store: My Store",
    "metadata": {
        "module": "stores",
        "store_id": "store_xxx",
        "store_name": "My Store",
        "action_type": "created"
    },
    "ip_address": "127.0.0.1",
    "user_agent": "Mozilla/5.0...",
    "timestamp": "2025-10-16T10:30:00+00:00",
    "created_at": "2025-10-16T10:30:00+00:00"
}
```

## Files to Check

1. **`activity_logger.php`** - Core logging functions
2. **`modules/stores/add.php`** - Store creation with logging
3. **`modules/users/profile/activity_manager.php`** - Activity display
4. **`firebase_rest_client.php`** - Firebase communication
5. **`storage/logs/errors.log`** - Error messages

## Next Steps

1. **First**: Navigate to `debug_activities.php` and see what's in the database
2. **Second**: Create a new store and immediately check the debug page again
3. **Third**: Check error logs for any messages
4. **Fourth**: If activities are in Firebase but not showing, check the activity_manager query logic

---

**Need Help?**
- Check error logs: `storage/logs/errors.log`
- Use debug tool: `modules/users/profile/debug_activities.php`
- Test logging: `test_activity_logging.php`
- Test user update: `test_user_update.php`
