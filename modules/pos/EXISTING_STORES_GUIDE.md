# Using POS with Existing Stores

## Your Existing Stores

You have **5 stores** in your system:

1. **Downtown Store** (ID: 1)
   - Code: DT001
   - City: New York
   - Address: 123 Main St

2. **Westside Warehouse** (ID: 2)
   - Code: WW002
   - City: Los Angeles
   - Address: 456 West Ave

3. **Central Distribution** (ID: 3)
   - Code: CD003
   - City: Chicago
   - Address: 789 Central Blvd

4. **North Retail** (ID: 4)
   - Code: NR004
   - City: Boston
   - Address: 321 North St

5. **South Store** (ID: 5)
   - Code: SS005
   - City: Atlanta
   - Address: 654 South Rd

## Quick Start Guide

### Step 1: Assign Products to Stores

Before using the POS, make sure your products are assigned to stores:

**Option A: Assign all products to one store (Quick Setup)**
```sql
UPDATE products SET store_id = 1 WHERE store_id IS NULL OR store_id = 0;
```

**Option B: Distribute products across stores**
```sql
-- Assign products 1-10 to Downtown Store
UPDATE products SET store_id = 1 WHERE id BETWEEN 1 AND 10;

-- Assign products 11-20 to Westside Warehouse
UPDATE products SET store_id = 2 WHERE id BETWEEN 11 AND 20;

-- And so on...
```

**Option C: Use the Product Management Module**
1. Go to Stock → View Stock
2. Edit each product
3. Select the store in the "Store" dropdown

### Step 2: Access POS

**From Dashboard:**
1. Open your main dashboard: `http://localhost/index.php`
2. See the "Point of Sale Systems" section
3. Click on either:
   - **Quick Service POS** - For fast checkout (cafés, convenience stores)
   - **Full Retail POS** - For detailed sales (retail stores)

**Direct URLs:**
- Quick Service: `http://localhost/modules/pos/quick_service.php`
- Full Retail: `http://localhost/modules/pos/full_retail.php`
- Sales Dashboard: `http://localhost/modules/pos/dashboard.php`

### Step 3: Select Store

When you open a POS system:

**If you have multiple stores:**
- A store selector modal will appear
- Click on the store you want to operate
- The POS will load products for that store only

**If you have only one store:**
- It will auto-select automatically
- No modal needed - starts immediately

**Switch stores:**
- Click the switch icon (⇄) in the top right corner
- Only available if you have access to multiple stores

## Features

### Store-Specific Operations

✅ **Products Filtered by Store**
- Each POS only shows products assigned to the selected store
- No confusion about which products belong where

✅ **Inventory Updates Per Store**
- When you sell a product, only that store's inventory decreases
- Other stores' inventory remains unchanged

✅ **Sales Tracked by Store**
- Every transaction records which store it occurred at
- Generate reports per store
- Track performance by location

✅ **User Access Control**
- Admin users: See all stores
- Regular users: See only assigned stores (via user_stores table)
- Assign users to specific stores for security

## Checking Your Setup

### Verify Products Have Stores

Run this to check:
```bash
php modules/pos/check_stores.php
```

Or query the database:
```sql
-- Check how many products per store
SELECT 
    s.name as store_name,
    COUNT(p.id) as product_count
FROM stores s
LEFT JOIN products p ON s.id = p.store_id
GROUP BY s.id, s.name;
```

### View Products Without Stores

```sql
SELECT id, name, sku, category 
FROM products 
WHERE store_id IS NULL OR store_id = 0
LIMIT 10;
```

## Example Workflows

### Workflow 1: Single Store Operation

**Scenario:** You operate "Downtown Store" only

**Setup:**
```sql
-- Assign all products to Downtown Store
UPDATE products SET store_id = 1;
```

**Usage:**
1. Open Quick Service POS
2. Store auto-selects to Downtown Store
3. All products are available
4. Complete sales normally
5. Downtown Store's inventory updates

### Workflow 2: Multi-Store Chain

**Scenario:** You have 5 stores, each with different products

