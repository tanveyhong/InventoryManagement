# POS Integration Module

## Overview

The POS Integration Module addresses one of the most common gaps in local businesses - delayed inventory adjustments - by enabling instant updates, thus improving stock accuracy and operational efficiency.

## Module Components

### ✅ 1. POS Interface Connector
Two types of POS systems designed for different retail scenarios:
- **Quick Service POS** (`quick_service.php`) - For fast-moving retail
- **Full Retail POS** (`full_retail.php`) - For traditional retail stores

### ✅ 2. Sales Transaction Logger
- Real-time transaction recording
- Detailed sale history with customer information
- Transaction ID generation and tracking
- Payment method logging (Cash, Card, Digital, Check)

### ✅ 3. Inventory Auto-Updater
- Instant inventory deduction upon sale completion
- Stock validation before sale
- Inventory change logging for audit trail
- Automatic low-stock alerts

### ✅ 4. Sales Dashboard
- Real-time sales monitoring (`dashboard.php`)
- Today's sales statistics
- Transaction history
- Performance analytics

## POS Systems

### 1. Quick Service POS (quick_service.php)

**Best for:**
- Convenience stores
- Cafés
- Quick service restaurants
- High-volume, fast-moving retail

**Features:**
- ⚡ Lightning-fast product selection with grid layout
- 🔍 Instant barcode scanning support
- 📱 Category-based product filtering
- 🎯 Popular products quick access (top 12 best-sellers)
- 💳 Quick payment processing (Cash, Card, Digital, Other)
- 🛒 Simple, intuitive cart management
- 📊 Real-time stock level indicators
- ⌨️ Keyboard shortcuts (F2: Search, F9: Checkout)

**Workflow:**
1. Scan barcode or click product
2. Adjust quantities in cart
3. Click checkout
4. Select payment method
5. Complete sale - inventory auto-updates

**Interface:** Single-screen design with products grid and cart sidebar

---

### 2. Full Retail POS (full_retail.php)

**Best for:**
- Clothing stores
- Electronics shops
- General merchandise
- Traditional retail with varied products

**Features:**
- 🛍️ Advanced product search and filtering
- 💰 Discount management (percentage-based)
- 👥 Customer information capture (Name, Phone, Email)
- 🔢 Multiple payment methods (Cash, Card, Digital, Check)
- 📝 Detailed transaction receipts
- ⏸️ Hold transaction functionality
- 📊 Stock level filters (In Stock, Low Stock, Out of Stock)
- 🎨 Professional, detailed interface
- ⌨️ Full keyboard navigation

**Workflow:**
1. Search and filter products
2. Add items to cart with quantity controls
3. Apply discounts if applicable
4. Enter customer information
5. Select payment method
6. Review transaction summary
7. Complete sale with receipt preview

**Interface:** Two-panel layout with detailed product listing and comprehensive cart management

---

## Database Schema

### Sales Table
```sql
- id: Primary key
- transaction_id: Unique transaction identifier (TXN-YYYYMMDD-XXXX)
- user_id: Cashier/User who processed the sale
- store_id: Store where sale occurred
- subtotal: Sale amount before tax and discount
- tax: Tax amount (currently 0%)
- discount: Discount amount applied
- total: Final amount charged
- payment_method: cash|card|digital|check|other
- customer_name: Optional customer name
- customer_phone: Optional customer phone
- customer_email: Optional customer email
- sale_date: Date and time of sale
- created_at: Record creation timestamp
```

### Sale Items Table
```sql
- id: Primary key
- sale_id: Reference to sales table
- product_id: Product sold
- quantity: Number of units sold
- price: Price per unit at time of sale
- subtotal: Total for this line item
- created_at: Record creation timestamp
```

### POS Logs Table
```sql
- id: Primary key
- user_id: User who performed action
- store_id: Store where action occurred
- action: Type of action (sale, void, return, etc.)
- description: Action details
- transaction_id: Related transaction
- amount: Monetary amount involved
- created_at: Log entry timestamp
```

## Installation

### 1. Database Setup

Run the schema file to create necessary tables:

```bash
sqlite3 storage/database.sqlite < modules/pos/schema.sql
```

Or import via PHP:

```php
$db = getDB();
$schema = file_get_contents('modules/pos/schema.sql');
$db->exec($schema);
```

### 2. Verify Tables

Check that tables were created:

```sql
SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'sale%';
```

Expected output:
- sales
- sale_items
- pos_logs

## Usage Guide

### Quick Service POS

#### Starting a Sale:
1. Navigate to `/modules/pos/quick_service.php`
2. Use search bar or scan barcode to find products
3. Click product cards to add to cart
4. Adjust quantities using +/- buttons
5. Click "Checkout" when ready

#### Completing Sale:
1. Select payment method
2. (Optional) Enter customer information
3. Click "Complete Sale"
4. Transaction recorded, inventory updated automatically
5. Cart clears for next customer

#### Keyboard Shortcuts:
- `F2`: Focus search bar
- `F9`: Open checkout (if cart not empty)

---

### Full Retail POS

#### Starting a Sale:
1. Navigate to `/modules/pos/full_retail.php`
2. Use search and filters to find products
3. Click product items to add to cart
4. Manage quantities with +/- controls

#### Applying Discounts:
1. Enter discount percentage (0-100)
2. Click "Apply" button
3. Summary updates automatically

#### Completing Sale:
1. Click "Proceed to Payment"
2. Select payment method
3. Enter customer information (optional but recommended)
4. Review transaction summary
5. Click "Complete & Print Receipt"
6. Transaction recorded, inventory updated

