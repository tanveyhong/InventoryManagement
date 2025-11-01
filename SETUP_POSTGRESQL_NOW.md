# Quick PostgreSQL Setup Guide

## 🎯 Complete Setup in 3 Steps

### ✅ Step 1: Install PostgreSQL (15 minutes)

1. **Download:** https://www.postgresql.org/download/windows/
   - Click "Download the installer"
   - Get version 16.x (latest)

2. **Install:**
   - Run the downloaded `.exe` file
   - **IMPORTANT:** When asked for password, use: `admin123` (or remember your own)
   - Keep all default settings
   - Install all components

3. **Verify Installation:**
   - Open PowerShell
   - Type: `psql --version`
   - If you see a version number, it's installed! ✅

---

### ✅ Step 2: Create Database (5 minutes)

Open **PowerShell as Administrator**, then copy and paste each command:

```powershell
# Connect to PostgreSQL (enter your password when prompted)
psql -U postgres
```

Inside the PostgreSQL prompt, run these commands one by one:

```sql
-- Create the database
CREATE DATABASE inventory_system;

-- Create a user
CREATE USER inventory_user WITH PASSWORD 'SecurePassword123!';

-- Give permissions
GRANT ALL PRIVILEGES ON DATABASE inventory_system TO inventory_user;

-- Switch to the new database
\c inventory_system

-- Grant schema access
GRANT ALL ON SCHEMA public TO inventory_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO inventory_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO inventory_user;

-- Exit
\q
```

---

### ✅ Step 3: Migrate Your Data (Automatic!)

Back in **regular PowerShell** (in your InventorySystem folder):

```powershell
# Update config to use PostgreSQL
php -r "file_put_contents('config.php', str_replace(\"define('DB_TYPE', 'sqlite');\", \"define('DB_TYPE', 'pgsql');\", file_get_contents('config.php')));"

# Update PostgreSQL password in config
php -r "file_put_contents('config.php', str_replace(\"define('PG_PASSWORD', 'your_secure_password');\", \"define('PG_PASSWORD', 'SecurePassword123!');\", file_get_contents('config.php')));"

# Run migration (this copies all your data from SQLite to PostgreSQL)
php migrate_sqlite_to_postgresql.php
```

**That's it!** 🎉

---

## 🌐 What You Get After Setup:

### ✅ Multi-User Access
- Partners can access from their own computers
- Multiple people can use the system at the same time
- Everyone sees the same real-time data

### ✅ Remote Access Ready
You can host this on:
- **AWS RDS** - Amazon's database service
- **Google Cloud SQL** - Google's database service
- **DigitalOcean** - Simple cloud hosting
- **ElephantSQL** - Free PostgreSQL hosting

### ✅ Better Performance
- Faster queries with large datasets
- Handles thousands of products easily
- Better indexing and optimization

### ✅ No More Firebase Quota Issues
- PostgreSQL has no read limits
- No quota consumption
- Free unlimited access to your own data

---

## 🆘 Troubleshooting

### "psql: command not found"
Add to your PATH:
1. Search Windows for "Environment Variables"
2. Edit "Path" variable
3. Add: `C:\Program Files\PostgreSQL\16\bin`
4. Restart PowerShell

### "Password authentication failed"
Make sure you're using the password you set during PostgreSQL installation.

### Migration fails
1. Check PostgreSQL is running (open Services, look for "postgresql-x64-16")
2. Make sure database `inventory_system` exists
3. Check user `inventory_user` has permissions

---

## 📞 Ready to Start?

1. Install PostgreSQL first
2. Then tell me "done installing" and I'll help with the migration!

---

## 🔄 Rollback (If Needed)

If something goes wrong, you can switch back to SQLite:

```powershell
# Restore config to SQLite
php -r "file_put_contents('config.php', str_replace(\"define('DB_TYPE', 'pgsql');\", \"define('DB_TYPE', 'sqlite');\", file_get_contents('config.php')));"
```

Your original data is backed up at: `storage/database.sqlite.backup_[timestamp]`
