# Stock Management Workflow

## Overview
This document explains the proper workflow for managing inventory and POS integration.

---

## System Architecture

### 1. **Inventory/Stock Management** (Primary)
- **Location**: `modules/stock/`
- **Purpose**: Central inventory management for all products
- **Features**:
  - Add new products (`add.php`)
  - Edit product details (`edit.php`)
  - **Adjust stock quantities** (`adjust.php`) ✅
  - View product details (`view.php`)
  - Delete products (`delete.php`)
  - List all products with filters (`list.php`)

### 2. **POS Integration** (Distribution)
- **Location**: `modules/pos/stock_pos_integration.php`
- **Purpose**: Distribute products from inventory to POS terminals
- **Features**:
  - View available products from main inventory
  - Batch add products to specific POS stores
  - Creates store-specific SKUs (e.g., `BEV001-S6`, `BEV001-S7`)
  - Products are added to both Firebase and SQL databases

### 3. **POS Terminal** (Sales Only)
- **Location**: `modules/pos/terminal.php`
- **Purpose**: Process sales transactions at store level
- **Features**:
  - View store-specific products
  - Add products to cart
  - Process sales and payments
  - **Automatically deducts stock** when sale is completed
  - Print receipts

---

## Proper Workflow

### ✅ Correct: Stock Adjustment in Inventory Module

**To add stock to products:**

1. Go to **Stock Management** → **List Products** (`modules/stock/list.php`)
2. Find the product you want to adjust
3. Click the **"Adjust"** button
4. Use +/- buttons or type the new quantity
5. Click **"Save"**

**What happens:**
- Stock quantity updated in **Firebase** (primary database)
- Stock quantity updated in **SQL database** (for POS consistency)
- POS cache cleared automatically
- Audit log created with old/new quantities
- Low stock alerts automatically resolved if quantity recovered

### ❌ Incorrect: Stock Adjustment in POS Terminal

**Stock management should NOT happen in POS because:**
- POS is for **sales transactions only**
- Stock adjustments require inventory permissions
- POS users should not have direct inventory control
- Stock changes should be tracked centrally

---

## Database Synchronization

### Dual Database System

**Firebase (Primary)**
- Document-based NoSQL database
- Used for real-time sync across multiple stores
- Primary source of truth

**SQLite (Secondary)**
- Local SQL database for faster queries
- Used by POS terminals for better performance
- Synced automatically from Firebase

### When Stock is Adjusted (`modules/stock/adjust.php`)

```
Stock Adjustment in Inventory Module
            ↓
    Updates Firebase first
            ↓
    Updates SQL database
            ↓
    Clears POS product cache
            ↓
    Logs to audit trail
            ↓
    Resolves low stock alerts
```

### When Sale is Completed (`modules/pos/terminal.php`)

```
Sale Processed in POS Terminal
            ↓
    Deducts quantity from Firebase
            ↓
    Deducts quantity from SQL
            ↓
    Creates sale record
            ↓
    Creates low stock alert if needed
            ↓
    Prints receipt
```

---

## Store-Specific Products

### SKU Format

When products are added to POS stores, they get store-specific SKUs:

- **Original SKU**: `BEV001`
- **Store 6 SKU**: `BEV001-S6`
- **Store 7 SKU**: `BEV001-S7`

**Why?**
- SQL database has UNIQUE constraint on SKU column
- Same product can exist in multiple stores with independent stock levels
- Allows per-store inventory tracking

### Filtering in POS Terminal

POS terminals automatically filter products by:
- `store_id` matches logged-in user's store
- `quantity > 0` (only show products in stock)
- `active = 1` (only show active products)
- `price > 0` (skip incomplete products)
- Valid SKU format (skip Firebase-generated random IDs)

---

## Permissions

### Inventory Management
- **View**: `can_view_inventory`
- **Edit**: `can_edit_inventory`
- **Delete**: `can_delete_inventory`
- **Adjust Stock**: `can_edit_inventory` ✅

### POS Terminal
- **Use POS**: `can_use_pos`
- **Process Sales**: `can_use_pos`
- **View Store Products**: `can_use_pos`
- **Adjust Stock**: ❌ NOT ALLOWED

---

## Common Tasks

### Task: Restock Products

**Steps:**
1. Receive new inventory shipment
2. Go to **Stock Management** → **List Products**
3. Use filters to find products (by store, category, etc.)
4. Click **"Adjust"** on each product
5. Enter new quantity (e.g., increase from 50 to 150)
6. System automatically:
   - Updates both databases
   - Clears POS cache
   - Resolves low stock alerts
   - Shows in POS terminals immediately

### Task: Add New Products to POS Store

**Steps:**
1. Go to **POS** → **Stock POS Integration** (`modules/pos/stock_pos_integration.php`)
2. Select target POS store from dropdown
3. Use filters to find products you want to add
4. Select products (checkbox)
5. Click **"Add Selected Products to Store"**
6. Products appear in POS terminal with store-specific SKUs

### Task: View POS Products

**Steps:**
1. Go to **Stock Management** → **List Products**
2. Use **"Store"** filter dropdown
3. Select your POS store (e.g., "Store 6", "Store 7")
4. View all products available in that store
5. Adjust quantities as needed

### Task: Process a Sale

**Steps:**
1. Open **POS Terminal** (`modules/pos/terminal.php`)
2. Search/browse for products
3. Click products to add to cart
4. Adjust quantities with +/- buttons
5. Enter customer name (optional)
6. Select payment method
7. Click **"Complete Sale"**
8. System automatically deducts stock

---

## Troubleshooting

### Problem: Products not showing in POS

**Check:**
1. Is product added to that specific store? (Use Stock POS Integration)
2. Does product have stock > 0?
3. Is product active (`active = 1`)?
4. Does product have valid price > 0?
5. Clear browser cache (Ctrl+F5)

### Problem: Stock not updating after adjustment

**Check:**
1. Did you use the **Adjust** button in Stock Management? (Not POS)
2. Check browser console for errors
3. Verify permissions (`can_edit_inventory`)
4. Check `storage/logs/` for error messages

### Problem: Duplicate products showing

**Cause:** Products exist in both Firebase and SQL with different formats

**Solution:** Use filters to remove invalid entries:
- SKU length > 50 characters (auto-filtered)
- Price = 0 (auto-filtered)
- Stock = 0 (auto-filtered)
- Random Firebase IDs in SKU (auto-filtered)

---

## Best Practices

✅ **DO:**
- Adjust stock through **Stock Management** module
- Use **Store filters** to view per-store inventory
- Add products to POS through **Stock POS Integration**
- Let POS automatically deduct stock on sales
- Keep SKUs consistent and meaningful

❌ **DON'T:**
- Try to adjust stock directly in POS terminal
- Manually edit database entries
- Use same SKU for products in different stores (system auto-appends store ID)
- Delete products that have sales history
- Skip stock adjustments after receiving shipments

---

## File Reference

| Task | File Path |
|------|-----------|
| View all products | `modules/stock/list.php` |
| Adjust stock quantities | `modules/stock/adjust.php` |
| Add products to POS store | `modules/pos/stock_pos_integration.php` |
| Process sales | `modules/pos/terminal.php` |
| View audit history | `modules/stock/stockAuditHis.php` |
| Check low stock alerts | `modules/alerts/low_stock.php` |

---

## Summary

- **Stock Management** = Add/Edit/Adjust inventory (Central Control)
- **POS Integration** = Distribute products to stores (One-time setup)
- **POS Terminal** = Sell products and auto-deduct stock (Daily operations)

**Remember:** Stock adjustments belong in the **Inventory Management System**, not in POS! 🎯
