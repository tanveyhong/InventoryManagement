<?php
require_once 'config.php';
require_once 'db.php';

$db = getSQLDB();

// Check total sales
$allSales = $db->fetchAll('SELECT id, sale_number, cashier_id, cashier_name, payment_method, total_amount, created_at FROM sales ORDER BY created_at DESC LIMIT 10');

echo "=== Total Sales in Database ===\n";
echo "Total sales count: " . count($allSales) . "\n\n";

if (!empty($allSales)) {
    echo "Recent sales:\n";
    foreach ($allSales as $s) {
        echo sprintf(
            "ID: %s | Sale#: %s | Cashier ID: %s | Cashier: %s | Method: %s | Amount: %.2f | Date: %s\n",
            $s['id'],
            $s['sale_number'],
            $s['cashier_id'] ?? 'NULL',
            $s['cashier_name'] ?? 'NULL',
            $s['payment_method'] ?? 'N/A',
            $s['total_amount'],
            $s['created_at']
        );
    }
} else {
    echo "No sales found in database.\n";
}

// Check current user ID from session
echo "\n=== Session Check ===\n";
session_start();
if (isset($_SESSION['user_id'])) {
    echo "Current User ID in session: " . $_SESSION['user_id'] . "\n";
    echo "Username: " . ($_SESSION['username'] ?? $_SESSION['email'] ?? 'N/A') . "\n";
    
    // Check sales for this user
    $userSales = $db->fetchAll(
        'SELECT COUNT(*) as count FROM sales WHERE cashier_id = ?',
        [$_SESSION['user_id']]
    );
    echo "Sales for current user: " . ($userSales[0]['count'] ?? 0) . "\n";
} else {
    echo "No user logged in (no session).\n";
}

// Check cashier_id column type
echo "\n=== Column Information ===\n";
$columns = $db->fetchAll("
    SELECT column_name, data_type, is_nullable 
    FROM information_schema.columns 
    WHERE table_name = 'sales' 
    AND column_name IN ('cashier_id', 'user_id')
    ORDER BY column_name
");

foreach ($columns as $col) {
    echo sprintf(
        "Column: %s | Type: %s | Nullable: %s\n",
        $col['column_name'],
        $col['data_type'],
        $col['is_nullable']
    );
}
?>
