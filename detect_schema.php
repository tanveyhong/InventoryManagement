<?php
// Detect SQLite schema and generate PostgreSQL CREATE TABLE statements

$sqlite = new PDO('sqlite:storage/database.sqlite');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get all tables
$tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo "-- PostgreSQL Schema Generated from SQLite\n\n";

foreach ($tables as $table) {
    echo "-- Table: $table\n";
    echo "DROP TABLE IF EXISTS $table CASCADE;\n";
    echo "CREATE TABLE $table (\n";
    
    $columns = $sqlite->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    $columnDefs = [];
    
    foreach ($columns as $col) {
        $colName = $col['name'];
        $type = $col['type'];
        
        // Convert SQLite types to PostgreSQL
        $pgType = match(strtoupper($type)) {
            'INTEGER' => 'INTEGER',
            'TEXT' => 'TEXT',
            'REAL' => 'DECIMAL(10,2)',
            'BLOB' => 'BYTEA',
            'NUMERIC' => 'NUMERIC',
            'VARCHAR', 'VARCHAR(255)' => 'VARCHAR(255)',
            'DATETIME' => 'TIMESTAMP',
            'DATE' => 'DATE',
            'TIME' => 'TIME',
            'BOOLEAN' => 'BOOLEAN',
            default => 'TEXT'
        };
        
        $def = "    \"$colName\" $pgType";
        
        if ($col['notnull'] == 1) {
            $def .= ' NOT NULL';
        }
        
        if ($col['pk'] == 1) {
            if (strtoupper($type) == 'INTEGER') {
                $def = "    \"$colName\" SERIAL PRIMARY KEY";
            } else {
                $def .= ' PRIMARY KEY';
            }
        }
        
        if ($col['dflt_value'] !== null) {
            $default = $col['dflt_value'];
            // Convert boolean defaults from SQLite (0/1) to PostgreSQL (FALSE/TRUE)
            if ($pgType == 'BOOLEAN') {
                $default = ($default == '0' || $default == 0) ? 'FALSE' : 'TRUE';
            }
            $def .= " DEFAULT " . $default;
        }
        
        $columnDefs[] = $def;
    }
    
    echo implode(",\n", $columnDefs);
    echo "\n);\n\n";
}

echo "\n-- Grant permissions\n";
echo "GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO inventory_user;\n";
echo "GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO inventory_user;\n";
