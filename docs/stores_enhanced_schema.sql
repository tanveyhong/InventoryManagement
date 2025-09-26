-- Enhanced Store Management Schema
-- This script extends the existing stores table and creates additional tables
-- for the comprehensive store mapping and management module

-- First, let's check if we need to add new columns to the stores table
-- Add coordinates and region support to existing stores table
ALTER TABLE stores 
ADD COLUMN latitude DECIMAL(10,7) NULL COMMENT 'Store latitude coordinate',
ADD COLUMN longitude DECIMAL(10,7) NULL COMMENT 'Store longitude coordinate',
ADD COLUMN region_id INT NULL COMMENT 'Reference to regions table',
ADD COLUMN store_type ENUM('retail', 'warehouse', 'distribution', 'flagship') DEFAULT 'retail',
ADD COLUMN operating_hours TEXT NULL COMMENT 'JSON format operating hours',
ADD COLUMN max_capacity INT DEFAULT 0 COMMENT 'Maximum inventory capacity',
ADD COLUMN store_size DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Store size in square meters',
ADD COLUMN opening_date DATE NULL COMMENT 'Store opening date',
ADD COLUMN timezone VARCHAR(50) DEFAULT 'UTC' COMMENT 'Store timezone',
ADD COLUMN contact_person VARCHAR(100) NULL COMMENT 'Primary contact person',
ADD COLUMN emergency_contact VARCHAR(20) NULL COMMENT 'Emergency contact number',
ADD COLUMN last_inventory_update TIMESTAMP NULL COMMENT 'Last inventory sync timestamp';

-- Create regions table for regional management
CREATE TABLE IF NOT EXISTS regions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL COMMENT 'Region name',
    code VARCHAR(10) UNIQUE NOT NULL COMMENT 'Region code',
    description TEXT NULL COMMENT 'Region description',
    regional_manager VARCHAR(100) NULL COMMENT 'Regional manager name',
    manager_email VARCHAR(100) NULL COMMENT 'Regional manager email',
    manager_phone VARCHAR(20) NULL COMMENT 'Regional manager phone',
    timezone VARCHAR(50) DEFAULT 'UTC' COMMENT 'Region timezone',
    active TINYINT(1) DEFAULT 1 COMMENT 'Active status',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create store_staff table for staff management
CREATE TABLE IF NOT EXISTS store_staff (
    id INT PRIMARY KEY AUTO_INCREMENT,
    store_id INT NOT NULL,
    name VARCHAR(100) NOT NULL COMMENT 'Staff member name',
    position VARCHAR(50) NOT NULL COMMENT 'Staff position/role',
    email VARCHAR(100) NULL COMMENT 'Staff email',
    phone VARCHAR(20) NULL COMMENT 'Staff phone',
    hire_date DATE NULL COMMENT 'Hire date',
    salary DECIMAL(10,2) NULL COMMENT 'Monthly salary',
    is_manager TINYINT(1) DEFAULT 0 COMMENT 'Is store manager',
    active TINYINT(1) DEFAULT 1 COMMENT 'Active status',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
);

-- Create store_performance table for tracking performance metrics
CREATE TABLE IF NOT EXISTS store_performance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    store_id INT NOT NULL,
    metric_date DATE NOT NULL COMMENT 'Date for the metrics',
    daily_sales DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Daily sales amount',
    transaction_count INT DEFAULT 0 COMMENT 'Number of transactions',
    customer_count INT DEFAULT 0 COMMENT 'Number of unique customers',
    inventory_turnover DECIMAL(8,2) DEFAULT 0.00 COMMENT 'Inventory turnover rate',
    profit_margin DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Profit margin percentage',
    operational_cost DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Daily operational costs',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_store_date (store_id, metric_date),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
);

-- Create store_alerts table for store-specific alerts
CREATE TABLE IF NOT EXISTS store_alerts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    store_id INT NOT NULL,
    alert_type ENUM('low_stock', 'expired_products', 'performance', 'maintenance', 'security') NOT NULL,
    title VARCHAR(200) NOT NULL COMMENT 'Alert title',
    message TEXT NOT NULL COMMENT 'Alert message',
    severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    is_read TINYINT(1) DEFAULT 0 COMMENT 'Alert read status',
    acknowledged_by INT NULL COMMENT 'User ID who acknowledged',
    acknowledged_at TIMESTAMP NULL COMMENT 'Acknowledgment timestamp',
    resolved TINYINT(1) DEFAULT 0 COMMENT 'Resolution status',
    resolved_at TIMESTAMP NULL COMMENT 'Resolution timestamp',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE,
    INDEX idx_store_alerts_type (store_id, alert_type),
    INDEX idx_store_alerts_severity (severity),
    INDEX idx_store_alerts_unread (is_read, created_at)
);

