# RMS SaaS — Database Architecture & Schema Specification

This document details the database architecture, table schemas, monetary persistence rules, and migration execution for RMS SaaS.

## 1. Financial & Monetary Precision

All monetary fields (`total_amount`, `subtotal`, `vat`, `service_charge`, `discount`, `price`, `amount`, `base_salary`, `net_salary`, `opening_cash`, `closing_cash`, `variance`) use `DECIMAL(10, 2)` or `DECIMAL(12, 2)` types. Floating-point types (`FLOAT`, `DOUBLE`) are strictly avoided for financial data to prevent rounding drift.

---

## 2. Table Indexing & Tenant Foreign Keys

Core tables are indexed on `(restaurant_id, ...)` to ensure multi-tenant query isolation and fast lookups:

- **`restaurants`**: `PRIMARY KEY (id)`, `UNIQUE KEY idx_uuid (uuid)`
- **`tables`**: `UNIQUE KEY idx_tenant_table (restaurant_id, table_number)`
- **`orders`**: `INDEX idx_order_tenant (restaurant_id, status)`, `INDEX idx_order_table (restaurant_id, table_number)`
- **`customers`**: `UNIQUE KEY idx_tenant_phone (restaurant_id, phone)`
- **`shifts`**: `INDEX idx_shift_status (restaurant_id, status)`
- **`inventory_items`**: `INDEX idx_item_tenant (restaurant_id, status)`
- **`employees`**: `UNIQUE KEY idx_emp_code (restaurant_id, employee_code)`
- **`reservations`**: `INDEX idx_res_date (restaurant_id, reservation_date, status)`

---

## 3. Migration Execution

Database schema updates are managed idempotently through the CLI migration runner:

```bash
php database/migrate.php
```

> [!NOTE]
> Web-accessible execution of `database/migrate.php` is blocked by an explicit `PHP_SAPI !== 'cli'` security check.

---

## 4. Tenant Purge & Zero-Orphan Cleanup

`TenantDeletionService` purges all tenant-owned records across 35+ database tables in dependency order:

1. Cash movements, register shifts, registers
2. Financial ledger transactions, payments, expenses
3. Loyalty balances & audit trails
4. Payroll items, periods, salary history, attendance, employees, shift templates
5. Order items, orders, menu items, categories, recipes
6. Inventory movements, stock audits, waste, purchase orders, suppliers
7. Asset depreciation, maintenance, warranties, assets
8. Table reservations, dining sessions, notifications, audit logs
9. Parent `restaurants` record
