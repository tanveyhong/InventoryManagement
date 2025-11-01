# PostgreSQL Installation Guide for Windows

## Quick Setup (5 minutes)

### Step 1: Download PostgreSQL
1. Go to: https://www.postgresql.org/download/windows/
2. Click "Download the installer"
3. Download the latest version (16.x recommended)
4. File size: ~300MB

### Step 2: Install PostgreSQL
1. Run the installer (postgresql-16.x-windows-x64.exe)
2. **Installation Directory**: Keep default `C:\Program Files\PostgreSQL\16`
3. **Components**: Select all (PostgreSQL Server, pgAdmin 4, Stack Builder, Command Line Tools)
4. **Data Directory**: Keep default
5. **Password**: Set a password for 'postgres' superuser
   - **IMPORTANT**: Remember this password!
   - Example: `admin123` (change in production)
6. **Port**: Keep default `5432`
7. **Locale**: Keep default
8. Click "Next" through remaining screens
9. Wait for installation (2-3 minutes)

### Step 3: Verify Installation
Open Command Prompt or PowerShell:
```bash
# Check PostgreSQL version
psql --version

# If command not found, add to PATH:
# Add to PATH: C:\Program Files\PostgreSQL\16\bin
```

### Step 4: Create Database
Open PowerShell **as Administrator**:

```powershell
# Method 1: Using psql command line
psql -U postgres

# You'll be prompted for the password you set during installation
# Once connected, run these SQL commands:
```

```sql
-- Create database
CREATE DATABASE inventory_system;

-- Create user
CREATE USER inventory_user WITH PASSWORD 'SecurePassword123!';

-- Grant privileges
GRANT ALL PRIVILEGES ON DATABASE inventory_system TO inventory_user;

-- Connect to the new database
\c inventory_system

-- Grant schema privileges
GRANT ALL ON SCHEMA public TO inventory_user;

-- Exit psql
\q
```

### Step 5: Configure Your Application

Edit `config.php`:

```php
// Change DB_TYPE from 'sqlite' to 'pgsql'
define('DB_TYPE', 'pgsql');

// PostgreSQL Configuration
define('PG_HOST', 'localhost');
define('PG_PORT', '5432');
define('PG_DATABASE', 'inventory_system');
define('PG_USERNAME', 'inventory_user');
define('PG_PASSWORD', 'SecurePassword123!'); // Use the password you set above
```

### Step 6: Run Migration
```bash
php migrate_sqlite_to_postgresql.php
```

### Step 7: Test Connection
```bash
# Test if you can connect
psql -U inventory_user -d inventory_system -h localhost

# You should see:
# inventory_system=>

# Exit with:
\q
```

## Alternative: Using pgAdmin (GUI)

If you prefer a graphical interface:

1. Open **pgAdmin 4** (installed with PostgreSQL)
2. Connect to **PostgreSQL 16** (it will ask for the postgres password)
3. Right-click **Databases** → **Create** → **Database**
   - Database: `inventory_system`
   - Owner: `postgres`
4. Right-click **Login/Group Roles** → **Create** → **Login/Group Role**
   - General tab → Name: `inventory_user`
   - Definition tab → Password: `SecurePassword123!`
   - Privileges tab → Check "Can login?"
5. Right-click `inventory_system` database → **Properties** → **Security**
   - Add `inventory_user` with ALL privileges

## Cloud Alternatives (No Installation Needed)

### ElephantSQL (Free Tier)
1. Go to https://www.elephantsql.com/
2. Sign up for free account
3. Create new instance:
   - Plan: Tiny Turtle (Free)
   - Data center: Choose nearest location
4. Copy connection details:
   - Server: `<something>.db.elephantsql.com`
   - User & Default database: `<your_username>`
   - Password: `<shown in details>`

Update config.php:
```php
define('PG_HOST', 'jelani.db.elephantsql.com'); // Your server
define('PG_PORT', '5432');
define('PG_DATABASE', 'xyzabcde'); // Your database name
define('PG_USERNAME', 'xyzabcde'); // Same as database
define('PG_PASSWORD', 'long_secure_password_here');
```

### AWS RDS (Production)
1. Go to AWS Console → RDS
2. Create database → PostgreSQL
3. Choose Free tier template
4. Set master username and password
5. Create database
6. Note the endpoint URL
7. Update config.php with endpoint

## Common Issues

### "psql: command not found"
**Solution**: Add PostgreSQL bin directory to PATH
```
Control Panel → System → Advanced → Environment Variables
Add to PATH: C:\Program Files\PostgreSQL\16\bin
```

### "password authentication failed"
**Solution**: 
1. Check password in config.php matches what you set
2. Try resetting password:
```bash
psql -U postgres
ALTER USER inventory_user WITH PASSWORD 'NewPassword123!';
```

### "could not connect to server"
**Solution**:
1. Check if PostgreSQL service is running:
```
services.msc → PostgreSQL 16 → Start
```

### "FATAL: database does not exist"
**Solution**: Create the database first
```bash
psql -U postgres
CREATE DATABASE inventory_system;
```

## Performance Tips

### After Migration
```sql
-- Connect to your database
\c inventory_system

-- Create indexes (already done by optimize script)
-- Analyze tables for query optimization
ANALYZE products;
ANALYZE sales;
ANALYZE stores;

-- Check database size
SELECT pg_size_pretty(pg_database_size('inventory_system'));

-- Check table sizes
SELECT 
    tablename,
    pg_size_pretty(pg_total_relation_size(tablename::text)) as size
FROM pg_tables 
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(tablename::text) DESC;
```

## Security Recommendations

1. **Change default passwords** - Don't use simple passwords in production
2. **Enable SSL** - For cloud databases
3. **Firewall rules** - Only allow connections from your application server
4. **Regular backups** - Set up automated daily backups
5. **Monitor connections** - Use `pg_stat_activity` to monitor active connections

## Next Steps

After successful installation:
1. ✅ Run migration script: `php migrate_sqlite_to_postgresql.php`
2. ✅ Test dashboard loading speed
3. ✅ Verify data integrity
4. ✅ Set up automated backups
5. ✅ Monitor performance

**Need help?** Check the error logs:
- PostgreSQL logs: `C:\Program Files\PostgreSQL\16\data\log\`
- Application logs: `storage/logs/errors.log`
