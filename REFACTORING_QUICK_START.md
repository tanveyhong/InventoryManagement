# INVENTORY SYSTEM REFACTORING - QUICK START GUIDE

## What Changed?

### OLD System:
- All products had store_id
- No central inventory tracking
- Hard to see total stock across stores

### NEW System:
- **Main Products** (no store_id) = Total inventory
- **Store Variants** (with store_id) = Stock in each store
- Automatic synchronization between main and variants

---

## New Files Created

1. **add_main_product.php** - Create new main products
2. **assign_to_store.php** - Assign main products to stores
3. **adjust_refactored.php** - Adjust stock with auto-update
4. **migrate_to_refactored_architecture.php** - Migration script
5. **docs/REFACTORED_ARCHITECTURE.md** - Full documentation

---

## How to Migrate Your Current Data

### Step 1: Run Migration Script
```bash
php migrate_to_refactored_architecture.php
```

This will:
- ✓ Create backup of your database
- ✓ Create main products (one per unique base SKU)
- ✓ Convert existing products to store variants
- ✓ Recalculate main product quantities
- ✓ Sync everything to Firebase

### Step 2: Verify Results
1. Open stock list page
2. You should see:
   - Main products (e.g., BEV-001)
   - Store variants indented below (e.g., └─ BEV-001-S6)

---

## How to Use the New System

### Adding New Products
1. Click "Add Main Product" button
2. Fill in product details
3. **Important:** SKU should be base format only (e.g., `BEV-001`)
   - ❌ Don't use: `BEV-001-S6` (system adds this automatically)
   - ✅ Use: `BEV-001`
4. Set initial quantity (optional)
5. Click "Create Main Product"

### Assigning to Stores
1. Find main product in stock list
2. Click "Assign to Store" button
3. Select store from dropdown
4. Enter quantity to assign
5. System will:
   - Create store variant (e.g., BEV-001-S6)
   - Subtract from main product
   - Log stock movements

### Adjusting Stock
1. Click "Adjust" on any product (main or variant)
2. Choose "Add" or "Subtract"
3. Enter quantity and reason
4. **For store variants:** Main product updates automatically
5. **For main products:** Adjust carefully (usually you adjust variants)

---

## Example Workflow

### Scenario: New Product Entry

**Step 1: Create Main Product**
```
Name: Coca-Cola 330ml
SKU: BEV-COLA-330
Quantity: 500
Store: (none - main product)
```
Result: Main product with 500 units total

**Step 2: Assign to Store 6**
```
Main Product: BEV-COLA-330
Store: Store 6
Quantity: 200
```
Result:
- Main Product (BEV-COLA-330): 300 remaining
- Store Variant (BEV-COLA-330-S6): 200 at Store 6

**Step 3: Assign to Store 7**
```
Main Product: BEV-COLA-330
Store: Store 7
Quantity: 150
```
Result:
- Main Product (BEV-COLA-330): 150 remaining
- Store Variant (BEV-COLA-330-S6): 200 at Store 6
- Store Variant (BEV-COLA-330-S7): 150 at Store 7

**Step 4: Restock Store 6**
```
Product: BEV-COLA-330-S6
Action: Add 50 units
Reason: Restock
```
Result:
- Store Variant (BEV-COLA-330-S6): 250 at Store 6
- Main Product (BEV-COLA-330): **200** (auto-calculated: 150 + 250 + 150)

---

## Stock List Display

### Before Migration:
```
❌ BEV-001-S6 (Coca-Cola) - 200 units
❌ BEV-001-S7 (Coca-Cola) - 150 units
No way to see total!
```

### After Migration:
```
✓ Coca-Cola 330ml (BEV-001) - 350 units [MAIN PRODUCT]
  └─ BEV-001-S6 (Store 6) - 200 units
  └─ BEV-001-S7 (Store 7) - 150 units
```

---

## Key Benefits

### 1. Clear Hierarchy
- See total inventory at a glance
- Understand store-level distribution
- Easy to spot imbalances

### 2. Automatic Updates
- Adjust store variant → main updates automatically
- No manual recalculation needed
- Data always consistent

### 3. Better Control
- Centralized inventory management
- Store-specific tracking
- Clear audit trail

### 4. Easier Reporting
- Total inventory across all stores
- Per-store inventory reports
- Stock movement tracking

---

## Important Rules

### DO:
✓ Create main products first (no store)
✓ Use base SKU format (e.g., BEV-001)
✓ Assign to stores via "Assign to Store" button
✓ Adjust store variants for local changes
✓ Let system handle main product calculations

### DON'T:
❌ Create products with store_id directly
❌ Use store suffix in main product SKU
❌ Manually adjust main product quantities
❌ Edit main product after creating variants
❌ Delete main product without deleting variants first

---

## Troubleshooting

### Problem: Main quantity doesn't match sum of variants
**Solution:**
```bash
php recalculate_main_quantities.php
```

### Problem: Can't see main products
**Solution:**
1. Check database: `SELECT * FROM products WHERE store_id IS NULL`
2. Run migration if not done
3. Clear cache and refresh

### Problem: Store variant shows wrong quantity
**Solution:**
1. Adjust the store variant
2. Main product will auto-update
3. Check stock_movements table for audit trail

---

## Migration Checklist

Before running migration:
- [ ] Backup database
- [ ] Note current product count
- [ ] Clear any pending transactions

After running migration:
- [ ] Verify main products created
- [ ] Check store variants converted
- [ ] Test "Add Main Product"
- [ ] Test "Assign to Store"
- [ ] Test stock adjustment
- [ ] Verify Firebase sync

---

## Files to Update (After Migration)

Replace old files with new versions:

1. **modules/stock/add.php** → Use `add_main_product.php` instead
2. **modules/stock/adjust.php** → Replace with `adjust_refactored.php`
3. **modules/stock/list.php** → Already updated with grouping

---

## Support

For issues or questions:
1. Check `docs/REFACTORED_ARCHITECTURE.md` for full documentation
2. Review migration log output
3. Check database backup in `storage/backups/`
4. Restore from backup if needed:
   ```bash
   cp storage/backups/pre_migration_*.sql inventory.db
   ```

---

## Next Steps

1. **Run Migration:**
   ```bash
   php migrate_to_refactored_architecture.php
   ```

2. **Test the System:**
   - Create a test main product
   - Assign to a test store
   - Adjust quantities
   - Verify cascading updates work

3. **Train Users:**
   - Show "Add Main Product" workflow
   - Demonstrate "Assign to Store"
   - Explain automatic quantity updates

4. **Monitor:**
   - Check Firebase usage (should stay low)
   - Verify data consistency
   - Review stock movement logs

---

## Ready to Start?

```bash
cd c:\Users\senpa\InventorySystem
php migrate_to_refactored_architecture.php
```

Type "yes" when prompted to begin migration!
