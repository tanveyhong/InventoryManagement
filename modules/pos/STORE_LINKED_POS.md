# Store-Linked POS System Guide

## Overview

The POS Integration Module is now fully linked to your store management system. Each POS terminal operates for a specific store, and all transactions are automatically linked to that store's inventory.

## How It Works

### 1. Store Selection

**When accessing any POS:**
- Users are presented with a **Store Selector** modal
- Shows all stores the user has access to
- Admin users see all stores
- Regular users see only their assigned stores (via `user_stores` table)

**Store Selection Features:**
- Visual store cards with store name, code, and location
- Current selected store is highlighted
- One-click store switching
- Store selection persists in session (`$_SESSION['pos_store_id']`)

### 2. Store-Specific Inventory

**Product Filtering:**
```sql
SELECT * FROM products 
WHERE store_id = ? 
AND deleted_at IS NULL
```

**Benefits:**
- POS shows only products available at the selected store
- Each store maintains separate inventory levels
- No confusion about which products belong where
- Accurate stock levels per location

### 3. Sales Transaction Linking

**Every sale records:**
- `store_id`: Which store the sale occurred at
- `user_id`: Who processed the sale
- `transaction_id`: Unique identifier (TXN-YYYYMMDD-XXXX)
- Customer information
- Payment method
- All line items with quantities

**Database Structure:**
```sql
sales (
    id, transaction_id, user_id, store_id,
    subtotal, tax, discount, total,
    payment_method, customer_name, customer_phone,
    sale_date, created_at
)

sale_items (
    id, sale_id, product_id,
    quantity, price, subtotal,
    created_at
)
```

### 4. Automatic Inventory Updates

**When sale is completed:**

1. **Stock Validation:**
   ```sql
   SELECT quantity FROM products 
   WHERE id = ? AND store_id = ?
   ```

2. **Inventory Deduction:**
   ```sql
   UPDATE products 
   SET quantity = quantity - ? 
   WHERE id = ? AND store_id = ?
   ```

3. **Activity Logging:**
   - Creates `inventory_logs` entry
   - Records user, store, product, quantity change
   - Maintains full audit trail

4. **Transaction Safety:**
   - All operations wrapped in database transaction
   - Rollback on any error
   - Ensures data integrity

## Store Access Control

### User-Store Relationships

**Database Table: `user_stores`**
```sql
CREATE TABLE user_stores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    store_id INT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (store_id) REFERENCES stores(id)
);
```

**Access Logic:**
- Admin users: Access to ALL stores
- Regular users: Access only to assigned stores
- Managers: Access to stores under their management

### Implementing Store Access

**To assign a user to a store:**
```sql
INSERT INTO user_stores (user_id, store_id) 
VALUES (1, 5);
```

**To check user's stores:**
```sql
SELECT s.* FROM stores s
JOIN user_stores us ON s.id = us.store_id
WHERE us.user_id = ?;
```

## POS Features by Store

### Quick Service POS

**Store-Specific Features:**
- Popular products filtered by store
- Category tabs based on store's product categories
- Fast barcode scanning for store products
- Instant cart updates

**Workflow:**
1. Select store from modal
2. POS loads products for that store only
3. Add products to cart
4. Complete sale
5. Inventory updates for that store
6. Transaction recorded with store_id

### Full Retail POS

**Store-Specific Features:**
- Advanced search within store's products
- Category filter based on store's categories
- Stock level filter (In Stock/Low Stock/Out of Stock)
- Customer information per store
- Discount management per store
- Receipt preview with store details

**Workflow:**
1. Select store from modal
2. POS loads products for that store
3. Search and filter products
4. Add to cart with quantity
5. Apply discounts if needed
6. Enter customer information
7. Select payment method
8. Review receipt preview
9. Complete sale
10. Inventory updates for that store
11. Transaction recorded with store_id

### Sales Dashboard

