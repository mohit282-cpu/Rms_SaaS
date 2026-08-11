# RMS SaaS — QR Cafe Restaurant Management System

A **multi-tenant SaaS** Restaurant Management System (RMS). One shared codebase powers an unlimited number of independent restaurant environments, each fully isolated by tenant (`restaurant_id`) while a **Super Admin platform** governs onboarding, subscriptions, and tenant lifecycle.

Built with plain **PHP 8.1+ / MySQL / Apache** — no Composer, no build step, no framework. Designed for XAMPP and shared cPanel hosting.

| Layer | Technology |
| --- | --- |
| Language | PHP 8.1+ (mysqli, mbstring) |
| Database | MySQL 8.0+ / MariaDB |
| Server | Apache 2.4 (`mod_rewrite`, `mod_headers`) |
| Frontend | Vanilla JavaScript + Tailwind CSS 3.4 (CDN) |
| Realtime | AJAX polling (no WebSockets/SSE) |
| Build tools | None — zero install, zero bundler |

---

## Surfaces

1. **Public customer site** — landing page with onboarding request form, QR-based digital menu, cart/checkout, live order tracking, settlement.
2. **Restaurant admin portal** (`admin/`) — POS orders, tables + QR codes, kitchen tickets, inventory, assets, staff RBAC, landing-page customizer.
3. **Kitchen Display System** — `kitchen-dashboard.php` / `kitchen-menu.php` with a separate KDS password.
4. **Super Admin platform** (`super-admin/`) — onboarding pipeline, tenant provisioning, subscription plans, tenant suspension/activation, password resets, support impersonation.

---

## Multi-Tenancy & SaaS Model

- **Tenant = one `restaurants` row.** Every admin feature is scoped by `getTenantId()` (`helpers/TenantContext.php`), resolved in this priority:
  1. `$_SESSION['restaurant_id']` (logged-in admin)
  2. `$_SESSION['customer_restaurant_id']` (QR ordering session)
  3. `$_SESSION['impersonating_restaurant_id']` (super-admin impersonation)
  4. fallback `null`
- **Plans** live in `subscription_plans` (4 seeded: Starter / Business / Pro / Enterprise) with `max_tables` and `max_staff` limits.
- **`SubscriptionService`** (`helpers/SubscriptionService.php`) gates tenant access: `isActive()`, `getRemainingDays()`, `getTenantPlanLimits()`. Suspended / cancelled / expired tenants are blocked; past-due subscriptions auto-flip to `EXPIRED`.
- **Onboarding** flows through `restaurant_requests` (public form) with lifecycle `PENDING → CONTACTED → APPROVED → CONVERTED` or `REJECTED`, plus a manual `create-restaurant.php` path. Both provision a tenant atomically: restaurant + owner `admin_user` + subscription + default tables + default category in one transaction.

### SaaS tables (migration `database/migrations/004_saas_multi_tenancy.sql`)

| Table | Purpose |
| --- | --- |
| `subscription_plans` | Plan catalog: price, `max_tables`, `max_staff`, features |
| `restaurants` | Tenants: status, `subscription_plan_id`, subscription dates/status |
| `subscriptions` | Tenant subscription records |
| `restaurant_requests` | Public onboarding applications |
| `notifications` | Platform + tenant notifications |

---

## Module Map

### Public (no login)

| Route | Purpose |
| --- | --- |
| `index.php` | Landing page + SaaS onboarding request form (content editable from admin) |
| `menu.php?table=…&token=…` | QR digital menu behind a per-table token (legacy HMAC-signed URLs also supported) |
| `cart.php` / `checkout.php` | Cart and checkout |
| `place-order.php` | Server-side order creation (rate-limited, idempotent, stock-checked, server-priced) |
| `order-success.php` | Live order tracker + settlement + waiter call |
| `privacy-policy.php`, `terms-of-service.php`, `landing-preview.php` | Legal pages and landing preview |
| `manifest.json` | PWA manifest (installable; no service worker / offline) |