**Setup:**
```sql
-- Downtown Store: Electronics
UPDATE products SET store_id = 1 WHERE category = 'Electronics';

-- Westside Warehouse: Groceries
UPDATE products SET store_id = 2 WHERE category = 'Groceries';

-- Central Distribution: Mix of products
UPDATE products SET store_id = 3 WHERE category IN ('Clothing', 'Home');

-- North Retail: Specialty items
UPDATE products SET store_id = 4 WHERE category = 'Specialty';

-- South Store: General merchandise
UPDATE products SET store_id = 5 WHERE category = 'General';
```

**Usage:**
1. Cashier at Downtown Store opens POS
2. Selects "Downtown Store" from modal
3. Sees only Electronics products
4. Completes sale
5. Downtown Store's electronics inventory updates

### Workflow 3: Warehouse + Retail

**Scenario:** Westside Warehouse supplies other stores

**Setup:**
1. Warehouse (Store 2) has all products (large quantities)
2. Retail stores have subset of products
3. Use transfer system to move inventory

**POS Usage:**
- Warehouse uses Full Retail POS for B2B sales
- Retail stores use Quick Service POS for customers
- Each location's inventory tracked separately

## Testing Your Setup

### Test 1: Store Selection

1. Open POS: `http://localhost/modules/pos/quick_service.php`
2. **Expected:** Store selector modal appears
3. **Verify:** All 5 stores are listed
4. Click "Downtown Store"
5. **Expected:** Modal closes, POS loads

### Test 2: Product Loading

1. After selecting a store
2. **Expected:** Products grid displays
3. **Verify:** Only products from that store show
4. Search for a product
5. **Expected:** Filtered results appear

### Test 3: Complete Sale

1. Add a product to cart
2. Click "Checkout"
3. Select payment method
4. Click "Complete Sale"
5. **Expected:** 
   - Success message with transaction ID
   - Cart clears
   - Product inventory decreased

### Test 4: Verify Database

```sql
-- Check latest sale
SELECT 
    s.transaction_id,
    s.total,
    st.name as store_name,
    u.username as cashier
FROM sales s
JOIN stores st ON s.store_id = st.id
JOIN users u ON s.user_id = u.id
ORDER BY s.created_at DESC
LIMIT 1;

-- Check updated inventory
SELECT 
    p.name,
    p.quantity,
    s.name as store_name
FROM products p
JOIN stores s ON p.store_id = s.id
ORDER BY p.updated_at DESC
LIMIT 5;
```

## Troubleshooting

### Issue: Store selector shows no stores

**Solution:**
```sql
-- Verify stores exist
SELECT * FROM stores;

-- If no stores, add one
INSERT INTO stores (name, code, city, address) 
VALUES ('Main Store', 'MS001', 'City', '123 Main St');
```

### Issue: No products appear after selecting store

**Cause:** Products not assigned to that store

**Solution:**
```sql
-- Check which products belong to store 1
SELECT id, name, store_id FROM products WHERE store_id = 1;

-- Assign products to store 1
UPDATE products SET store_id = 1 WHERE id IN (1,2,3,4,5);
```

### Issue: Sale completes but inventory doesn't update

**Cause:** Product might belong to different store than selected

**Solution:**
```sql
-- Verify product's store matches sale's store
SELECT 
    p.id, p.name, p.store_id as product_store,
    s.store_id as sale_store
FROM sale_items si
JOIN products p ON si.product_id = p.id
JOIN sales s ON si.sale_id = s.id
ORDER BY si.created_at DESC
LIMIT 5;
```

## Quick Setup Script

Want to quickly assign all products to Downtown Store? Run this:

```sql
-- Assign all products to Downtown Store (ID: 1)
UPDATE products 
SET store_id = 1 
WHERE store_id IS NULL OR store_id = 0 OR store_id = '';

-- Verify
SELECT 
    'Total Products' as metric,
    COUNT(*) as count
FROM products
UNION ALL
SELECT 
    'Products with Store' as metric,
    COUNT(*) as count
FROM products 
WHERE store_id IS NOT NULL AND store_id != 0;
```

---

## Next Steps

1. ✅ **Verify your stores** - Run `check_stores.php`
2. ✅ **Assign products to stores** - Use SQL or Product Management UI
3. ✅ **Test POS system** - Complete a test transaction
4. ✅ **Verify inventory update** - Check products table
5. ✅ **Review sales data** - Check sales and sale_items tables

**You're ready to go!** Your POS systems are now fully integrated with your existing stores. 🎉
