# RMS SaaS — REST API Specification

This specification documents the primary REST API endpoints available for floor operations, billing, KDS kitchen updates, inventory management, shift reconciliation, and HR workflows.

## Authentication & Security Headers

All state-changing POST requests require:
- **Session Authentication**: Active PHP session cookie.
- **CSRF Token**: `csrf_token` POST field or `X-CSRF-Token` header.
- **Content Type**: `application/x-www-form-urlencoded` or `application/json`.

---

## Endpoint Index

### 1. Table Payment & Billing Operations (`api/table-payment.php`)

| Action | Method | Description | Permission Required |
| :--- | :--- | :--- | :--- |
| `search_customer` | `POST/GET` | Search customer CRM profile by phone | Staff Auth |
| `create_customer` | `POST` | Register new customer profile from POS | Staff Auth |
| `calculate_bill` | `POST` | Server-side authoritative bill calculation | Staff Auth |
| `process_payment` | `POST` | Settle table bill & update register shift | `payments.settle` |
| `split_bill` | `POST` | Settle partial split bill amount | `payments.settle` |
| `transfer_table` | `POST` | Reassign active order(s) from Source to Target Table | Staff Auth |
| `merge_bills` | `POST` | Combine active order items into target order | Staff Auth |
| `apply_ncr` | `POST` | Apply no-charge / complimentary waiver | `payments.ncr` |
| `refund` | `POST` | Process order refund & reverse loyalty points | `payments.refund` |

---

### 2. Real-Time Table Stream (`api/tables-stream.php`)

- **Method**: `GET`
- **Description**: Returns real-time JSON floor layout status for all tables, active orders, and aggregated running bills.

---

### 3. Customer QR Checkout Session (`api/customer-session.php`)

- **Method**: `POST`
- **Actions**: `validate_session`, `create_order`, `get_bill`
- **Description**: Handles self-service customer QR ordering, table token validation, and KDS queue dispatch.

---

### 4. Inventory & Stock Operations (`api/inventory.php`)

- **Method**: `POST`
- **Actions**: `list_items`, `save_item`, `save_recipe`, `adjust_stock`, `log_waste`, `create_po`
- **Description**: Manages raw ingredient stock, recipe bill of materials (BOM), stock audits, and purchase orders.
