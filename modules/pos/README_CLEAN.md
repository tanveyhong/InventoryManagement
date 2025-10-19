# POS Module - Cleaned Up

Point of Sale system integrated with Firebase store management.

## ✅ Working Features

- **Full Retail POS Interface** - Complete point of sale system
- **Firebase Store Integration** - Auto-selects store from Firebase ID
- **Product Management** - View and sell products with live inventory
- **Cart System** - Add/remove items, adjust quantities
- **Multiple Payment Methods** - Cash, Card, Mobile Pay, Other
- **Transaction Processing** - Complete sales with automatic inventory deduction
- **Barcode Scanning Support** - Search by barcode
- **Category Filtering** - Filter products by category
- **Stock Alerts** - Visual indicators for low/out of stock

## 📁 File Structure

```
modules/pos/
├── full_retail.php          # Main POS interface ⭐
├── sync_firebase_stores.php # Sync stores from Firebase
├── add_sample_products.php  # Add test products (optional)
├── dashboard.php            # POS dashboard
├── install.php              # Database setup
├── schema.sql               # Database schema
├── README.md                # This file
├── api/
│   ├── get_products.php     # Fetch products for POS
│   └── complete_sale.php    # Process checkout
└── storage/                 # Empty (uses main database)
```

## 🚀 How to Use

### From Store List (Recommended)
1. Go to **Store Management** (`modules/stores/list.php`)
2. Find a store with POS enabled (purple "POS" button)
3. Click **POS** button
4. Store is automatically selected and POS opens
5. Start adding products to cart!

### Manual Access
- URL: `modules/pos/full_retail.php?store_firebase_id=<firebase_id>`

## 🔧 Database Configuration

**Fixed Issue**: Database path is now absolute to prevent module-specific database creation.

```php
// config.php
define('DB_NAME', __DIR__ . '/storage/database.sqlite');
```

This ensures all modules use the SAME database at `storage/database.sqlite`.

## 📊 Database Schema

### Products Table
- Uses `active` column (1 = active, 0 = inactive)
- No `deleted_at` column
- Columns: id, name, sku, barcode, category, price, quantity, reorder_level, store_id, etc.

### Stores Table
- Uses `active` column (1 = active, 0 = inactive)
- Has `firebase_id` for Firebase integration
- Has `has_pos` flag to enable POS for specific stores
- Columns: id, name, code, firebase_id, has_pos, active, etc.

### Sales Tables
- `sales` - Transaction records
- `sale_items` - Line items for each sale

## 🔄 Firebase Integration

### How It Works
1. Stores are managed in Firebase
2. `sync_firebase_stores.php` syncs Firebase stores to SQL
3. SQL stores get `firebase_id` field mapped to Firebase document ID
4. POS button passes `?store_firebase_id=xxx` parameter
5. POS queries SQL: `SELECT id FROM stores WHERE firebase_id = ?`
6. Store is auto-selected and POS is ready

### Syncing Stores
```bash
php modules/pos/sync_firebase_stores.php
```

## 🐛 Troubleshooting

### Store Not Auto-Selecting
- ✅ **Fixed**: Database path was relative, causing each module to create its own database
- ✅ **Solution**: Changed to absolute path in `config.php`

### "Central Distribution" Showing Instead of Real Store
- ✅ **Fixed**: POS was using `modules/pos/storage/database.sqlite` with old demo data
- ✅ **Solution**: Removed old database, now uses main database

### Products Not Loading
- ✅ **Fixed**: Query referenced `deleted_at` column that doesn't exist
- ✅ **Solution**: Changed to use `active` column

## 🎯 Sample Products

To add sample products for testing:
```bash
php modules/pos/add_sample_products.php
```

This creates 23 sample products across 6 categories.

## 📝 Recent Changes

- ✅ Removed debug panel
- ✅ Removed test files
- ✅ Removed documentation files (debug guides)
- ✅ Fixed database path to use absolute path
- ✅ Fixed column references (deleted_at → active)
- ✅ Fixed JavaScript syntax error (JSON encoding)
- ✅ Cleaned up debug logging code

## 🎉 Status

**WORKING** - Store auto-selection, product loading, and sales transactions are all functioning correctly!
