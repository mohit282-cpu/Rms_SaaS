# Multi-Tenant SaaS Platform Architecture

## Overview
The RMS SaaS Platform transforms the single-restaurant Restaurant Management System into a secure, multi-tenant software-as-a-service application. Multiple restaurant businesses operate independently on a shared application codebase and database infrastructure while maintaining strict logical data isolation.

---

## High-Level System Architecture

```
                    RMS SaaS Platform
                           |
        +------------------+------------------+
        |                  |                  |
        v                  v                  v
   Public Website     Super Admin      Restaurant Portal
        |                  |                  |
        |                  |          +-------+-------+
        |                  |          |       |       |
        |                  |          v       v       v
        |                  |        Menu    Orders   KDS
        |                  |        Tables Inventory Assets
        |                  |        Payments Reports Staff
        |                  |
        |                  +--> Restaurant Requests (Onboarding)
        |                  +--> Tenant Accounts & Governance
        |                  +--> Subscription Plans & Billing
        |
        +--> Public Restaurant Request Form
```

---

## 1. Major System Surfaces

### Surface 1: Public Landing Website (`/index.php`)
- **Target Audience:** Prospective restaurant owners and public visitors.
- **Key Modules:**
  - Product showcase & feature highlights.
  - Interactive onboarding request form.
  - Multi-tier subscription plan overview (Starter, Business, Pro, Enterprise).
  - Portal login access for staff and super admins.

### Surface 2: Super Admin Platform Panel (`/super-admin/`)
- **Target Audience:** Platform operations, support teams, and system administrators.
- **Key Capabilities:**
  - Real-time SaaS metric dashboard (Total, Active, Suspended, Subscriptions, Pending Requests).
  - Onboarding pipeline review (Pending -> Contacted -> Approved -> Converted / Rejected).
  - Manual & automatic restaurant tenant account creation.
  - Temporary credential generation and password reset.
  - Tenant account suspension, activation, and deletion.
  - Audit-logged support impersonation ("Login as Restaurant").
  - Subscription plan configuration and tenant lifecycle management.

### Surface 3: Isolated Restaurant Portal (`/admin/`)
- **Target Audience:** Restaurant owners, managers, cashiers, chefs, waiters, and inventory managers.
- **Key Capabilities:**
  - POS Order Queue & Tables Management.
  - Menu Category & Item Catalog.
  - Kitchen Display System (KDS).
  - Ingredient Recipe & Inventory Control.
  - Asset Tagging & Equipment Maintenance.
  - Payment Gateway Configuration.
  - Sales & Operational Analytics Reports.
  - 8-Step Interactive Onboarding Wizard.

---

## 2. Core SaaS Security Infrastructure

### Tenant Context Service (`helpers/TenantContext.php`)
The `TenantContext` class acts as the single source of truth for resolving tenant identity:
- Resolves tenant ID exclusively from authenticated sessions (`$_SESSION['restaurant_id']` or `$_SESSION['customer_restaurant_id']`).
- Never trusts client-submitted GET/POST parameters or cookies for authorization.
- Provides `assertOwnership()` to block cross-tenant IDOR attacks.

### Subscription Service (`helpers/SubscriptionService.php`)
- Evaluates subscription validity (`ACTIVE`, `TRIAL`, `PAST_DUE`, `EXPIRED`, `SUSPENDED`, `CANCELLED`).
- Enforces table limits and staff user quotas per plan tier.
