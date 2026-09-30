# Backend Conversion Guide (Current Repository State)

This guide documents the backend state in this repository and prevents outdated setup assumptions.

## 1) What is currently runnable

The production-wired backend in this repository is the PHP application in the root directory:

- Entry point: `index.php`
- SQL layer: `sql_db.php`
- Firebase layer: `db.php`, `firebase_config.php`, `firebase_rest_client.php`
- Config source: `config.php` (copied from `config.example.php`)

Most maintenance and migration operations are implemented as PHP scripts in the project root (for example `create_users_tables.php`, `create_supply_chain_tables.php`, `sync_sql_to_firebase.php`).

## 2) Node API status in this checkout

`api/server.js`, `api/package.json`, and `api/README.md` are currently empty files. Because of that, this checkout does **not** provide a runnable Node backend startup path right now.

Do not document or rely on `npm run`/`node api/server.js` flows until those files are populated with actual implementation and scripts.

## 3) Safe developer setup flow

1. Install PHP dependencies:
   ```bash
   composer install
   ```
2. Create environment config:
   ```bash
   cp config.example.php config.php
   ```
3. Set `DB_TYPE` / `DB_DRIVER` and credentials in `config.php`.
4. Run required setup scripts from the repository root:
   ```bash
   php create_users_tables.php
   php create_supply_chain_tables.php
   ```

## 4) Docker note

The current `Dockerfile` runs `cp config.production.php config.php` during build. Ensure `config.production.php` exists in your deployment context, or update your deployment process accordingly.

## 5) Documentation update rule

When backend architecture changes, update these files together in one PR:

- `README.md`
- `docs/BACKEND_CONVERSION_GUIDE.md`
- any architecture doc that mentions runtime commands (for example `docs/NODEJS_HYBRID_ARCHITECTURE.md`)

This keeps onboarding instructions aligned with the actual code and scripts present in the repository.
