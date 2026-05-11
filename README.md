# ACCBOS — Accounting Connector for BOS

ACCBOS is the middleware layer that connects our BOS / AiServe platform with
accounting systems such as **SQL Account**, AutoCount, UBS, and Bukku.
Phase 1 focuses on issuing Sales Orders to **SQL Account** via its API.

```
BOS / AiServe Order
        ↓
ACCBOS Middleware
        ↓
Data Validation
        ↓
Accounting Connector Mapping
        ↓
SQL Account API
        ↓
Sales Order Created
        ↓
SO Number Sync Back to BOS
```

---

## Tech stack

- Native PHP 8+
- MySQL 5.7+ / MariaDB 10.3+
- HTML, CSS, JavaScript
- Bootstrap 5 (CDN)
- No Laravel, no Composer, no Docker
- Compatible with Hostinger VPS / shared hosting (Apache)

---

## Folder structure

```
/accbos
  /config         db_config.php, app_config.php
  /includes       auth, csrf, functions, header, footer
  /connectors
    /sql_account  SqlAccountClient, SqlAccountSigner,
                  SqlAccountSalesOrder, SqlAccountCustomer, SqlAccountStock
  /admin          login, logout, dashboard, companies, sales_orders,
                  sales_order_create/detail/push, sync_queue, sync_retry, api_logs
  /database       schema.sql
  /assets         css/style.css, js/app.js
  /logs           runtime logs (gitignored)
```

---

## Installation

1. **Create database**

   ```sql
   CREATE DATABASE accbos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. **Load schema**

   ```bash
   mysql -u root -p accbos < database/schema.sql
   ```

3. **Configure**

   Either set environment variables (`ACCBOS_DB_HOST`, `ACCBOS_DB_NAME`,
   `ACCBOS_DB_USER`, `ACCBOS_DB_PASS`, `ACCBOS_BASE_URL`, `ACCBOS_APP_KEY`)
   or edit defaults inside `config/db_config.php` and `config/app_config.php`.

4. **Point Apache** at the `accbos/` folder. Default URL: `http://your-host/accbos/`.

5. **Sign in** with the seeded admin:

   - Username: `admin`
   - Password: `change_this_password`

   **Change this password immediately after first login.**

---

## Phase 1 deliverables (this release)

- Database schema (`admins`, `companies`, `sales_orders`, `sales_order_items`,
  `api_logs`, `sync_queue`, `system_settings`)
- Secure admin login / logout (PDO prepared statements, CSRF, session timeout)
- Dashboard with company / SO / queue stats
- Company management (list, create, edit, detail)
- SQL Account connector skeleton with AWS SigV4-style signer, JSON client,
  and `SalesOrder`, `Customer`, `Stock` mappers
- Sales Order CRUD (header + items, auto totals)
- "Push to SQL Account" action with validation, response capture, retry queue
- API call log with detail view
- Sync queue with manual retry

> Endpoint paths and field names inside `connectors/sql_account/` use the
> conventional shape. Replace them with the values from the official SQL
> Account Postman collection (look for `// TODO: Replace endpoint and field
> names...` markers) before going live.

---

## Roadmap

- **Phase 2** — Hardening of SO push pipeline, scheduled queue worker.
- **Phase 3** — Customer / Stock / Invoice / Payment sync.
- **Phase 4** — AutoCount, UBS import/export, Bukku connector.

---

## Security notes

- All input goes through PDO prepared statements.
- All output is escaped via `e()` (htmlspecialchars).
- CSRF tokens guard every POST form.
- API secret keys are masked in the UI after save and never returned in plain
  text to the browser.
- Sensitive folders (`config/`, `includes/`, `connectors/`, `database/`,
  `logs/`) are blocked at the Apache level via `.htaccess`.
