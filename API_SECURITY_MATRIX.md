# API Security Matrix

Complete inventory of application API endpoints detailing access controls, authentication requirements, rate limiting, and transaction boundaries.

| Endpoint Path | HTTP Method | Authentication | Required Role / Permission | CSRF Verification | Rate Limit | Idempotency Key | Transaction Isolated | Audit Logged |
|---|---|---|---|---|---|---|---|---|
| `place-order.php` | POST | Customer Session | Table Session | Yes | 10 / min | Yes (`HTTP_IDEMPOTENCY_KEY`) | Yes | Yes |
| `order-success.php` | GET | Customer Session | Table Session Match | No (Read) | Standard | No | No | No |
| `api/get-order-status.php` | GET | Session / Staff | Table Session or Staff | No (Read) | Standard | No | No | No |
| `api/call-waiter.php` | POST | Customer Session | Table Session | Yes (Resolve) | 3 / 2 min | No | No | Yes |
| `api/call-waiter.php` | POST (Resolve) | Staff Session | Kitchen / Admin | Yes | 30 / min | No | Yes | Yes |
| `api/update-order.php` | POST | Staff Session | Kitchen / Admin / Cashier | Yes | 60 / min | No | Yes | Yes |
| `api/orders-stream.php` | GET | Staff Session | Kitchen / Admin / Cashier | No (Read) | Polling | No | No | No |
| `api/kitchen-stream.php` | GET | Staff Session | Kitchen / Admin | No (Read) | Polling | No | No | No |
| `api/inventory.php` | GET/POST | Staff Session | Inventory Manager / Admin | Yes (Write) | 30 / min | No | Yes | Yes |
| `api/assets.php` | GET/POST | Staff Session | Manager / Admin | Yes (Write) | 30 / min | No | Yes | Yes |
| `api/payment-settings.php` | POST | Staff Session | Admin Only | Yes | 10 / min | No | Yes | Yes |
| `api/health.php` | GET | Public | None | No (Read) | Standard | No | No | No |
