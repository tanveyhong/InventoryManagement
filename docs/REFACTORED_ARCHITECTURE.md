# REFACTORED INVENTORY ARCHITECTURE

## Overview
This refactoring implements a **Main Product + Store Variants** system where:
- Main products hold the total inventory across all locations
- Store variants are created when assigning products to specific stores
- Stock changes in stores automatically update the main product

---

## Architecture

### 1. Main Products
**Characteristics:**
- `store_id` = NULL
- SKU format: `BASE-CODE` (e.g., `BEV-001`, `CARE-003`, `FOOD-PASTA`)
- Quantity = Total across all stores
- Created via: `add_main_product.php`

**Example:**
```
ID: 1
Name: Coca-Cola 330ml
SKU: BEV-001
Store ID: NULL
Quantity: 500 (total across all stores)
```

### 2. Store Variants
**Characteristics:**
- `store_id` = specific store ID
- SKU format: `BASE-CODE-S{store_id}` (e.g., `BEV-001-S6`, `CARE-003-S7`)
- Quantity = Stock in that specific store
- Created via: `assign_to_store.php`

**Example:**
```
ID: 10
Name: Coca-Cola 330ml
SKU: BEV-001-S6
Store ID: 6
Quantity: 200 (in Store 6 only)

ID: 11
Name: Coca-Cola 330ml
SKU: BEV-001-S7
Store ID: 7
Quantity: 150 (in Store 7 only)
```

---

## Key Workflows

### Workflow 1: Creating a New Product
```
1. User creates main product via add_main_product.php
   - Name: "Coca-Cola 330ml"
   - SKU: "BEV-001"
   - Initial Quantity: 500
   - Store: None (main product)

2. System creates:
   - SQL record: id=1, sku="BEV-001", store_id=NULL, quantity=500
   - Firebase doc: id="1", sku="BEV-001", store_id=null, quantity=500
```

### Workflow 2: Assigning to Stores
```
1. User clicks "Assign to Store" on main product
   
2. User selects:
   - Store: "Store 6"
   - Quantity to assign: 200

3. System performs:
   a) Create store variant:
      - SKU: "BEV-001-S6"
      - Store ID: 6
      - Quantity: 200
      
   b) Update main product:
      - Quantity: 500 - 200 = 300
      
   c) Log movements:
      - Main product: OUT 200 (Store Assignment)
      - Store variant: IN 200 (Store Assignment)

4. Result:
   - Main Product (BEV-001): 300 remaining
   - Store Variant (BEV-001-S6): 200 at Store 6
```

### Workflow 3: Stock Adjustments (Store Variant)
```
1. User adjusts store variant BEV-001-S6
   - Action: Add 50 units
   - Reason: Restock

2. System performs:
   a) Update store variant:
      - BEV-001-S6: 200 + 50 = 250
      
   b) Recalculate main product:
      - Get all variants: BEV-001-S6 (250), BEV-001-S7 (150)
      - Total: 250 + 150 = 400
      - Update BEV-001: quantity = 400
      
   c) Log movements:
      - BEV-001-S6: IN 50 (Restock)
      - BEV-001: IN 50 (Cascading Update)

3. Result:
   - Store variant updated
   - Main product automatically recalculated
```

### Workflow 4: POS Sale
```
1. POS sale processes for BEV-001-S6:
   - Sold: 10 units

2. System performs:
   a) Update store variant:
      - BEV-001-S6: 250 - 10 = 240
      
   b) Recalculate main product:
      - Total: 240 (S6) + 150 (S7) = 390
      - Update BEV-001: quantity = 390
      
   c) Log movements:
      - BEV-001-S6: OUT 10 (POS Sale)
      - BEV-001: OUT 10 (Cascading Update)

3. Result:
   - Store stock reduced
   - Main product reflects total reduction
```

---

## Database Schema

### Products Table
```sql
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    sku TEXT UNIQUE,
    barcode TEXT,
    description TEXT,
    category TEXT,
    unit TEXT DEFAULT 'pcs',
    cost_price REAL DEFAULT 0,
    price REAL DEFAULT 0,
    quantity INTEGER DEFAULT 0,
    reorder_level INTEGER DEFAULT 0,
    expiry_date DATE,
    store_id INTEGER,  -- NULL for main, ID for variants
    active INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id)
);
```

### Stock Movements Table
```sql
CREATE TABLE stock_movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER,
    store_id INTEGER,
    movement_type TEXT, -- 'in' or 'out'
    quantity INTEGER,
    reference TEXT,
    notes TEXT,
    user_id INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (store_id) REFERENCES stores(id)
);
```

