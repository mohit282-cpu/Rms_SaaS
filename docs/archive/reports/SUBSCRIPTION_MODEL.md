# SaaS Subscription Tiers & Feature Governance

## Overview
The RMS SaaS Platform supports manual subscription management by Super Admins. Subscriptions govern tenant feature access, dining table limits, and staff user quotas.

---

## 1. Subscription Tiers

| Plan Tier | Monthly Price | Max Tables | Max Staff | Included Feature Modules |
| :--- | :--- | :--- | :--- | :--- |
| **STARTER** | $29.00 / mo | 10 Tables | 3 Users | Basic POS, QR Menu, Table Ordering, Cash Settlements |
| **BUSINESS** | $69.00 / mo | 25 Tables | 10 Users | Full POS, Kitchen KDS, Basic Inventory, Online Payment Gateways, Multi-Role RBAC |
| **PRO** | $129.00 / mo | 50 Tables | 25 Users | Enterprise KDS, Advanced Recipe Stock Audits, Asset Tagging & Depreciation, Analytics |
| **ENTERPRISE**| $249.00 / mo | 999 Tables | 100 Users | Unlimited Tables, Custom Integrations, Dedicated SLA & Priority Support |

---

## 2. Subscription Status Lifecycle

```
   TRIAL ──> ACTIVE ──> PAST_DUE ──> EXPIRED ──> SUSPENDED / CANCELLED
```

### Status Definitions:
- **`TRIAL`**: Initial evaluation period (default 14 days). Full access permitted.
- **`ACTIVE`**: Active paid subscription. Unrestricted access within plan quotas.
- **`PAST_DUE`**: Payment grace period. Staff warned via banner.
- **`EXPIRED`**: Subscription end date passed. Access restricted via `TenantContext::requireTenant()`.
- **`SUSPENDED`**: Manually suspended by Super Admin. All portal logins blocked with 403 screen.
- **`CANCELLED`**: Tenant contract terminated.

---

## 3. Subscription Enforcement Service (`helpers/SubscriptionService.php`)
- `SubscriptionService::isActive($restaurantId)`
- `SubscriptionService::canAccessTenant($restaurantId)`
- `SubscriptionService::getRemainingDays($restaurantId)`
- `SubscriptionService::getTenantPlanLimits($restaurantId)`
