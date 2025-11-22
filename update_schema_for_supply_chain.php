<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    
    // 1. Create stock_movements table
    $sql1 = "
    CREATE TABLE IF NOT EXISTS stock_movements (
        id SERIAL PRIMARY KEY,
        product_id INTEGER REFERENCES products(id),
        store_id INTEGER REFERENCES stores(id),
        movement_type VARCHAR(20) NOT NULL, -- in, out, adjustment, transfer
        quantity INTEGER NOT NULL,
        reference VARCHAR(100),
        notes TEXT,
        user_id INTEGER REFERENCES users(id),
        created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
    );";
    $db->execute($sql1);
    echo "Table 'stock_movements' created/verified.\n";

    // 2. Add supplier_id to products table
    // Check if column exists first to avoid error
    $checkCol = $db->fetch("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_name='products' AND column_name='supplier_id'
    ");

    if (!$checkCol) {
        $db->execute("ALTER TABLE products ADD COLUMN supplier_id INTEGER REFERENCES suppliers(id)");
        echo "Column 'supplier_id' added to 'products' table.\n";
    } else {
        echo "Column 'supplier_id' already exists in 'products' table.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
