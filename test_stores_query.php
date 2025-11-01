<?php
// Test stores query performance
require_once 'config.php';
require_once 'sql_db.php';

$sqlDb = SQLDatabase::getInstance();

echo "Testing stores query performance...\n\n";

// Test 1: Simple store count
$start = microtime(true);
$result = $sqlDb->fetch("SELECT COUNT(*) as total FROM stores WHERE active = TRUE");
$time1 = microtime(true) - $start;
echo "Test 1 - Store count: {$result['total']} stores in " . round($time1 * 1000, 2) . "ms\n";

// Test 2: Get stores without products
$start = microtime(true);
$stores = $sqlDb->fetchAll("SELECT * FROM stores WHERE active = TRUE ORDER BY name ASC LIMIT 20");
$time2 = microtime(true) - $start;
echo "Test 2 - Get 20 stores (no products): " . count($stores) . " stores in " . round($time2 * 1000, 2) . "ms\n";

// Test 3: Product count query
$start = microtime(true);
$result = $sqlDb->fetch("SELECT COUNT(*) as total FROM products WHERE active = TRUE");
$time3 = microtime(true) - $start;
echo "Test 3 - Product count: {$result['total']} products in " . round($time3 * 1000, 2) . "ms\n";

// Test 4: Current GROUP BY query (slow)
$start = microtime(true);
$stores = $sqlDb->fetchAll("
    SELECT 
        s.*,
        COALESCE(COUNT(p.id), 0) as product_count,
        COALESCE(SUM(CASE WHEN p.active = TRUE THEN p.quantity ELSE 0 END), 0) as total_stock
    FROM stores s
    LEFT JOIN products p ON p.store_id = s.id AND p.active = TRUE
    WHERE s.active = TRUE
    GROUP BY s.id
    ORDER BY s.name ASC
    LIMIT 20
");
$time4 = microtime(true) - $start;
echo "Test 4 - GROUP BY query: " . count($stores) . " stores in " . round($time4 * 1000, 2) . "ms\n";

// Test 5: Optimized with subquery (faster)
$start = microtime(true);
$stores = $sqlDb->fetchAll("
    SELECT 
        s.*,
        (SELECT COUNT(*) FROM products p WHERE p.store_id = s.id AND p.active = TRUE) as product_count,
        (SELECT COALESCE(SUM(quantity), 0) FROM products p WHERE p.store_id = s.id AND p.active = TRUE) as total_stock
    FROM stores s
    WHERE s.active = TRUE
    ORDER BY s.name ASC
    LIMIT 20
");
$time5 = microtime(true) - $start;
echo "Test 5 - Subquery approach: " . count($stores) . " stores in " . round($time5 * 1000, 2) . "ms\n";

// Test 6: No aggregation at all (fastest)
$start = microtime(true);
$stores = $sqlDb->fetchAll("
    SELECT * FROM stores WHERE active = TRUE ORDER BY name ASC LIMIT 20
");
$time6 = microtime(true) - $start;
echo "Test 6 - No aggregation: " . count($stores) . " stores in " . round($time6 * 1000, 2) . "ms\n";

echo "\n=== Performance Summary ===\n";
echo "Fastest approach: Test 6 (no aggregation) - " . round($time6 * 1000, 2) . "ms\n";
echo "Subquery approach: Test 5 - " . round($time5 * 1000, 2) . "ms\n";
echo "GROUP BY approach: Test 4 - " . round($time4 * 1000, 2) . "ms (current)\n";
echo "\nRecommendation: Use lazy loading - fetch stores first, then get counts via JavaScript API calls\n";