---

## File Structure

### New Files Created
1. **add_main_product.php** - Create main products (no store assignment)
2. **assign_to_store.php** - Assign main product to stores (creates variants)
3. **adjust_refactored.php** - Adjust stock with cascading updates

### Files to Update
1. **list.php** - Display main products and their store variants grouped
2. **adjust.php** - Replace with adjust_refactored.php logic
3. **modules/pos/terminal.php** - Update to handle main product recalculation
4. **sync_sql_to_firebase.php** - Sync both main and variants

---

## Benefits

### 1. Centralized Inventory Control
- Single source of truth for total inventory
- Easy to see total stock across all locations
- Main product shows aggregate data

### 2. Store-Level Tracking
- Each store has its own inventory count
- Store managers can track their specific stock
- Transfer between stores is tracked

### 3. Automatic Synchronization
- Store changes automatically update main product
- No manual recalculation needed
- Data consistency guaranteed

### 4. Clear Hierarchy
```
Main Product (BEV-001) - 500 units total
├── Store Variant (BEV-001-S6) - 200 units
├── Store Variant (BEV-001-S7) - 150 units
└── Store Variant (BEV-001-S8) - 150 units
```

### 5. Audit Trail
- All movements logged at both levels
- Clear visibility of stock flow
- Cascading updates documented

---

## Migration Steps

### Step 1: Backup Current Data
```bash
php backup_database.php
```

### Step 2: Identify Main Products
```sql
-- Find products that should be main products
-- (products without duplicates across stores)
SELECT DISTINCT 
    REPLACE(sku, '-S' || store_id, '') as base_sku,
    name
FROM products
WHERE store_id IS NOT NULL
GROUP BY base_sku;
```

### Step 3: Create Main Products
```bash
php create_main_products.php
```

### Step 4: Update Existing Products
```bash
php convert_to_variants.php
```

### Step 5: Recalculate Main Quantities
```bash
php recalculate_main_quantities.php
```

### Step 6: Verify Data
```bash
php verify_migration.php
```

---

## Usage Guide

### For Store Managers
1. **View Stock**: See your store's variants in the stock list
2. **Adjust Stock**: Use adjust button on your store's variant
3. **Check Total**: View main product to see total across all stores

### For Inventory Managers
1. **Add New Products**: Use "Add Main Product" button
2. **Assign to Stores**: Click "Assign to Store" on main products
3. **Monitor Total**: Main products show aggregate inventory
4. **Transfer Stock**: Adjust down in one store, adjust up in another

### For System Administrators
1. **Monitor Sync**: Check that main quantities = sum of variants
2. **Audit Logs**: Review stock_movements for cascading updates
3. **Firebase Sync**: Verify Firebase matches SQL data

---

## API Endpoints (Future)

### Get Main Product with Variants
```
GET /api/products/{id}/variants
Response: {
    main: {...},
    variants: [...]
}
```

### Assign to Store
```
POST /api/products/{id}/assign
Body: {
    store_id: 6,
    quantity: 200
}
```

### Transfer Between Stores
```
POST /api/products/transfer
Body: {
    from_variant_id: 10,
    to_variant_id: 11,
    quantity: 50
}
```

---

## Troubleshooting

### Issue: Main quantity doesn't match sum of variants
**Solution:**
```bash
php recalculate_main_quantities.php
```

### Issue: Variant created without main product
**Solution:**
```php
// Find orphan variants
SELECT * FROM products 
WHERE store_id IS NOT NULL
AND REPLACE(sku, '-S' || store_id, '') NOT IN (
    SELECT sku FROM products WHERE store_id IS NULL
);
```

### Issue: Duplicate SKUs
**Solution:**
Ensure UNIQUE constraint on (sku, store_id) combination

---

## Future Enhancements

1. **Bulk Assignment**: Assign to multiple stores at once
2. **Auto-Distribution**: Distribute stock evenly across stores
3. **Transfer Wizard**: GUI for moving stock between stores
4. **Low Stock Alerts**: Per-store and aggregate alerts
5. **Forecasting**: Predict stock needs per store
6. **Reorder Management**: Auto-generate purchase orders

---

## Notes
- Always use `add_main_product.php` for new products
- Never manually create products with store_id directly
- Use `assign_to_store.php` to create store variants
- Cascading updates are automatic - don't manually adjust main products
