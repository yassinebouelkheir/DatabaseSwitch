# DatabaseSwitch — Dolibarr Database Migration Tool

Bidirectional MariaDB ↔ PostgreSQL migration for Dolibarr with zero downtime.

![Version](https://img.shields.io/badge/version-1.1.1-blue)
![Dolibarr](https://img.shields.io/badge/Dolibarr-14.0%2B-purple)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)
![License](https://img.shields.io/badge/license-GPL--3.0-orange)

## Features

- **Bidirectional** — MariaDB → PostgreSQL and PostgreSQL → MariaDB
- **8-step workflow** — Guided migration with progress tracking and error handling
- **Staging database** — All changes happen in a temporary copy, never on production
- **100% row verification** — Every table is counted source vs staging before swap
- **Atomic swap** — Rename staging → production in one operation, rollback if failure
- **conf.php auto-patch** — Updates Dolibarr configuration automatically after migration
- **Backup first** — Automatic backup before any destructive operation
- **BI Views transfer** — Automatically recreates Power BI views in the target dialect

## Migration Workflow

```
┌─────────────┐     ┌─────────────┐     ┌──────────────┐     ┌──────────────┐
│  test_conn  │ ──→ │ step_check  │ ──→ │ step_backup  │ ──→ │ step_staging │
│ Connections │     │   Tools     │     │  .sql.gz     │     │ Create temp  │
└─────────────┘     └─────────────┘     └──────────────┘     └──────────────┘
                                                                     │
┌─────────────┐     ┌─────────────┐     ┌──────────────┐     ┌──────┴───────┐
│  step_swap  │ ←── │step_verify  │ ←── │ step_seqfix  │ ←── │step_migrate  │
│ Rename → GO │     │ Row counts  │     │  Sequences   │     │ Copy data    │
└─────────────┘     └─────────────┘     └──────────────┘     └──────────────┘
```

## Compatibility

| | MariaDB → PG | PG → MariaDB |
|---|---|---|
| **Data transfer** | pgloader (fast, bulk) | PDO line-by-line |
| **Type conversion** | Automatic (CAST rules) | Automatic (pgTypeToMysql) |
| **Sequences** | `setval()` from MAX(rowid) | AUTO_INCREMENT from MAX |
| **DEFAULT values** | Restored via `step_defaults` | Included in CREATE TABLE |
| **BI Views** | Recreated in PG syntax | Recreated in MySQL syntax |

### Tools Required

| Tool | MariaDB → PG | PG → MariaDB |
|---|---|---|
| pgloader | ✅ Required | — |
| pg_dump | ✅ Required | ✅ Required |
| psql | ✅ Required | ✅ Required |
| mysqldump | ✅ Required | ✅ Required |
| mysql client | ✅ Required | ✅ Required |

## Installation

1. Download `databaseswitch-1.1.1.zip`
2. Extract to `htdocs/custom/databaseswitch/`
3. Go to **Home → Setup → Modules** → Search for "DatabaseSwitch" → **Activate**
4. Click the **DatabaseSwitch** menu entry (admin only)

### Prerequisites

```bash
# MariaDB → PostgreSQL
sudo apt install pgloader postgresql-client mariadb-client

# PostgreSQL → MariaDB
sudo apt install postgresql-client mariadb-client
```

## Usage

1. Open **DatabaseSwitch** from the admin menu
2. Fill in source and destination connection details
3. Click **Run** — the 8 steps execute automatically
4. Watch the progress log for each step
5. After `step_swap`, Dolibarr is running on the new database

### Important Notes

- **Admin only** — Only Dolibarr administrators can access DatabaseSwitch
- **Backup first** — Step 2 creates an automatic backup, but make your own too
- **Test first** — Run on a copy of your database before production
- **conf.php** — Must be writable by www-data (`chmod 640`, `chown www-data:www-data`)

## File Structure

```
databaseswitch/
├── core/modules/
│   └── modDatabaseSwitch.class.php   # Module descriptor + conf.php permissions
├── ajax/
│   └── migrate.php               # AJAX migration engine (all 8 steps)
├── lib/
│   └── databaseswitch.lib.php          # Shell commands, PG/MySQL connections, utilities
├── sql/
│   ├── views_bi_mysql.sql        # BI views (MariaDB dialect)
│   └── views_bi_pgsql.sql        # BI views (PostgreSQL dialect)
├── css/
│   └── databaseswitch.css            # Navbar icon styling
├── img/                          # Module icons
├── langs/
│   ├── fr_FR/databaseswitch.lang
│   └── en_US/databaseswitch.lang
└── admin/
    └── index.php                 # Main UI (connection form + step runner)
```

## BI Views

DatabaseSwitch includes 9 Power BI-ready SQL views that are automatically recreated after migration in the correct dialect:

| View | Type | Description |
|------|------|-------------|
| `v_bi_dim_client` | Dimension | Clients and prospects |
| `v_bi_dim_produit` | Dimension | Products and services |
| `v_bi_dim_fournisseur` | Dimension | Suppliers |
| `v_bi_dim_date` | Dimension | Calendar 2020-2030 (FR labels) |
| `v_bi_fact_ventes` | Fact | Invoice lines with margin |
| `v_bi_fact_achats` | Fact | Supplier invoice lines |
| `v_bi_pipeline` | Bonus | Commercial proposals with conversion |
| `v_bi_stock` | Bonus | Stock by warehouse with alerts |
| `v_bi_tickets` | Bonus | Support tickets with resolution time |

## Security

- Admin-only access enforced (`$user->admin` check)
- Passwords never logged (redacted in debug output)
- PostgreSQL passwords escaped with `str_replace("'", "''")` (not `addslashes`)
- Staging name validated before every step
- Backup files created with `0600` permissions
- pgloader `.load` files deleted immediately after use
- Shell arguments escaped with `escapeshellarg()`

## Changelog

### v1.1.1
- Fix: PostgreSQL password SQL injection (`str_replace` instead of `addslashes`)
- Fix: Staging name validation on `step_seqfix`, `step_verify`, `step_swap`
- Add: BI views auto-recreation after migration (MySQL + PostgreSQL dialects)
- Add: `phpmin` and `need_dolibarr_version` in module descriptor
- Add: English translation (`langs/en_US/`)

### v1.1.0
- PG compatibility layer (compat functions)
- Hook system for auto-fix on every page
- `step_defaults` for MySQL DEFAULT value restoration
- Improved error messages and debug logging

### v1.0.0
- Initial release with 8-step workflow
- MariaDB → PostgreSQL via pgloader
- PostgreSQL → MariaDB via PDO line-by-line
- Atomic swap with rollback

## Author

**BOUELKHEIR Yassine**

## License

GPL-3.0
