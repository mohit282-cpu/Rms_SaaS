# RMS_System — Complete QA, Security & Business-Logic Bug Report

**Repository:** `https://github.com/Wearing-wind/RMS_System`  
**Project:** Restaurant Management System (RMS)  
**Assessment type:** Static QA, Security Audit, Business-Logic Review  
**Assessment scope:** All findings identified across the previous review rounds  
**Status:** Consolidated remediation report  
**Important:** This report is based on static source review and repository analysis. It is not a live penetration test against a deployed production environment.

---

# 1. Executive Summary

The RMS_System contains several useful security mechanisms and a reasonably complete restaurant workflow, including authentication, CSRF protection, prepared SQL statements, inventory services, customer ordering, KDS workflows, dining sessions, table management, and payment configuration.

However, the application currently has significant weaknesses in **authorization consistency, business-logic enforcement, concurrency control, payment integrity, inventory integrity, file upload security, and deployment hygiene**.

The most important conclusion is:

> Security controls exist, but they are not consistently enforced across all endpoints and business workflows.

The highest-risk problems are not limited to classic SQL injection. The most serious remaining risks are **order manipulation, IDOR/session takeover, duplicate operations, race conditions, inventory double-deduction, payment/business-state manipulation, and unauthorized state transitions**.

---

# 2. Severity Classification

| Severity | Meaning |
|---|---|
| CRITICAL | Can directly cause financial loss, unauthorized control, major data compromise, or serious business-integrity failure |
| HIGH | Significant security, authorization, inventory, payment, or operational risk |
| MEDIUM | Important weakness that can cause incorrect behavior, information disclosure, or reliability problems |
| LOW | Minor issue, hardening opportunity, or maintainability concern |

---

# 3. Consolidated Finding Summary

| ID | Severity | Finding | Category | Status |
|---|---|---|---|---|
| RMS-001 | CRITICAL | Client-controlled add-on prices | Price Tampering | RESOLVED |
| RMS-002 | CRITICAL | Rate limiter does not increment automatically | Rate Limiting | RESOLVED |
| RMS-003 | CRITICAL | Session-based rate limiting is bypassable | Abuse Prevention | RESOLVED |
| RMS-004 | CRITICAL | `order-success.php` order/session takeover | IDOR / Session Security | RESOLVED |
| RMS-005 | CRITICAL | Unauthenticated default to Table 1 | Authorization | RESOLVED |
| RMS-006 | HIGH | Customer can spoof waiter-call table | Authorization | RESOLVED |
| RMS-007 | CRITICAL | Waiter calls can be resolved without proper authorization | RBAC | RESOLVED |
| RMS-008 | HIGH | KDS order updates bypass central order/inventory logic | Architecture | RESOLVED |
| RMS-009 | HIGH | Invalid order status transitions | Business Logic | RESOLVED |
| RMS-010 | HIGH | Concurrent completion can double-deduct inventory | Race Condition | RESOLVED |
| RMS-011 | HIGH | Inventory commits before order update | Transaction Integrity | RESOLVED |
| RMS-012 | HIGH | Kitchen can modify payment method | RBAC | RESOLVED |
| RMS-013 | HIGH | Payment settings endpoint lacks authorization/CSRF | Authorization | RESOLVED |
| RMS-014 | HIGH | Payment QR upload is insufficiently validated | File Upload | RESOLVED |
| RMS-015 | HIGH | Kitchen API exposes cost/profit information | Information Disclosure | RESOLVED |
| RMS-016 | MEDIUM | N+1 queries in order-success flow | Performance | RESOLVED |
| RMS-017 | MEDIUM | Public order-status API exposes order information by ID | IDOR / Privacy | RESOLVED |
| RMS-018 | MEDIUM | Public orders endpoint exposes data by ID | IDOR / Privacy | RESOLVED |
| RMS-019 | CRITICAL | `.gitignore` missing / sensitive files risk | Secrets / Deployment | RESOLVED |
| RMS-020 | HIGH | Automatic `admin/admin123` default account | Authentication | RESOLVED |
| RMS-021 | CRITICAL | Duplicate order replay / no idempotency | Business Logic | RESOLVED |
| RMS-022 | CRITICAL | Concurrent dining-session creation | Race Condition | RESOLVED |
| RMS-023 | HIGH | Batch-number race condition | Race Condition | RESOLVED |
| RMS-024 | HIGH | Unlimited order quantity | Input / Business Logic | RESOLVED |
| RMS-025 | CRITICAL | Stock quantity not enforced during ordering | Inventory | RESOLVED |
| RMS-026 | HIGH | Availability check has TOCTOU race | Inventory | RESOLVED |
| RMS-027 | CRITICAL | Concurrent completion can deduct inventory twice | Inventory / Concurrency | RESOLVED |
| RMS-028 | HIGH | Invalid order lifecycle transitions | Business Logic | RESOLVED |
| RMS-029 | HIGH | Completed-order cancellation is not a proper refund flow | Payment / Business Logic | RESOLVED |
| RMS-030 | HIGH | Payment method is not whitelist validated | Payment | RESOLVED |
| RMS-031 | HIGH | Payment changes can cause duplicate/inconsistent waiter calls | Payment / Workflow | RESOLVED |
| RMS-032 | HIGH | Payment operations lack idempotency | Payment | RESOLVED |
| RMS-033 | HIGH | Unlimited batch creation | Abuse / Business Logic | RESOLVED |
| RMS-034 | HIGH | Dining session and payment settlement are weakly coupled | Business Logic | RESOLVED |
| RMS-035 | HIGH | Table occupancy can become inconsistent | Business Logic | RESOLVED |
| RMS-036 | MEDIUM | Floating-point money calculations | Financial Integrity | RESOLVED |
| RMS-037 | MEDIUM | No maximum order value | Abuse / Business Logic | RESOLVED |
| RMS-038 | HIGH | Inventory check/deduct is not atomic | Concurrency | RESOLVED |