### Kitchen Display System (separate password)

| Route | Purpose |
| --- | --- |
| `kitchen-dashboard.php` | Live ticket wall, KPI cards, status buttons (Start Cooking → Mark Ready → Serve), 2s polling |
| `kitchen-menu.php` | Kitchen-side menu with quick stock toggles |

### Admin console (login required)

| Area | Pages |
| --- | --- |
| Operations Center | `index.php` (realtime KPIs, live orders, low-stock, activity feed) |
| Floor & Tables | `tables.php` (CRUD, capacity, status, QR generation) |
| Orders | `orders.php`, `order-details.php` |
| Menu | `menu-items.php`, `categories.php` |
| Landing customizer | `landing-page.php` |
| Payments | `payment-settings.php` (gateway config) |
| Inventory | `inventory.php`, `inventory-items.php`, `inventory-categories.php`, `suppliers.php`, `purchase-orders.php`, `goods-receiving.php`, `stock-movements.php`, `stock-audit.php`, `waste.php`, `recipes.php`, `inventory-reports.php` |
| Assets | `asset-dashboard.php`, `assets.php`, `asset-categories.php`, `asset-maintenance.php`, `asset-warranty.php`, `asset-transfers.php`, `asset-depreciation.php`, `asset-qr.php`, `asset-reports.php` |
| Security & IAM | `change-password.php` |
| Setup | `setup-wizard.php` |

### Super Admin platform (`super-admin/`)

| Page | Purpose |
| --- | --- |
| `login.php` / `logout.php` | Platform auth (CSRF + rate-limited 5/5 min) |
| `index.php` | Tenant/request/subscription metrics dashboard |
| `requests.php` | Onboarding pipeline: search, filter, paginate, mark contacted, reject, approve & provision tenant |
| `create-restaurant.php` | Manual tenant provisioning with credential delivery |
| `restaurants.php` | Tenant list + manage: suspend, activate, disable, reset password, change username, impersonate |
| `subscriptions.php` | Plan/tenant subscription governance (upgrade/downgrade with usage-limit checks) |

---

## Roles & Permissions

Authentication: `admin_users` table, bcrypt-hashed passwords, session-based. Super admins are flagged via `is_super_admin = 1` (or role `SUPER_ADMIN`).

- **Super Admin** — full platform governance + impersonation of any tenant.
- **OWNER / MANAGER** — full restaurant operations.
- **CASHIER** — order/payment views and settlement.
- **KITCHEN** — order views + kitchen status updates.
- **WAITER** — order create, table views, waiter calls.
- **INVENTORY_MANAGER** — inventory, suppliers, purchase orders, recipes.

Enforcement points: `Auth::requireAdmin()`, `Auth::requireSuperAdmin()`, `Auth::requireRestaurant()` (admin + tenant), `Auth::checkPermission()` (role → permission map, `helpers/Auth.php`), and shared sidebar gating in `admin/includes/sidebar.php`.

**Kitchen (KDS)** uses a separate password stored in `landing_page_settings` (default fallback `kitchen123` — change it). **Customers** need no login — access is authenticated by the table's QR token bound to the session, which also guards order lookups.

---

## Order Lifecycle

```
new → preparing → ready → completed → refund_requested → refunded
   ↘ cancelled
```

Enforced by `helpers/OrderService.php` (`$allowedTransitions`) inside row-locked (`FOR UPDATE`) transactions. Pricing is recomputed server-side from the database; the client never supplies totals.

### Table lifecycle

```
vacant → occupied → payment_pending → vacant
vacant → reserved → occupied → cleaning → vacant
occupied ↔ disabled (admin)
```

---

## Database

The core schema (~38 tables) is auto-created/upgraded by `config.php` (`ensureDatabaseSchema`) on first run. Manual imports and incremental migrations are provided under `database/`:

- `database.sql` — core schema + seed data (default admin: `admin` / `admin123`)
- `database_inventory_assets.sql` — inventory + asset schema
- `database/migrations/001_initial_schema.sql`
- `database/migrations/002_inventory_assets.sql`
- `database/migrations/003_security_idempotency.sql`
- `database/migrations/004_saas_multi_tenancy.sql` — **SaaS layer (run this for multi-tenant)**

> The SaaS tables (`restaurants`, `restaurant_requests`, `subscriptions`, `subscription_plans`, `notifications`) are **not** auto-created by `config.php` — apply migration `004` (or the seed SQL) explicitly.

Core domains: identity (`admin_users`, `user_sessions`), menu (`categories`, `menu_items`, `menu_addons`), floor (`tables`, `dining_sessions`), orders (`orders`, `order_items`, `waiter_calls`), payments (`payment_gateways`, `payment_settings`, `payment_transactions`), content (`landing_page_settings`), audit (`audit_logs`), inventory (~13 tables), assets (~7 tables), SaaS (5 tables).

---

## Payments

**Offline-first.** No real online transactions, no webhooks/IPN, no external payment APIs.

1. Customer orders → `payment_status = 'pending'`.
2. Order must be `completed` (served) before settlement unlocks on `order-success.php`.
3. Settlement: **cash at table**, or **scan-and-pay** the merchant's eSewa/Khalti QR image.
4. Staff mark the order paid; methods: `cash, card, esewa, khalti, fonepay, connectips, imepay`.

`admin/payment-settings.php` stores gateway configuration records only (seeded with sandbox credentials). The "Test API" button is a stub. See `SUBSCRIPTION_MODEL.md` for the plan/billing model.

---

## API (`api/`)

All endpoints return JSON. Success: `{ "success": true, ... }`. Errors follow `{ "success": false, "message": "…" }`.

| Endpoint | Purpose |
| --- | --- |
| `health.php` | Health check |
| `get-order-status.php` | Live order status (session/table guarded) |
| `orders.php`, `update-order.php`, `orders-stream.php` | Order list/create/update + realtime feed |
| `kitchen-stream.php` | Kitchen ticket stream |
| `call-waiter.php` | Waiter call + serve/dismiss |
| `menu.php`, `menu-status.php`, `toggle-stock.php`, `menu-stream.php` | Menu payload + availability toggles |
| `tables-stream.php`, `categories-stream.php`, `payment-stream.php`, `security-stream.php`, `landing-stream.php`, `inventory-stream.php`, `asset-stream.php` | Per-module realtime streams |
| `dashboard-stream.php` | Operations Center KPIs + live orders + search |
| `inventory.php`, `assets.php` | Inventory / asset CRUD |

Realtime = AJAX polling with exponential backoff (KDS 2s, dashboard 3s, order tracker 3.5s). Streams call `session_write_close()` before long work.

---

## Security

| Control | Where |
| --- | --- |
| Bcrypt password hashing | `Auth` + `admin/login-process.php` + `super-admin/login.php` |
| CSRF tokens on all state-changing requests | `helpers/CSRF.php` |
| Session hardening (HttpOnly, SameSite=Lax, Secure on HTTPS, regenerate on login, 7200s idle) | `Auth::startSession` |
| Rate limiting (admin login 5/5 min, place-order 10/min, super-admin login 5/5 min) | `helpers/RateLimiter.php` |
| Security headers (CSP, X-Frame-Options, nosniff, Referrer-Policy, Permissions-Policy, HSTS) | `helpers/Security.php` + `.htaccess` |
| Per-table QR auth (32-hex token) + legacy HMAC-SHA256 signed URLs | `config.php` table-token helpers |
| Session table pinning / IDOR guards on order lookups | `api/get-order-status.php`, `order-success.php` |
| Server-side pricing + idempotency keys | `place-order.php` |
| Row-locked transactions for order transitions | `helpers/OrderService.php` |
| Tenant isolation on every admin query | `helpers/TenantContext.php` |
| Audit trail | `audit_logs` + `Security::logAudit` |
| Upload validation (MIME allowlist, ≤2MB, random names, SVG rejected) | inventory/asset uploads |
| Protected files (`.env`, `config.php`, `database.sql` blocked; directory listing off) | `.htaccess` |

