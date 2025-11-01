<?php
/**
 * Dashboard Performance Optimization - Create Database Indexes
 * 
 * This script creates indexes on frequently queried columns to dramatically
 * improve dashboard loading speed.
 */

require_once 'config.php';
require_once 'sql_db.php';

echo "=== Dashboard Performance Optimization ===\n\n";

try {
    $sqlDb = SQLDatabase::getInstance();
    
    echo "Creating indexes for dashboard queries...\n\n";
    
    // Index for products.active (used in total products count)
    echo "1. Creating index on products.active... ";
    try {
        $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_products_active ON products(active)");
        echo "✓ Done\n";
    } catch (Exception $e) {
        echo "Already exists or error: " . $e->getMessage() . "\n";
    }
    
    // Composite index for products low stock query (quantity, reorder_level, active)
    echo "2. Creating composite index on products(quantity, reorder_level, active)... ";
    try {
        $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_products_low_stock ON products(quantity, reorder_level, active)");
        echo "✓ Done\n";
    } catch (Exception $e) {
        echo "Already exists or error: " . $e->getMessage() . "\n";
    }
    
    // Index for stores.active
    echo "3. Creating index on stores.active... ";
    try {
        $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_stores_active ON stores(active)");
        echo "✓ Done\n";
    } catch (Exception $e) {
        echo "Already exists or error: " . $e->getMessage() . "\n";
    }
    
    // Index for sales.created_at (used for date filtering)
    echo "4. Creating index on sales.created_at... ";
    try {
        $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_sales_created_at ON sales(created_at)");
        echo "✓ Done\n";
    } catch (Exception $e) {
        echo "Already exists or error: " . $e->getMessage() . "\n";
    }
    
    // Composite index for sales queries (created_at, total)
    echo "5. Creating composite index on sales(created_at, total)... ";
    try {
        $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_sales_date_total ON sales(created_at, total)");
        echo "✓ Done\n";
    } catch (Exception $e) {
        echo "Already exists or error: " . $e->getMessage() . "\n";
    }
    
    // Index for store_id in products (for POS queries)
    echo "6. Creating index on products.store_id... ";
    try {
        $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_products_store_id ON products(store_id)");
        echo "✓ Done\n";
    } catch (Exception $e) {
        echo "Already exists or error: " . $e->getMessage() . "\n";
    }
    
    // Composite index for POS product queries
    echo "7. Creating composite index on products(store_id, active, quantity)... ";
    try {
        $sqlDb->execute("CREATE INDEX IF NOT EXISTS idx_products_pos_queries ON products(store_id, active, quantity)");
        echo "✓ Done\n";
    } catch (Exception $e) {
        echo "Already exists or error: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== Index Creation Complete ===\n\n";
    
    // Analyze tables to update statistics for query optimizer
    echo "Analyzing tables to update query optimizer statistics...\n";
    try {
        $sqlDb->execute("ANALYZE products");
        echo "✓ Products analyzed\n";
    } catch (Exception $e) {
        echo "Error analyzing products: " . $e->getMessage() . "\n";
    }
    
    try {
        $sqlDb->execute("ANALYZE stores");
        echo "✓ Stores analyzed\n";
    } catch (Exception $e) {
        echo "Error analyzing stores: " . $e->getMessage() . "\n";
    }
    
    try {
        $sqlDb->execute("ANALYZE sales");
        echo "✓ Sales analyzed\n";
    } catch (Exception $e) {
        echo "Error analyzing sales: " . $e->getMessage() . "\n";
    }
    
    echo "\n=== Optimization Complete! ===\n";
    echo "Dashboard queries should now be significantly faster.\n";
    echo "The following improvements have been made:\n";
    echo "  - Products count query: Indexed on 'active' column\n";
    echo "  - Low stock query: Composite index on quantity, reorder_level, active\n";
    echo "  - Stores count: Indexed on 'active' column\n";
    echo "  - Sales queries: Indexed on 'created_at' and composite index with 'total'\n";
    echo "  - POS queries: Optimized with store_id and composite indexes\n";
    echo "  - Query optimizer statistics updated\n\n";
    
    // Show index information
    echo "=== Created Indexes Summary ===\n";
    $indexes = $sqlDb->fetchAll("SELECT name, tbl_name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_%' ORDER BY tbl_name, name");
    
    $current_table = '';
    foreach ($indexes as $index) {
        if ($current_table !== $index['tbl_name']) {
            $current_table = $index['tbl_name'];
            echo "\nTable: {$current_table}\n";
        }
        echo "  - {$index['name']}\n";
    }
    
    echo "\n✅ All optimizations applied successfully!\n\n";
    
} catch (Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