**Total consolidated findings: 38 (38 RESOLVED)**

---

# 4. Detailed Findings

## RMS-001 — Client-Controlled Add-On Prices

**Severity:** CRITICAL  
**Category:** Price Tampering / Financial Integrity

### Problem

The main menu item price is validated server-side, but add-on/customization prices can still be derived from client-submitted data.

Potentially trusted fields include:

- add-on name
- add-on price

### Attack Scenario

An attacker submits:

```json
{
  "id": 10,
  "quantity": 1,
  "customizations": {
    "extras": [
      {
        "name": "Extra Cheese",
        "price": 0
      }
    ]
  }
}
```

even when the actual price is higher.

### Required Fix

Client should submit only the add-on ID:

```json
{
  "addon_id": 3
}
```

Server must query:

```sql
SELECT id, name, price
FROM menu_addons
WHERE id = ?
AND status = 'active'
```

Never trust:

- client price
- client product name
- client calculated subtotal
- client calculated total

### Acceptance Criteria

- Server calculates all prices.
- Add-on price cannot be overridden by POST data.
- Unknown add-on IDs are rejected.
- Inactive add-ons are rejected.

---

## RMS-002 — Rate Limiter Does Not Increment Automatically

**Severity:** CRITICAL  
**Category:** Rate Limiting

### Problem

`RateLimiter::enforce()` checks whether a limit has been exceeded but does not automatically increment the counter.

Some endpoints call `enforce()` without calling `hit()`.

### Impact

The endpoint may appear rate-limited but actually allow unlimited requests.

### Fix

Make `enforce()` perform:

1. Read current counter.
2. Check limit.
3. Increment counter atomically.
4. Reject if limit exceeded.

Prefer centralized enforcement so developers cannot forget `hit()`.

---

## RMS-003 — Rate Limiter Can Be Bypassed Through New Sessions

**Severity:** CRITICAL  
**Category:** Abuse Prevention

### Problem

The limiter relies heavily on session state.

An attacker may create new sessions to reset counters.

### Fix

Use persistent server-side rate limiting:

- Redis
- database
- APCu
- another server-side cache

Recommended keys:

```text
IP + username
IP + endpoint
IP + customer session
```

Use different thresholds for:

- login
- order creation
- payment
- waiter calls
- public APIs

---

## RMS-004 — `order-success.php` Allows Order/Session Takeover

**Severity:** CRITICAL  
**Category:** IDOR / Authorization

### Problem

The endpoint retrieves an order by ID and then uses the order's table number to modify the customer's session.

An attacker who knows another order ID may potentially influence their own customer session.

### Fix

Require proof of ownership:

```text
current customer session
        ↓
dining_session_id
        ↓
order.dining_session_id
```

Order ID alone must never authorize access.

### Acceptance Criteria

- Random order ID is insufficient.
- User can only access orders belonging to their dining session.
- Invalid ownership returns `403` or `404`.

---

## RMS-005 — Unauthenticated Requests Default to Table 1

**Severity:** CRITICAL  
**Category:** Authorization

### Problem

The application uses a real table as a fallback:

```php
$table_num = $_SESSION['customer_table_id'] ?? '1';
```

### Impact

Unauthenticated users may interact with Table 1-related logic.

### Fix

Never default authorization-sensitive data to a real table.

Require:

```text
valid customer dining session
```

Otherwise:

```text
403 Forbidden
```

---

## RMS-006 — Customer Can Spoof Waiter-Call Table

**Severity:** HIGH  
**Category:** Authorization

### Problem

A submitted table value can be used to establish:

```text
customer_table_id
```

### Attack

A customer at Table 2 could submit:

```text
table=15
```

and potentially create a waiter call for Table 15.

### Fix

Table identity must come exclusively from a validated dining session / QR token.

Never trust:

```text
POST table
GET table
hidden input table
```

as an authorization source.

---

## RMS-007 — Waiter Calls Can Be Resolved Without Proper Authorization

**Severity:** CRITICAL  
**Category:** RBAC

### Problem

The endpoint supports an action similar to:

```text
?action=serve&id=123
```

without enforcing proper staff authorization before the state-changing operation.

### Fix

Require:

```text
POST
authenticated staff
waiter/manager/admin permission
CSRF token
```

GET must never perform the state change.

---

## RMS-008 — KDS Order Updates Bypass Central Business Logic

**Severity:** HIGH  
**Category:** Architecture / Data Integrity

### Problem

Different endpoints can modify order status directly.

One path uses inventory/business logic while another directly updates:

```sql
UPDATE orders SET status = ?
```

### Impact

The same business operation behaves differently depending on which UI triggered it.

### Fix

Create a single service:

```text
OrderService::transitionStatus()
```

All:

- KDS
- Admin
- Manager
- Cashier

must call the same service.

---

## RMS-009 — Invalid Order Status Transitions

**Severity:** HIGH  
**Category:** Business Logic

### Problem

The endpoint validates that the status is a known string but does not enforce valid transitions.

### Invalid Examples

```text
COMPLETED → NEW
COMPLETED → PREPARING
CANCELLED → PREPARING
READY → NEW
```

### Required State Machine

```text
NEW
 ├── PREPARING
 └── CANCELLED

PREPARING
 ├── READY
 └── CANCELLED

READY
 └── COMPLETED

COMPLETED
 └── REFUND_REQUESTED

REFUND_REQUESTED
 └── REFUNDED

CANCELLED
 └── terminal
```

---

## RMS-010 — Concurrent Completion Can Double-Deduct Inventory

**Severity:** HIGH

### Problem

Two simultaneous completion requests can both see the order as incomplete.

### Attack

```text
Request A → read status
Request B → read status

A → deduct stock
B → deduct stock
```

### Fix

Use atomic state transition or row locking:

```sql
UPDATE orders
SET status = 'completed'
WHERE id = ?
AND status = 'ready'
```

Then require:

```text
affected_rows = 1
```

before performing the one-time completion operation.

---

## RMS-011 — Inventory Commits Before Order Update

**Severity:** HIGH  
**Category:** Transaction Integrity

### Problem

Inventory can commit independently before the order status update succeeds.

### Failure

```text
Inventory deducted
↓
Order update fails
```

### Fix

Use one database transaction:

```text
BEGIN

lock order
lock inventory
validate state
deduct/reserve inventory
insert inventory transaction
update order
commit

ROLLBACK on any failure
```

---

## RMS-012 — Kitchen Can Modify Payment Method

**Severity:** HIGH  
**Category:** RBAC

### Problem

Kitchen-level permissions can potentially modify payment-related fields.

### Fix

Restrict payment operations to:

```text
Cashier
Manager
Admin
```

Kitchen should only handle:

```text
NEW
PREPARING
READY
```

---

## RMS-013 — Payment Settings Endpoint Lacks Proper Authorization

**Severity:** HIGH

### Problem

Payment configuration changes require strong administrative authorization and CSRF protection.

### Fix

Require:

```text
authenticated admin
RBAC permission
POST
CSRF
audit logging
```

Recommended permission:

```text
settings.payment.manage
```

---

## RMS-014 — Payment QR Upload Insufficiently Validated

**Severity:** HIGH  
**Category:** File Upload

### Problems

- extension derived from user filename
- weak MIME validation
- no strong size restriction
- potentially unsafe permissions
- predictable filename generation
- upload directory permissions too broad

### Fix

Use an allowlist:

```text
image/png
image/jpeg
image/webp
```

Validate actual file MIME.

Generate random filenames.

Limit file size.

Store outside executable web directories when possible.

Set safe permissions.

---

## RMS-015 — Kitchen API Exposes Cost/Profit Data

**Severity:** HIGH  
**Category:** Information Disclosure

### Problem

Kitchen API can expose fields such as:

- cost price
- profit margin

### Fix

Create role-specific response DTOs.

Kitchen:

```text
name
selling price
availability
preparation data
```

Management:

```text
cost
profit
margin
supplier
```

---

## RMS-016 — N+1 Query in Order Success Flow

**Severity:** MEDIUM

### Problem

Orders are queried and then items are queried individually per order.

### Fix

Use a single bulk query with JOIN or `WHERE order_id IN (...)`.

---

## RMS-017 — Public Order Status Uses Guessable Order ID

**Severity:** MEDIUM

### Problem

Public order tracking based only on numeric order IDs creates an enumeration risk.

### Fix

Use a random tracking token:

```text
256-bit random token
```

Store its hash server-side if practical.

---

## RMS-018 — Public Orders Endpoint Exposes Data by ID

**Severity:** MEDIUM

### Problem

A public endpoint accepts an order ID without sufficient ownership proof.

### Fix

Separate:

```text
Customer order status API
Staff order API
```

Customer API should require an unguessable tracking token.

---

## RMS-019 — Missing `.gitignore` / Sensitive Files Risk

**Severity:** CRITICAL

### Problem

The repository lacks adequate ignore rules while the project contains sensitive/deployment-related files.

### Required `.gitignore`

```gitignore
.env
.env.*
!.env.example

/storage/logs/
/storage/backups/

/uploads/
/images/payment/

*.log
*.sql
.DS_Store
```

### Important

If secrets were ever committed:

1. Rotate them.
2. Remove them from current files.
3. Rewrite Git history if necessary.
4. Check forks/clones.
5. Never assume deleting the file from the latest commit is enough.

---

## RMS-020 — Default `admin/admin123` Account

**Severity:** HIGH

### Problem

The application can automatically create a predictable administrator credential.

### Fix

Production must not silently create:

```text
admin / admin123
```

Use:

- installation wizard
- environment-provided bootstrap secret
- first-run forced password change
- disable bootstrap after setup

---

# 5. Business-Logic Findings

## RMS-021 — Duplicate Order Replay

**Severity:** CRITICAL

### Problem

There is no strong idempotency key for order creation.

### Attack

Send the same request twice.

Expected:

```text
1 logical order
```

Risk:

```text
2 orders
2 kitchen tickets
2 inventory effects
```

### Fix

Require:

```text
Idempotency-Key: UUID
```

Database:

```sql
UNIQUE(idempotency_key)
```

If the key already exists, return the original order.

---

## RMS-022 — Concurrent Dining Session Creation

**Severity:** CRITICAL

### Problem

The application checks for an active session and then creates one.

