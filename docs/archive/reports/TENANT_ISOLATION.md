# Tenant Isolation & IDOR Protection Matrix

## Security Mandate
In the RMS Multi-Tenant SaaS platform, every restaurant is an isolated tenant (`restaurant_id`). Data belonging to Restaurant A **MUST NEVER** be accessible, viewable, editable, or leakable to Restaurant B under any circumstance.

---

## 1. Tenant Context Resolution Rules

```
                      Client Request
                            ↓
               Authenticate User / QR Token
                            ↓
       Bind Session: $_SESSION['restaurant_id']
                            ↓
                     TenantContext
                            ↓
           Automatic SQL Filter (restaurant_id = ?)
```

### Critical Rules:
1. **No Client Parameter Trust:** `restaurant_id` passed via `$_GET`, `$_POST`, cookies, headers, or JSON body is **NEVER** trusted for authorization.
2. **Session Scope:** Tenant identity is read strictly from `TenantContext::getTenantId()`.
3. **Database Guarding:** Every restaurant-scoped SQL query includes `WHERE restaurant_id = ?`.

---

## 2. IDOR Defense Matrix

| Entity | Vulnerability Vectors | Protection Mechanism | Expected Outcome |
| :--- | :--- | :--- | :--- |
| **Orders** | Tampering `order_id` in URL or API request | `TenantContext::assertOwnership($conn, 'orders', $id)` | 403 Forbidden / 404 Not Found |
| **Tables** | Spoofing `table_id` or table number | Encrypted cryptographic QR token (`qr_token`) | 403 Invalid QR Token |
| **Menu Items** | Requesting category or item ID from another tenant | Query filtered by `restaurant_id` + ownership check | 0 rows returned / 404 |
| **Inventory & Stock**| Modifying item stock or recipe for wrong tenant | Strict `restaurant_id` binding on stock updates | 403 Forbidden |
| **Assets** | Scanning asset QR tag belonging to another tenant | Ownership validation on asset tag lookup | Access Denied |
| **Payments** | Settle order payment for another restaurant | Order ownership assertion prior to payment process | 403 Forbidden |
| **Users / Staff** | Viewing staff accounts of another restaurant | Scoped `SELECT * FROM admin_users WHERE restaurant_id = ?` | Isolated Result |

---

## 3. Verification Test Suite Results
Automated integration tests in `tests/saas_tenant_isolation_test.php` verify:
- Cross-tenant order queries return 0 rows.
- GET/POST parameter forgery attempts fail to change the session tenant context.
- Super Admin support impersonation mode requires explicit audit logging.
