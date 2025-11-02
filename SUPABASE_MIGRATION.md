# Supabase Migration Guide

## Step 1: Create Supabase Project (15 minutes)

1. **Go to:** https://supabase.com
2. **Sign up/Login** (free tier includes):
   - 500MB database space
   - 2GB bandwidth/month
   - 50MB file storage
   - Unlimited API requests
   
3. **Create New Project:**
   - Organization: Your choice
   - Project Name: `inventory-system`
   - Database Password: **SAVE THIS!** (you'll need it)
   - Region: Choose closest to your location
     - Singapore: `ap-southeast-1`
     - US East: `us-east-1`
     - Europe: `eu-west-1`
   
4. **Wait ~2 minutes** for provisioning

5. **Get Connection Details:**
   - Go to: Project Settings → Database
   - Copy these values:
     ```
     Host: db.xxxxxxxxxxxxx.supabase.co
     Database name: postgres
     Port: 5432
     User: postgres
     Password: [your password]
     ```

---

## Step 2: Update Configuration (5 minutes)

Create new file: `config_supabase.php`

```php
<?php
/**
 * Supabase PostgreSQL Configuration
 */

// Supabase Database Configuration
define('DB_TYPE', 'postgresql');
define('DB_HOST', 'db.xxxxxxxxxxxxx.supabase.co'); // Replace with your host
define('DB_PORT', '5432');
define('DB_NAME', 'postgres');
define('DB_USER', 'postgres');
define('DB_PASS', 'your-database-password'); // Replace with your password
define('DB_CHARSET', 'utf8');

// SSL is REQUIRED for Supabase
define('DB_SSL_MODE', 'require');

// Supabase Project URL and Keys (for future API usage)
define('SUPABASE_URL', 'https://xxxxxxxxxxxxx.supabase.co');
define('SUPABASE_ANON_KEY', 'your-anon-key'); // Get from Project Settings → API
define('SUPABASE_SERVICE_KEY', 'your-service-role-key'); // Keep secret!

// Keep Firebase config for backup system
define('FIREBASE_DATABASE_URL', 'https://senpai-ef088-default-rtdb.asia-southeast1.firebasedatabase.app/');
define('FIREBASE_API_KEY', 'AIzaSyAI4sBJIxzMPbbNsNwp9d1fq-Nzp42iu_k');
define('FIREBASE_PROJECT_ID', 'senpai-ef088');

// Session Configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

// Timezone
date_default_timezone_set('Asia/Kuala_Lumpur');
?>
```

---

## Step 3: Update sql_db.php for SSL (10 minutes)

Modify the PDO connection to support Supabase SSL:

```php
// In sql_db.php, update the connection method:

private function __construct() {
    try {
        $dsn = DB_TYPE . ':host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
        
        // Add SSL mode for Supabase
        if (defined('DB_SSL_MODE')) {
            $dsn .= ';sslmode=' . DB_SSL_MODE;
        }
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false, // Don't use persistent for cloud
        ];
        
        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        throw new Exception("Database connection failed");
    }
}
```

---

## Step 4: Export Current Schema (15 minutes)

### Option A: Using pg_dump (Recommended)

```powershell
# Export schema only (structure)
pg_dump -h localhost -U postgres -d inventory_db --schema-only -f schema.sql

# Export data only
pg_dump -h localhost -U postgres -d inventory_db --data-only -f data.sql

# Or export everything
pg_dump -h localhost -U postgres -d inventory_db -f full_backup.sql
```

### Option B: Using Supabase SQL Editor

1. Go to: Database → SQL Editor in Supabase
2. Copy/paste your table creation scripts
3. Run them one by one

---

## Step 5: Import to Supabase (30 minutes)

### Method 1: Using psql command

```powershell
# Set password as environment variable (Windows PowerShell)
$env:PGPASSWORD = "your-supabase-password"

# Import schema
psql -h db.xxxxxxxxxxxxx.supabase.co -U postgres -d postgres -f schema.sql

# Import data
psql -h db.xxxxxxxxxxxxx.supabase.co -U postgres -d postgres -f data.sql

# Clear password
Remove-Item Env:\PGPASSWORD
```

### Method 2: Using Supabase Dashboard

1. Go to: Database → SQL Editor
2. Click: "+ New query"
3. Paste your SQL and run

---

## Step 6: Switch Configuration (2 minutes)

### Option A: Rename files
```powershell
# Backup current config
Rename-Item config.php config_local.php

# Use Supabase config
Rename-Item config_supabase.php config.php
```

### Option B: Modify existing config.php

Just update the database constants:

```php
// FROM (Local):
define('DB_HOST', 'localhost');

// TO (Supabase):
define('DB_HOST', 'db.xxxxxxxxxxxxx.supabase.co');
define('DB_SSL_MODE', 'require');
```

---

## Step 7: Test Connection (15 minutes)

Create test script: `test_supabase.php`

```php
<?php
require_once 'config.php';
require_once 'sql_db.php';

try {
    $db = SQLDatabase::getInstance();
    
    // Test query
    $result = $db->fetch("SELECT current_database(), version()");
    
    echo "✅ Connected to Supabase!\n";
    echo "Database: " . $result['current_database'] . "\n";
    echo "Version: " . $result['version'] . "\n";
    
    // Test tables
    $tables = $db->fetchAll("
        SELECT tablename 
        FROM pg_tables 
        WHERE schemaname = 'public' 
        ORDER BY tablename
    ");
    
    echo "\n📊 Tables found: " . count($tables) . "\n";
    foreach ($tables as $table) {
        echo "  - " . $table['tablename'] . "\n";
    }
    
    // Test a query
    $count = $db->fetch("SELECT COUNT(*) as total FROM products");
    echo "\n📦 Products: " . $count['total'] . "\n";
    
    echo "\n✅ All tests passed!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
```

Run it:
```powershell
php test_supabase.php
```

---

## Step 8: Verify Application (30 minutes)

Test all major features:

1. **Login System** ✓
   - Navigate to login page
   - Login with existing user
   
2. **Products Module** ✓
   - View products list
   - Add new product
   - Edit product
   
3. **POS Terminal** ✓
   - Make a sale
   - Verify sale_items saved
   - Check stock deduction
   
4. **Demand Forecasting** ✓
   - Select product
   - View forecast
   - Verify historical data
   
5. **Reports** ✓
   - Generate sales report
   - Check data accuracy

---

## Supabase Advantages

### ✅ What You Get FREE:

1. **500MB Database** (enough for 100k+ products)
2. **Automatic Backups** (daily, 7-day retention)
3. **Built-in Auth** (optional, can use later)
4. **Real-time Subscriptions** (for live updates)
5. **Row Level Security** (for multi-tenant)
6. **Auto-generated APIs** (REST + GraphQL)
7. **Dashboard** (SQL editor, table viewer)
8. **Monitoring** (query performance, logs)

### 🚀 Future Features You Can Add:

1. **Real-time Dashboard**
   ```javascript
   // Auto-update when new sale recorded
   supabase
     .from('sales')
     .on('INSERT', payload => {
       updateDashboard(payload.new);
     })
     .subscribe();
   ```

2. **Row Level Security**
   ```sql
   -- Users only see their store's data
   CREATE POLICY "Users see own store" ON products
     FOR SELECT USING (store_id = current_user_store());
   ```

3. **API Access**
   ```javascript
   // Mobile app can connect directly
   const { data } = await supabase
     .from('products')
     .select('*')
     .eq('active', true);
   ```

---

## Performance Tips

### 1. Connection Pooling

Supabase handles this automatically! No code changes needed.

### 2. Indexes

```sql
-- Add in Supabase SQL Editor
CREATE INDEX idx_products_store ON products(store_id) WHERE active = true;
CREATE INDEX idx_sales_date ON sales(created_at);
CREATE INDEX idx_sale_items_product ON sale_items(product_id);
```

### 3. Enable pg_stat_statements

Already enabled in Supabase! Check query performance in Dashboard → Database → Query Performance.

---

## Rollback Plan

If something goes wrong:

```powershell
# Restore local config
Rename-Item config.php config_supabase.php
Rename-Item config_local.php config.php

# Your local database is untouched
```

---

## Cost Estimate

**Free Tier Limits:**
- 500MB database ✓ (your current DB is probably <100MB)
- 2GB bandwidth/month ✓ (plenty for internal use)
- Unlimited API requests ✓

**Paid Tier ($25/month) includes:**
- 8GB database
- 250GB bandwidth
- Daily backups
- Point-in-time recovery
- 99.9% uptime SLA

You'll likely stay on **FREE tier** for quite a while!

---

## Next Steps

1. ✅ Create Supabase account
2. ✅ Get connection credentials
3. ✅ Update config files
4. ✅ Export local database
5. ✅ Import to Supabase
6. ✅ Test application
7. 🎉 Deploy!

---

## Support Resources

- **Documentation:** https://supabase.com/docs
- **Community:** https://github.com/supabase/supabase/discussions
- **Status:** https://status.supabase.com
- **Discord:** https://discord.supabase.com

---

## Troubleshooting

### Error: "SSL required"
Add to config: `define('DB_SSL_MODE', 'require');`

### Error: "Connection timeout"
Check firewall allows outbound port 5432

### Error: "Authentication failed"
Verify password from Supabase dashboard

### Slow queries
Check indexes, use Query Performance tab

---

**Ready to migrate? Let me know when you have the Supabase credentials!**
