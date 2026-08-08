# SaaS Restaurant Onboarding Workflow & Governance

## Overview
The onboarding workflow for new restaurant businesses follows a controlled, Super-Admin-governed lifecycle to prevent spam account creation and ensure verified setup.

---

## Onboarding Sequence Diagram

```
Restaurant Owner             Landing Page             Super Admin Panel           Restaurant Portal
      |                           |                           |                           |
      |--- 1. Submits Request --->|                           |                           |
      |                           |--- 2. Create PENDING ---->|                           |
      |                           |       Request Notification|                           |
      |                           |                           |                           |
      |                           |                           |--- 3. Reviews Request --->|
      |                           |                           |--- 4. Contacts Owner ---->|
      |                           |                           |--- 5. Approves Request -->|
      |                           |                           |--- 6. Creates Tenant ---->|
      |                           |                           |       Account & Owner User|
      |<-- 7. Receives Creds -----|---------------------------|                           |
      |   (Username & Temp Pass)  |                           |                           |
      |                           |                           |                           |
      |--- 8. Logs In ------------------------------------------------------------------->|
      |--- 9. Forces Password Change ---------------------------------------------------->|
      |--- 10. Completes Setup Wizard (0–100%) ------------------------------------------>|
      |--- 11. Goes Live ---------------------------------------------------------------->|
```

---

## Step-by-Step Lifecycle

### Step 1: Public Request Submission (`index.php#request-demo`)
- Prospective restaurant owner fills out the request form.
- Form creates record in `restaurant_requests` table with status `PENDING`.
- Automated Super Admin notification is logged to `notifications`.

### Step 2: Super Admin Governance (`super-admin/requests.php`)
- Super Admin receives notification banner on dashboard.
- Super Admin reviews restaurant details, table count, PAN number, and contact info.
- Super Admin updates status to `CONTACTED` or clicks `Approve & Onboard`.

### Step 3: Account & Credentials Provisioning (`super-admin/create-restaurant.php`)
- Form pre-fills restaurant data from approved request.
- System generates:
  - Unique `uuid` (`rest_...`)
  - Formatted `restaurant_code` (`RMS-000125`)
  - Owner User account in `admin_users`
  - Secure random temporary password
  - Sets `force_password_change = 1`
  - Active subscription record in `subscriptions`

### Step 4: Restaurant First Login & Setup Wizard (`admin/setup-wizard.php`)
- Owner logs in with temporary credentials.
- Prompted immediately to update password (`change-password.php`).
- Redirected to 8-step interactive Setup Wizard:
  1. Restaurant Information & Contact
  2. Logo Upload
  3. Dining Tables Creation
  4. Menu Categories & Items
  5. Payment Gateway Setup
  6. Staff User Accounts & Roles
  7. Table QR Code Generation
  8. Finalize & Go Live (Progress 100%)
