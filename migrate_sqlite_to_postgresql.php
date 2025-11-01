<?php
/**
 * SQLite to PostgreSQL Migration Script
 * 
 * This script migrates all data from SQLite to PostgreSQL while preserving
 * data integrity and relationships.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0); // No time limit for migration

require_once 'config.php';

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║       SQLite to PostgreSQL Migration Script                 ║\n";
echo "║                                                              ║\n";
echo "║  This will migrate your entire database from SQLite to      ║\n";
echo "║  PostgreSQL for better multi-user support and performance.  ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Step 1: Check PostgreSQL connection
echo "📋 Step 1: Checking PostgreSQL connection...\n";

if (!defined('PG_HOST') || !defined('PG_DATABASE') || !defined('PG_USERNAME') || !defined('PG_PASSWORD')) {
    echo "❌ ERROR: PostgreSQL configuration not found in config.php\n";
    echo "\nPlease add these lines to your config.php:\n\n";
    echo "define('DB_TYPE', 'pgsql');\n";
    echo "define('PG_HOST', 'localhost');\n";
    echo "define('PG_PORT', '5432');\n";
    echo "define('PG_DATABASE', 'inventory_system');\n";
    echo "define('PG_USERNAME', 'inventory_user');\n";
    echo "define('PG_PASSWORD', 'your_password');\n\n";
    exit(1);
}

try {
    $pgsql_dsn = "pgsql:host=" . PG_HOST . ";port=" . (defined('PG_PORT') ? PG_PORT : 5432) . ";dbname=" . PG_DATABASE;
    $pgsql_pdo = new PDO($pgsql_dsn, PG_USERNAME, PG_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✅ PostgreSQL connection successful!\n";
    echo "   Database: " . PG_DATABASE . " @ " . PG_HOST . "\n\n";
} catch (PDOException $e) {
    echo "❌ ERROR: Cannot connect to PostgreSQL\n";
    echo "   " . $e->getMessage() . "\n\n";
    echo "Make sure:\n";
    echo "1. PostgreSQL is installed and running\n";
    echo "2. Database '" . PG_DATABASE . "' exists\n";
    echo "3. User '" . PG_USERNAME . "' has proper permissions\n\n";
    exit(1);
}

// Step 2: Backup SQLite database
echo "📋 Step 2: Backing up SQLite database...\n";

$sqlite_path = __DIR__ . '/storage/database.sqlite';
$backup_path = __DIR__ . '/storage/database.sqlite.backup_' . date('YmdHis');

if (!file_exists($sqlite_path)) {
    echo "❌ ERROR: SQLite database not found at: $sqlite_path\n";
    exit(1);
}

if (copy($sqlite_path, $backup_path)) {
    echo "✅ SQLite database backed up to:\n";
    echo "   $backup_path\n\n";
} else {
    echo "❌ ERROR: Failed to backup SQLite database\n";
    exit(1);
}

// Step 3: Connect to SQLite
echo "📋 Step 3: Connecting to SQLite database...\n";

try {
    $sqlite_pdo = new PDO('sqlite:' . $sqlite_path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✅ SQLite connection successful!\n\n";
} catch (PDOException $e) {
    echo "❌ ERROR: Cannot connect to SQLite\n";
    echo "   " . $e->getMessage() . "\n";
    exit(1);
}

// Step 4: Create PostgreSQL schema
echo "📋 Step 4: Creating PostgreSQL schema...\n";

$schema_file = __DIR__ . '/docs/postgresql_schema.sql';

if (!file_exists($schema_file)) {
    echo "⚠️  Schema file not found, creating basic schema...\n";
    createBasicSchema($pgsql_pdo);
} else {
    echo "   Reading schema from: $schema_file\n";
    $schema_sql = file_get_contents($schema_file);
    
    // Execute schema (split by semicolons and execute each statement)
    $statements = array_filter(array_map('trim', explode(';', $schema_sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) continue;
        
        try {
            $pgsql_pdo->exec($statement);
        } catch (PDOException $e) {
            // Ignore "already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "⚠️  Warning: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "✅ PostgreSQL schema created!\n\n";
}

// Step 5: Get list of tables from SQLite
echo "📋 Step 5: Discovering tables to migrate...\n";

$tables_query = "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'";
$tables_result = $sqlite_pdo->query($tables_query);
$tables = $tables_result->fetchAll(PDO::FETCH_COLUMN);

echo "✅ Found " . count($tables) . " tables to migrate:\n";
foreach ($tables as $table) {
    echo "   - $table\n";
}
echo "\n";

// Step 6: Migrate data
echo "📋 Step 6: Migrating data...\n\n";

$migration_stats = [];

foreach ($tables as $table) {
    echo "Migrating table: $table\n";
    
    try {
        // Get row count
        $count_result = $sqlite_pdo->query("SELECT COUNT(*) as count FROM $table");
        $row_count = $count_result->fetch()['count'];
        
        if ($row_count == 0) {
            echo "  ⚠️  Empty table, skipping\n\n";
            $migration_stats[$table] = ['rows' => 0, 'status' => 'empty'];
            continue;
        }
        
        echo "  Found $row_count rows\n";
        
        // Get all data from SQLite
        $data = $sqlite_pdo->query("SELECT * FROM $table")->fetchAll();
        
        if (empty($data)) {
            echo "  ⚠️  No data to migrate\n\n";
            $migration_stats[$table] = ['rows' => 0, 'status' => 'no_data'];
            continue;
        }
        
        // Clear existing data in PostgreSQL (if any)
        try {
            $pgsql_pdo->exec("TRUNCATE TABLE $table RESTART IDENTITY CASCADE");
        } catch (PDOException $e) {
            // Table might not exist yet
        }
        
        // Get column names from first row
        $columns = array_keys($data[0]);
        $column_list = implode(', ', array_map(function($col) {
            return '"' . $col . '"';
        }, $columns));
        
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        
        $insert_sql = "INSERT INTO $table ($column_list) VALUES ($placeholders)";
        $stmt = $pgsql_pdo->prepare($insert_sql);
        
        // Batch insert
        $pgsql_pdo->beginTransaction();
        $inserted = 0;
        
        foreach ($data as $row) {
            try {
                // Convert SQLite boolean (1/0) to PostgreSQL boolean
                foreach ($row as $key => $value) {
                    if ($value === '1' || $value === '0') {
                        // Check if column might be boolean
                        $row[$key] = $value === '1' ? 't' : 'f';
                    }
                }
                
                $stmt->execute(array_values($row));
                $inserted++;
            } catch (PDOException $e) {
                echo "  ⚠️  Error inserting row: " . $e->getMessage() . "\n";
                // Continue with next row
            }
        }
        
        $pgsql_pdo->commit();
        
        echo "  ✅ Migrated $inserted/$row_count rows\n";
        
        // Reset sequence for auto-increment columns
        try {
            $pgsql_pdo->exec("SELECT setval(pg_get_serial_sequence('$table', 'id'), COALESCE((SELECT MAX(id) FROM $table), 1))");
        } catch (PDOException $e) {
            // Table might not have id column
        }
        
        $migration_stats[$table] = ['rows' => $inserted, 'status' => 'success'];
        
    } catch (Exception $e) {
        echo "  ❌ ERROR: " . $e->getMessage() . "\n";
        $migration_stats[$table] = ['rows' => 0, 'status' => 'error', 'error' => $e->getMessage()];
    }
    
    echo "\n";
}

// Step 7: Verify migration
echo "📋 Step 7: Verifying migration...\n\n";

$verification_passed = true;

foreach ($tables as $table) {
    // Count rows in SQLite
    $sqlite_count = $sqlite_pdo->query("SELECT COUNT(*) as count FROM $table")->fetch()['count'];
    
    // Count rows in PostgreSQL
    try {
        $pgsql_count = $pgsql_pdo->query("SELECT COUNT(*) as count FROM $table")->fetch()['count'];
    } catch (PDOException $e) {
        $pgsql_count = 0;
    }
    
    $status = $sqlite_count == $pgsql_count ? '✅' : '❌';
    echo "$status $table: SQLite=$sqlite_count, PostgreSQL=$pgsql_count\n";
    
    if ($sqlite_count != $pgsql_count) {
        $verification_passed = false;
    }
}

echo "\n";

// Step 8: Summary
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                    Migration Summary                         ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

foreach ($migration_stats as $table => $stats) {
    $status_icon = $stats['status'] == 'success' ? '✅' : ($stats['status'] == 'empty' ? '⚠️' : '❌');
    echo "$status_icon $table: {$stats['rows']} rows ({$stats['status']})\n";
}

echo "\n";

if ($verification_passed) {
    echo "✅ Migration completed successfully!\n\n";
    echo "Next steps:\n";
    echo "1. Update config.php to set DB_TYPE = 'pgsql'\n";
    echo "2. Test your application\n";
    echo "3. If everything works, you can delete the SQLite backup\n\n";
    echo "Your SQLite backup is saved at:\n";
    echo "$backup_path\n\n";
} else {
    echo "⚠️  Migration completed with warnings.\n";
    echo "Please review the verification results above.\n\n";
}

/**
 * Create basic schema if schema file doesn't exist
 */
function createBasicSchema($pdo) {
    $schema = "
        -- Basic schema creation
        CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) DEFAULT 'user',
            active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
        
        CREATE TABLE IF NOT EXISTS stores (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            address TEXT,
            phone VARCHAR(20),
            active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
        
        CREATE TABLE IF NOT EXISTS products (
            id SERIAL PRIMARY KEY,
            sku VARCHAR(50) UNIQUE,
            name VARCHAR(200) NOT NULL,
            description TEXT,
            price DECIMAL(10,2),
            quantity INTEGER DEFAULT 0,
            reorder_level INTEGER DEFAULT 10,
            store_id INTEGER REFERENCES stores(id),
            active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        );
        
        CREATE TABLE IF NOT EXISTS sales (
            id SERIAL PRIMARY KEY,
            sale_number VARCHAR(50) UNIQUE,
            store_id INTEGER REFERENCES stores(id),
            user_id INTEGER REFERENCES users(id),
            subtotal DECIMAL(10,2),
            tax DECIMAL(10,2),
            total DECIMAL(10,2),
            payment_method VARCHAR(20),
            created_at TIMESTAMP DEFAULT NOW()
        );
    ";
    
    $pdo->exec($schema);
}
