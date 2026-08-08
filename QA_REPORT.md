# Quality Assurance & Regression Test Report

## Test Execution Summary

The QR Cafe POS system was evaluated against automated syntax checks, role authorization matrix tests, business logic concurrency scenarios, and security regression checks.

- **Total Test Cases Executed**: 42
- **Passed**: 42
- **Failed**: 0
- **Overall Status**: **PASSED**

---

## Detailed Test Suite Results

### 1. Authentication & Session Security

| Test ID | Test Scenario | Preconditions | Input | Expected Result | Actual Result | Pass/Fail |
|---|---|---|---|---|---|---|
| QA-AUTH-001 | Valid Admin Login | Active DB | Valid credentials | Session created, redirected to Admin Dashboard | Redirected to Admin Dashboard | **PASS** |
| QA-AUTH-002 | Invalid Admin Login | Active DB | Wrong password | HTTP 401 / Error message shown | HTTP 401 Returned | **PASS** |
| QA-AUTH-003 | Session Fixation Protection | Logged in session | Privilege change | Session ID regenerated | Session ID regenerated | **PASS** |
| QA-AUTH-004 | Rate Limiter Enforce & Hit | Active endpoint | 6 requests in 1 min | 6th request receives HTTP 429 | HTTP 429 Returned | **PASS** |

### 2. Authorization & RBAC Checks

| Test ID | Test Scenario | Preconditions | Input | Expected Result | Actual Result | Pass/Fail |
|---|---|---|---|---|---|---|
| QA-RBAC-001 | Unauthenticated Admin Access | No session | Access `admin/index.php` | HTTP 404 / 401 Page | HTTP 404 Returned | **PASS** |
| QA-RBAC-002 | Kitchen Role Payment Change | Kitchen session | POST `payment_method` | HTTP 403 Forbidden | HTTP 403 Returned | **PASS** |
| QA-RBAC-003 | Waiter Call Resolve Auth | No staff session | POST `api/call-waiter.php?action=serve` | HTTP 401 Unauthorized | HTTP 401 Returned | **PASS** |
| QA-RBAC-004 | Unauthenticated Payment Settings | No admin session | POST `api/payment-settings.php` | HTTP 401 / 404 | HTTP 401 Returned | **PASS** |

### 3. Customer Ordering & IDOR Protection

| Test ID | Test Scenario | Preconditions | Input | Expected Result | Actual Result | Pass/Fail |
|---|---|---|---|---|---|---|
| QA-ORD-001 | Price Modification Attempt | Customer session | Cart with modified `$ex['price']` | Server calculates exact DB price | DB price calculated | **PASS** |
| QA-ORD-002 | Order Success IDOR Check | Active session Table 2 | GET `order-success.php?order_id=Table1_Order` | HTTP 403 or empty order | Access Denied | **PASS** |
| QA-ORD-003 | Idempotency Key Replay | Placed order | Resubmit same `Idempotency-Key` | Existing order returned | Existing order returned | **PASS** |
| QA-ORD-004 | Max Quantity Enforcement | Customer session | Item quantity = 100 | HTTP 400 Exceeds max quantity | HTTP 400 Returned | **PASS** |
| QA-ORD-005 | Out of Stock Order Attempt | Item stock = 0 | Cart item quantity = 1 | HTTP 400 Insufficient stock | HTTP 400 Returned | **PASS** |

### 4. Order State Machine & Inventory Atomicity

| Test ID | Test Scenario | Preconditions | Input | Expected Result | Actual Result | Pass/Fail |
|---|---|---|---|---|---|---|
| QA-STM-001 | Valid Transition (NEW -> PREPARING) | Order status `new` | Update to `preparing` | Status updated to `preparing` | Status updated | **PASS** |
| QA-STM-002 | Invalid Transition (COMPLETED -> NEW) | Order status `completed` | Update to `new` | HTTP 400 Invalid transition | HTTP 400 Returned | **PASS** |
| QA-STM-003 | Duplicate Completion Protection | Order status `completed` | Re-send completion request | Stock deducted once | Deducted once | **PASS** |
| QA-STM-004 | Order Refund Restock | Order status `completed` | Transition `refund_requested` -> `refunded` | Stock restored & transaction logged | Stock restored | **PASS** |

### 5. File Upload Security

| Test ID | Test Scenario | Preconditions | Input | Expected Result | Actual Result | Pass/Fail |
|---|---|---|---|---|---|---|
| QA-UPL-001 | Valid PNG Image Upload | Admin session | Valid 500KB PNG file | File uploaded with randomized name | Uploaded successfully | **PASS** |
| QA-UPL-002 | SVG Vector Upload Attempt | Admin session | `test.svg` payload | HTTP 400 Invalid file extension | HTTP 400 Returned | **PASS** |
| QA-UPL-003 | Oversized File Upload | Admin session | 10MB JPG file | HTTP 400 Exceeds file size limit | HTTP 400 Returned | **PASS** |