See `SECURITY.md`, `SECURITY_AUDIT.md`, and `API_SECURITY_MATRIX.md`.

---

## Installation

### Requirements
PHP 8.1+ (`mysqli`, `mbstring`), MySQL 8.0+ / MariaDB, Apache 2.4 (XAMPP works out of the box).

### 1. Place the code
Copy the project into your web root (e.g. `htdocs/Rms_SaaS`).

### 2. Configure the environment
```bash
cp .env.example .env
```
Edit `.env` — database credentials and secrets (see below). **Change `JWT_SECRET` in production.**

### 3. Set up the database
Create an empty database (e.g. `qr_restaurant`).

**Option A — automatic:** point `.env` at the empty database. The core schema is created on first request. Then apply the SaaS layer:
```bash
mysql -u root -p qr_restaurant < database/migrations/004_saas_multi_tenancy.sql
```

**Option B — full manual import:**
```bash
mysql -u root -p qr_restaurant < database.sql
mysql -u root -p qr_restaurant < database_inventory_assets.sql
mysql -u root -p qr_restaurant < database/migrations/004_saas_multi_tenancy.sql
```

### 4. Serve
- Public site: `http://localhost/Rms_SaaS/`
- Admin login: `http://localhost/Rms_SaaS/admin/login.php`
- Super Admin: `http://localhost/Rms_SaaS/super-admin/login.php`
- KDS: `http://localhost/Rms_SaaS/kitchen-dashboard.php`

### 5. Print table QR codes
Admin console → **Floor & Tables** → generate/print QR codes and place them on tables.

---

## First-Run Admin Setup & Credentials

In accordance with security best practices, static default passwords are not embedded in production source code.

- **Restaurant Admin**: Initial bootstrap password is derived from `APP_ADMIN_PASSWORD` in `.env` (or cryptographically generated on first boot via `bin2hex(random_bytes(8))`). Change password immediately after first login in **Settings → Change Password**.
- **Super Admin**: Provisioned securely via `super-admin/create-restaurant.php` or `database/migrate.php` with explicit password hashing.
- **Kitchen (KDS)**: Configurable in **Settings → KDS Password**. Change default prior to kitchen deployment.

---

## Environment Variables (`.env`)

| Variable | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `QR Cafe Restaurant Management System` | Display name |
| `APP_ENV` | `production` | App environment |
| `APP_DEBUG` | `false` | Error verbosity (use `true` in dev) |
| `APP_URL` | `http://localhost/RMS_System` | Base URL |
| `DB_HOST` | `localhost` | Database host |
| `DB_PORT` | `3306` | Database port |
| `DB_DATABASE` | `qr_restaurant` | Database name |
| `DB_USERNAME` | `root` | Database user |
| `DB_PASSWORD` | *(empty)* | Database password |
| `JWT_SECRET` | `RMS_SECURE_HMAC_SECRET_KEY_2026_CHANGE_IF_NEEDED` | HMAC key for QR tokens / signed URLs — **change it** |
| `SESSION_LIFETIME` | `7200` | Session idle timeout (seconds) |
| `LOG_CHANNEL` | `daily` | Log rotation channel |

`.env` is gitignored; never commit real credentials.

---

## Project Structure

