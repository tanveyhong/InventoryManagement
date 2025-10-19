<?php
/**
 * Create Sales Tables
 * Creates sales and sale_items tables for POS transactions
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = getSQLDB();

echo "=== CREATING SALES TABLES ===" . PHP_EOL . PHP_EOL;

try {
    // Create sales table
    echo "Creating sales table..." . PHP_EOL;
    $salesTableSQL = "
    CREATE TABLE IF NOT EXISTS sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_number VARCHAR(50) UNIQUE,
        store_id INTEGER,
        user_id INTEGER,
        customer_name VARCHAR(100),
        customer_email VARCHAR(100),
        customer_phone VARCHAR(20),
        subtotal DECIMAL(10, 2) DEFAULT 0.00,
        tax_amount DECIMAL(10, 2) DEFAULT 0.00,
        discount_amount DECIMAL(10, 2) DEFAULT 0.00,
        total_amount DECIMAL(10, 2) DEFAULT 0.00,
        payment_method VARCHAR(20) DEFAULT 'cash',
        payment_status VARCHAR(20) DEFAULT 'completed',
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    
    $db->execute($salesTableSQL);
    echo "✅ Sales table created successfully" . PHP_EOL;
    
    // Create sale_items table
    echo "Creating sale_items table..." . PHP_EOL;
    $saleItemsTableSQL = "
    CREATE TABLE IF NOT EXISTS sale_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price DECIMAL(10, 2) NOT NULL,
        discount_amount DECIMAL(10, 2) DEFAULT 0.00,
        subtotal DECIMAL(10, 2) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id)
    )";
    
    $db->execute($saleItemsTableSQL);
    echo "✅ Sale_items table created successfully" . PHP_EOL;
    
    // Create indexes
    echo "Creating indexes..." . PHP_EOL;
    
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_sales_store ON sales(store_id)",
        "CREATE INDEX IF NOT EXISTS idx_sales_user ON sales(user_id)",
        "CREATE INDEX IF NOT EXISTS idx_sales_created ON sales(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_sales_number ON sales(sale_number)",
        "CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items(sale_id)",
        "CREATE INDEX IF NOT EXISTS idx_sale_items_product ON sale_items(product_id)"
    ];
    
    foreach ($indexes as $indexSQL) {
        $db->execute($indexSQL);
    }
    
    echo "✅ Indexes created successfully" . PHP_EOL . PHP_EOL;
    
    // Verify tables were created
    echo "Verifying tables..." . PHP_EOL;
    $tables = $db->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('sales', 'sale_items')");
    
    foreach ($tables as $table) {
        echo "  ✅ " . $table['name'] . " exists" . PHP_EOL;
    }
    
    echo PHP_EOL . "=== SALES TABLES CREATED SUCCESSFULLY ===" . PHP_EOL;
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . PHP_EOL;
    echo "Stack trace: " . $e->getTraceAsString() . PHP_EOL;
}
?>
