# SaaS Multi-Tenant Database Migration Strategy

## Migration Approach
The database migration strategy converts the single-tenant RMS database schema into a multi-tenant schema non-destructively. Existing single-restaurant data is automatically migrated into Tenant 1 ("Default Restaurant") without data loss.

---

## 1. Migration File Sequence
Migrations are stored in `database/migrations/`:

1. `001_initial_schema.sql` - Core POS Tables (`admin_users`, `categories`, `menu_items`, `tables`, `dining_sessions`, `orders`, `order_items`)
2. `002_inventory_assets.sql` - Inventory & Asset Tables (`inventory_categories`, `suppliers`, `inventory_items`, `recipes`, `inventory_transactions`, `assets`)
3. `003_security_idempotency.sql` - Payment Gateways, Payment Transactions, Audit Logs, Waiter Calls
4. `004_saas_multi_tenancy.sql` - SaaS Core Tables (`restaurants`, `subscription_plans`, `subscriptions`, `restaurant_requests`, `notifications`) and `restaurant_id` column additions.

---

## 2. Multi-Tenant Table Architecture

### New Platform Tables
- **`restaurants`**: Central tenant account registry (id, uuid, restaurant_code, name, owner_name, email, phone, status, subscription_plan_id, subscription_status, subscription_end).
- **`subscription_plans`**: Master plan definition tiers (Starter, Business, Pro, Enterprise).
- **`subscriptions`**: Active tenant subscription contracts.
- **`restaurant_requests`**: Onboarding request queue generated from landing website.
- **`notifications`**: Super Admin and tenant notifications.

### Tenant-Owned Entity Tables
All entity tables contain `restaurant_id INT NOT NULL DEFAULT 1` and associated index `idx_tenant_rest`:
- `admin_users`
- `categories`
- `menu_items`
- `tables`
- `dining_sessions`
- `orders`
- `order_items`
- `inventory_categories`
- `inventory_units`
- `suppliers`
- `inventory_items`
- `recipes`
- `inventory_transactions`
- `assets`
- `payment_gateways`
- `payment_transactions`
- `audit_logs`
- `waiter_calls`
- `landing_page_settings`

---

## 3. Backward Compatibility Backfill
During bootstrap, `applySaaSMultiTenancyMigration($conn)` executes:
```sql
UPDATE `{table}` SET restaurant_id = 1 WHERE restaurant_id IS NULL OR restaurant_id = 0;
```
This guarantees that pre-existing single-restaurant data remains immediately operational under Tenant 1 (`RMS-000001`).