-- Create store_inventory_snapshots for historical tracking
CREATE TABLE IF NOT EXISTS store_inventory_snapshots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    store_id INT NOT NULL,
    snapshot_date DATE NOT NULL COMMENT 'Date of the snapshot',
    total_products INT DEFAULT 0 COMMENT 'Total number of products',
    total_quantity INT DEFAULT 0 COMMENT 'Total inventory quantity',
    total_value DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Total inventory value',
    categories_count INT DEFAULT 0 COMMENT 'Number of product categories',
    low_stock_items INT DEFAULT 0 COMMENT 'Number of low stock items',
    expired_items INT DEFAULT 0 COMMENT 'Number of expired items',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_store_snapshot (store_id, snapshot_date),
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
);

-- Add foreign key constraint for region_id in stores table
ALTER TABLE stores 
ADD CONSTRAINT fk_stores_region 
FOREIGN KEY (region_id) REFERENCES regions(id) ON DELETE SET NULL;

-- Insert sample regions
INSERT INTO regions (name, code, description, timezone) VALUES
('North Region', 'NORTH', 'Northern stores region covering major northern cities', 'America/New_York'),
('South Region', 'SOUTH', 'Southern stores region covering southern territories', 'America/Chicago'),
('East Region', 'EAST', 'Eastern coastal region stores', 'America/New_York'),
('West Region', 'WEST', 'Western region including Pacific coast', 'America/Los_Angeles'),
('Central Region', 'CENT', 'Central region covering midwest areas', 'America/Chicago');

-- Create indexes for better performance
CREATE INDEX idx_stores_coordinates ON stores(latitude, longitude);
CREATE INDEX idx_stores_region ON stores(region_id);
CREATE INDEX idx_stores_type ON stores(store_type);
CREATE INDEX idx_performance_date ON store_performance(metric_date);
CREATE INDEX idx_performance_store_date ON store_performance(store_id, metric_date);

-- Create view for store analytics
CREATE OR REPLACE VIEW store_analytics_view AS
SELECT 
    s.id,
    s.name,
    s.code,
    s.city,
    s.state,
    s.store_type,
    r.name as region_name,
    r.code as region_code,
    COUNT(DISTINCT p.id) as total_products,
    COALESCE(SUM(p.quantity), 0) as total_inventory,
    COALESCE(SUM(p.quantity * p.cost_price), 0) as inventory_value,
    COUNT(DISTINCT CASE WHEN p.quantity <= p.reorder_level THEN p.id END) as low_stock_count,
    COUNT(DISTINCT CASE WHEN p.expiry_date < CURDATE() THEN p.id END) as expired_count,
    COUNT(DISTINCT ss.id) as staff_count,
    s.latitude,
    s.longitude,
    s.max_capacity,
    s.store_size,
    s.opening_date,
    s.last_inventory_update
FROM stores s
LEFT JOIN regions r ON s.region_id = r.id
LEFT JOIN products p ON s.id = p.store_id AND p.active = 1
LEFT JOIN store_staff ss ON s.id = ss.store_id AND ss.active = 1
WHERE s.active = 1
GROUP BY s.id, r.id;

-- Create view for regional summary
CREATE OR REPLACE VIEW regional_summary_view AS
SELECT 
    r.id as region_id,
    r.name as region_name,
    r.code as region_code,
    r.regional_manager,
    COUNT(DISTINCT s.id) as total_stores,
    COUNT(DISTINCT CASE WHEN s.active = 1 THEN s.id END) as active_stores,
    COALESCE(SUM(sas.total_products), 0) as total_products,
    COALESCE(SUM(sas.total_inventory), 0) as total_inventory,
    COALESCE(SUM(sas.inventory_value), 0) as total_inventory_value,
    COALESCE(AVG(sp.daily_sales), 0) as avg_daily_sales,
    COALESCE(SUM(sp.daily_sales), 0) as total_sales_last_30_days
FROM regions r
LEFT JOIN stores s ON r.id = s.region_id
LEFT JOIN store_analytics_view sas ON s.id = sas.id
LEFT JOIN store_performance sp ON s.id = sp.store_id 
    AND sp.metric_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
WHERE r.active = 1
GROUP BY r.id;