```text
Rms_SaaS/
├── .htaccess                  # Routing, security headers, caching, protected files
├── .env.example               # Environment template (copy to .env)
├── config.php                 # Boot: env loader, DB, schema auto-create, auth, helpers
├── index.php                  # Landing page + onboarding request form
├── menu.php                   # QR digital menu
├── cart.php / checkout.php / place-order.php / order-success.php
├── kitchen-dashboard.php / kitchen-menu.php     # KDS
├── privacy-policy.php / terms-of-service.php / landing-preview.php
├── admin/                     # Restaurant console (32 pages + includes)
├── super-admin/               # SaaS governance platform
├── api/                       # JSON endpoints + realtime streams
├── app/                       # Autoloader, DatabaseService, LoggerService
├── helpers/                   # Auth, CSRF, Security, RateLimiter, Response,
│                              # TenantContext, SubscriptionService, Inventory, OrderService
├── database/                  # SQL imports + migrations (001–004)
├── css/ js/ images/           # Assets
├── resources/views/           # 404 / 500 templates
├── storage/                   # Logs & backups (gitignored)
├── tests/                     # saas_tenant_isolation_test.php (manual)
└── *.md                       # Architecture & QA docs (see below)
```

---

## Documentation

| File | Content |
| --- | --- |
| `SAAS_ARCHITECTURE.md` | Multi-tenant architecture overview |
| `SAAS_ONBOARDING.md` | Onboarding & provisioning workflow |
| `SUBSCRIPTION_MODEL.md` | Plan/subscription model |
| `TENANT_ISOLATION.md` | Tenant data isolation design |
| `DATABASE_MIGRATION.md` | Schema migration guide |
| `FINAL_PRODUCTION_CHECKLIST.md` | Production readiness checklist |
| `API_SECURITY_MATRIX.md` | Per-endpoint security review |
| `SECURITY.md` / `SECURITY_AUDIT.md` | Security policy and audit |
| `QA_REPORT.md` | QA results |
| `FIXED_ISSUES.md` | Changelog of fixes (`RMS-###`) |

---

## Development

No build step — edit PHP/JS/CSS and refresh.

```bash
php -l <file>            # PHP syntax check
node --check js/script.js   # JS syntax check (if Node available)
```

There is **no automated test runner / CI** in the repository. `tests/saas_tenant_isolation_test.php` is a manual CLI/HTTP isolation check.

### Conventions
- Prepared statements everywhere; **always tenant-scope queries** by `getTenantId()`.
- CSRF on every POST; never log plaintext passwords.
- Fixes logged in `FIXED_ISSUES.md` as `RMS-###`.

---

## Known Limitations

1. **No real online payments** — gateways are configuration records; no webhooks/IPN.
2. **No `LICENSE` file** — see [License](#license).
3. **PWA without a service worker** — installable, no offline caching.
4. **Tailwind via CDN** — needs internet at runtime for styling.
5. **Not 100% prepared statements** — some queries use `real_escape_string` interpolation (inputs escaped).
6. **No automated test suite / CI.**
7. **KDS default password** (`kitchen123`) — must be changed.
8. **Realtime is polling-based** — fine for small restaurants; high concurrency would warrant SSE/WebSockets.
9. **SaaS schema requires manual migration `004`** — not auto-created by `config.php`.

---

## Deployment

Designed for classic PHP hosting / cPanel / XAMPP — no containers.

1. Upload the project to a PHP 8.1+ host.
2. Ensure Apache `mod_rewrite` + `mod_headers`; `.htaccess` is included.
3. Create the MySQL DB + user; set credentials in `.env`.
4. Import schema + migration `004`.
5. Set `APP_ENV=production`, `APP_DEBUG=false`, a strong `JWT_SECRET`.
6. Serve over HTTPS for `Secure` cookies / HSTS to take effect.

Backups: `storage/backups` is gitignored, but there is **no built-in backup tooling** — use the host's scheduled MySQL backup.

---

## License

**Not specified.** The repository contains no `LICENSE` file, so no license terms are granted by the repository itself. Until a license is added, assume "all rights reserved."

---

## Support

- Open an issue for bugs and feature requests.
- Report security findings privately — do not open a public issue.
