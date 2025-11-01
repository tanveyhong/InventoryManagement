# Migration from SQLite to PostgreSQL

## Overview
This guide will help you migrate your inventory system from SQLite (local) to PostgreSQL (multi-user, cloud-ready).

## Benefits of PostgreSQL
- ✅ **Multi-user support** - Multiple POS terminals can access same database
- ✅ **ACID compliance** - Reliable transactions, no data loss
- ✅ **Better performance** - Optimized for concurrent access
- ✅ **Cloud-ready** - Can be hosted on AWS RDS, Google Cloud SQL, Azure
- ✅ **Advanced features** - JSON support, full-text search, geospatial queries
- ✅ **Scalability** - Handles millions of records efficiently

## Prerequisites

### Option 1: Local PostgreSQL Installation (Development)
1. Download PostgreSQL: https://www.postgresql.org/download/windows/
2. Install with default settings
3. Remember the password you set for the `postgres` user
4. Default port: 5432

### Option 2: Cloud PostgreSQL (Production - Recommended)
- **AWS RDS**: Free tier available, easy setup
- **Google Cloud SQL**: $7/month for small instance
- **ElephantSQL**: Free tier with 20MB storage
- **Heroku Postgres**: Free tier with 10K rows

## Migration Steps

### Step 1: Install PostgreSQL
```bash
# Windows: Download and run installer from postgresql.org
# Or use Chocolatey
choco install postgresql

# Verify installation
psql --version
```

### Step 2: Create Database and User
```bash
# Connect to PostgreSQL as superuser
psql -U postgres

# In PostgreSQL console:
CREATE DATABASE inventory_system;
CREATE USER inventory_user WITH PASSWORD 'your_secure_password';
GRANT ALL PRIVILEGES ON DATABASE inventory_system TO inventory_user;
\q
```

### Step 3: Update Configuration
Edit `config.php` and add PostgreSQL settings:
```php
// PostgreSQL Configuration
define('DB_TYPE', 'pgsql'); // Change from 'sqlite' to 'pgsql'
define('PG_HOST', 'localhost'); // Or your cloud database host
define('PG_PORT', '5432');
define('PG_DATABASE', 'inventory_system');
define('PG_USERNAME', 'inventory_user');
define('PG_PASSWORD', 'your_secure_password');
```

### Step 4: Run Migration Script
```bash
php migrate_sqlite_to_postgresql.php
```

This will:
1. Create PostgreSQL schema
2. Export all data from SQLite
3. Import data into PostgreSQL
4. Verify data integrity
5. Create indexes for performance

### Step 5: Test the System
1. Clear all caches: `php -r "array_map('unlink', glob('storage/cache/*.cache'));"`
2. Test dashboard loading
3. Test POS terminal
4. Test stock management
5. Verify all features work

### Step 6: Switch Firebase to Backup Mode
Once PostgreSQL is working:
- PostgreSQL becomes primary (all reads/writes)
- Firebase becomes backup (sync every 15 minutes)
- Reduces Firebase quota usage to ~100 operations/day

## Rollback Plan
If something goes wrong:
1. Your SQLite database is preserved as `storage/database.sqlite.backup`
2. Change `DB_TYPE` back to `'sqlite'` in `config.php`
3. Restore from backup if needed

## Performance Comparison

### Before (SQLite + Firebase):
- Dashboard load: 2-3 seconds
- Firebase reads: 25,000/day (exceeds free tier)
- Multi-user: Not supported
- Offline: Not reliable

### After (PostgreSQL + Firebase backup):
- Dashboard load: 0.2-0.5 seconds ⚡
- Firebase reads: ~100/day (well within free tier)
- Multi-user: Fully supported
- Offline: Can queue operations

## Cloud Deployment Options

### AWS RDS PostgreSQL (Recommended for Production)
**Pros:**
- Free tier: 750 hours/month for 12 months
- db.t3.micro instance (1 vCPU, 1GB RAM)
- 20GB storage
- Automated backups
- Easy scaling

**Setup:**
1. Go to AWS RDS Console
2. Create database → PostgreSQL
3. Choose Free tier template
4. Set database name, username, password
5. Note the endpoint URL
6. Update `config.php` with RDS endpoint

### Google Cloud SQL
**Pros:**
- $7/month for db-f1-micro
- 10GB storage included
- Automatic backups
- Easy integration with Google Cloud

### ElephantSQL (Good for Testing)
**Pros:**
- Free tier: 20MB storage
- No credit card required
- Good for development/testing

**Setup:**
1. Go to elephantsql.com
2. Create free account
3. Create "Tiny Turtle" instance (free)
4. Copy connection string
5. Update `config.php`

## Maintenance

### Daily Backups (Automated)
```bash
# Add to cron or Windows Task Scheduler
pg_dump -U inventory_user inventory_system > backup_$(date +%Y%m%d).sql
```

### Monitor Performance
```sql
-- Check slow queries
SELECT query, calls, total_time, mean_time
FROM pg_stat_statements
ORDER BY mean_time DESC
LIMIT 10;

-- Check database size
SELECT pg_size_pretty(pg_database_size('inventory_system'));
```

## Support
If you encounter issues during migration:
1. Check logs in `storage/logs/migration.log`
2. Verify PostgreSQL is running: `pg_isready`
3. Test connection: `psql -U inventory_user -d inventory_system`

## Next Steps After Migration
1. ✅ Set up automated daily backups
2. ✅ Configure connection pooling for better performance
3. ✅ Set up monitoring alerts
4. ✅ Schedule Firebase sync to run every 15 minutes (not real-time)
5. ✅ Add database indexes for frequently queried fields
