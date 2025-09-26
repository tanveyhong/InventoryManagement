-- PostgreSQL Database Schema for Inventory Management System

-- Create database (run as superuser)
-- CREATE DATABASE inventory_system WITH ENCODING 'UTF8' LC_COLLATE 'en_US.UTF-8' LC_CTYPE 'en_US.UTF-8';
-- \c inventory_system;

-- Enable extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gin";

-- Create ENUM types
CREATE TYPE user_role AS ENUM ('admin', 'manager', 'user');
CREATE TYPE movement_type AS ENUM ('in', 'out', 'adjustment');
CREATE TYPE reference_type AS ENUM ('purchase', 'sale', 'adjustment', 'transfer', 'return');
CREATE TYPE payment_method AS ENUM ('cash', 'card', 'transfer', 'other');
CREATE TYPE payment_status AS ENUM ('pending', 'completed', 'refunded');
CREATE TYPE po_status AS ENUM ('draft', 'sent', 'received', 'cancelled');
CREATE TYPE alert_type AS ENUM ('low_stock', 'expiry', 'overstock', 'custom');
CREATE TYPE alert_priority AS ENUM ('low', 'medium', 'high');

-- Users table with PostgreSQL optimizations
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    phone VARCHAR(20),
    role user_role DEFAULT 'user',
    active BOOLEAN DEFAULT TRUE,
    remember_token VARCHAR(255),
    remember_expires TIMESTAMP WITH TIME ZONE,
    last_login TIMESTAMP WITH TIME ZONE,
    preferences JSONB DEFAULT '{}',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Create indexes for users
CREATE INDEX idx_users_username ON users(username);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_active ON users(active);
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_preferences ON users USING GIN(preferences);

