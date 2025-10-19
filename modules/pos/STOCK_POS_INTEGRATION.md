# Stock-POS Integration Guide

## Overview
The Stock-POS integration provides real-time synchronization between your inventory management system and point of sale terminals. When a sale is made through POS, stock levels are automatically updated.

## Product Distribution

### Automatic Distribution
Run the distribution script to add products to all POS-enabled stores:

```bash
php modules/pos/distribute_products.php
```

### What Gets Distributed:
- **Common Products** (5 items): Available in all stores
  - Water 500ml
  - Coca-Cola 330ml
  - Bread White
  - Milk 1L
  - Eggs 12pcs

- **Unique Products** (5 items per store): Store-specific inventory
  - Store 1: Beverages (Coffee, Tea, Energy Drinks)
  - Store 2: Snacks (Cookies, Chips, Nuts)
  - Store 3: Food (Rice, Pasta, Canned Goods)
  - Store 4: Dairy (Yogurt, Cheese, Ice Cream)
  - Store 5: Personal Care (Shampoo, Toothpaste, Soap)
  - Store 6: Household (Towels, Detergent, Trash Bags)

### Results:
```
Store #6 (ae): 56 products [POS ENABLED]
Store #7 (duag): 10 products [POS ENABLED]
Store #8 (Vey Hong Tandddd): 10 products [POS ENABLED]
Store #9 (sparkling water): 10 products [POS ENABLED]
Store #10 (Pork John Pork): 10 products [POS ENABLED]
Store #12 (vienna): 10 products [POS ENABLED]
```

## Integration Dashboard

### Access
Navigate to: `modules/pos/stock_pos_integration.php`

### Features:

#### 1. **POS Store Overview**
Each POS-enabled store shows:
- Total products available
- Current stock levels
- Number of sales
- Quick links to POS and Stock

#### 2. **Low Stock Alerts**
- Monitors products below reorder level
- Shows store location
- Displays remaining quantity
- Auto-updates every 30 seconds

#### 3. **Out of Stock Alerts**
- Lists products with zero inventory
- Prevents POS sales of unavailable items
- Helps with reordering

#### 4. **Recent Sales**
- Last 10 POS transactions
- Sale number and amount
- Store and timestamp
- Item count per sale

## How Synchronization Works

### Sale Process Flow:
```
Customer purchase at POS
        ↓
Sale recorded in `sales` table
        ↓
Sale items saved in `sale_items` table
        ↓
Stock automatically deducted from `products` table
        ↓
Stock movement recorded in `stock_movements` table
        ↓
Low stock alerts triggered if quantity <= reorder_level
```

### Database Tables:

#### Products Table
```sql
- id: Product identifier
- name: Product name
- sku: Stock keeping unit
- barcode: Barcode number
- store_id: Which store has this product
- quantity: Current stock level
- reorder_level: Min stock before alert
- price: Selling price
- cost_price: Purchase cost
- active: Product status
```

#### Sales Table
```sql
- id: Sale identifier
- sale_number: Unique sale reference
- store_id: Which store made the sale
- user_id: Cashier/user who processed sale
- total_amount: Total sale value
- payment_method: cash, card, transfer
- created_at: When sale was made
```

#### Sale Items Table
```sql
- id: Line item identifier
- sale_id: Parent sale
- product_id: Product sold
- quantity: How many sold
- unit_price: Price at time of sale
- subtotal: quantity × unit_price
```

#### Stock Movements Table
```sql
- id: Movement identifier
- product_id: Affected product
- movement_type: in, out, adjustment
- quantity_change: How many (+ or -)
- quantity_before: Stock before
- quantity_after: Stock after
- reference_type: sale, purchase, adjustment
- created_at: When movement occurred
```

## Viewing Stock Data

### Stock Module Integration

#### Access Stock List:
Navigate to: `modules/stock/list.php`

#### Filter by Store:
```
modules/stock/list.php?store=6
```
Shows only products for Store #6

#### Filter by Category:
```
modules/stock/list.php?category=Beverages
```
Shows only beverage products

#### Search Products:
```
modules/stock/list.php?search=water
```
Searches product names and SKUs

## Manual Stock Adjustment

If stock levels need correction:

1. Go to **Stock** → **Adjust Stock**
2. Select the product
3. Enter new quantity
4. Add reason for adjustment
5. Save

This creates a stock movement record with type `adjustment`.

## Product Management

### Adding New Products

#### Via Database:
```sql
INSERT INTO products (
    name, sku, barcode, category, 
    cost_price, price, quantity, reorder_level,
    store_id, unit, active
) VALUES (
    'New Product', 'SKU-001', '1234567890',
    'Category', 5.00, 10.00, 100, 20,
    6, 'pcs', 1
);
```

#### Via Stock Module:
1. Go to **Stock** → **Add Product**
2. Fill in product details
3. Select store
4. Set quantity and reorder level
5. Save

### Updating Products

1. Go to **Stock** → **Stock List**
2. Click Edit on product
3. Update details
4. Save changes

### Deactivating Products

Instead of deleting, set `active = 0`:
```sql
UPDATE products SET active = 0 WHERE id = ?;
```

This hides the product from POS but keeps historical data.

## Monitoring & Reports

### Key Metrics to Monitor:

1. **Stock Turnover**
   - How fast products sell
   - Which items are popular

2. **Low Stock Frequency**
   - How often items go low
   - Adjust reorder levels

3. **Out of Stock Events**
   - Lost sales opportunities
   - Customer satisfaction impact

4. **Sales per Store**
   - Performance comparison
   - Inventory allocation

### Export Options:

Stock module supports export to:
- CSV (Excel compatible)
- PDF (Printable reports)
- JSON (API integration)

## Best Practices

### 1. **Regular Stock Checks**
- Physical count weekly
- Compare with system
- Adjust discrepancies

### 2. **Reorder Levels**
- Set based on sales velocity
- Consider lead time
- Adjust seasonally

### 3. **Product Distribution**
- Analyze sales by store
- Move slow items
- Stock fast movers

### 4. **Data Cleanup**
- Archive old sales monthly
- Remove inactive products yearly
- Back up database regularly

## Troubleshooting

### Stock Not Updating After Sale
**Check**:
1. POS completion script
2. Database triggers
3. Transaction rollbacks
4. Error logs

### Negative Stock Values
**Fix**:
```sql
UPDATE products 
SET quantity = GREATEST(0, quantity) 
WHERE quantity < 0;
```

### Duplicate Products
**Prevent**:
- Unique SKU constraint
- Barcode validation
- Store + name combination

## API Endpoints

### Get Products by Store
```
GET /modules/pos/api/get_products.php?store_id=6
```

### Record Sale
```
POST /modules/pos/api/complete_sale.php
{
  "store_id": 6,
  "items": [
    {"product_id": 1, "quantity": 2, "price": 10.00}
  ],
  "payment_method": "cash"
}
```

### Check Stock Level
```
GET /modules/stock/api/stock_level.php?product_id=1
```

## Future Enhancements

- [ ] Real-time sync via WebSockets
- [ ] Mobile app for stock taking
- [ ] Barcode scanner integration
- [ ] Automated reordering
- [ ] Predictive stock analytics
- [ ] Multi-warehouse support

## Support

For issues or questions:
1. Check error logs: `storage/logs/errors.log`
2. Review documentation
3. Contact system administrator

---

**Integration Status**: ✅ Active  
**Last Updated**: October 20, 2025  
**Database**: SQLite with Firebase sync