Two simultaneous requests can both see no active session.

### Fix

Database must enforce one active session per table.

Use:

- unique constraint where supported
- transaction
- row locking
- explicit session creation service

---

## RMS-023 — Batch Number Race Condition

**Severity:** HIGH

### Problem

Batch number is derived using:

```text
COUNT(*) + 1
```

Two simultaneous requests can produce the same number.

### Fix

Use database-controlled sequencing or lock the dining session while allocating the next batch number.

---

## RMS-024 — Unlimited Order Quantity

**Severity:** HIGH

### Problem

Quantity is checked for:

```text
> 0
```

but not for a reasonable maximum.

### Fix

Enforce:

```text
MAX_ITEM_QUANTITY
MAX_CART_ITEMS
MAX_ORDER_VALUE
```

Recommended initial limits should be configurable by restaurant.

---

## RMS-025 — Stock Quantity Not Enforced During Ordering

**Severity:** CRITICAL

### Problem

An item may remain orderable while actual inventory is zero.

### Fix

At order time:

1. Validate menu item.
2. Validate stock.
3. Lock stock row.
4. Reserve/deduct stock.
5. Create order.
6. Commit atomically.

---

## RMS-026 — Inventory Availability TOCTOU Race

**Severity:** HIGH

### Problem

Stock availability is checked separately from consumption.

### Fix

Use:

```sql
SELECT ... FOR UPDATE
```

inside a transaction.

---

## RMS-027 — Double Inventory Deduction

**Severity:** CRITICAL

### Problem

Concurrent requests can execute the same one-time inventory operation more than once.

### Fix

Use:

```text
order_id + inventory_item_id + transaction_type
```

with a unique constraint where appropriate.

Example:

```sql
UNIQUE(order_id, inventory_item_id, transaction_type)
```

---

## RMS-028 — Invalid Order Lifecycle

**Severity:** HIGH

### Fix

Centralize state transitions and validate:

```text
current_state
+
requested_state
```

before every update.

---

## RMS-029 — Cancellation Is Not the Same as Refund

**Severity:** HIGH

### Problem

A completed paid order should not simply become cancelled.

### Required Model

```text
COMPLETED
    ↓
REFUND_REQUESTED
    ↓
REFUNDED
```

Inventory restoration, payment reversal, receipt correction, and audit logging should be explicit.

---

## RMS-030 — Payment Method Not Whitelist Validated

**Severity:** HIGH

### Problem

Sanitization is not business validation.

### Fix

Whitelist:

```text
cash
card
esewa
khalti
fonepay
connectips
imepay
```

Use the exact values defined by the application's payment configuration.

Reject everything else.

---

## RMS-031 — Payment Change Can Create Workflow Inconsistency

### Problem

Changing payment method may create waiter calls or other side effects.

### Fix

Payment changes must be explicit state transitions:

```text
payment method
payment status
payment intent
cash collection status
```

must be handled separately.

---

## RMS-032 — Payment Idempotency Missing

**Severity:** HIGH

### Problem

Repeated payment requests can potentially create duplicate payment records or duplicate callbacks.

### Fix

Store:

```text
gateway
gateway_transaction_id
idempotency_key
amount
currency
status
```

Use unique constraints.

Never mark a payment successful based only on a client redirect.

---

## RMS-033 — Unlimited Batch Creation

**Severity:** HIGH

### Problem

A customer can repeatedly create batches.

### Fix

Implement:

```text
maximum active batches
minimum request interval
maximum unpaid session amount
per-session order limits
```

---

## RMS-034 — Weak Dining Session / Payment Settlement Coupling

**Severity:** HIGH

### Required Flow

```text
OPEN
 ↓
ORDERING
 ↓
PAYMENT_PENDING
 ↓
PAID
 ↓
SETTLED
 ↓
CLOSED
```

Only after settlement should the table become available.

---

## RMS-035 — Table Occupancy Can Become Inconsistent

**Severity:** HIGH

### Required Invariant

```text
TABLE OCCUPIED
⇔
ACTIVE DINING SESSION EXISTS
```

And:

```text
TABLE VACANT
⇔
NO OPEN SESSION
AND
NO UNPAID ORDERS
```

The server/database must maintain this invariant.

---

## RMS-036 — Floating-Point Money Calculations

**Severity:** MEDIUM

### Problem

Money is processed using floating-point variables.

### Fix

Use:

```sql
DECIMAL(10,2)
```

and preferably integer minor units internally where practical.

Never trust client totals.

---

## RMS-037 — No Maximum Order Value

**Severity:** MEDIUM

### Fix

Add configurable:

```text
MAX_ORDER_VALUE
MAX_ITEM_QUANTITY
MAX_CART_QUANTITY
```

Reject excessive orders server-side.

---

## RMS-038 — Inventory Check/Deduct Is Not Atomic

**Severity:** HIGH

### Fix

Inventory operation must be:

```text
BEGIN
↓
SELECT stock FOR UPDATE
↓
validate
↓
update stock
↓
insert transaction
↓
COMMIT
```

No separate check and deduction outside a transaction.

---

# 6. Required Security Architecture

The recommended architecture is:

```text
Customer
   |
   v
API Controller
   |
   v
Authentication
   |
   v
Authorization
   |
   v
Validation
   |
   v
Business Service
   |
   +-------- OrderService
   |
   +-------- PaymentService
   |
   +-------- InventoryService
   |
   +-------- DiningSessionService
   |
   +-------- TableService
   |
   v
Database Transaction
```

Controllers must not directly implement complex business rules.

---

# 7. Required Core Services

Create/standardize:

```text
AuthService
AuthorizationService
OrderService
PaymentService
InventoryService
DiningSessionService
TableService
AuditService
IdempotencyService
```

---

# 8. Required Database Constraints

Add constraints wherever possible.

Examples:

```sql
UNIQUE(idempotency_key);
```

```sql
UNIQUE(gateway_transaction_id);
```

```sql
UNIQUE(order_id, inventory_item_id, transaction_type);
```

Ensure only one active dining session exists for a table.

Use foreign keys for:

```text
orders
order_items
dining_sessions
tables
payments
inventory_transactions
```

Use appropriate indexes for:

```text
orders.status
orders.dining_session_id
orders.created_at
dining_sessions.table_id
dining_sessions.status
payments.transaction_id
inventory_transactions.order_id
```

---

# 9. Required Order State Machine

Implement centrally:

```text
                 ┌──────────────┐
                 │     NEW      │
                 └──────┬───────┘
                        │
             ┌──────────┴──────────┐
             v                     v
       PREPARING               CANCELLED
             │
             v
           READY
             │
             v
        COMPLETED
             │
             v
     REFUND_REQUESTED
             │
             v
          REFUNDED
```

No endpoint should be able to bypass this.

---

# 10. Required Payment State Machine

```text
UNPAID
   |
   v
PAYMENT_PENDING
   |
   +------> FAILED
   |
   v
PAID
   |
   v
REFUND_REQUESTED
   |
   v
REFUNDED
```

Payment success must be confirmed server-to-server with the gateway where supported.

---

# 11. Required Inventory State Model

```text
AVAILABLE
   |
   v
RESERVED
   |
   v
CONSUMED
```

Cancellation:

```text
RESERVED → RELEASED
```

Refund/approved reversal:

```text
CONSUMED → RESTOCKED
```

All movements require immutable inventory transaction records.

---

# 12. QA Test Plan

## Ordering Tests

- [ ] Empty cart
- [ ] Zero quantity
- [ ] Negative quantity
- [ ] Extremely large quantity
- [ ] Invalid menu item ID
- [ ] Inactive menu item
- [ ] Sold-out menu item
- [ ] Invalid add-on
- [ ] Fake add-on price
- [ ] Fake menu price
- [ ] Fake subtotal
- [ ] Fake total
- [ ] Duplicate order
- [ ] Double-click
- [ ] Browser retry
- [ ] Simultaneous order requests
- [ ] Maximum order value
- [ ] Maximum number of items
- [ ] Maximum number of batches

## Table Tests