**Store-Specific Analytics:**
```php
// Filter by store
$storeId = $_GET['store'] ?? null;

$query = "
    SELECT 
        COUNT(DISTINCT s.id) as transaction_count,
        SUM(s.total) as total_sales,
        SUM(si.quantity) as total_items
    FROM sales s
    LEFT JOIN sale_items si ON s.id = si.sale_id
    WHERE DATE(s.sale_date) = CURDATE()
";

if ($storeId) {
    $query .= " AND s.store_id = ?";
}
```

**Dashboard Features:**
- Filter by store
- View today's sales per store
- Transaction history per store
- Top selling products per store
- Payment method breakdown per store

## Implementation Details

### API Endpoints

**GET `/modules/pos/api/get_products.php`**
- Accepts `store_id` parameter
- Returns products filtered by store
- Includes stock levels
- Returns store information

**POST `/modules/pos/api/complete_sale.php`**
- Requires `store_id` in request body
- Validates stock availability
- Updates inventory for specific store
- Creates sale record linked to store

### Session Management

**POS Store Session:**
```php
// Set store for POS session
$_SESSION['pos_store_id'] = $_GET['store'];

// Retrieve in other pages
$storeId = $_SESSION['pos_store_id'] ?? null;
```

**Benefits:**
- Maintains store selection across page refreshes
- Separate from main user session
- Can be cleared independently
- Persists through transaction flow

### JavaScript Store Handling

**Get Store from URL:**
```javascript
const urlParams = new URLSearchParams(window.location.search);
const storeId = urlParams.get('store');
```

**Pass to API:**
```javascript
const response = await fetch(
    `api/get_products.php?store_id=${storeId}`
);
```

**Include in Sale Data:**
```javascript
const saleData = {
    store_id: storeId,
    items: cart,
    payment_method: selectedMethod
};
```

## Benefits of Store-Linked POS

### 1. Accurate Inventory Management
- Each store's inventory is independent
- No confusion about which location has what
- Real-time stock levels per store
- Prevents overselling from wrong location

### 2. Location-Based Analytics
- Sales reports per store
- Performance comparison between locations
- Identify best-selling products per store
- Optimize inventory per location

### 3. Multi-Location Support
- Run multiple stores from single system
- Centralized management
- Distributed operations
- Consistent user experience

### 4. Audit Trail
- Every transaction linked to store
- Track who sold what, where, when
- Full history for each location
- Compliance and reporting

### 5. User Management
- Restrict users to specific stores
- Role-based access control
- Manager oversight per location
- Admin access to all stores

## Usage Examples

### Example 1: Convenience Store Chain

**Scenario:** 5 convenience stores in different cities

**Setup:**
1. Create 5 store records
2. Assign products to each store
3. Assign cashiers to their home stores
4. Each store operates Quick Service POS

**Result:**
- Each cashier only sees their store's products
- Sales automatically update that store's inventory
- Owner can view consolidated reports
- Each location's performance tracked separately

### Example 2: Retail Chain with Warehouses

**Scenario:** 3 retail stores + 1 warehouse

**Setup:**
1. Create 4 store records (3 retail + 1 warehouse)
2. Warehouse has all products
3. Retail stores have subset of products
4. Use Full Retail POS for stores
5. Use transfer system for warehouse

**Result:**
- Stores operate independently
- Warehouse manages distribution
- Transfer products between locations
- Track inventory at each location

### Example 3: Franchise Model

**Scenario:** Multiple franchisees, central management

**Setup:**
1. Each franchise is a store
2. Owner/admin sees all locations
3. Franchisee users see only their store
4. Centralized product catalog
5. Store-specific pricing allowed

**Result:**
- Franchisees operate autonomously
- Central office has full visibility
- Consistent branding and products
- Location-specific flexibility

## Reporting Queries

### Sales by Store (Today)
```sql
SELECT 
    s.name as store_name,
    COUNT(sa.id) as transaction_count,
    SUM(sa.total) as total_sales
FROM stores s
LEFT JOIN sales sa ON s.id = sa.store_id 
    AND DATE(sa.sale_date) = CURDATE()
GROUP BY s.id
ORDER BY total_sales DESC;
```

