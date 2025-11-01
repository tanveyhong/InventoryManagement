<?php
/**
 * Create Users Table in PostgreSQL
 * Migrate user management from Firebase to PostgreSQL
 */

require_once 'config.php';
require_once 'sql_db.php';
require_once 'getDB.php';

$pgsql = SQLDatabase::getInstance();
$firebase = getDB();

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║       Users Table Migration to PostgreSQL                   ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// Step 1: Create users table
echo "📋 Step 1: Creating users table in PostgreSQL...\n";

try {
    $pgsql->execute("
    CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        firebase_id VARCHAR(255) UNIQUE,
        username VARCHAR(100) UNIQUE NOT NULL,
        email VARCHAR(255) UNIQUE,
        password_hash TEXT NOT NULL,
        full_name VARCHAR(255),
        phone VARCHAR(20),
        role VARCHAR(50) DEFAULT 'user',
        status VARCHAR(20) DEFAULT 'active',
        avatar_url TEXT,
        last_login TIMESTAMP,
        remember_token VARCHAR(255),
        remember_token_expires TIMESTAMP,
        email_verified BOOLEAN DEFAULT FALSE,
        two_factor_enabled BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP
    )");
    
    // Create indexes
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_users_username ON users(username)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_users_status ON users(status)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_users_firebase_id ON users(firebase_id)");
    
    echo "✅ Users table created successfully!\n\n";
} catch (Exception $e) {
    echo "❌ Error creating users table: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 2: Create user_activities table
echo "📋 Step 2: Creating user_activities table...\n";

try {
    $pgsql->execute("
    CREATE TABLE IF NOT EXISTS user_activities (
        id SERIAL PRIMARY KEY,
        firebase_id VARCHAR(255),
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        action VARCHAR(100) NOT NULL,
        description TEXT,
        ip_address VARCHAR(45),
        user_agent TEXT,
        metadata JSONB,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP
    )");
    
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_activities_user_id ON user_activities(user_id)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_activities_action ON user_activities(action)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_activities_created_at ON user_activities(created_at)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_activities_deleted_at ON user_activities(deleted_at)");
    
    echo "✅ User activities table created successfully!\n\n";
} catch (Exception $e) {
    echo "❌ Error creating user_activities table: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 3: Create user_permissions table
echo "📋 Step 3: Creating user_permissions table...\n";

try {
    $pgsql->execute("
    CREATE TABLE IF NOT EXISTS user_permissions (
        id SERIAL PRIMARY KEY,
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        permission VARCHAR(100) NOT NULL,
        granted_by INTEGER REFERENCES users(id),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, permission)
    )");
    
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_permissions_user_id ON user_permissions(user_id)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_permissions_permission ON user_permissions(permission)");
    
    echo "✅ User permissions table created successfully!\n\n";
} catch (Exception $e) {
    echo "❌ Error creating user_permissions table: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 4: Create user_store_access table
echo "📋 Step 4: Creating user_store_access table...\n";

try {
    $pgsql->execute("
    CREATE TABLE IF NOT EXISTS user_store_access (
        id SERIAL PRIMARY KEY,
        user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
        store_id INTEGER REFERENCES stores(id) ON DELETE CASCADE,
        granted_by INTEGER REFERENCES users(id),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, store_id)
    )");
    
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_store_access_user_id ON user_store_access(user_id)");
    $pgsql->execute("CREATE INDEX IF NOT EXISTS idx_store_access_store_id ON user_store_access(store_id)");
    
    echo "✅ User store access table created successfully!\n\n";
} catch (Exception $e) {
    echo "❌ Error creating user_store_access table: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 5: Migrate users from Firebase
echo "📋 Step 5: Migrating users from Firebase...\n";

try {
    $users = $firebase->readAll('users');
    if (empty($users)) {
        echo "⚠️  No users found in Firebase\n\n";
    } else {
        $migratedCount = 0;
        foreach ($users as $firebaseId => $user) {
            // Skip if not an array
            if (!is_array($user)) continue;
            
            // Check if already migrated
            $existing = $pgsql->fetchAll(
                "SELECT id FROM users WHERE firebase_id = ?",
                [$firebaseId]
            );
            
            if (!empty($existing)) {
                echo "  ⏩ User {$user['username']} already migrated\n";
                continue;
            }
            
            // Insert user
            $insertData = [
                'firebase_id' => $firebaseId,
                'username' => $user['username'] ?? 'user_' . substr($firebaseId, 0, 8),
                'email' => $user['email'] ?? null,
                'password_hash' => $user['password_hash'] ?? password_hash('changeme', PASSWORD_DEFAULT),
                'full_name' => $user['full_name'] ?? null,
                'phone' => $user['phone'] ?? null,
                'role' => $user['role'] ?? 'user',
                'status' => $user['status'] ?? 'active',
                'avatar_url' => $user['avatar_url'] ?? null,
                'last_login' => !empty($user['last_login']) ? date('Y-m-d H:i:s', strtotime($user['last_login'])) : null,
                'email_verified' => !empty($user['email_verified']) ? 'TRUE' : 'FALSE',
                'created_at' => !empty($user['created_at']) ? date('Y-m-d H:i:s', strtotime($user['created_at'])) : null,
                'updated_at' => !empty($user['updated_at']) ? date('Y-m-d H:i:s', strtotime($user['updated_at'])) : null
            ];
            
            // Remove null values
            $insertData = array_filter($insertData, function($value) {
                return $value !== null;
            });
            
            $columns = array_keys($insertData);
            $placeholders = array_fill(0, count($columns), '?');
            $values = array_values($insertData);
            
            $sql = sprintf(
                "INSERT INTO users (%s) VALUES (%s) RETURNING id",
                implode(', ', $columns),
                implode(', ', $placeholders)
            );
            
            $result = $pgsql->fetch($sql, $values);
            if ($result) {
                $migratedCount++;
                echo "  ✅ Migrated user: {$user['username']}\n";
            } else {
                echo "  ❌ Failed to migrate user: {$user['username']}\n";
            }
        }
        
        echo "\n✅ Migrated $migratedCount users from Firebase\n\n";
    }
} catch (Exception $e) {
    // Check if it's a duplicate key error
    if (strpos($e->getMessage(), 'duplicate key') !== false || strpos($e->getMessage(), 'Unique violation') !== false) {
        echo "  ⏩ Skipping duplicate user\n";
        echo "\n✅ Migrated $migratedCount users from Firebase (some skipped due to duplicates)\n\n";
    } else {
        echo "❌ Error migrating users: " . $e->getMessage() . "\n";
    }
}

// Step 6: Summary
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║                    Migration Complete                        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

echo "✅ Tables created:\n";
echo "   - users\n";
echo "   - user_activities\n";
echo "   - user_permissions\n";
echo "   - user_store_access\n\n";

// Show user count
$userCount = $pgsql->fetch("SELECT COUNT(*) as count FROM users");
echo "📊 Total users in PostgreSQL: " . $userCount['count'] . "\n";