- [ ] Invalid table ID
- [ ] Table takeover
- [ ] URL table manipulation
- [ ] Two customers on same table
- [ ] Concurrent session creation
- [ ] Table occupancy mismatch
- [ ] Session close without payment
- [ ] Payment without active session
- [ ] Reopen closed session

## Kitchen Tests

- [ ] New → Preparing
- [ ] Preparing → Ready
- [ ] Ready → Completed
- [ ] New → Completed
- [ ] Completed → Preparing
- [ ] Cancelled → Preparing
- [ ] Double completion
- [ ] Simultaneous completion
- [ ] Unauthorized status change

## Inventory Tests

- [ ] Stock = 0
- [ ] Stock = 1 with two simultaneous orders
- [ ] Duplicate deduction
- [ ] Cancellation restock
- [ ] Refund restock
- [ ] Failed order rollback
- [ ] Concurrent inventory updates
- [ ] Negative inventory
- [ ] Inventory transaction duplication

## Payment Tests

- [ ] Fake payment method
- [ ] Fake payment amount
- [ ] Duplicate payment request
- [ ] Payment replay
- [ ] Duplicate gateway callback
- [ ] Successful payment callback twice
- [ ] Failed callback after success
- [ ] Client-side payment success manipulation
- [ ] Refund
- [ ] Partial refund if supported
- [ ] Payment method switching
- [ ] Cash settlement
- [ ] QR settlement
- [ ] eSewa
- [ ] Khalti
- [ ] Fonepay
- [ ] ConnectIPS
- [ ] IME Pay

---

# 13. Concurrency Test Suite

These tests are mandatory before production.

Run simultaneous requests for:

```text
POST /place-order.php
POST /update-order.php
POST /payment
POST /waiter-call
POST /session
POST /inventory
```

Test:

```text
2 simultaneous requests
5 simultaneous requests
10 simultaneous requests
50 simultaneous requests
```

Check for:

- duplicate records
- duplicate inventory deductions
- duplicate payments
- duplicate sessions
- inconsistent table states
- duplicate batches
- negative inventory
- incorrect totals

---

# 14. Security Test Suite

## Authentication

- [ ] Brute force
- [ ] Session fixation
- [ ] Session reuse
- [ ] Password reset abuse
- [ ] Default credentials
- [ ] Privilege escalation

## Authorization

Test every endpoint as:

```text
anonymous
customer
waiter
kitchen
cashier
manager
admin
```

Expected result must be explicitly defined for each endpoint.

## Input Security

- [ ] SQL injection
- [ ] XSS
- [ ] HTML injection
- [ ] JSON manipulation
- [ ] Negative numbers
- [ ] Overflow values
- [ ] Null values
- [ ] Unexpected arrays
- [ ] Duplicate fields

## CSRF

Test all state-changing endpoints.

## File Upload

- [ ] PHP disguised as image
- [ ] SVG
- [ ] oversized file
- [ ] invalid MIME
- [ ] double extension
- [ ] path traversal
- [ ] executable file
- [ ] malicious filename

---

# 15. API Security Standard

Every state-changing API should follow:

```text
1. Start session
2. Authenticate
3. Authorize
4. Validate HTTP method
5. Validate CSRF for browser sessions
6. Validate JSON/input schema
7. Apply rate limit
8. Apply idempotency if operation is repeatable
9. Execute business service
10. Use transaction
11. Audit sensitive action
12. Return safe response
```

---

# 16. Logging and Audit Requirements

Create an audit log for:

- login
- logout
- role changes
- menu price changes
- inventory changes
- order cancellation
- order completion
- payment changes
- payment refunds
- table/session closure
- payment configuration changes
- admin configuration changes

Audit records should include:

```text
user_id
role
action
entity_type
entity_id
old_value
new_value
IP
user_agent
timestamp
request_id
```

Do not log:

- passwords
- API secrets
- payment private keys
- full sensitive tokens

---

# 17. Production Hardening Checklist

Before production:

- [ ] Remove `.env` from Git
- [ ] Rotate exposed credentials
- [ ] Remove database backups from public repository
- [ ] Disable debug mode
- [ ] Disable PHP error display
- [ ] Configure secure cookies
- [ ] Configure HTTPS
- [ ] Add HSTS after HTTPS is verified
- [ ] Configure CSP carefully
- [ ] Disable directory listing
- [ ] Protect upload directories
- [ ] Restrict database user privileges
- [ ] Disable dangerous PHP functions if appropriate
- [ ] Configure backup encryption
- [ ] Configure monitoring
- [ ] Configure error logging
- [ ] Configure database backups
- [ ] Test restore process
- [ ] Add dependency scanning
- [ ] Add static analysis
- [ ] Add automated tests
- [ ] Add CI security checks

---

# 18. Recommended CI Pipeline

Every push should run:

```text
PHP syntax check
        ↓
Unit tests
        ↓
Integration tests
        ↓
Business-logic tests
        ↓
Concurrency tests
        ↓
Security static analysis
        ↓
Dependency audit
        ↓
Build/deploy
```

Recommended categories:

```text
PHPStan/Psalm
PHPUnit
Composer audit
Semgrep
OWASP ZAP against a test deployment
```

---

# 19. Definition of Done

A finding should not be marked fixed merely because the UI works.

A bug is considered fixed only when:

1. Server-side validation exists.
2. Authorization is enforced server-side.
3. Business rules are centralized.
4. Concurrent requests are safe.
5. Database constraints support the rule.
6. Transaction boundaries are correct.
7. Audit logging exists where required.
8. Automated regression test exists.
9. Existing functionality still works.
10. A negative/security test confirms the exploit no longer works.

---

# 20. Final Priority Order

## P0 — Must Fix Before Production

1. Add-on price tampering
2. Order IDOR/session takeover
3. Waiter-call authorization
4. Payment settings authorization
5. Duplicate order/idempotency
6. Dining-session race condition
7. Inventory double deduction
8. Stock enforcement
9. Payment idempotency
10. Secret exposure/deployment hygiene
11. Default admin credentials
12. Atomic order/inventory transactions
13. Order state machine

## P1 — Fix Immediately After P0

14. Rate limiter
15. Payment RBAC
16. File upload security
17. Table/session consistency
18. Payment state machine
19. Inventory locking
20. Batch-number generation
21. Quantity limits
22. Maximum order value
23. Public order tracking authorization
24. KDS/business-logic centralization

## P2 — Hardening

25. N+1 queries
26. Money precision
27. Role-specific API DTOs
28. Audit logging improvements
29. Automated regression tests
30. CI/CD security scanning
31. Performance/concurrency testing

---

# 21. Final Assessment

### Current rating

| Area | Score |
|---|---:|
| Code Quality | 6/10 |
| SQL Injection Protection | 7/10 |
| Authentication | 5/10 |
| Authorization | 4/10 |
| Business Logic | 4/10 |
| Concurrency | 3/10 |
| Payment Integrity | 3/10 |
| Inventory Integrity | 4/10 |
| Overall Security | **4/10** |

### Overall conclusion

The application has a solid feature foundation but is **not production-ready from a security/business-integrity perspective**.

The most important remediation is not simply adding more validation. The application needs a centralized business-logic architecture where:

```text
OrderService
PaymentService
InventoryService
DiningSessionService
TableService
```

control state transitions and database transactions.

The database must enforce critical invariants, while APIs must enforce authentication, authorization, validation, idempotency, and CSRF.

---

# 22. Final QA Sign-Off Criteria

Do not mark the RMS production-ready until all of the following are true:

```text
[ ] 0 CRITICAL findings
[ ] 0 unresolved HIGH findings affecting money/security
[ ] Duplicate order tests pass
[ ] Concurrent session tests pass
[ ] Concurrent inventory tests pass
[ ] Double-payment tests pass
[ ] Order state-machine tests pass
[ ] Payment state-machine tests pass
[ ] Table/session consistency tests pass
[ ] RBAC matrix passes
[ ] IDOR tests pass
[ ] File-upload tests pass
[ ] Rate-limit tests pass
[ ] Secret scanning passes
[ ] Automated regression suite passes
[ ] Production deployment checklist passes
```

**Target production security rating after remediation: 8.5+/10.**
