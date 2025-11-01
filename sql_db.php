<?php
// SQL Database Connection Class
class SQLDatabase {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            if (DB_DRIVER === 'sqlite' || DB_TYPE === 'sqlite') {
                // SQLite configuration
                $db_dir = dirname(DB_NAME);
                if (!file_exists($db_dir)) {
                    mkdir($db_dir, 0777, true);
                }
                
                $dsn = 'sqlite:' . DB_NAME;
                $this->pdo = new PDO($dsn);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                
                // Initialize tables
                $this->initializeTables();
                
                // Upgrade existing tables if needed
                $this->upgradeDatabase();
                
            } elseif (DB_DRIVER === 'pgsql' || DB_TYPE === 'pgsql') {
                // PostgreSQL configuration
                $host = defined('PG_HOST') ? PG_HOST : 'localhost';
                $port = defined('PG_PORT') ? PG_PORT : 5432;
                $database = defined('PG_DATABASE') ? PG_DATABASE : 'inventory_system';
                $username = defined('PG_USERNAME') ? PG_USERNAME : 'postgres';
                $password = defined('PG_PASSWORD') ? PG_PASSWORD : '';
                
                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
                
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => false,
                ];
                
                $this->pdo = new PDO($dsn, $username, $password, $options);
                
                // Set search path for PostgreSQL
                $this->pdo->exec("SET search_path TO public");
                
            } elseif (DB_DRIVER === 'mysql' || DB_TYPE === 'mysql') {
                // MySQL configuration
                $host = defined('MYSQL_HOST') ? MYSQL_HOST : 'localhost';
                $port = defined('MYSQL_PORT') ? MYSQL_PORT : 3306;
                $database = defined('MYSQL_DATABASE') ? MYSQL_DATABASE : 'inventory_system';
                $username = defined('MYSQL_USERNAME') ? MYSQL_USERNAME : 'root';
                $password = defined('MYSQL_PASSWORD') ? MYSQL_PASSWORD : '';
                
                $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
                
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => false,
                ];
                
                $this->pdo = new PDO($dsn, $username, $password, $options);
            } else {
                throw new Exception("Unsupported database driver: " . (defined('DB_TYPE') ? DB_TYPE : DB_DRIVER));
            }
            
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    // Initialize tables for SQLite
    private function initializeTables() {
        try {
            // Create basic stores table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS stores (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    code VARCHAR(50) UNIQUE,
                    address TEXT,
                    city VARCHAR(100),
                    state VARCHAR(100),
                    zip_code VARCHAR(20),
                    phone VARCHAR(20),
                    email VARCHAR(100),
                    manager_name VARCHAR(100),
                    description TEXT,
                    latitude DECIMAL(10,7),
                    longitude DECIMAL(10,7),
                    region_id INTEGER,
                    store_type VARCHAR(50) DEFAULT 'retail',
                    status VARCHAR(20) DEFAULT 'active',
                    operating_hours TEXT,
                    max_capacity INTEGER DEFAULT 0,
                    store_size DECIMAL(10,2) DEFAULT 0.00,
                    opening_date DATE,
                    timezone VARCHAR(50) DEFAULT 'UTC',
                    contact_person VARCHAR(100),
                    emergency_contact VARCHAR(20),
                    last_inventory_update TIMESTAMP,
                    active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Create regions table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS regions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(100) NOT NULL,
                    code VARCHAR(10) UNIQUE NOT NULL,
                    description TEXT,
                    regional_manager VARCHAR(100),
                    manager_email VARCHAR(100),
                    manager_phone VARCHAR(20),
                    timezone VARCHAR(50) DEFAULT 'UTC',
                    active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Create products table for inventory
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS products (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    sku VARCHAR(100) UNIQUE,
                    barcode VARCHAR(100),
                    description TEXT,
                    category VARCHAR(100),
                    unit VARCHAR(20),
                    cost_price DECIMAL(10,2) DEFAULT 0.00,
                    selling_price DECIMAL(10,2) DEFAULT 0.00,
                    price DECIMAL(10,2) DEFAULT 0.00,
                    quantity INTEGER DEFAULT 0,
                    reorder_level INTEGER DEFAULT 0,
                    expiry_date DATE,
                    store_id INTEGER,
                    active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (store_id) REFERENCES stores(id)
                )
            ");

                // Create stock_movements table (basic schema)
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS stock_movements (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        product_id INTEGER NOT NULL,
                        user_id INTEGER,
                        store_id INTEGER,
                        movement_type VARCHAR(50) NOT NULL,
                        quantity INTEGER NOT NULL,
                        reference VARCHAR(100),
                        notes TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (product_id) REFERENCES products(id)
                    )
                ");

                // Ensure compatibility: if an existing table lacks `store_id`, add it
                try {
                    $cols = $this->pdo->query("PRAGMA table_info(stock_movements)")->fetchAll(PDO::FETCH_ASSOC);
                    $hasStoreId = false;
                    foreach ($cols as $col) {
                        if (isset($col['name']) && $col['name'] === 'store_id') {
                            $hasStoreId = true;
                            break;
                        }
                    }

                    if (!$hasStoreId) {
                        $this->pdo->exec("ALTER TABLE stock_movements ADD COLUMN store_id INTEGER");
                    }
                } catch (PDOException $e) {
                    // Non-fatal: log and continue
                    error_log('Failed to ensure stock_movements.store_id column: ' . $e->getMessage());
                }
            
            // Create store_performance table for analytics
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS store_performance (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id INTEGER,
                    metric_date DATE,
                    daily_sales DECIMAL(10,2) DEFAULT 0.00,
                    customer_count INTEGER DEFAULT 0,
                    avg_transaction_value DECIMAL(10,2) DEFAULT 0.00,
                    staff_rating DECIMAL(3,2) DEFAULT 0.00,
                    inventory_turnover DECIMAL(5,2) DEFAULT 0.00,
                    total_sales DECIMAL(12,2) DEFAULT 0.00,
                    avg_rating DECIMAL(3,2) DEFAULT 0.00,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (store_id) REFERENCES stores(id)
                )
            ");
            
            // Create store_staff table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS store_staff (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id INTEGER,
                    user_id INTEGER,
                    name VARCHAR(100) NOT NULL,
                    position VARCHAR(50),
                    is_manager BOOLEAN DEFAULT 0,
                    hire_date DATE,
                    hourly_rate DECIMAL(8,2) DEFAULT 0.00,
                    active BOOLEAN DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (store_id) REFERENCES stores(id)
                )
            ");
            
            // Create store_alerts table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS store_alerts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    store_id INTEGER,
                    product_id INTEGER NULL,
                    alert_type VARCHAR(50),
                    title VARCHAR(255),
                    message TEXT,
                    priority VARCHAR(20) DEFAULT 'medium',
                    is_resolved BOOLEAN DEFAULT 0,
                    resolved_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (store_id) REFERENCES stores(id),
                    FOREIGN KEY (product_id) REFERENCES products(id)
                )
            ");
            
            // Create shift_logs table for staff tracking
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS shift_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    staff_id INTEGER,
                    date DATE,
                    start_time TIME,
                    end_time TIME,
                    break_minutes INTEGER DEFAULT 0,
                    total_hours DECIMAL(4,2) DEFAULT 0.00,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (staff_id) REFERENCES store_staff(id)
                )
            ");
            
            // Insert sample data if tables are empty
            $count = $this->pdo->query("SELECT COUNT(*) FROM regions")->fetchColumn();
            if ($count == 0) {
                $this->insertSampleData();
            }
            
        } catch (PDOException $e) {
            error_log("Failed to initialize tables: " . $e->getMessage());
        }
    }
    
    // Upgrade existing database schema
    private function upgradeDatabase() {
        try {
            // Check if status column exists in stores table
            $columns = $this->pdo->query("PRAGMA table_info(stores)")->fetchAll();
            $hasStatus = false;
            foreach ($columns as $column) {
                if ($column['name'] === 'status') {
                    $hasStatus = true;
                    break;
                }
            }
            
            // Add status column if missing
            if (!$hasStatus) {
                $this->pdo->exec("ALTER TABLE stores ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
                // Update existing records to have 'active' status
                $this->pdo->exec("UPDATE stores SET status = 'active' WHERE status IS NULL");
            }
            
            // Check if price column exists in products table
            $productColumns = $this->pdo->query("PRAGMA table_info(products)")->fetchAll();
            $hasPrice = false;
            foreach ($productColumns as $column) {
                if ($column['name'] === 'price') {
                    $hasPrice = true;
                    break;
                }
            }
            
            // Add price column if missing
            if (!$hasPrice) {
                $this->pdo->exec("ALTER TABLE products ADD COLUMN price DECIMAL(10,2) DEFAULT 0.00");
                // Copy selling_price to price for existing records
                $this->pdo->exec("UPDATE products SET price = selling_price WHERE price IS NULL OR price = 0");
            }
            
            // Check if firebase_id and manager columns exist in stores table
            $storesColumns = $this->pdo->query("PRAGMA table_info(stores)")->fetchAll();
            $hasFirebaseId = false;
            $hasManager = false;
            
            foreach ($storesColumns as $column) {
                if ($column['name'] === 'firebase_id') {
                    $hasFirebaseId = true;
                }
                if ($column['name'] === 'manager') {
                    $hasManager = true;
                }
            }
            
            // Add firebase_id and manager columns if missing (for syncing with Firebase stores)
            if (!$hasFirebaseId) {
                $this->pdo->exec("ALTER TABLE stores ADD COLUMN firebase_id VARCHAR(50)");
            }
            
            // Add manager column if missing (for simple manager name)
            if (!$hasManager) {
                $this->pdo->exec("ALTER TABLE stores ADD COLUMN manager VARCHAR(100)");
            }
            
            // Check for POS integration columns
            $hasPosEnabled = false;
            $hasPosTerminalId = false;
            $hasPosType = false;
            
            foreach ($storesColumns as $column) {
                if ($column['name'] === 'has_pos') {
                    $hasPosEnabled = true;
                }
                if ($column['name'] === 'pos_terminal_id') {
                    $hasPosTerminalId = true;
                }
                if ($column['name'] === 'pos_type') {
                    $hasPosType = true;
                }
            }
            
            // Add POS integration columns if missing
            if (!$hasPosEnabled) {
                $this->pdo->exec("ALTER TABLE stores ADD COLUMN has_pos BOOLEAN DEFAULT 0");
            }
            if (!$hasPosTerminalId) {
                $this->pdo->exec("ALTER TABLE stores ADD COLUMN pos_terminal_id VARCHAR(50)");
            }
            if (!$hasPosType) {
                $this->pdo->exec("ALTER TABLE stores ADD COLUMN pos_type VARCHAR(50) DEFAULT 'quick_service'");
            }
            
        } catch (PDOException $e) {
            error_log("Database upgrade failed: " . $e->getMessage());
        }
    }
    
    // Insert sample data
    private function insertSampleData() {
        try {
            // Insert sample regions
            $this->pdo->exec("
                INSERT INTO regions (name, code, description, timezone) VALUES
                ('North Region', 'NORTH', 'Northern stores region', 'America/New_York'),
                ('South Region', 'SOUTH', 'Southern stores region', 'America/Chicago'),
                ('East Region', 'EAST', 'Eastern coastal region', 'America/New_York'),
                ('West Region', 'WEST', 'Western region', 'America/Los_Angeles'),
                ('Central Region', 'CENT', 'Central region', 'America/Chicago')
            ");
            
            // Insert sample stores
            $this->pdo->exec("
                INSERT INTO stores (name, code, address, city, state, zip_code, phone, email, latitude, longitude, region_id, store_type, status, contact_person, active) VALUES
                ('Downtown Store', 'DT001', '123 Main St', 'New York', 'NY', '10001', '(555) 123-4567', 'downtown@inventory.com', 40.7128, -74.0060, 3, 'retail', 'active', 'John Smith', 1),
                ('Westside Warehouse', 'WW002', '456 West Ave', 'Los Angeles', 'CA', '90001', '(555) 234-5678', 'westside@inventory.com', 34.0522, -118.2437, 4, 'warehouse', 'active', 'Jane Doe', 1),
                ('Central Distribution', 'CD003', '789 Central Blvd', 'Chicago', 'IL', '60601', '(555) 345-6789', 'central@inventory.com', 41.8781, -87.6298, 5, 'distribution', 'active', 'Mike Johnson', 1),
                ('North Retail', 'NR004', '321 North St', 'Boston', 'MA', '02101', '(555) 456-7890', 'north@inventory.com', 42.3601, -71.0589, 1, 'retail', 'active', 'Sarah Wilson', 1),
                ('South Store', 'SS005', '654 South Rd', 'Atlanta', 'GA', '30301', '(555) 567-8901', 'south@inventory.com', 33.7490, -84.3880, 2, 'retail', 'inactive', 'David Brown', 1)
            ");
            
            // Insert sample products
            $this->pdo->exec("
                INSERT INTO products (name, sku, category, unit, cost_price, selling_price, price, quantity, reorder_level, store_id, active) VALUES
                ('Laptop Computer', 'LAP001', 'Electronics', 'piece', 500.00, 799.99, 799.99, 25, 5, 1, 1),
                ('Office Chair', 'CHR001', 'Furniture', 'piece', 150.00, 249.99, 249.99, 15, 3, 1, 1),
                ('Printer Paper', 'PPR001', 'Office Supplies', 'ream', 8.50, 12.99, 12.99, 100, 20, 2, 1),
                ('Desk Lamp', 'DLM001', 'Furniture', 'piece', 25.00, 39.99, 39.99, 30, 10, 3, 1),
                ('Wireless Mouse', 'MSE001', 'Electronics', 'piece', 15.00, 24.99, 24.99, 50, 15, 1, 1),
                ('Keyboard', 'KEY001', 'Electronics', 'piece', 20.00, 34.99, 34.99, 40, 10, 1, 1),
                ('Monitor', 'MON001', 'Electronics', 'piece', 180.00, 299.99, 299.99, 20, 5, 1, 1),
                ('Filing Cabinet', 'FIL001', 'Furniture', 'piece', 80.00, 129.99, 129.99, 8, 2, 2, 1)
            ");
            
            // Insert sample performance data
            $this->pdo->exec("
                INSERT INTO store_performance (store_id, metric_date, daily_sales, customer_count, avg_transaction_value, staff_rating, total_sales, avg_rating) VALUES
                (1, date('now', '-1 day'), 2450.50, 45, 54.45, 4.2, 2450.50, 4.2),
                (1, date('now', '-2 day'), 1890.25, 38, 49.75, 4.1, 1890.25, 4.1),
                (1, date('now', '-3 day'), 3200.75, 52, 61.55, 4.3, 3200.75, 4.3),
                (2, date('now', '-1 day'), 1750.00, 28, 62.50, 3.8, 1750.00, 3.8),
                (2, date('now', '-2 day'), 2100.80, 35, 60.02, 3.9, 2100.80, 3.9),
                (3, date('now', '-1 day'), 3500.25, 68, 51.47, 4.5, 3500.25, 4.5),
                (4, date('now', '-1 day'), 1425.90, 22, 64.81, 4.0, 1425.90, 4.0),
                (5, date('now', '-1 day'), 890.50, 15, 59.37, 3.5, 890.50, 3.5)
            ");
            
            // Insert sample staff data
            $this->pdo->exec("
                INSERT INTO store_staff (store_id, name, position, is_manager, hire_date, hourly_rate, active) VALUES
                (1, 'John Smith', 'Store Manager', 1, '2023-01-15', 25.00, 1),
                (1, 'Alice Johnson', 'Sales Associate', 0, '2023-03-20', 16.50, 1),
                (1, 'Bob Wilson', 'Cashier', 0, '2023-05-10', 15.00, 1),
                (2, 'Jane Doe', 'Warehouse Manager', 1, '2022-11-01', 28.00, 1),
                (2, 'Carlos Martinez', 'Warehouse Associate', 0, '2023-02-14', 18.00, 1),
                (3, 'Mike Johnson', 'Distribution Manager', 1, '2022-08-12', 30.00, 1),
                (4, 'Sarah Wilson', 'Store Manager', 1, '2023-01-05', 24.50, 1),
                (5, 'David Brown', 'Store Manager', 1, '2022-12-20', 23.00, 1)
            ");
            
        } catch (PDOException $e) {
            error_log("Failed to insert sample data: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    // Execute a query and return a single row
    public function fetch($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $this->lastStatement = $stmt;
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("SQL fetch error: " . $e->getMessage() . " | SQL: " . $sql);
            throw new Exception("Database query failed: " . $e->getMessage());
        }
    }
    
    // Execute a query and return all rows
    public function fetchAll($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $this->lastStatement = $stmt;
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("SQL fetchAll error: " . $e->getMessage() . " | SQL: " . $sql);
            throw new Exception("Database query failed: " . $e->getMessage());
        }
    }
    
    // Execute a query (INSERT, UPDATE, DELETE)
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            $this->lastStatement = $stmt;
            return $result;
        } catch (PDOException $e) {
            error_log("SQL execute error: " . $e->getMessage() . " | SQL: " . $sql);
            throw new Exception("Database query failed: " . $e->getMessage());
        }
    }
    
    // Get last insert ID
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    // Begin transaction
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    // Commit transaction
    public function commit() {
        return $this->pdo->commit();
    }
    
    // Rollback transaction
    public function rollback() {
        return $this->pdo->rollback();
    }
    
    // Check if we're in a transaction
    public function inTransaction() {
        return $this->pdo->inTransaction();
    }
    
    // Get row count for last executed statement
    private $lastStatement = null;
    
    public function rowCount() {
        if ($this->lastStatement) {
            return $this->lastStatement->rowCount();
        }
        return 0;
    }
    
    // Test connection
    public function testConnection() {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
}

// Initialize SQL database connection
try {
    $sql_db = SQLDatabase::getInstance();
} catch (Exception $e) {
    error_log("Failed to initialize SQL database: " . $e->getMessage());
    // Continue with Firebase as fallback
}