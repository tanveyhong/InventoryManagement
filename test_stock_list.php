<?php
/**
 * Test Stock List - Debug expiry date display
 */

require_once 'config.php';
require_once 'sql_db.php';

try {
    $sqlDb = SQLDatabase::getInstance();
    
    // Get products with expiry dates
    $sql = "SELECT 
        id,
        product_name,
        sku,
        category,
        quantity,
        expiry_date,
        store_id
    FROM products 
    WHERE active = TRUE 
    AND expiry_date IS NOT NULL
    ORDER BY expiry_date ASC";
    
    $products = $sqlDb->fetchAll($sql);
    
    echo "<h2>Products with Expiry Dates from Database</h2>";
    echo "<p>Total products with expiry dates: " . count($products) . "</p>";
    
    if (empty($products)) {
        echo "<p style='color: red;'>❌ No products found with expiry dates!</p>";
        
        // Check if there are ANY products
        $totalProducts = $sqlDb->fetch("SELECT COUNT(*) as count FROM products WHERE active = TRUE");
        echo "<p>Total active products: " . $totalProducts['count'] . "</p>";
        
        // Check expiry_date column
        $sampleProducts = $sqlDb->fetchAll("SELECT product_name, sku, expiry_date FROM products WHERE active = TRUE LIMIT 10");
        echo "<h3>Sample Products (first 10):</h3>";
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>Product Name</th><th>SKU</th><th>Expiry Date (raw)</th><th>Is NULL?</th></tr>";
        foreach ($sampleProducts as $p) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($p['product_name']) . "</td>";
            echo "<td>" . htmlspecialchars($p['sku']) . "</td>";
            echo "<td>" . htmlspecialchars($p['expiry_date'] ?? 'NULL') . "</td>";
            echo "<td>" . ($p['expiry_date'] === null ? 'YES' : 'NO') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
        echo "<tr style='background: #667eea; color: white;'>";
        echo "<th>Product</th><th>SKU</th><th>Category</th><th>Qty</th><th>Expiry Date</th><th>Days</th><th>Status</th>";
        echo "</tr>";
        
        $now = new DateTime();
        
        foreach ($products as $product) {
            $expiryDate = $product['expiry_date'];
            $productName = htmlspecialchars($product['product_name']);
            $sku = htmlspecialchars($product['sku']);
            $category = htmlspecialchars($product['category'] ?? 'N/A');
            $qty = $product['quantity'];
            
            // Calculate days until expiry
            $expiryDateTime = new DateTime($expiryDate);
            $interval = $now->diff($expiryDateTime);
            $daysUntilExpiry = (int)$interval->format('%r%a');
            
            // Format expiry date
            $formattedDate = $expiryDateTime->format('M j, Y');
            
            // Determine status and days text
            if ($daysUntilExpiry < 0) {
                $status = 'Expired';
                $statusColor = '#ef4444';
                $daysText = abs($daysUntilExpiry) . ' day' . (abs($daysUntilExpiry) != 1 ? 's' : '') . ' ago';
            } elseif ($daysUntilExpiry == 0) {
                $status = 'Expires Today';
                $statusColor = '#f59e0b';
                $daysText = 'Today';
            } elseif ($daysUntilExpiry <= 7) {
                $status = 'Next 7 days';
                $statusColor = '#f59e0b';
                $daysText = 'in ' . $daysUntilExpiry . ' day' . ($daysUntilExpiry != 1 ? 's' : '');
            } elseif ($daysUntilExpiry <= 30) {
                $status = 'Next 30 days';
                $statusColor = '#3b82f6';
                $daysText = 'in ' . $daysUntilExpiry . ' day' . ($daysUntilExpiry != 1 ? 's' : '');
            } else {
                $status = 'Good';
                $statusColor = '#10b981';
                $daysText = 'in ' . $daysUntilExpiry . ' days';
            }
            
            echo "<tr>";
            echo "<td>{$productName}</td>";
            echo "<td>{$sku}</td>";
            echo "<td>{$category}</td>";
            echo "<td>{$qty}</td>";
            echo "<td>{$formattedDate}</td>";
            echo "<td>{$daysText}</td>";
            echo "<td style='color: {$statusColor}; font-weight: bold;'>{$status}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
    
    // Expected products from user's list
    echo "<h2 style='margin-top: 40px;'>Expected Products (from your list)</h2>";
    $expected = [
        ['name' => 'Chips More withdb 1', 'sku' => 'W-001', 'category' => 'Foods', 'qty' => 21, 'expiry' => '2025-10-01'],
        ['name' => 'Cheese', 'sku' => 'D-001', 'category' => 'Foods', 'qty' => 19, 'expiry' => '2025-10-23'],
        ['name' => 'Lays Potato Chips', 'sku' => 'SNK-001', 'category' => 'Snacks', 'qty' => 80, 'expiry' => '2025-11-18'],
        ['name' => 'Lays Potato Chips', 'sku' => 'SNK-001-S6', 'category' => 'Snacks', 'qty' => 80, 'expiry' => '2025-11-20'],
        ['name' => 'Candy Mix', 'sku' => 'SNK-004', 'category' => 'Snacks', 'qty' => 200, 'expiry' => '2025-11-29'],
    ];
    
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    echo "<tr style='background: #10b981; color: white;'>";
    echo "<th>Product</th><th>SKU</th><th>Found in DB?</th></tr>";
    
    foreach ($expected as $exp) {
        $found = false;
        foreach ($products as $p) {
            if ($p['sku'] === $exp['sku']) {
                $found = true;
                break;
            }
        }
        
        $foundText = $found ? '✅ YES' : '❌ NO';
        $color = $found ? '#10b981' : '#ef4444';
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($exp['name']) . "</td>";
        echo "<td>" . htmlspecialchars($exp['sku']) . "</td>";
        echo "<td style='color: {$color}; font-weight: bold;'>{$foundText}</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
