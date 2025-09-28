<?php
// tools/db_debug.php
// Small diagnostic script to show which DB file and sample product rows from SQL and Firebase.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sql_db.php';
require_once __DIR__ . '/../getDB.php';

function safe_print($label, $data) {
    echo "== $label ==\n";
    if (is_array($data) || is_object($data)) {
        echo print_r($data, true) . "\n";
    } else {
        echo (string)$data . "\n\n";
    }
}

// DB_NAME from config.php
echo "Running DB debug...\n\n";

if (defined('DB_NAME')) {
    $dbPath = DB_NAME;
    echo "DB_NAME (raw): $dbPath\n";
    $real = @realpath(__DIR__ . '/../' . $dbPath);
    echo "Realpath (attempt): " . ($real ?: 'N/A') . "\n";
    $abs = @realpath($dbPath);
    echo "realpath(DB_NAME): " . ($abs ?: 'N/A') . "\n\n";
} else {
    echo "DB_NAME not defined in config.php\n\n";
}

// Check file existence
$sqlite = __DIR__ . '/../' . (defined('DB_NAME') ? DB_NAME : 'storage/database.sqlite');
if (file_exists($sqlite)) {
    $info = stat($sqlite);
    safe_print('SQLite file stat', $info);
} else {
    echo "SQLite file not found at: $sqlite\n\n";
}

// Query SQL DB for recent products (if available)
try {
    $sql = getSQLDB();
    if ($sql) {
        $rows = $sql->fetchAll('SELECT id, name, sku, quantity, price FROM products ORDER BY id DESC LIMIT 10');
        safe_print('Recent products (SQL)', $rows ?: 'No rows returned');
    } else {
        echo "getSQLDB() returned null\n";
    }
} catch (Exception $e) {
    safe_print('SQL query error', $e->getMessage());
}

// Query Firebase for 'products' collection
try {
    $db = getDB();
    if ($db) {
        $fb = $db->readAll('products', [], null, 10);
        safe_print('Firebase products (readAll)', $fb ?: 'No documents returned');
    } else {
        echo "getDB() returned null\n";
    }
} catch (Exception $e) {
    safe_print('Firebase query error', $e->getMessage());
}

echo "Done.\n";