### Inventory Levels by Store
```sql
SELECT 
    s.name as store_name,
    p.name as product_name,
    p.quantity,
    p.reorder_level,
    CASE 
        WHEN p.quantity <= p.reorder_level THEN 'Low Stock'
        WHEN p.quantity = 0 THEN 'Out of Stock'
        ELSE 'In Stock'
    END as status
FROM products p
JOIN stores s ON p.store_id = s.id
ORDER BY s.name, status, p.name;
```

### Best Sellers by Store (Last 30 Days)
```sql
SELECT 
    s.name as store_name,
    p.name as product_name,
    SUM(si.quantity) as total_sold,
    SUM(si.subtotal) as total_revenue
FROM sale_items si
JOIN sales sa ON si.sale_id = sa.id
JOIN stores s ON sa.store_id = s.id
JOIN products p ON si.product_id = p.id
WHERE sa.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
GROUP BY s.id, p.id
ORDER BY s.name, total_sold DESC;
```

## Future Enhancements

### 1. Store Transfer System
- Move inventory between stores
- Track transfers in transit
- Approval workflow
- Transfer history

### 2. Store-Specific Pricing
- Different prices per location
- Promotional pricing per store
- Dynamic pricing rules
- Bulk pricing tiers

### 3. Store Performance Dashboard
- Sales comparison charts
- Inventory turnover rates
- Profitability per store
- Customer traffic analysis

### 4. Mobile POS with Store Locking
- Mobile app with store selection
- Offline mode with sync
- Bluetooth barcode scanners
- Receipt printing

### 5. Multi-Currency Support
- Different currencies per store
- Exchange rate management
- Multi-currency reporting
- Currency conversion

## Troubleshooting

### Problem: Products Not Showing

**Cause:** No products assigned to selected store

**Solution:**
```sql
-- Check products for store
SELECT * FROM products WHERE store_id = 5;

-- Assign products to store
UPDATE products SET store_id = 5 WHERE id IN (1,2,3);
```

### Problem: User Can't See Any Stores

**Cause:** User not assigned to any stores and not admin

**Solution:**
```sql
-- Check user role
SELECT role FROM users WHERE id = 1;

-- Assign user to stores
INSERT INTO user_stores (user_id, store_id) 
VALUES (1, 5), (1, 6);
```

### Problem: Inventory Not Updating

**Cause:** Product has different store_id than sale

**Solution:**
```sql
-- Verify product's store
SELECT id, name, store_id FROM products WHERE id = 10;

-- Check sale's store
SELECT id, transaction_id, store_id FROM sales WHERE id = 100;

-- They should match!
```

### Problem: Store Selector Always Showing

**Cause:** Store not being saved to session

**Solution:**
```php
// Ensure session is started
session_start();

// Check if store is being saved
var_dump($_SESSION['pos_store_id']);

// Clear and reset
unset($_SESSION['pos_store_id']);
```

## Security Considerations

### 1. Store Access Validation
- Always verify user has access to requested store
- Check both database and session
- Prevent store_id manipulation in URLs

### 2. Transaction Integrity
- Use database transactions for sales
- Rollback on any error
- Validate stock before deducting

### 3. Audit Logging
- Log all POS activities
- Record store, user, time, action
- Maintain for compliance

### 4. Data Isolation
- Users should only see their store's data
- Admins override with proper authentication
- Encrypt sensitive customer information

---

**Version:** 1.0.0  
**Last Updated:** January 17, 2025  
**Status:** Production Ready ✅

**Ready for Use:**
- ✅ Store selection modal
- ✅ Product filtering by store
- ✅ Sales linked to stores
- ✅ Automatic inventory updates per store
- ✅ Store switching capability
- ✅ User access control
- ✅ Audit trail per store
