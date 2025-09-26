# Firebase Migration Guide

## Overview
Your inventory system has been migrated from traditional SQL database (SQLite/PostgreSQL) to Firebase Firestore. This document explains the changes and how to use the new Firebase integration.

## Files Created/Modified

### 1. `firebase_config.php`
Contains Firebase project configuration with your credentials.

### 2. `db.php` (Modified)
The Database class now connects to Firebase Firestore instead of SQL databases. New methods:
- `create($collection, $data, $documentId = null)` - Create documents
- `read($collection, $documentId)` - Read a single document
- `readAll($collection, $conditions, $orderBy, $limit)` - Read multiple documents
- `update($collection, $documentId, $data)` - Update documents
- `delete($collection, $documentId)` - Delete documents

### 3. `src/firebase.js`
ES6 module version for modern JavaScript applications.

### 4. `assets/js/firebase.js`
Browser-compatible version that works with CDN includes.

### 5. `firebase_test.html`
Example HTML file showing Firebase integration.

## Database Structure Changes

### SQL Tables → Firebase Collections
```
SQL Tables          →    Firebase Collections
-----------              -------------------
users               →    users
products            →    products  
inventory           →    inventory
stores              →    stores
transactions        →    transactions
alerts              →    alerts
reports             →    reports
```

### SQL Queries → Firebase Operations

#### CREATE (Insert)
```php
// OLD SQL
$sql = "INSERT INTO products (name, price, quantity) VALUES (?, ?, ?)";
$db->query($sql, [$name, $price, $quantity]);

// NEW Firebase
$data = ['name' => $name, 'price' => $price, 'quantity' => $quantity];
$id = $db->create('products', $data);
```

#### READ (Select)
```php
// OLD SQL - Single record
$sql = "SELECT * FROM products WHERE id = ?";
$product = $db->fetch($sql, [$id]);

// NEW Firebase - Single document
$product = $db->read('products', $id);

// OLD SQL - Multiple records
$sql = "SELECT * FROM products WHERE category = ? ORDER BY name LIMIT 10";
$products = $db->fetchAll($sql, [$category]);

// NEW Firebase - Multiple documents
$conditions = [['category', '==', $category]];
$orderBy = ['field' => 'name', 'direction' => 'ASC'];
$products = $db->readAll('products', $conditions, $orderBy, 10);
```

#### UPDATE
```php
// OLD SQL
$sql = "UPDATE products SET price = ?, quantity = ? WHERE id = ?";
$db->query($sql, [$newPrice, $newQuantity, $id]);

// NEW Firebase
$data = ['price' => $newPrice, 'quantity' => $newQuantity];
$db->update('products', $id, $data);
```

#### DELETE
```php
// OLD SQL
$sql = "DELETE FROM products WHERE id = ?";
$db->query($sql, [$id]);

// NEW Firebase
$db->delete('products', $id);
```

## Firestore Query Operators

Firebase uses different operators than SQL:
- `==` (equal)
- `!=` (not equal)
- `<` (less than)
- `<=` (less than or equal)
- `>` (greater than)
- `>=` (greater than or equal)
- `array-contains` (for arrays)
- `array-contains-any` (for arrays)
- `in` (value in array)
- `not-in` (value not in array)

## JavaScript Integration

### Basic Usage
```javascript
// Create a product
const productData = {
    name: 'New Product',
    price: 29.99,
    quantity: 100,
    createdAt: new Date().toISOString()
};
const id = await FirebaseHelper.create('products', productData);

// Read products
const products = await FirebaseHelper.readAll('products');

// Update a product
await FirebaseHelper.update('products', id, { price: 24.99 });

// Delete a product
await FirebaseHelper.delete('products', id);

// Real-time listening
const unsubscribe = FirebaseHelper.listen('products', (products) => {
    console.log('Products updated:', products);
});
```

### Offline Support
```javascript
// Go offline (use cached data)
await goOffline();

// Go online (sync with server)
await goOnline();

// Check if offline
if (isOffline()) {
    console.log('Currently offline');
}

// Listen for network changes
const cleanup = setupNetworkListener(
    () => console.log('Back online'),
    () => console.log('Gone offline')
);
```

## Migration Tips

### 1. Update Your Existing PHP Code
Replace SQL queries with Firebase operations:

```php
// Instead of:
$products = $db->fetchAll("SELECT * FROM products WHERE store_id = ?", [$storeId]);

// Use:
$conditions = [['store_id', '==', $storeId]];
$products = $db->readAll('products', $conditions);
```

### 2. Handle Document IDs
Firebase auto-generates document IDs. Store them for reference:

```php
// Create with auto-generated ID
$productId = $db->create('products', $productData);

// Create with custom ID
$db->create('products', $productData, 'custom-product-id');
```

### 3. Data Structure
Firebase stores documents as key-value pairs (like JSON):

```php
$productData = [
    'name' => 'Product Name',
    'price' => 29.99,
    'categories' => ['electronics', 'gadgets'], // Arrays are supported
    'details' => [                              // Nested objects are supported
        'weight' => '1.5kg',
        'color' => 'blue'
    ],
    'createdAt' => date('c') // ISO 8601 date format
];
```

### 4. Transactions
For complex operations that need to be atomic:

```php
// Firebase transactions are different - implement as needed for your use case
// You may need to use the native Firebase SDK methods for complex transactions
```

## Benefits of Firebase

1. **Real-time updates** - Data syncs in real-time across all clients
2. **Offline support** - Automatic caching and sync when back online
3. **Scalability** - Automatically scales with your application
4. **Security** - Built-in authentication and security rules
5. **No server maintenance** - Fully managed by Google

## Next Steps

1. Update your existing modules to use the new Firebase methods
2. Test the Firebase integration using `firebase_test.html`
3. Configure Firebase security rules in the Firebase Console
4. Set up Firebase Authentication if needed
5. Consider implementing real-time features using Firebase listeners

## Security Notes

- The current configuration includes API keys in the code. For production, consider using environment variables or Firebase security rules.
- Set up Firestore security rules in the Firebase Console to control access to your data.
- Consider implementing Firebase Authentication for user management.