# API Security & Tenant Isolation Matrix

## API Governance Policy
Every HTTP API and Server-Sent Event (SSE) endpoint must enforce strict authentication, tenant scoping, role-based authorization, rate limiting, and CSRF protection.

---

## Endpoint Security Specifications

| Endpoint | Method | Auth Guard | Tenant Isolation Enforcement | CSRF | Rate Limit |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `/index.php` (Request Form) | POST | Public | Inserts into `restaurant_requests` with `PENDING` | Required | 3 / hr |
| `/super-admin/login.php` | POST | Public | Authenticates Super Admin (`is_super_admin = 1`) | Required | 5 / 5 min |
| `/super-admin/create-restaurant.php` | POST | Super Admin | Creates tenant + owner user + subscription | Required | 10 / min |
| `/super-admin/restaurants.php` | POST | Super Admin | Tenant suspend/activate/reset credentials | Required | 20 / min |
| `/admin/login-process.php` | POST | Staff | Binds session `restaurant_id` & checks temp pass | Required | 5 / 5 min |
| `/admin/setup-wizard.php` | POST | Restaurant Owner | Scoped setup edits (`restaurant_id = TenantContext::getTenantId()`) | Required | 30 / min |
| `/place-order.php` | POST | Customer QR | Session table token + `restaurant_id = TenantContext::getTenantId()` | Required | 10 / min |
| `/api/orders-stream.php` | GET | KDS / Staff | Filters SSE orders stream by `restaurant_id` | Session | N/A (Stream) |
| `/api/inventory.php` | POST | Staff | `TenantContext::assertOwnership()` for item edits | Required | 30 / min |
| `/api/assets.php` | POST | Staff | `TenantContext::assertOwnership()` for asset edits | Required | 30 / min |
