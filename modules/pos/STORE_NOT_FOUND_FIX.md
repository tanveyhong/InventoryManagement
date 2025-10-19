# ✅ "Store not found" Error - FIXED!

## Root Cause
The store list page loads stores from **Firebase** and uses Firebase document IDs (like `"xPHAv0hTdZlwAh0nN7pr"`), but the `enable_pos.php` API was only looking for **SQL integer IDs** (like `6`, `7`, `8`).

## The Fix
Added **smart ID detection** that handles both Firebase IDs and SQL IDs:

```php
// Check if this is a Firebase ID (string) or SQL ID (numeric)
if (is_numeric($storeId)) {
    // It's a SQL ID
    $store = $db->fetch("SELECT id, name, has_pos FROM stores WHERE id = ?", [$storeId]);
} else {
    // It's a Firebase ID
    $store = $db->fetch("SELECT id, name, has_pos FROM stores WHERE firebase_id = ?", [$storeId]);
}

// Use the SQL ID from this point forward
$sqlStoreId = $store['id'];
```

## How It Works Now

### When called from Store List (Firebase ID):
1. User clicks **"Enable POS"** button
2. JavaScript sends Firebase ID: `"DzkGlqw5gMrGxSqrGAMQ"`
3. API detects it's not numeric
4. Looks up store by `firebase_id` column
5. Gets SQL ID and enables POS ✅

### When called from Edit Page (SQL ID):
1. User submits edit form
2. Form sends SQL ID: `8`
3. API detects it's numeric
4. Looks up store by `id` column
5. Enables POS ✅

## Test Results

| Test | Store ID | Type | Result |
|------|----------|------|--------|
| SQL ID | `6` | Integer | ✅ Success |
| SQL ID | `7` | Integer | ✅ Success |
| Firebase ID | `DzkGlqw5gMrGxSqrGAMQ` | String | ✅ Success |
| Invalid ID | `NonExistentFirebaseID` | String | ✅ Correct error |

## Store ID Mapping Example

```
SQL Database:
+----+-------------+-----------------------+---------+
| id | name        | firebase_id           | has_pos |
+----+-------------+-----------------------+---------+
| 6  | ae          | 4Xc7wLZVTFHzepdgrYBR  | 1       |
| 7  | duag        | 6Tu7Q6mKqanz7fEFcLUT  | 1       |
| 8  | Vey Hong... | DzkGlqw5gMrGxSqrGAMQ  | 1       |
+----+-------------+-----------------------+---------+
```

## What Changed

**File**: `modules/stores/api/enable_pos.php`

**Before**:
```php
$store = $db->fetch("SELECT id, name, has_pos FROM stores WHERE id = ?", [$storeId]);
```

**After**:
```php
if (is_numeric($storeId)) {
    $store = $db->fetch("SELECT id, name, has_pos FROM stores WHERE id = ?", [$storeId]);
} else {
    $store = $db->fetch("SELECT id, name, has_pos FROM stores WHERE firebase_id = ?", [$storeId]);
}
$sqlStoreId = $store['id']; // Always use SQL ID for updates
```

## Try It Now! 🎉

Go to **Stores → Store List** and click the **"Enable POS"** button on any store. It will work perfectly!

The error is completely fixed! ✅
