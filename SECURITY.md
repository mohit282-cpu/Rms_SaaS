# SaaS Platform Security & Compliance Architecture

## Security Policy
Security is built into every layer of the RMS SaaS application. This document outlines the cryptographic standards, access controls, session policies, and defense-in-depth mechanisms implemented to ensure complete data protection.

---

## 1. Authentication & Session Security
- **BCRYPT Hashing:** All passwords are stored using `password_hash($pass, PASSWORD_BCRYPT)`. Plaintext passwords are **NEVER** stored.
- **Session Pinning & Regeneration:** `Auth::regenerateSession()` is invoked on every login to prevent Session Fixation.
- **Cookie Security:** Cookies use `HttpOnly`, `SameSite=Lax`, and `Secure` (when on HTTPS).
- **Temporary Passwords:** Forced password update enforced on initial login via `force_password_change`.

---

## 2. Multi-Tenant Logical Data Scoping (IDOR Prevention)
- **Centralized Context:** `TenantContext::getTenantId()` resolves tenant ID from verified session data only.
- **Parametric Query Scoping:** Every SQL statement filters by `WHERE restaurant_id = ?`.
- **Resource Ownership Assertion:** `TenantContext::assertOwnership($conn, $table, $id)` verifies resource ownership before rendering or mutating records.

---

## 3. Input Hardening & CSRF Protection
- **CSRF Tokens:** All state-changing POST forms and API endpoints verify cryptographic CSRF tokens (`CSRF::verifyToken()`).
- **Input Sanitization:** `Security::sanitize()` removes malicious XSS tags and scripts.
- **SQL Injection Prevention:** All SQL queries use MySQLi prepared statements with bound parameters (`$stmt->bind_param()`).

---

## 4. Payment Secrets & Idempotency Security
- **Payment Credentials:** Merchant keys and gateway API secrets are stored per tenant in `payment_gateways`.
- **Idempotency Keys:** Duplicate order payments and transactions are prevented using unique `idempotency_key` database constraints.

---

## 5. Audit Logging & Impersonation Governance
- All critical platform events (logins, password resets, tenant creation, tenant suspension, impersonation) are logged to `audit_logs` with IP, timestamp, user agent, and event type.
- Support impersonation ("Login as Restaurant") displays a persistent banner and records explicit audit trails.