-- Stores table with PostgreSQL features
CREATE TABLE stores (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE,
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(50),
    zip_code VARCHAR(20),
    phone VARCHAR(20),
    email VARCHAR(100),
    manager_name VARCHAR(100),
    description TEXT,
    location POINT, -- PostgreSQL geometric type for GPS coordinates
    settings JSONB DEFAULT '{}',
    active BOOLEAN DEFAULT TRUE,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_stores_name ON stores(name);
CREATE INDEX idx_stores_code ON stores(code);
CREATE INDEX idx_stores_active ON stores(active);
CREATE INDEX idx_stores_location ON stores USING GIST(location);
CREATE INDEX idx_stores_settings ON stores USING GIN(settings);

-- Categories table with hierarchical support
CREATE TABLE categories (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE,
    description TEXT,
    parent_id INTEGER REFERENCES categories(id),
    path TEXT[], -- PostgreSQL array for category hierarchy
    level INTEGER DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    metadata JSONB DEFAULT '{}',
    active BOOLEAN DEFAULT TRUE,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_categories_name ON categories(name);
CREATE INDEX idx_categories_parent ON categories(parent_id);
CREATE INDEX idx_categories_path ON categories USING GIN(path);
CREATE INDEX idx_categories_active ON categories(active);
CREATE INDEX idx_categories_metadata ON categories USING GIN(metadata);

-- Suppliers table
CREATE TABLE suppliers (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE,
    contact_person VARCHAR(100),
    email VARCHAR(100),
    phone VARCHAR(20),
    address TEXT,
    city VARCHAR(100),
    state VARCHAR(50),
    zip_code VARCHAR(20),
    website VARCHAR(255),
    notes TEXT,
    payment_terms JSONB DEFAULT '{}',
    active BOOLEAN DEFAULT TRUE,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_suppliers_name ON suppliers(name);
CREATE INDEX idx_suppliers_code ON suppliers(code);
CREATE INDEX idx_suppliers_active ON suppliers(active);
CREATE INDEX idx_suppliers_payment_terms ON suppliers USING GIN(payment_terms);

-- Products table with PostgreSQL advanced features
CREATE TABLE products (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    name VARCHAR(200) NOT NULL,
    sku VARCHAR(50) UNIQUE,
    barcode VARCHAR(100),
    description TEXT,
    category_id INTEGER REFERENCES categories(id),
    supplier_id INTEGER REFERENCES suppliers(id),
    store_id INTEGER REFERENCES stores(id),
    price NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    cost NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    quantity INTEGER NOT NULL DEFAULT 0,
    min_stock_level INTEGER DEFAULT 10,
    max_stock_level INTEGER DEFAULT 1000,
    unit VARCHAR(20) DEFAULT 'pcs',
    weight NUMERIC(8, 3),
    dimensions VARCHAR(50),
    expiry_date DATE,
    batch_number VARCHAR(50),
    location VARCHAR(100),
    image_urls TEXT[], -- PostgreSQL array for multiple images
    attributes JSONB DEFAULT '{}', -- Flexible product attributes
    tags TEXT[], -- Product tags for search
    search_vector TSVECTOR, -- Full-text search
    active BOOLEAN DEFAULT TRUE,
    created_by INTEGER REFERENCES users(id),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Create indexes for products with PostgreSQL optimizations
CREATE INDEX idx_products_name ON products(name);
CREATE INDEX idx_products_sku ON products(sku);
CREATE INDEX idx_products_barcode ON products(barcode);
CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_supplier ON products(supplier_id);
CREATE INDEX idx_products_store ON products(store_id);
CREATE INDEX idx_products_quantity ON products(quantity);
CREATE INDEX idx_products_active ON products(active);
CREATE INDEX idx_products_expiry ON products(expiry_date);
CREATE INDEX idx_products_attributes ON products USING GIN(attributes);
CREATE INDEX idx_products_tags ON products USING GIN(tags);
CREATE INDEX idx_products_search ON products USING GIN(search_vector);
CREATE INDEX idx_products_price_range ON products(price, active);

-- Stock movements table with partitioning support
CREATE TABLE stock_movements (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    product_id INTEGER NOT NULL REFERENCES products(id),
    user_id INTEGER NOT NULL REFERENCES users(id),
    movement_type movement_type NOT NULL,
    quantity_change INTEGER NOT NULL,
    quantity_before INTEGER NOT NULL DEFAULT 0,
    quantity_after INTEGER NOT NULL DEFAULT 0,
    reference_type reference_type NOT NULL,
    reference_id INTEGER,
    notes TEXT,
    metadata JSONB DEFAULT '{}',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
) PARTITION BY RANGE (created_at);

-- Create partitions for stock movements (monthly partitions)
CREATE TABLE stock_movements_2025_09 PARTITION OF stock_movements
    FOR VALUES FROM ('2025-09-01') TO ('2025-10-01');
CREATE TABLE stock_movements_2025_10 PARTITION OF stock_movements
    FOR VALUES FROM ('2025-10-01') TO ('2025-11-01');

-- Indexes for stock movements
CREATE INDEX idx_stock_movements_product ON stock_movements(product_id);
CREATE INDEX idx_stock_movements_user ON stock_movements(user_id);
CREATE INDEX idx_stock_movements_type ON stock_movements(movement_type);
CREATE INDEX idx_stock_movements_created ON stock_movements(created_at);
CREATE INDEX idx_stock_movements_metadata ON stock_movements USING GIN(metadata);

-- Sales table with better financial tracking
CREATE TABLE sales (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    sale_number VARCHAR(50) UNIQUE,
    store_id INTEGER REFERENCES stores(id),
    user_id INTEGER NOT NULL REFERENCES users(id),
    customer_data JSONB DEFAULT '{}', -- Flexible customer information
    subtotal NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    tax_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    discount_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    total_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    payment_method payment_method DEFAULT 'cash',
    payment_status payment_status DEFAULT 'completed',
    payment_data JSONB DEFAULT '{}', -- Payment details
    notes TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_sales_sale_number ON sales(sale_number);
CREATE INDEX idx_sales_store ON sales(store_id);
CREATE INDEX idx_sales_user ON sales(user_id);
CREATE INDEX idx_sales_created ON sales(created_at);
CREATE INDEX idx_sales_status ON sales(payment_status);
CREATE INDEX idx_sales_customer_data ON sales USING GIN(customer_data);
CREATE INDEX idx_sales_payment_data ON sales USING GIN(payment_data);

-- Sale items table
CREATE TABLE sale_items (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    sale_id INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id),
    quantity INTEGER NOT NULL,
    unit_price NUMERIC(10, 2) NOT NULL,
    discount_amount NUMERIC(10, 2) DEFAULT 0.00,
    subtotal NUMERIC(10, 2) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_sale_items_sale ON sale_items(sale_id);
CREATE INDEX idx_sale_items_product ON sale_items(product_id);

-- Purchase orders with workflow support
CREATE TABLE purchase_orders (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    po_number VARCHAR(50) UNIQUE,
    supplier_id INTEGER NOT NULL REFERENCES suppliers(id),
    store_id INTEGER REFERENCES stores(id),
    user_id INTEGER NOT NULL REFERENCES users(id),
    status po_status DEFAULT 'draft',
    subtotal NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    tax_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    total_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    expected_date DATE,
    received_date DATE,
    notes TEXT,
    workflow_data JSONB DEFAULT '{}', -- Approval workflow
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_purchase_orders_po_number ON purchase_orders(po_number);
CREATE INDEX idx_purchase_orders_supplier ON purchase_orders(supplier_id);
CREATE INDEX idx_purchase_orders_status ON purchase_orders(status);
CREATE INDEX idx_purchase_orders_created ON purchase_orders(created_at);
CREATE INDEX idx_purchase_orders_workflow ON purchase_orders USING GIN(workflow_data);

-- Purchase order items
CREATE TABLE purchase_order_items (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    po_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    product_id INTEGER NOT NULL REFERENCES products(id),
    quantity_ordered INTEGER NOT NULL,
    quantity_received INTEGER DEFAULT 0,
    unit_cost NUMERIC(10, 2) NOT NULL,
    subtotal NUMERIC(10, 2) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_purchase_order_items_po ON purchase_order_items(po_id);
CREATE INDEX idx_purchase_order_items_product ON purchase_order_items(product_id);

-- Alerts table with Redis integration support
CREATE TABLE alerts (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    type alert_type NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    priority alert_priority DEFAULT 'medium',
    product_id INTEGER REFERENCES products(id),
    store_id INTEGER REFERENCES stores(id),
    user_id INTEGER REFERENCES users(id),
    alert_data JSONB DEFAULT '{}',
    is_read BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_alerts_type ON alerts(type);
CREATE INDEX idx_alerts_priority ON alerts(priority);
CREATE INDEX idx_alerts_product ON alerts(product_id);
CREATE INDEX idx_alerts_user ON alerts(user_id);
CREATE INDEX idx_alerts_read ON alerts(is_read);
CREATE INDEX idx_alerts_active ON alerts(is_active);
CREATE INDEX idx_alerts_data ON alerts USING GIN(alert_data);

-- Settings table with JSONB
CREATE TABLE settings (
    id SERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value JSONB,
    description TEXT,
    category VARCHAR(50) DEFAULT 'general',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_settings_key ON settings(setting_key);
CREATE INDEX idx_settings_category ON settings(category);
CREATE INDEX idx_settings_value ON settings USING GIN(setting_value);

-- Audit log with better JSON support
CREATE TABLE audit_log (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID DEFAULT uuid_generate_v4() UNIQUE NOT NULL,
    user_id INTEGER REFERENCES users(id),
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    record_id INTEGER,
    old_values JSONB,
    new_values JSONB,
    ip_address INET,
    user_agent TEXT,
    session_id VARCHAR(255),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
) PARTITION BY RANGE (created_at);

-- Create partitions for audit log (monthly)
CREATE TABLE audit_log_2025_09 PARTITION OF audit_log
    FOR VALUES FROM ('2025-09-01') TO ('2025-10-01');
CREATE TABLE audit_log_2025_10 PARTITION OF audit_log
    FOR VALUES FROM ('2025-10-01') TO ('2025-11-01');

CREATE INDEX idx_audit_log_user ON audit_log(user_id);
CREATE INDEX idx_audit_log_action ON audit_log(action);
CREATE INDEX idx_audit_log_table ON audit_log(table_name);
CREATE INDEX idx_audit_log_created ON audit_log(created_at);
CREATE INDEX idx_audit_log_old_values ON audit_log USING GIN(old_values);
CREATE INDEX idx_audit_log_new_values ON audit_log USING GIN(new_values);

-- Insert default data

-- Default admin user (password: admin123)
INSERT INTO users (username, email, password, first_name, last_name, role) VALUES
('admin', 'admin@inventory.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System', 'Administrator', 'admin');

-- Default categories with hierarchy
INSERT INTO categories (name, code, description, path, level, created_by) VALUES
('Electronics', 'ELEC', 'Electronic devices and accessories', ARRAY['Electronics'], 0, 1),
('Clothing', 'CLTH', 'Apparel and clothing items', ARRAY['Clothing'], 0, 1),
('Food & Beverage', 'FOOD', 'Food and beverage products', ARRAY['Food & Beverage'], 0, 1),
('Books & Media', 'BOOK', 'Books, magazines, and media', ARRAY['Books & Media'], 0, 1),
('Home & Garden', 'HOME', 'Home and garden supplies', ARRAY['Home & Garden'], 0, 1);

-- Default store
INSERT INTO stores (name, code, address, city, state, phone, manager_name, created_by) VALUES
('Main Store', 'MAIN', '123 Main Street', 'Anytown', 'State', '(555) 123-4567', 'Store Manager', 1);

-- Default settings with JSONB
INSERT INTO settings (setting_key, setting_value, description, category) VALUES
('low_stock_threshold', '10', 'Default minimum stock level for low stock alerts', 'inventory'),
('expiry_alert_days', '30', 'Number of days before expiry to trigger alerts', 'inventory'),
('tax_rate', '0.08', 'Default tax rate for sales', 'sales'),
('currency', '"USD"', 'Default currency', 'general'),
('items_per_page', '20', 'Default number of items to display per page', 'general'),
('forecasting_config', '{"algorithm": "linear_regression", "window_size": 30, "confidence_threshold": 0.7}', 'Forecasting configuration', 'forecasting');

-- Create functions and triggers

-- Function to update search vector for products
CREATE OR REPLACE FUNCTION update_product_search_vector()
RETURNS TRIGGER AS $$
BEGIN
    NEW.search_vector := to_tsvector('english', 
        COALESCE(NEW.name, '') || ' ' ||
        COALESCE(NEW.sku, '') || ' ' ||
        COALESCE(NEW.description, '') || ' ' ||
        COALESCE(array_to_string(NEW.tags, ' '), '')
    );
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger to update search vector
CREATE TRIGGER trigger_update_product_search_vector
    BEFORE INSERT OR UPDATE ON products
    FOR EACH ROW
    EXECUTE FUNCTION update_product_search_vector();

-- Function to update category path
CREATE OR REPLACE FUNCTION update_category_path()
RETURNS TRIGGER AS $$
DECLARE
    parent_path TEXT[];
    parent_level INTEGER;
BEGIN
    IF NEW.parent_id IS NULL THEN
        NEW.path := ARRAY[NEW.name];
        NEW.level := 0;
    ELSE
        SELECT path, level INTO parent_path, parent_level
        FROM categories WHERE id = NEW.parent_id;
        
        NEW.path := parent_path || NEW.name;
        NEW.level := parent_level + 1;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger for category hierarchy
CREATE TRIGGER trigger_update_category_path
    BEFORE INSERT OR UPDATE ON categories
    FOR EACH ROW
    EXECUTE FUNCTION update_category_path();

-- Function for stock movement logging
CREATE OR REPLACE FUNCTION log_stock_movement()
RETURNS TRIGGER AS $$
BEGIN
    IF OLD.quantity != NEW.quantity THEN
        INSERT INTO stock_movements (
            product_id, 
            user_id, 
            movement_type, 
            quantity_change,
            quantity_before,
            quantity_after,
            reference_type,
            notes
        ) VALUES (
            NEW.id,
            COALESCE(current_setting('inventory.current_user_id', true)::INTEGER, 1),
            CASE 
                WHEN NEW.quantity > OLD.quantity THEN 'in'::movement_type
                ELSE 'out'::movement_type
            END,
            NEW.quantity - OLD.quantity,
            OLD.quantity,
            NEW.quantity,
            'adjustment'::reference_type,
            'Stock level changed via system'
        );
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger for stock movement logging
CREATE TRIGGER trigger_log_stock_movement
    AFTER UPDATE ON products
    FOR EACH ROW
    WHEN (OLD.quantity IS DISTINCT FROM NEW.quantity)
    EXECUTE FUNCTION log_stock_movement();

-- Create materialized views for performance

-- Low stock products materialized view
CREATE MATERIALIZED VIEW mv_low_stock_products AS
SELECT 
    p.id,
    p.name,
    p.sku,
    p.quantity,
    p.min_stock_level,
    c.name as category_name,
    s.name as store_name,
    p.updated_at
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
LEFT JOIN stores s ON p.store_id = s.id
WHERE p.quantity <= p.min_stock_level 
    AND p.active = TRUE;

CREATE UNIQUE INDEX ON mv_low_stock_products(id);
CREATE INDEX ON mv_low_stock_products(updated_at);

-- Products expiring soon materialized view
CREATE MATERIALIZED VIEW mv_expiring_products AS
SELECT 
    p.id,
    p.name,
    p.sku,
    p.expiry_date,
    p.quantity,
    c.name as category_name,
    s.name as store_name,
    (p.expiry_date - CURRENT_DATE) as days_to_expiry,
    p.updated_at
FROM products p
LEFT JOIN categories c ON p.category_id = c.id
LEFT JOIN stores s ON p.store_id = s.id
WHERE p.expiry_date IS NOT NULL 
    AND p.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '30 days'
    AND p.active = TRUE
ORDER BY p.expiry_date;

CREATE UNIQUE INDEX ON mv_expiring_products(id);
CREATE INDEX ON mv_expiring_products(expiry_date);

-- Sales summary materialized view
CREATE MATERIALIZED VIEW mv_sales_summary AS
SELECT 
    DATE(s.created_at) as sale_date,
    s.store_id,
    st.name as store_name,
    COUNT(s.id) as total_sales,
    SUM(s.total_amount) as total_revenue,
    AVG(s.total_amount) as avg_sale_amount,
    COUNT(DISTINCT si.product_id) as unique_products_sold,
    SUM(si.quantity) as total_items_sold
FROM sales s
LEFT JOIN stores st ON s.store_id = st.id
LEFT JOIN sale_items si ON s.id = si.sale_id
WHERE s.payment_status = 'completed'
GROUP BY DATE(s.created_at), s.store_id, st.name;

CREATE UNIQUE INDEX ON mv_sales_summary(sale_date, store_id);

-- Function to refresh materialized views
CREATE OR REPLACE FUNCTION refresh_materialized_views()
RETURNS VOID AS $$
BEGIN
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_low_stock_products;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_expiring_products;
    REFRESH MATERIALIZED VIEW CONCURRENTLY mv_sales_summary;
END;
$$ LANGUAGE plpgsql;

-- Create a function to partition tables automatically
CREATE OR REPLACE FUNCTION create_monthly_partitions(table_name TEXT, start_date DATE, months_ahead INTEGER DEFAULT 3)
RETURNS VOID AS $$
DECLARE
    partition_date DATE;
    partition_name TEXT;
    start_range TEXT;
    end_range TEXT;
BEGIN
    FOR i IN 0..months_ahead LOOP
        partition_date := date_trunc('month', start_date) + (i || ' months')::INTERVAL;
        partition_name := table_name || '_' || to_char(partition_date, 'YYYY_MM');
        start_range := to_char(partition_date, 'YYYY-MM-DD');
        end_range := to_char(partition_date + INTERVAL '1 month', 'YYYY-MM-DD');
        
        EXECUTE format('
            CREATE TABLE IF NOT EXISTS %I PARTITION OF %I
            FOR VALUES FROM (%L) TO (%L)',
            partition_name, table_name, start_range, end_range
        );
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- Create initial partitions
SELECT create_monthly_partitions('stock_movements', CURRENT_DATE, 6);
SELECT create_monthly_partitions('audit_log', CURRENT_DATE, 6);

COMMIT;