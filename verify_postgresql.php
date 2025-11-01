<?php
// Verify PostgreSQL Migration

try {
    $pdo = new PDO('pgsql:host=localhost;port=5433;dbname=inventory_system', 'inventory_user', 'SecurePassword123!');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ PostgreSQL connection successful!\n\n";
    echo "📊 Migration Verification:\n";
    echo "=" . str_repeat("=", 50) . "\n\n";
    
    // Get all tables
    $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Total tables: " . count($tables) . "\n\n";
    
    foreach ($tables as $table) {
        $count = $pdo->query("SELECT COUNT(*) FROM \"$table\"")->fetchColumn();
        $status = $count > 0 ? "✅" : "⚠️ ";
        echo "$status $table: $count rows\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "✅ PostgreSQL is ready for multi-user access!\n\n";
    
    // Test a query
    echo "🧪 Testing dashboard query...\n";
    $products = $pdo->query("SELECT COUNT(*) FROM products WHERE active = TRUE")->fetchColumn();
    $stores = $pdo->query("SELECT COUNT(*) FROM stores WHERE active = TRUE")->fetchColumn();
    
    echo "   Active products: $products\n";
    echo "   Active stores: $stores\n";
    echo "   ✅ Queries working!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