#### Advanced Features:
- **Hold Transaction**: Save current cart for later
- **Stock Filters**: Filter by stock levels
- **Category Filter**: Browse by product category
- **Detailed Receipt Preview**: See exactly what customer receives

---

## API Endpoints

### GET /modules/pos/api/get_products.php

Returns all available products with stock information.

**Response:**
```json
{
  "success": true,
  "products": [
    {
      "id": 1,
      "name": "Product Name",
      "sku": "SKU123",
      "barcode": "1234567890",
      "category": "Electronics",
      "price": "99.99",
      "quantity": 50,
      "reorder_level": 10,
      "in_stock": true,
      "low_stock": false
    }
  ],
  "count": 1
}
```

### POST /modules/pos/api/complete_sale.php

Processes a sale transaction and updates inventory.

**Request:**
```json
{
  "items": [
    {
      "product_id": 1,
      "quantity": 2,
      "price": "99.99"
    }
  ],
  "payment_method": "cash",
  "customer_name": "John Doe",
  "customer_phone": "555-0123",
  "customer_email": "john@example.com",
  "discount_percent": 10,
  "store_id": 1,
  "user_id": 1
}
```

**Response:**
```json
{
  "success": true,
  "message": "Sale completed successfully",
  "transaction_id": "TXN-20250117-0042",
  "sale_id": 42,
  "total": "179.98"
}
```

## Features Comparison

| Feature | Quick Service POS | Full Retail POS |
|---------|-------------------|-----------------|
| **Speed** | ⚡⚡⚡ Ultra Fast | ⚡⚡ Fast |
| **Product Grid** | ✅ Yes | ❌ No (List view) |
| **Barcode Scanning** | ✅ Optimized | ✅ Yes |
| **Discounts** | ❌ No | ✅ Yes (%) |
| **Customer Info** | ✅ Name & Phone | ✅ Name, Phone, Email |
| **Hold Transaction** | ❌ No | ✅ Yes |
| **Stock Filters** | ❌ No | ✅ Yes |
| **Category Filter** | ✅ Tabs | ✅ Dropdown |
| **Payment Methods** | 4 options | 4 options |
| **Receipt Preview** | ❌ No | ✅ Yes |
| **Best For** | Fast checkout | Detailed sales |

## Data Sync

### Real-Time Inventory Updates

When a sale is completed:
1. **Validation**: System checks if sufficient stock available
2. **Transaction**: Sale record created in database
3. **Inventory Update**: Product quantities decreased atomically
4. **Logging**: Inventory changes logged for audit trail
5. **Notifications**: Low-stock alerts triggered if applicable

### Sync Process Flow

```
Sale Initiated
     ↓
Stock Validation ← Check product quantity
     ↓
Begin Transaction
     ↓
Create Sale Record → Generate transaction_id
     ↓
Insert Sale Items → Record each product/quantity/price
     ↓
Update Inventory ← Decrease product.quantity
     ↓
Log Changes → Create inventory_logs entry
     ↓
Commit Transaction
     ↓
Success Response
```

### Error Handling

- **Insufficient Stock**: Transaction rolled back, error returned
- **Database Error**: Transaction rolled back, state preserved
- **Network Error**: Client retries, idempotency ensured

## Security Features

### Access Control
- User authentication required
- Session validation on all requests
- Store-level access restrictions

### Data Validation
- Input sanitization
- SQL injection prevention (prepared statements)
- XSS protection (HTML escaping)
- CSRF protection (session tokens)

### Audit Trail
- All transactions logged with user_id
- Inventory changes tracked
- POS actions recorded in pos_logs table

## Performance Optimization

### Quick Service POS
- Preloads popular products
- Client-side cart management
- Minimal database queries
- Optimized for high transaction volume

### Full Retail POS
- Lazy loading with search filters
- Client-side discount calculations
- Efficient stock queries with indexes
- Batch operations for multi-item sales

## Troubleshooting

### Issue: Products Not Loading

**Check:**
1. Database connection in `config.php`
2. Products table has data
3. Browser console for errors
4. PHP error logs

**Fix:**
```php
// Verify products exist
$db = getDB();
$count = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
echo "Products in database: $count";
```

### Issue: Inventory Not Updating

**Check:**
1. Transaction commits successfully
2. Products table has `quantity` column
3. User has write permissions

**Fix:**
```sql
-- Verify inventory change
SELECT * FROM products WHERE id = X;
-- Check inventory logs
SELECT * FROM inventory_logs WHERE product_id = X ORDER BY created_at DESC LIMIT 5;
```

### Issue: Sale Not Completing

**Check:**
1. Network connection
2. JSON payload format
3. Server PHP errors
4. Database write permissions

**Debug:**
```javascript
// Enable console logging
console.log('Sale data:', saleData);
// Check response
console.log('Server response:', result);
```

## Future Enhancements

- [ ] Offline mode with sync when back online
- [ ] Receipt printing integration
- [ ] Barcode scanner hardware integration
- [ ] Returns and refunds module
- [ ] Loyalty program integration
- [ ] Multiple payment methods per transaction
- [ ] Gift cards and store credit
- [ ] Employee performance tracking
- [ ] Advanced reporting and analytics
- [ ] Integration with accounting software

## Support

For issues or questions:
1. Check this documentation
2. Review error logs in `storage/logs/`
3. Test with sample transactions
4. Verify database integrity

## License

Part of the Inventory Management System.

---

**Version:** 1.0.0  
**Last Updated:** October 17, 2025  
**Status:** Production Ready ✅
