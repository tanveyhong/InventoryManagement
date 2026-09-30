# InventoryManagement

InventoryManagement is a PHP-based inventory and POS application with a hybrid data model:
- application modules in `modules/` and `src/`
- Firebase access via `db.php` / `firebase_rest_client.php`
- SQL access via `sql_db.php` (PostgreSQL, SQLite, and MySQL drivers)

## Repository reality check

The actively wired runtime is the PHP app in the repository root (`index.php`).
The `api/` folder currently contains placeholder files (`api/server.js`, `api/package.json`, `api/README.md` are empty), so Node API startup commands are not currently usable from this checkout.

## Local setup (PHP runtime)

1. Install PHP 8.2+ with extensions used by the app (`pdo`, `pdo_pgsql`, `pgsql`).
2. Install Composer dependencies:
   ```bash
   composer install
   ```
3. Create local configuration from the template:
   ```bash
   cp config.example.php config.php
   ```
4. Update database and Firebase credentials in `config.php`.
5. Start the app:
   ```bash
   php -S 127.0.0.1:8000 -t /home/runner/work/InventoryManagement/InventoryManagement
   ```
6. Open `http://127.0.0.1:8000`.

## Database bootstrap helpers

The repository includes one-off setup and migration scripts. Common examples:

- `create_users_tables.php`
- `create_supply_chain_tables.php`
- `create_sales_tables.php`
- `create_stock_transfers_table.php`
- `update_schema_for_supply_chain.php`

Run them from the project root after `config.php` is configured:

```bash
php create_users_tables.php
```

## Schema references

- PostgreSQL schema: `docs/postgresql_schema.sql`
- Legacy MySQL-style schema: `docs/schema.sql`

## Related docs

- `DEPLOYMENT_GUIDE.md`
- `MIGRATION_TO_POSTGRESQL.md`
- `docs/BACKEND_CONVERSION_GUIDE.md`
- `docs/REFACTORED_ARCHITECTURE.md`
