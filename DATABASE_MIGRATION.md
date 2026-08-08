# Database Migration & Schema Documentation

## Database Overview
The QR Cafe POS system uses MySQL 8.0+ (`utf8mb4_unicode_ci` / `InnoDB`). All tables utilize primary keys, indexed foreign key relationships, transactional row locking support, and unique constraints for data integrity.

---

## Migration History & Package Scripts (`database/migrations/`)

### 1. `001_initial_schema.sql` (Core POS & Session Tables)
- **`admin_users`**: User authentication, role assignment (`admin`, `manager`, `cashier`, `kitchen`).
- **`categories`**: Menu category hierarchy.
- **`menu_items`**: Menu catalog with pricing, preparation times, allergens, and stock tracking.
- **`tables`**: Floor layout, table capacity, QR tokens (`qr_token`), and occupancy status.
- **`dining_sessions`**: Active table sessions (`session_token`), running totals, status.
- **`orders`**: POS order batches, idempotency keys (`idempotency_key`), table number, total amount, status.
- **`order_items`**: Line items per order batch.

### 2. `002_inventory_assets.sql` (Enterprise Inventory & Asset Management)
- **`inventory_categories`**: Stock category tracking (Vegetables, Meat, Beverages, etc.).
- **`inventory_units`**: Unit conversions (kg, L, pcs, box).
- **`suppliers`**: Vendor registry, PAN/VAT info, payment terms.
- **`inventory_items`**: Ingredient master list, current stock, min/max thresholds, purchase/average cost.
- **`recipes`**: Bill of Materials (BOM) linking menu items to raw ingredients with waste percentage.
- **`inventory_transactions`**: Immutable stock movement audit log (`purchase`, `sale`, `waste`, `adjustment`, `restock`).
- **`assets`**: Asset register, tag tracking, serial numbers, locations, depreciation calculations.

### 3. `003_security_idempotency.sql` (FinTech Gateways, Idempotency & Audit Logs)
- **`payment_gateways`**: Gateway credentials (eSewa, Khalti, Fonepay, ConnectIPS, IME Pay).
- **`payment_transactions`**: Payment verification log, `gateway_transaction_id` unique constraint, `idempotency_key`.
- **`audit_logs`**: Immutable security event log (logins, role changes, payment configuration updates).
- **`waiter_calls`**: Table assistance requests and resolution tracking.

---

## Required Unique Constraints & Indexes

```sql
-- Idempotency Constraints
ALTER TABLE orders ADD UNIQUE INDEX idx_orders_idempotency (idempotency_key);
ALTER TABLE payment_transactions ADD UNIQUE INDEX idx_pay_tx_id (transaction_id);
ALTER TABLE payment_transactions ADD UNIQUE INDEX idx_pay_idem (idempotency_key);
ALTER TABLE tables ADD UNIQUE INDEX idx_tables_qr_token (qr_token);

-- Performance Indexes
ALTER TABLE orders ADD INDEX idx_orders_created (created_at);
ALTER TABLE orders ADD INDEX idx_orders_status_pay (status, payment_status);
ALTER TABLE orders ADD INDEX idx_orders_table_status (table_number, status);
ALTER TABLE inventory_transactions ADD INDEX idx_trans_order_item (order_id, inventory_item_id, transaction_type);
```
