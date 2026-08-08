# Fixed Issues Log - RMS System Remediation

Summary of all remediated security vulnerabilities, authorization bugs, race conditions, and business logic flaws across the project.

| Issue ID | Severity | Description | Root Cause | Remediation Applied | Status |
|---|---|---|---|---|---|
| RMS-001 | CRITICAL | Client-Controlled Add-On Prices | Client POST parameters trusted for customization prices. | Looked up add-on prices server-side from DB in `place-order.php`. | FIXED |
| RMS-002 | CRITICAL | Rate Limiter Counter Not Incrementing | `RateLimiter::enforce()` did not invoke `hit()`. | Automatically incremented counter inside `enforce()` in `RateLimiter.php`. | FIXED |
| RMS-003 | CRITICAL | Rate Limiter Bypass | Counter relied only on session cookies. | Integrated IP + key hashing and session persistence in `RateLimiter.php`. | FIXED |
| RMS-004 | CRITICAL | Order/Session Takeover | `order-success.php` fetched orders without checking session table ownership. | Bound order access to active customer session table in `order-success.php`. | FIXED |
| RMS-005 | CRITICAL | Unauthenticated Default to Table 1 | `$_SESSION['customer_table_id'] ?? '1'` assigned real table. | Removed default fallback; required valid session or returned HTTP 403. | FIXED |
| RMS-006 | HIGH | Waiter Call Table Spoofing | POST parameters trusted for table number. | Bound waiter calls strictly to validated session table number in `api/call-waiter.php`. | FIXED |
| RMS-007 | CRITICAL | Unauthorized Waiter Call Resolution | Resolution endpoints lacked staff authentication. | Enforced staff login, POST method, and CSRF verification in `api/call-waiter.php`. | FIXED |
| RMS-008 | HIGH | KDS Order Update Bypass | Endpoints updated `orders` status directly bypassing business logic. | Centralized order status changes through `OrderService::transitionStatus()`. | FIXED |
| RMS-009 | HIGH | Invalid Order Status Transitions | Status string was updated without state machine rules. | Enforced strict order state machine transitions in `helpers/OrderService.php`. | FIXED |
| RMS-010 | HIGH | Double Inventory Deduction | Concurrent completion calls executed stock deductions twice. | Protected order completion status update with atomic DB transaction locks. | FIXED |
| RMS-011 | HIGH | Transaction Misordering | Inventory committed before order status updated. | Wrapped inventory deductions and order updates inside a single DB transaction. | FIXED |
| RMS-012 | HIGH | Kitchen Modifying Payment Method | Kitchen role lacked payment RBAC boundaries. | Restricted payment method updates to Cashier/Manager/Admin roles in `api/update-order.php`. | FIXED |
| RMS-013 | HIGH | Payment Settings Authorization | Endpoint lacked admin auth & CSRF verification. | Enforced Admin login and CSRF token validation in `admin/payment-settings.php`. | FIXED |
| RMS-014 | HIGH | Payment QR File Upload Insecurity | Weak MIME check, unrandomized filenames, SVG allowed. | Removed SVG extension, enforced 2MB limit, randomized filenames in `helpers/Security.php`. | FIXED |
| RMS-015 | HIGH | Kitchen API Data Leakage | Kitchen stream returned cost price & profit fields. | Stripped sensitive financial data from KDS stream payloads in `api/kitchen-stream.php`. | FIXED |
| RMS-016 | MEDIUM | N+1 Query in Order Success | Queries iterated inside loop per item. | Optimized pre-loading and batch queries. | FIXED |
| RMS-017 | MEDIUM | Order Status IDOR Enumeration | `api/get-order-status.php` returned order by ID without session check. | Bound status lookup to active table session in `api/get-order-status.php`. | FIXED |
| RMS-018 | MEDIUM | Public Orders Endpoint IDOR | Public order APIs exposed details by ID. | Enforced active table session ownership check. | FIXED |
| RMS-019 | CRITICAL | Missing `.gitignore` Rules | Sensitive files risked commit. | Created `.gitignore` excluding `.env`, logs, backups, and upload folders. | FIXED |
| RMS-020 | HIGH | Static Admin Default Password | Static `admin123` initialized on first run. | Generated cryptographically secure bootstrap password in `config.php`. | FIXED |
| RMS-021 | CRITICAL | Duplicate Order Replay | No idempotency check on POST orders. | Implemented `Idempotency-Key` header & DB unique check in `place-order.php`. | FIXED |
| RMS-022 | CRITICAL | Concurrent Dining Session Creation | Race condition when creating dining session. | Applied `FOR UPDATE` row locking during dining session creation in `place-order.php`. | FIXED |
| RMS-023 | HIGH | Batch Number Race Condition | Batch calculated using non-atomic `COUNT(*)+1`. | Calculated batch numbers inside locked DB transaction in `place-order.php`. | FIXED |
| RMS-024 | HIGH | Unlimited Order Quantity | Quantity only checked for $>0$. | Enforced `MAX_ITEM_QUANTITY` (50 pcs) limit in `place-order.php`. | FIXED |
| RMS-025 | CRITICAL | Stock Quantity Not Enforced | Items orderable when stock zero. | Enforced stock quantity check and locked stock row in `place-order.php`. | FIXED |
| RMS-026 | HIGH | Inventory TOCTOU Race | Availability checked outside transaction lock. | Applied `SELECT ... FOR UPDATE` row locks during inventory check in `place-order.php`. | FIXED |
| RMS-027 | CRITICAL | Double Ingredient Deduction | Inventory deducted multiple times on retry. | Checked `inventory_transactions` order ID & transaction type before deducting. | FIXED |
| RMS-028 | HIGH | Invalid Order Lifecycle | Illegal status transitions permitted. | Enforced valid lifecycle (`new` -> `preparing` -> `ready` -> `completed`). | FIXED |
| RMS-029 | HIGH | Cancellation Instead of Refund | Completed order cancelled without refund flow. | Implemented explicit refund lifecycle (`COMPLETED` -> `REFUND_REQUESTED` -> `REFUNDED`). | FIXED |
| RMS-030 | HIGH | Payment Method Whitelist Missing | Unsanitized payment method strings accepted. | Enforced payment method whitelist validation in `api/update-order.php`. | FIXED |
| RMS-031 | HIGH | Payment Change Side Effects | Changing payment created duplicate waiter calls. | Handled payment changes as explicit state transitions. | FIXED |
| RMS-032 | HIGH | Payment Idempotency Missing | Duplicate callbacks processed twice. | Unique constraints on `gateway_transaction_id` and idempotency keys. | FIXED |
| RMS-033 | HIGH | Unlimited Batch Creation | Unrestricted customer ordering. | Rate-limited order placement per session. | FIXED |
| RMS-034 | HIGH | Weak Session & Payment Coupling | Table freed while orders unpaid. | Enforced session settlement rules prior to vacating tables. | FIXED |
| RMS-035 | HIGH | Table Occupancy Inconsistency | Table status desynced from active session. | Maintained strict table vacant/occupied invariant server-side. | FIXED |
| RMS-036 | MEDIUM | Floating-Point Calculations | Financial values processed via float. | Standardized money values using `DECIMAL(10,2)` in DB queries. | FIXED |
| RMS-037 | MEDIUM | No Maximum Order Value | Unlimited cart totals allowed. | Enforced `MAX_ORDER_VALUE` (Rs. 50,000) server-side limit in `place-order.php`. | FIXED |
| RMS-038 | HIGH | Non-Atomic Inventory Deduction | Stock checked and updated in separate queries. | Executed stock check and deduction inside single DB transaction with row locks. | FIXED |
