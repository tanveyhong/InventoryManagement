<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    
    echo "Creating stock_transfers table...\n";
    
    $sql = "
    CREATE TABLE IF NOT EXISTS stock_transfers (
        id SERIAL PRIMARY KEY,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        from_store_id INTEGER, -- NULL for Warehouse
        to_store_id INTEGER,   -- NULL for Warehouse
        status VARCHAR(20) DEFAULT 'pending', -- pending, completed, cancelled
        requested_by INTEGER,
        approved_by INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    
    CREATE INDEX IF NOT EXISTS idx_transfers_status ON stock_transfers(status);
    CREATE INDEX IF NOT EXISTS idx_transfers_product ON stock_transfers(product_id);
    CREATE INDEX IF NOT EXISTS idx_transfers_to_store ON stock_transfers(to_store_id);
    ";
    
    $db->execute($sql);
    
    echo "Table stock_transfers created successfully.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>