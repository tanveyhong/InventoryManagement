<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    
    $sql1 = "
    CREATE TABLE IF NOT EXISTS suppliers (
        id SERIAL PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        contact_person VARCHAR(100),
        email VARCHAR(100),
        phone VARCHAR(20),
        address TEXT,
        active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
        updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
    );";
    $db->execute($sql1);
    echo "Table 'suppliers' created.\n";

    $sql2 = "
    CREATE TABLE IF NOT EXISTS purchase_orders (
        id SERIAL PRIMARY KEY,
        po_number VARCHAR(50) UNIQUE NOT NULL,
        supplier_id INTEGER REFERENCES suppliers(id),
        store_id INTEGER REFERENCES stores(id),
        status VARCHAR(20) DEFAULT 'draft', -- draft, ordered, received, cancelled
        total_amount DECIMAL(10, 2) DEFAULT 0.00,
        expected_date DATE,
        notes TEXT,
        created_by INTEGER REFERENCES users(id),
        created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
        updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
    );";
    $db->execute($sql2);
    echo "Table 'purchase_orders' created.\n";

    $sql3 = "
    CREATE TABLE IF NOT EXISTS purchase_order_items (
        id SERIAL PRIMARY KEY,
        po_id INTEGER REFERENCES purchase_orders(id) ON DELETE CASCADE,
        product_id INTEGER REFERENCES products(id),
        quantity INTEGER NOT NULL,
        unit_cost DECIMAL(10, 2) NOT NULL,
        total_cost DECIMAL(10, 2) NOT NULL,
        received_quantity INTEGER DEFAULT 0
    );";
    $db->execute($sql3);
    echo "Table 'purchase_order_items' created.\n";

    // Add supplier_id to products table if it doesn't exist
    // Note: SQLDatabase::execute might not support DO $$ blocks depending on implementation, 
    // so we'll check via PHP first.
    
    $columns = $db->fetchAll("SELECT column_name FROM information_schema.columns WHERE table_name = 'products' AND column_name = 'supplier_id'");
    if (empty($columns)) {
        $db->execute("ALTER TABLE products ADD COLUMN supplier_id INTEGER REFERENCES suppliers(id)");
        echo "Added supplier_id to products table.\n";
    } else {
        echo "supplier_id already exists in products table.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>