# POS Integration Guide

## How to Enable POS for Stores

There are **three ways** to enable POS functionality for a store:

### 1. During Store Creation
When adding a new store:
1. Go to **Stores** → **Add New Store**
2. Scroll to the **POS Integration** section
3. Check the box **"Link POS System to this store"**
4. Fill in optional POS details (Terminal ID, Type)
5. Save the store

### 2. From Store List (Quick Enable)
For existing stores without POS:
1. Go to **Stores** → **Store List**
2. Find the store you want to enable POS for
3. Click the **"Enable POS"** button (green with + icon)
4. Confirm the action
5. The button will change to **"POS"** and you can open the POS system

### 3. Via Store Edit Page
For existing stores:
1. Go to **Stores** → **Store List**
2. Click **Edit** (pencil icon) on the store
3. Scroll to the **POS Integration** section
4. Check the box **"Enable POS System for this store"**
5. Click **Update Store**

## Features

### Store List Actions
- **POS Button** (purple gradient): Opens POS for stores with POS enabled
- **Enable POS Button** (outlined): Shows for stores without POS, enables it with one click

### POS System
- Automatically loads the selected store's inventory
- Displays store name in the POS interface
- Supports barcode scanning
- Real-time inventory sync
- Sales tracking and receipt generation

## Product Management

Products are stored in the database and can be added using:

### Add Products Script
```bash
php modules/pos/add_products.php
```

This script:
- Adds 23 sample products across 6 categories
- Assigns products to a POS-enabled store
- Prevents duplicate entries
- Shows summary of added products

### Categories Included
- Beverages (Coca-Cola, Pepsi, Water, Juice, Coffee)
- Snacks (Chips, Chocolate, Cookies, Candy, Nuts)
- Food (Noodles, Bread, Sandwiches, Rice)
- Dairy (Milk, Yogurt, Cheese)
- Personal Care (Shampoo, Toothpaste, Soap)
- Household (Tissues, Paper Towels, Garbage Bags)

## Database Structure

### Stores Table
- `has_pos`: Boolean (1 = enabled, 0 = disabled)
- `pos_terminal_id`: Optional terminal identifier
- `pos_type`: Type of POS system

### Products Table
- `name`, `sku`, `barcode`: Product identification
- `category`, `price`, `cost_price`: Product details
- `quantity`, `reorder_level`: Inventory management
- `store_id`: Links product to specific store
- `active`: Boolean (1 = active, 0 = inactive)

## API Endpoints

### Enable POS
**Endpoint**: `modules/stores/api/enable_pos.php`
- **Method**: POST
- **Parameters**: `store_id`
- **Response**: JSON with success status

### Get Products
**Endpoint**: `modules/pos/api/get_products.php`
- **Method**: GET
- **Parameters**: `store_id` (optional)
- **Response**: JSON array of products

## Notes

- POS is only visible for stores with `has_pos = 1`
- Products are filtered by `active = 1` and store_id
- Each store can have its own product inventory
- The POS option was removed from the main dashboard for cleaner UI
- POS is now accessed exclusively through the Store List page
