<?php
require_once 'config.php';
require_once 'sql_db.php';

$db = SQLDatabase::getInstance();

echo "=== CHECKING SALES TABLE STRUCTURE ===\n\n";

try {
    $db_type = defined('DB_TYPE') ? DB_TYPE : 'sqlite';
    
    if ($db_type === 'pgsql') {
        // PostgreSQL - check information_schema
        echo "Database: PostgreSQL\n\n";
        $cols = $db->fetchAll("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'sales' ORDER BY ordinal_position");
        echo "Sales table columns:\n";
        foreach ($cols as $c) {
            echo "  - {$c['column_name']} ({$c['data_type']})\n";
        }
    } else {
        // SQLite - check table exists
        $tableCheck = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name='sales'");
    
        if (empty($tableCheck)) {
            echo "❌ Sales table does NOT exist!\n";
            echo "Creating sales table...\n\n";
            
            $createTable = "
            CREATE TABLE IF NOT EXISTS sales (
                id TEXT PRIMARY KEY,
                sale_number TEXT UNIQUE NOT NULL,
                store_id TEXT NOT NULL,
                user_id TEXT NOT NULL,
                cashier_name TEXT,
                customer_name TEXT DEFAULT 'Walk-in Customer',
                subtotal REAL NOT NULL,
                tax REAL DEFAULT 0,
                total_amount REAL NOT NULL,
                payment_method TEXT DEFAULT 'cash',
                amount_paid REAL NOT NULL,
                'change' REAL DEFAULT 0,
                items_count INTEGER DEFAULT 0,
                items TEXT,
                notes TEXT,
                status TEXT DEFAULT 'completed',
                sale_date TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            
            CREATE INDEX IF NOT EXISTS idx_sales_store_id ON sales(store_id);
            CREATE INDEX IF NOT EXISTS idx_sales_sale_date ON sales(sale_date);
            CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at);
            CREATE INDEX IF NOT EXISTS idx_sales_status ON sales(status);
            ";
            
            $db->execute($createTable);
            echo "✅ Sales table created successfully!\n";
            
        } else {
            echo "✅ Sales table exists\n\n";
            
            // Show structure (SQLite compatible)
            $structure = $db->fetchAll("PRAGMA table_info(sales)");
            echo "Table structure:\n";
            foreach ($structure as $col) {
                echo "  - {$col['name']}: {$col['type']}\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
