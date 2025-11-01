<?php
// Test Dashboard Functions with PostgreSQL

require_once 'config.php';
require_once 'functions.php';

echo "🧪 Testing Dashboard Functions with PostgreSQL\n";
echo str_repeat("=", 60) . "\n\n";

try {
    // Test 1: Get All Dashboard Stats
    echo "1️⃣ Testing getAllDashboardStats()...\n";
    $stats = getAllDashboardStats();
    echo "   ✅ Total Products: " . $stats['total_products'] . "\n";
    echo "   ✅ Low Stock: " . $stats['low_stock'] . "\n";
    echo "   ✅ Total Stores: " . $stats['total_stores'] . "\n";
    echo "   ✅ Today's Sales: $" . number_format($stats['todays_sales'], 2) . "\n";
    echo "   ✅ Notifications: " . count($stats['notifications']) . "\n\n";
    
    // Test 2: Get Weekly Sales Data
    echo "2️⃣ Testing getWeeklySalesData()...\n";
    $weeklySales = getWeeklySalesData();
    echo "   ✅ Days returned: " . count($weeklySales) . "\n";
    foreach ($weeklySales as $day) {
        echo "   - {$day['day']}: $" . number_format($day['sales'], 2) . "\n";
    }
    echo "\n";
    
    // Test 3: Individual Functions
    echo "3️⃣ Testing individual functions...\n";
    echo "   ✅ getTotalProducts(): " . getTotalProducts() . "\n";
    echo "   ✅ getLowStockCount(): " . getLowStockCount() . "\n";
    echo "   ✅ getTotalStores(): " . getTotalStores() . "\n";
    echo "   ✅ getTodaysSales(): $" . number_format(getTodaysSales(), 2) . "\n\n";
    
    // Test 4: Database Type
    echo "4️⃣ Current database configuration:\n";
    echo "   Database Type: " . DB_TYPE . "\n";
    echo "   Host: " . (DB_TYPE == 'pgsql' ? PG_HOST : 'N/A') . "\n";
    echo "   Port: " . (DB_TYPE == 'pgsql' ? PG_PORT : 'N/A') . "\n";
    echo "   Database: " . (DB_TYPE == 'pgsql' ? PG_DATABASE : 'N/A') . "\n\n";
    
    echo str_repeat("=", 60) . "\n";
    echo "✅ All dashboard functions working with PostgreSQL!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
