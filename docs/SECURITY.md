# RMS SaaS — Production Security Architecture

This document describes the multi-tenant security model, role-based access control (RBAC), CSRF protection, and data isolation enforced throughout the RMS SaaS application.

## 1. Multi-Tenant Context & Isolation

- **Tenant Isolation**: Every database query on tenant-owned data (`orders`, `tables`, `customers`, `employees`, `inventory`, `shifts`, `expenses`) is scoped to `restaurant_id`.
- **Session Context**: The active tenant ID is resolved exclusively server-side via `TenantContext::getTenantId()`. Client-supplied `restaurant_id` parameters in `$_GET` or `$_POST` are ignored for authorization.
- **Fail-Closed Design**: Unauthenticated or contextless requests resolve `restaurant_id` to `0`, preventing accidental cross-tenant data leaks.

---

## 2. Role-Based Access Control (RBAC)

Authorization is enforced server-side through `AuthorizationService` and `PermissionService`:

| Canonical Role | Target Audience | Key Permissions |
| :--- | :--- | :--- |
| **`SUPER_ADMIN`** | SaaS Platform Owners | Global tenant management, subscription provisioning, tenant deletion purge. |
| **`OWNER`** | Restaurant Owners / Franchisees | Full tenant control, financial reporting, HR salary management, settings. |
| **`MANAGER`** | Store Managers | Operations, staff scheduling, inventory, menu items, table management. |
| **`CASHIER`** | POS Cashiers | Order billing, payment settlement (`payments.settle`), register shift opening/closing. |
| **`WAITER`** | Floor Staff | Table seating, order placement, table status transitions (`payments.settle` denied). |
| **`KITCHEN`** | Kitchen / KDS | Order status transitions (`preparing` $\rightarrow$ `ready` $\rightarrow$ `completed`), recipe deductions (`payments.refund` denied). |
| **`INVENTORY_MANAGER`** | Stock Keepers | Inventory stock adjustments, purchase orders, recipe management. |
| **`HR_MANAGER`** | Human Resources | Employee records, shift templates, attendance, payroll calculation. |
| **`ACCOUNTANT`** | Finance / Audit | Read-only ledger access, P&L reports, expense management (`hr.manage_salary` restricted). |

---

## 3. Session & Cryptographic Protection

- **Password Hashing**: Passwords stored using `password_hash()` with `PASSWORD_BCRYPT`.
- **CSRF Tokens**: Verified on state-changing `POST` requests via `CSRF::requireValidToken()`. Accepts `csrf_token` POST field or `X-CSRF-Token` header.
- **Signed QR Tokens**: Machine-scannable 2D SVG QR codes use HMAC-SHA256 signatures (`QR_SECRET_KEY`) to prevent table QR token forgery.
- **Register Row-Locking**: Active cashier shifts enforce row-level locks (`SELECT FOR UPDATE`) to prevent race conditions during register opening and closing.

---

## 4. Input Sanitization & Error Handling

- **Sanitization**: All user inputs sanitized via `Security::sanitize()`.
- **Error Display**: In production (`APP_ENV=production`), `display_errors` is disabled. Generic error messages are presented to users, while detailed traces are logged server-side to `storage/logs/php_errors.log`.
- **Upload Hardening**: User upload directories restrict file extensions, generate random non-deterministic filenames, and enforce `.htaccess` execution blocks.
