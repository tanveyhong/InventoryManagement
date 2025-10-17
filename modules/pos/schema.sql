-- POS Integration Module Database Tables
-- Tables for sales transactions, sale items, and POS logs

-- Sales table - Main transaction records
CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    transaction_id VARCHAR(50) UNIQUE NOT NULL,
    user_id INTEGER NOT NULL,
    store_id INTEGER,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    customer_name VARCHAR(100),
    customer_phone VARCHAR(20),
    customer_email VARCHAR(100),
    sale_date DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL
);

-- Sale items table - Individual items in each sale
CREATE TABLE IF NOT EXISTS sale_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sale_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1,
    price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
);

-- POS logs table - Audit trail for POS activities
CREATE TABLE IF NOT EXISTS pos_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    store_id INTEGER,
    action VARCHAR(50) NOT NULL,
    description TEXT,
    transaction_id VARCHAR(50),
    amount DECIMAL(10,2),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_sales_transaction_id ON sales(transaction_id);
CREATE INDEX IF NOT EXISTS idx_sales_user_id ON sales(user_id);
CREATE INDEX IF NOT EXISTS idx_sales_store_id ON sales(store_id);
CREATE INDEX IF NOT EXISTS idx_sales_sale_date ON sales(sale_date);
CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at);

CREATE INDEX IF NOT EXISTS idx_sale_items_sale_id ON sale_items(sale_id);
CREATE INDEX IF NOT EXISTS idx_sale_items_product_id ON sale_items(product_id);

CREATE INDEX IF NOT EXISTS idx_pos_logs_user_id ON pos_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_pos_logs_transaction_id ON pos_logs(transaction_id);
CREATE INDEX IF NOT EXISTS idx_pos_logs_created_at ON pos_logs(created_at);

-- Sample data for testing (optional)
-- INSERT INTO sales (transaction_id, user_id, subtotal, tax, total, payment_method, sale_date) 
-- VALUES ('TXN-20250101-0001', 1, 50.00, 0.00, 50.00, 'cash', datetime('now'));
