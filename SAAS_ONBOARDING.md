# RMS SaaS Onboarding & Manual Provisioning Standard

## Overview
RMS SaaS uses a controlled, Super Admin-governed onboarding workflow. Restaurant credentials and usernames are **never automatically generated**. Super Admins manually assign administrator usernames and passwords when provisioning accounts.

---

## Workflow Steps

```
Public Request Form (index.php)
       ↓
Pending Queue (super-admin/requests.php)
       ↓
Super Admin Review & Approval
       ↓
Manual Account Creation (super-admin/create-restaurant.php)
  - Restaurant Details (Name, Owner, Email, Phone, PAN, Address, Type, Plan, Status)
  - Manual Credentials (Admin Username *, Admin Password *, Confirm Password *)
       ↓
BCRYPT Password Hashing & Tenant Binding
       ↓
Credential Delivery Screen (Copy Credentials Action)
       ↓
Manual Credential Transmission to Restaurant Administrator
       ↓
Restaurant Login & 8-Step Setup Wizard
```

---

## Security & Credential Rules

1. **No Auto-Generated Credentials:** Usernames and passwords MUST NOT be automatically created or derived from restaurant names. Super Admin enters both manually.
2. **Username Uniqueness & Validation:**
   - Case-normalized (`strtolower`).
   - Format: 4–30 characters (`/^[a-zA-Z0-9_]{4,30}$/`).
   - Duplicate usernames return: `"Username already exists. Please choose another username."`
3. **Password Security:**
   - Passwords hashed using `password_hash($password, PASSWORD_BCRYPT)`.
   - Zero plaintext storage in database, audit logs, API responses, or local storage.
4. **Credential Delivery:**
   - Displays a confirmation card with a **"Copy Credentials"** button.
   - Credentials sent manually to the restaurant owner via phone, SMS, or secure messaging.
5. **Super Admin Governance:**
   - Reset Administrator Password (manual input of New Password + Confirm Password).
   - Change Administrator Username (validating uniqueness).
   - Activate / Suspend / Disable tenant account status.
