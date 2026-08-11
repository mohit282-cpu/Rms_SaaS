<p align="center">
  <h1 align="center">🍽️ RMS SaaS</h1>
  <h3 align="center">Restaurant Management & Operating System</h3>
  <p align="center">
    Run your restaurant's tables, orders, kitchen, billing, inventory,<br>
    customers, loyalty, staff, and business operations — from one platform.
  </p>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/MySQL-8.0+-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL 8.0+">
  <img src="https://img.shields.io/badge/Apache-2.4-D22128?style=for-the-badge&logo=apache&logoColor=white" alt="Apache 2.4">
  <img src="https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black" alt="JavaScript">
  <img src="https://img.shields.io/badge/Tailwind_CSS-CDN-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white" alt="Tailwind CSS">
  <img src="https://img.shields.io/badge/License-All_Rights_Reserved-red?style=for-the-badge" alt="License">
</p>

<p align="center">
  <a href="#-features">⭐ Features</a> · 
  <a href="#-security-overview">🔐 Security</a> · 
  <a href="#-restaurant-admin-portal">🏪 Operations</a> · 
  <a href="#-kitchen-display-system-kds">👨‍🍳 Kitchen</a> · 
  <a href="#-pos--billing">💳 Billing</a> · 
  <a href="#-inventory-management">📦 Inventory</a> · 
  <a href="#-staff--hr-management">👥 HR</a> · 
  <a href="#-super-admin--saas-platform">☁️ SaaS</a>
</p>

---

## 📖 Table of Contents

- [Overview](#-overview)
- [Who Is It For?](#-who-is-it-for)
- [What Problem Does RMS Solve?](#-what-problem-does-rms-solve)
- [Core Restaurant Workflow](#-core-restaurant-workflow)
- [Features](#-features)
- [Customer Experience (QR Ordering)](#-customer-experience--qr-ordering)
- [Restaurant Admin Portal](#-restaurant-admin-portal)
- [Floor & Table Operations](#-floor--table-operations)
- [POS & Billing](#-pos--billing)
- [Kitchen Display System (KDS)](#-kitchen-display-system-kds)
- [Customer CRM & Loyalty](#-customer-crm--loyalty)
- [Inventory Management](#-inventory-management)
- [Suppliers & Purchasing](#-suppliers--purchasing)
- [Asset Management](#-asset-management)
- [Staff & HR Management](#-staff--hr-management)
- [Register / Cash Float Shifts](#-register--cash-float-shifts)
- [Expenses & Operational P&L](#-expenses--operational-pl)
- [Reservations](#-reservations)
- [Super Admin & SaaS Platform](#-super-admin--saas-platform)
- [Multi-Tenancy](#-multi-tenancy)
- [User Roles & Permissions](#-user-roles--permissions)
- [Security Overview](#-security-overview)
- [Architecture](#-architecture)
- [API Reference](#-api-reference)
- [Database Overview](#-database-overview)
- [Installation](#-installation)
- [Production Deployment](#-production-deployment)
- [Project Structure](#-project-structure)
- [Feature Status Matrix](#-feature-status-matrix)
- [Current Limitations](#-current-limitations)
- [Roadmap](#-roadmap)
- [Support & Contributing](#-support--contributing)

---

## 🌐 Overview

RMS SaaS is a **multi-tenant Restaurant Management System** that connects the complete restaurant workflow — from a customer scanning a QR code at their table, through kitchen preparation, to billing, payment, and business reporting.

```text
                           RMS SaaS
                              │
            ┌─────────────────┼─────────────────┐
            │                 │                 │
        CUSTOMER          RESTAURANT          PLATFORM
            │                 │                 │
        QR Menu           POS / Billing      Super Admin
        Ordering          Floor & Tables     Tenant Management
        Order Tracking    Kitchen / KDS      Subscription Plans
        Loyalty           Inventory          Onboarding Pipeline
                          Customers / CRM
                          Staff & HR
                          Expenses
                          Assets
                          Reports
```

**One codebase** serves unlimited independent restaurants. Each restaurant gets its own isolated environment — its own menu, tables, orders, customers, inventory, and staff — managed through a shared platform governed by a Super Admin.

| Layer | Technology |
| :--- | :--- |
| Language | PHP 8.1+ (no framework, no Composer) |
| Database | MySQL 8.0+ / MariaDB |
| Web Server | Apache 2.4 (`mod_rewrite`, `mod_headers`) |
| Frontend | Vanilla JavaScript + Tailwind CSS 3.4 (CDN) |
| Realtime | AJAX polling (no WebSockets or SSE) |
| Build Tools | **None** — zero install, zero bundler |

---

## 🎯 Who Is It For?

| | Audience | What RMS Provides |
| :---: | :--- | :--- |
| 🍽️ | **Restaurants** | Full POS, table management, kitchen display, billing, inventory, customer loyalty, staff HR, and business reporting |
| ☕ | **Cafés** | QR-based self-ordering, fast billing, customer retention through loyalty, and inventory tracking |
| 🏨 | **Hotels (F&B)** | Restaurant and food & beverage operations within a hotel property |
| 🏢 | **Multi-Outlet Operators** | One platform managing multiple independent restaurant locations through multi-tenancy |

> **Important**: RMS currently supports **restaurant and F&B operations**. Full hotel PMS functionality (room management, guest folios, housekeeping, accommodation billing) and hostel/bed management are **not** part of the current system.

---

## 💡 What Problem Does RMS Solve?

<table>
<tr>
<th width="50%">❌ Before RMS</th>
<th width="50%">✅ After RMS</th>
</tr>
<tr>
<td>

- Paper order slips get lost
- Kitchen shouts across the room
- Manual bill calculations with errors
- No customer history or loyalty
- Spreadsheet inventory tracking
- Cash discrepancies at end of day
- No visibility into business performance
- Each restaurant needs separate software

</td>
<td>

- Digital orders flow instantly to kitchen
- KDS shows real-time ticket queue
- Server-calculated bills with tax & loyalty
- Customer profiles with visit history & points
- Automated stock tracking with recipes
- Register shift reconciliation with variance
- Live dashboard with revenue & order KPIs
- One SaaS platform serves all restaurants

</td>
</tr>
</table>

---

## 🔄 Core Restaurant Workflow

This is how a single customer visit flows through the system, end to end:

```text
┌─────────────────────────────────────────────────────────────────┐
│                    COMPLETE ORDER LIFECYCLE                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│   👤 CUSTOMER ARRIVES                                           │
│       ↓                                                         │
│   🪑 TABLE ASSIGNED (status: occupied)                          │
│       ↓                                                         │
│   📱 CUSTOMER SCANS QR  ──or──  🧑‍🍳 WAITER TAKES ORDER         │
│       ↓                                                         │
│   📋 ORDER CREATED (status: new)                                │
│       ↓                                                         │
│   👨‍🍳 KITCHEN RECEIVES ORDER                                    │
│       ↓                                                         │
│   🔥 KITCHEN PREPARES (status: preparing)                       │
│       ↓                                                         │
│   ✅ ORDER READY (status: ready)                                │
│       ↓                                                         │
│   🍽️ FOOD SERVED (status: completed)                            │
│       ↓                                                         │
│   📄 TABLE → WAITING BILL (status: waiting_bill)                │
│       ↓                                                         │
│   💰 CASHIER OPENS TABLE DRAWER                                 │
│       ↓                                                         │
│   👤 CUSTOMER IDENTIFIED (optional loyalty lookup)              │
│       ↓                                                         │
│   🧾 BILL CALCULATED (subtotal + tax + service - discounts)     │
│       ↓                                                         │
│   💳 PAYMENT SETTLED (cash / card / digital)                    │
│       ↓                                                         │
│   🧾 RECEIPT GENERATED + LOYALTY POINTS AWARDED                 │
│       ↓                                                         │
│   🧹 TABLE CLEARED (status: cleaning → vacant)                  │
│       ↓                                                         │
│   📊 DASHBOARD & REPORTS UPDATED                                │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

| Stage | What Happens |
| :--- | :--- |
| **Customer Arrives** | Guest walks in or has a reservation. Waiter assigns them to an available table. |
| **Order Created** | Customer scans the table QR code to browse the menu and place an order, or a waiter creates it from the admin POS. |
| **Kitchen Receives** | The order appears instantly on the Kitchen Display System (KDS) as a live ticket. |
| **Kitchen Prepares** | Kitchen staff marks the ticket as "Preparing" — the table status updates in real time. |
| **Order Ready** | Kitchen marks "Ready" — waiter is notified to serve. |
| **Food Served** | Order marked "Completed." When all kitchen orders for the table are done, the table transitions to **Waiting Bill**. |
| **Bill Calculated** | Cashier opens the table in the POS. All unpaid orders are aggregated into one bill with tax, service charge, discounts, and optional loyalty redemption — all calculated server-side. |
| **Payment Settled** | Cashier processes cash, card, or digital payment. Server validates amounts, locks the order row, and prevents duplicate charges. |
| **Receipt & Loyalty** | Receipt is generated and loyalty points are awarded **only after** successful payment. |
| **Table Cleared** | Table transitions to "Cleaning" then "Vacant" — ready for the next guest. |

---

## ⭐ Features

### 🏪 Restaurant Operations

| Module | What It Does |
| :--- | :--- |
| **Operations Dashboard** | Real-time KPIs — today's revenue, order count, average ticket, table occupancy, low-stock alerts, and live activity feed |
| **Floor & Tables** | Create tables by zone, set capacity, generate QR codes, manage table lifecycle (vacant → occupied → waiting bill → cleaning) |
| **Orders** | Create, view, filter, and manage orders with full status tracking and audit trail |
| **Menu Management** | Categories, items with images, pricing, dietary tags (veg/non-veg), allergens, calories, preparation time, add-ons, and stock toggles |
| **POS & Billing** | In-drawer table billing with multi-order aggregation, tax, service charge, discounts, loyalty, and server-authoritative pricing |
| **Payments** | Cash (with change calculation), card, and digital QR — all manually confirmed by cashier |
| **Reservations** | Date/time/guest reservations with status tracking |

### 👨‍🍳 Kitchen

| Module | What It Does |
| :--- | :--- |
| **Kitchen Display (KDS)** | Live order ticket wall with status buttons (Start Cooking → Mark Ready → Serve), KPI cards, and 2-second polling |
| **Kitchen Menu** | Kitchen-side menu view with quick stock availability toggles |

### 👤 Customer

| Module | What It Does |
| :--- | :--- |
| **QR Digital Menu** | Scan a table QR code → browse the full restaurant menu on your phone — no app install required |
| **Self-Ordering** | Add items to cart, customize with add-ons, place order directly from the phone |
| **Live Order Tracking** | Real-time order status page showing preparation progress |
| **Waiter Call** | One-tap button to call a waiter to the table |
| **Loyalty** | Points earned on purchases and redeemable on future visits |

### 📦 Back Office

| Module | What It Does |
| :--- | :--- |
| **Inventory** | Items, categories, units, stock levels, movement tracking, waste logging, stock audits, alerts, and reports |
| **Suppliers** | Supplier directory with contact details and order history |
| **Purchase Orders** | Create POs, track status, receive goods against POs |
| **Recipes** | Define recipes linking menu items to inventory ingredients with quantity ratios |
| **Assets** | Equipment tracking with categories, maintenance schedules, warranties, transfers, depreciation, and QR tags |
| **Expenses** | Categorized expense tracking for operational cost management |

### 👥 People

| Module | What It Does |
| :--- | :--- |
| **Staff & RBAC** | Staff accounts with role-based access control across 8 roles and 100+ granular permissions |
| **HR / Employees** | Employee profiles, departments, designations, employment types, salary management |
| **Shift Scheduling** | Shift templates, employee-to-shift assignment, and scheduling |
| **Attendance** | Clock in/out with grace periods, late detection, worked hours, and overtime calculation |
| **Payroll** | Payroll period management, server-side salary calculation, approval workflow, disbursement, and payslip generation |
| **Register Shifts** | Cashier shift lifecycle — opening float, sales tracking, cash in/out, reconciliation, variance, and shift closure |

### ☁️ Platform (SaaS)

| Module | What It Does |
| :--- | :--- |
| **Super Admin** | Platform-wide governance — tenant management, subscription plans, onboarding pipeline |
| **Multi-Tenancy** | Every restaurant is an isolated tenant sharing one codebase and database |
| **Subscription Plans** | Configurable plans (Starter / Business / Pro / Enterprise) with table and staff limits |
| **Onboarding** | Public request form → admin review → tenant provisioning pipeline |

---

## 📱 Customer Experience — QR Ordering

Customers interact with RMS without downloading an app or creating an account.

```text
📱 Scan Table QR Code
        ↓
   Verify Table Token ──→ Invalid? Access Denied
        ↓
🍽️ Browse Restaurant Menu
        ↓
   Select Items + Add-ons
        ↓
🛒 Review Cart
        ↓
   Place Order (server-side pricing, rate-limited, idempotent)
        ↓
✅ Order Confirmed
        ↓
📡 Live Order Tracking (polling every 3.5 seconds)
        ↓
🔔 Call Waiter (optional)
        ↓
💳 Payment at Table
```

**How it works:**

- Each physical table has a **unique QR code** containing a cryptographic token
- Scanning the QR opens the restaurant's digital menu — scoped to that specific table
- No login or account required — the QR token authenticates the customer session
- Order pricing is calculated **server-side** from the database — the browser never supplies totals
- Orders are **rate-limited** (10/minute) and protected with **idempotency keys** to prevent duplicates
- After placing an order, the customer sees a **live tracking page** with real-time status updates
- The customer can tap **Call Waiter** to send a notification to staff

---

## 🏪 Restaurant Admin Portal

The admin portal (`admin/`) is the central hub for restaurant operations.

```text
🏪 Restaurant Admin
│
├── 📊 Dashboard ─────────── Live KPIs, revenue, orders, activity feed
├── 🪑 Floor & Tables ────── Table CRUD, zones, QR codes, table status, POS billing
├── 📋 Orders ─────────────── Order list, status filters, order details
├── 🍕 Menu ───────────────── Categories, items, pricing, images, add-ons, stock
├── 👤 Customers ──────────── Customer profiles, phone lookup, visit history
├── ⭐ Loyalty ─────────────── Points settings, earning/redemption rules
├── 🏷️ Reservations ────────── Date/time/guest reservations
├── 📦 Inventory ──────────── Items, categories, stock, movements, audits, waste
├── 🚚 Suppliers ──────────── Supplier directory
├── 🛒 Purchase Orders ────── PO creation, goods receiving
├── 🍳 Recipes ────────────── Menu-to-inventory ingredient mapping
├── 🏗️ Assets ─────────────── Equipment, maintenance, warranties, depreciation
├── 💰 Expenses ───────────── Categorized expense tracking
├── 👥 Staff & HR ─────────── Employees, roles, attendance, shifts, payroll
├── 💵 Register Shifts ────── Cashier shifts, cash float, reconciliation
├── 🎨 Landing Page ────────── Customize the public-facing restaurant page
├── ⚙️ Settings ───────────── Restaurant settings, payment config, KDS password
└── 🔑 Change Password ───── Secure password update
```

---

## 🪑 Floor & Table Operations

Tables are the operational heart of the restaurant. Each table has a lifecycle that reflects where the customer is in their visit.

### Table Status Flow

```text
   🟢 VACANT ──────→ 🔵 RESERVED
       │                   │
       ▼                   ▼
   🟠 OCCUPIED ←──────────┘
       │
       ▼
   🔥 ORDERING / PREPARING / READY
       │
       ▼
   📄 WAITING BILL  (kitchen finished, payment pending)
       │
       ▼
   💳 PAYMENT PENDING
       │
       ▼
   🧹 CLEANING
       │
       ▼
   🟢 VACANT
```

| Status | Meaning |
| :--- | :--- |
| **Vacant** | Table is available for seating |
| **Occupied** | Guests are seated |
| **Reserved** | Table is held for a future reservation |
| **Waiting Bill** | All kitchen orders are completed — awaiting payment |
| **Cleaning** | Payment complete — table is being cleared |
| **Disabled** | Table is temporarily out of service |

**Key behaviors:**

- When the kitchen completes the **last active order** for a table, the table automatically transitions to **Waiting Bill** — it does **not** become vacant until payment is completed
- Cashiers **cannot** mark a table as vacant while unpaid orders exist
- Table status transitions are **server-enforced** — not just UI state

### Table Features

- Create tables by **zone** (e.g., Ground Floor, Terrace, Private Room)
- Set **capacity** per table
- Generate and print **QR codes** for each table
- Assign **waiters** to tables
- View all tables on a **live floor grid** with color-coded status indicators

---

## 💳 POS & Billing

Billing happens **inside the table drawer** on the Floor & Tables page (`admin/tables.php`). There is no separate billing page — billing is integrated directly into the POS workflow.

### Bill Calculation Flow

```text
🪑 Select Table (Waiting Bill / Payment Pending)
        ↓
📋 Load ALL Unpaid Orders for This Table
        ↓
   ┌────────────────────────────────┐
   │   BILL BREAKDOWN              │
   │                               │
   │   Subtotal (all order items)  │
   │   + Service Charge (%)        │
   │   + VAT / Tax (%)             │
   │   − Discount                  │
   │   − Loyalty Points Discount   │
   │   − NCR / Complimentary       │
   │   ═══════════════════════     │
   │   GRAND TOTAL                 │
   └────────────────────────────────┘
        ↓
💳 Select Payment Method
        ↓
✅ Payment Settled
        ↓
🧾 Receipt Generated + Loyalty Awarded
```

### Payment Methods

| Method | How It Works |
| :--- | :--- |
| 💵 **Cash** | Cashier enters amount received. System calculates change. **Pay button is disabled until cash ≥ amount due** (server-validated). |
| 💳 **Card** | Cashier processes card on physical terminal, then confirms in RMS. Manual confirmation — no live gateway API. |
| 📱 **Digital / QR** | Customer scans the merchant's payment QR (eSewa, Khalti, etc.), cashier confirms receipt. Manual confirmation — no live gateway API. |

> **⚠️ Payment Integration Note**: RMS does **not** integrate with live payment gateway APIs. Card and digital payments are **manually confirmed** by the cashier after processing on external terminals or apps. There are no webhooks, IPNs, or automated payment verifications. Payment configuration in Settings stores gateway display information only.

### Billing Safety Controls

- **Server-authoritative pricing** — the browser never supplies totals; all prices are recalculated from the database
- **Row-level locking** — order rows are locked with `SELECT FOR UPDATE` during payment to prevent concurrent double-settlement
- **Idempotent payments** — if a payment is accidentally submitted twice, the system detects the already-paid order and returns the existing transaction without creating a duplicate
- **Cash validation** — server rejects cash payments where `cash_received < amount_due`
- **Receipt timing** — receipts are generated **only after** successful payment
- **Loyalty timing** — points are awarded **only after** successful payment

### Additional Billing Features

- **Split Billing** — split a bill across multiple payments with server-enforced `split_total ≤ bill_total`
- **Refunds** — full and partial refunds with reason tracking, inventory restock, loyalty reversal, and audit logging
- **NCR / Complimentary** — mark items as non-chargeable with authorization and audit trail
- **Void** — void unpaid orders with reason and authorization logging

---

## 👨‍🍳 Kitchen Display System (KDS)

The KDS is a dedicated screen for kitchen staff showing live order tickets.

```text
📋 New Order Arrives
       ↓
🔥 Start Cooking (status: preparing)
       ↓
✅ Mark Ready (status: ready)
       ↓
🍽️ Serve (status: completed)
```

**How it works:**

- Accessed at `kitchen-dashboard.php` with a **separate KDS password** (not a staff account)
- Shows a **live ticket wall** with all active orders
- Each ticket shows table number, items, quantities, and special instructions
- Kitchen staff tap **status buttons** to progress orders through the pipeline
- **KPI cards** show total orders, preparing, ready, and completed counts
- The display **polls every 2 seconds** via AJAX for near-real-time updates
- `kitchen-menu.php` provides a kitchen-side menu view with **quick stock toggles** to mark items as sold out

> **Architecture Note**: The KDS uses AJAX polling (2-second intervals), not WebSockets or Server-Sent Events. This is reliable for small-to-medium restaurant volumes but is not designed for high-concurrency environments with hundreds of simultaneous kitchen displays.

---

## ⭐ Customer CRM & Loyalty

### Customer Lookup Flow

```text
📱 Phone Number
       ↓
👤 Customer Profile (auto-created or existing)
       ↓
📊 Order History + Visit Count + Total Spent
       ↓
⭐ Loyalty Points Balance
       ↓
   ┌──────────┐    ┌──────────┐
   │  REDEEM  │    │   EARN   │
   │  Points  │    │  Points  │
   └──────────┘    └──────────┘
```

During billing, the cashier can **search by phone number** to pull up a customer profile. If the customer exists, their loyalty balance is shown and points can be redeemed as a bill discount.

### Loyalty Settings (per restaurant)

| Setting | What It Controls |
| :--- | :--- |
| **Enable/Disable** | Toggle the entire loyalty system on or off |
| **Points per currency unit** | How many points are earned per unit of spend (e.g., 1 point per ₹100) |
| **Point value** | Monetary value of one loyalty point when redeeming |
| **Minimum redemption** | Minimum points that can be redeemed in a single transaction |
| **Maximum redemption** | Cap on points redeemable per transaction |
| **Maximum discount %** | Cap on how much of a bill can be covered by loyalty |

### How Points Work

1. **Earning**: After a successful payment, points are calculated based on the eligible bill amount and the restaurant's earning rules
2. **Redemption**: During billing, the cashier enters points to redeem. The system validates against the customer's balance, minimum/maximum rules, and discount caps
3. **Ledger**: Every earning and redemption is recorded in `loyalty_transactions` with full audit trail
4. **Idempotency**: Duplicate earning/redemption attempts for the same order are silently skipped

---

## 📦 Inventory Management

```text
🛒 PURCHASE ORDER
       ↓
📦 GOODS RECEIVED (Inspected + Accepted)
       ↓
📊 STOCK UPDATED (Inventory Items)
       ↓
🍳 RECIPE / CONSUMPTION
       ↓
🍽️ ORDER PLACED (Auto-deduction via recipes)
       ↓
📉 STOCK DEPLETED
       ↓
⚠️ LOW STOCK ALERT
       ↓
📊 INVENTORY REPORTS
```

| Module | Purpose |
| :--- | :--- |
| **Inventory Items** | Track ingredients and supplies with SKU, unit, stock quantity, min/max levels, and cost |
| **Inventory Categories** | Organize items into categories (Produce, Dairy, Meat, etc.) |
| **Units** | Define measurement units (kg, liters, pieces, etc.) |
| **Stock Movements** | View a complete history of stock changes with reasons |
| **Stock Audit** | Conduct physical stock counts and reconcile with system quantities |
| **Waste Tracking** | Log waste with reasons for shrinkage analysis |
| **Alerts** | Automatic alerts when stock falls below minimum levels |
| **Reports** | Stock valuation, movement summaries, and consumption analysis |

---

## 🚚 Suppliers & Purchasing

```text
🏭 Supplier Created (company, contact, payment terms)
       ↓
🛒 Purchase Order Created (items, quantities, prices)
       ↓
📧 PO Sent to Supplier
       ↓
📦 Goods Received (inspected against PO)
       ↓
📊 Inventory Updated Automatically
```

- **Supplier Directory**: Store supplier details — company name, contact person, phone, email, address, and payment terms
- **Purchase Orders**: Create POs with line items, quantities, and expected prices. Track PO status through the approval and fulfillment cycle
- **Goods Receiving**: Record received goods against purchase orders, inspect quality, and accept or reject deliveries

---

## 🏗️ Asset Management

Track restaurant equipment and physical assets throughout their lifecycle.

```text
🏗️ Asset Registered (name, code, category, purchase info)
       ↓
📋 Maintenance Scheduled (preventive or reactive)
       ↓
🛡️ Warranty Tracked (provider, policy, expiry, claims)
       ↓
🔄 Transfers Logged (between locations or departments)
       ↓
📉 Depreciation Calculated (straight-line by category rate)
       ↓
📊 Asset Reports Generated
```

| Feature | Details |
| :--- | :--- |
| **Asset Registry** | Name, code, category, purchase date, purchase cost, location, condition, status |
| **Categories** | Organize assets (Kitchen Equipment, Furniture, Electronics, etc.) with depreciation rates |
| **Maintenance** | Schedule and track maintenance tasks with costs and completion dates |
| **Warranties** | Track warranty providers, policy numbers, coverage, expiry dates, and claim status |
| **Transfers** | Log asset movements between locations with transfer history |
| **Depreciation** | Calculate depreciation by category rate with current book value |
| **QR Tags** | Generate QR codes for physical asset identification |
| **Reports** | Asset valuation, depreciation schedules, maintenance cost analysis |

---

## 👥 Staff & HR Management

RMS includes a complete HR module covering the employee lifecycle from onboarding through payroll.

> **Important**: There are **two separate shift systems** in RMS. **Employee Work Shifts** (HR scheduling) are different from **Register/Cashier Shifts** (cash float management). They serve different purposes and should not be confused.

### Employee Lifecycle

```text
👤 EMPLOYEE ONBOARDED
       ↓
🆔 Profile Created (code, department, designation, salary)
       ↓
🔑 System Account Linked (optional — for POS access)
       ↓
📅 SHIFT ASSIGNED (from shift templates)
       ↓
⏰ CLOCK IN (with grace period check)
       ↓
   ⏳ Working...
       ↓
⏰ CLOCK OUT
       ↓
📊 HOURS CALCULATED (worked hours − break = net, overtime detected)
       ↓
💰 PAYROLL PERIOD CREATED
       ↓
🧮 SALARY CALCULATED (base + overtime + allowances − deductions)
       ↓
✅ PAYROLL APPROVED
       ↓
💵 PAYROLL DISBURSED
       ↓
🧾 PAYSLIP GENERATED
```

### HR Features

| Feature | Details |
| :--- | :--- |
| **Employee Profiles** | Full profile with photo, contact, department, designation, employment type, salary, bank info, and emergency contact |
| **Shift Templates** | Define reusable shifts with start/end time, break duration, grace period, and overtime threshold |
| **Shift Assignment** | Assign employees to specific shifts on specific dates |
| **Attendance** | Clock in/out with automatic late detection, grace period handling, and worked hours calculation |
| **Overtime** | Automatic overtime calculation when worked hours exceed the shift's threshold |
| **Salary History** | Full audit trail of salary changes with effective dates and reasons |
| **Salary Advances** | Track advance payments with repayment status |
| **Payroll Periods** | Create payroll periods (e.g., monthly), calculate salaries, approve, and disburse |
| **Payslips** | Generate itemized payslips showing base salary, overtime, allowances, deductions, and net pay |
| **Soft Deactivation** | Employees can be deactivated (resigned, terminated) while preserving all historical records |

### Permission Enforcement

- **Salary data** is restricted to roles with `hr.manage_salary` permission (Owner, HR Manager)
- **Payroll approval** requires `payroll.approve` permission
- **Cross-tenant isolation** — one restaurant cannot view another restaurant's employee or payroll data

---

## 💵 Register / Cash Float Shifts

Separate from employee work shifts, **register shifts** track the cashier's drawer throughout a business day.

```text
🔓 OPEN SHIFT
   │  Register name + Opening cash float
   ↓
💰 CASH SALES ────────────── Automatically tracked from payments
💳 CARD SALES ────────────── Automatically tracked from payments
📱 DIGITAL SALES ─────────── Automatically tracked from payments
   ↓
💵 CASH IN ───────────────── Manual cash additions (change, petty cash)
💸 CASH OUT ──────────────── Manual cash removals (bank deposit, supplies)
   ↓
🔄 REFUNDS ───────────────── Cash refunds tracked against the shift
   ↓
📊 EXPECTED CASH = Opening + Cash Sales + Cash In − Cash Out − Cash Refunds
   ↓
💰 ACTUAL CASH ───────────── Cashier counts the physical drawer
   ↓
📈 VARIANCE = Actual − Expected
   ↓
🔒 CLOSE SHIFT ───────────── Shift locked, variance recorded, audit trail
```

| Feature | Details |
| :--- | :--- |
| **Multiple Registers** | Support for multiple registers/counters per restaurant |
| **Automatic Sales Tracking** | Cash, card, and digital sales are automatically attributed to the active shift |
| **Cash Movements** | Log cash-in and cash-out events with reasons and amounts |
| **Denomination Counting** | Optional cash denomination breakdown at shift close |
| **Variance Calculation** | Server-calculated difference between expected and actual cash |
| **Shift Locking** | Closed shifts are immutable — no modifications after closure |
| **Concurrency Protection** | Only one shift can be open per register at a time (row-level locking) |
| **Audit History** | Complete shift history with all transactions and movements |

---

## 💰 Expenses & Operational P&L

```text
💵 Revenue (from payment_transactions)
   −
💸 Expenses (categorized operational costs)
   ═
📊 Operating Result
```

- **Expense Categories**: Rent, Utilities, Supplies, Maintenance, Marketing, Salaries, etc.
- **Expense Tracking**: Record expenses with date, category, amount, vendor, description, and receipt reference
- **Operational P&L**: The dashboard provides revenue and expense summaries for operational profit/loss visibility

> **Note**: This is **operational P&L functionality** — not a full double-entry accounting or ERP general ledger. For complete financial reporting, integrate with dedicated accounting software.

---

## 📅 Reservations

```text
👤 Customer Details (name, phone)
       ↓
📅 Date + ⏰ Time + 👥 Guest Count
       ↓
🪑 Table Assignment (optional)
       ↓
📋 Reservation Created
       ↓
   Status: Pending → Confirmed → Seated → Completed
                  → Cancelled / No-Show
```

Reservations allow restaurants to pre-book tables for future dates and times. Staff can manage reservations from the admin portal, track status changes, and plan table availability.

> **Note**: Reservations are a **basic implementation** — there is no automated SMS/email confirmation, no online booking widget for customers, and no integration with external reservation platforms.

---

## ☁️ Super Admin & SaaS Platform

The Super Admin portal (`super-admin/`) manages the entire multi-tenant platform.

```text
                    👑 SUPER ADMIN
                         │
          ┌──────────────┼──────────────┐
          │              │              │
     🏪 Tenants     💎 Plans     📊 Dashboard
          │              │              │
    ┌─────┴─────┐    ┌───┴───┐     KPI Metrics
    │           │    │       │     Active Tenants
 Onboarding  Manage  Limits  Pricing  Revenue
    │           │
 Pipeline    Suspend / Activate
    │        Password Reset
 Approve     Impersonate
    │
 Provision Tenant
```

### Super Admin Features

| Feature | What It Does |
| :--- | :--- |
| **Dashboard** | Platform-wide metrics — total tenants, active subscriptions, recent activity |
| **Onboarding Pipeline** | Public request form → PENDING → CONTACTED → APPROVED → CONVERTED (auto-provisions tenant) or REJECTED |
| **Manual Provisioning** | Create a new restaurant tenant with owner account, subscription, default tables, and categories — all in one atomic transaction |
| **Tenant Management** | View all restaurants, suspend/activate accounts, reset passwords, change usernames |
| **Impersonation** | Log into any tenant's admin portal to provide support — without knowing their password |
| **Subscription Plans** | Manage plan catalog — name, pricing, max tables, max staff, features |
| **Subscription Governance** | Upgrade/downgrade tenant plans with usage-limit validation |

### Onboarding Flow

```text
🌐 Public Website
   │
   └── Restaurant submits onboarding request form
              ↓
📋 Request appears in Super Admin pipeline (status: PENDING)
              ↓
☎️ Super Admin contacts restaurant (status: CONTACTED)
              ↓
   ┌──────────┴──────────┐
   │                     │
✅ APPROVE            ❌ REJECT
   │
🏪 Tenant provisioned automatically:
   ├── Restaurant record created
   ├── Owner admin_user account created
   ├── Subscription activated
   ├── Default tables + categories seeded
   └── Credentials delivered
```

---

## 🏢 Multi-Tenancy

Every restaurant in RMS is an **isolated tenant**. The same codebase and database serve all tenants, but each restaurant's data is completely separated.

```text
                    RMS SaaS Database
                         │
        ┌────────────────┼────────────────┐
        │                │                │
   🏪 Restaurant A   🏪 Restaurant B   🏪 Restaurant C
        │                │                │
   ├── Staff          ├── Staff         ├── Staff
   ├── Tables         ├── Tables        ├── Tables
   ├── Menu           ├── Menu          ├── Menu
   ├── Orders         ├── Orders        ├── Orders
   ├── Customers      ├── Customers     ├── Customers
   ├── Inventory      ├── Inventory     ├── Inventory
   ├── Employees      ├── Employees     ├── Employees
   └── Settings       └── Settings      └── Settings
```

**How isolation works:**

- Every database table has a `restaurant_id` column
- Every query is scoped to the authenticated user's `restaurant_id`
- Tenant context is resolved via `TenantContext.php` — from session, never from user input
- There is **no fallback tenant** — if a user has no assigned restaurant, login is blocked
- Suspended or expired tenants are blocked from accessing the admin portal
- Super Admin can impersonate any tenant for support purposes

**Subscription limits** (enforced per plan):

- Maximum number of tables
- Maximum number of staff accounts

---

## 👮 User Roles & Permissions

RMS uses a centralized **Role-Based Access Control (RBAC)** system with 9 roles and 100+ granular permissions.

| Role | Access Level |
| :--- | :--- |
| 👑 **SUPER_ADMIN** | Full platform governance + tenant impersonation |
| 🏪 **OWNER** | Full restaurant operations (all permissions) |
| 📊 **MANAGER** | Operations, orders, payments, inventory, assets, tables, menu, limited staff and HR |
| 👥 **HR_MANAGER** | Staff, HR, shifts, attendance, payroll, reports |
| 📒 **ACCOUNTANT** | Payroll, reports, payment views, order views, HR views |
| 💰 **CASHIER** | Orders (view/create/settle), payments (view/settle), tables (view), attendance clock |
| 👨‍🍳 **KITCHEN** | Orders (view/update), attendance clock |
| 🍽️ **WAITER** | Orders (view/create), tables (view), waiter calls, attendance clock |
| 📦 **INVENTORY_MANAGER** | Inventory, suppliers, purchase orders, recipes, asset views, attendance clock |

**Permission Groups**: `orders`, `payments`, `inventory`, `suppliers`, `purchase_orders`, `recipes`, `assets`, `tables`, `menu`, `staff`, `reports`, `settings`, `waiter_calls`, `notifications`, `hr`, `shifts`, `attendance`, `payroll`

Each group has action-level permissions (e.g., `orders.view`, `orders.create`, `payments.settle`, `hr.manage_salary`).

**Enforcement**: Permissions are checked server-side via `Auth::checkPermission()` and `AuthorizationService::requirePermissionApi()`. The sidebar menu is gated by role. API endpoints enforce permissions before executing operations.

---

## 🔐 Security Overview

| Control | Implementation |
| :---: | :--- |
| 🔐 | **Password Hashing** — bcrypt with `password_hash()` / `password_verify()` |
| 🛡️ | **CSRF Protection** — tokens on all state-changing POST requests |
| 🚦 | **Rate Limiting** — login: 5 attempts/5 min, order placement: 10/min |
| 🍪 | **Session Hardening** — HttpOnly, SameSite=Lax, Secure on HTTPS, regenerate on login, 7200s idle timeout |
| 🏢 | **Tenant Isolation** — every query scoped by `restaurant_id`, no fallback tenant |
| 👮 | **RBAC** — 9 roles, 100+ permissions, server-enforced on pages and APIs |
| 💳 | **Server-Side Pricing** — the browser never supplies payment totals |
| 🔒 | **Row Locking** — `SELECT FOR UPDATE` on order/payment transactions to prevent double-settlement |
| 🧾 | **Audit Trail** — `audit_logs` table with `Security::logAudit()` for sensitive operations |
| 📁 | **Upload Validation** — MIME allowlist, ≤2MB size limit, random filenames, SVG rejected |
| 🌐 | **Security Headers** — CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS |
| 📄 | **Protected Files** — `.htaccess` blocks access to `.env`, `.sql`, `.log`, and sensitive files |
| 🔑 | **Secret Management** — HMAC key from environment variable or auto-generated persistent secret |
| 🚫 | **Error Privacy** — production mode suppresses raw database errors from HTTP responses |

---

## 🏗️ Architecture

```text
┌──────────────────────────────────────────────────────┐
│                    BROWSER / CLIENT                   │
│                                                      │
│   Customer (QR Menu)    Admin Portal    Kitchen KDS   │
│   Vanilla JS            Vanilla JS     Vanilla JS    │
│   Tailwind CSS (CDN)    Tailwind CSS   Tailwind CSS  │
└────────────────────┬─────────────────────────────────┘
                     │  AJAX / Form POST
                     ▼
┌──────────────────────────────────────────────────────┐
│                  APACHE 2.4 + PHP 8.1+               │
│                                                      │
│   ┌─────────────────────────────────────────────┐    │
│   │  config.php (Bootstrap)                      │    │
│   │  ├── Environment (.env)                      │    │
│   │  ├── Database Connection                     │    │
│   │  ├── Schema Auto-Creation                    │    │
│   │  └── Helper Class Loading                    │    │
│   └─────────────────────────────────────────────┘    │
│                        │                              │
│   ┌────────────────────┼────────────────────┐        │
│   │                    │                    │        │
│   ▼                    ▼                    ▼        │
│  Auth              TenantContext        RBAC         │
│  (Session)         (restaurant_id)      (Permissions)│
│   │                    │                    │        │
│   └────────────────────┼────────────────────┘        │
│                        │                              │
│   ┌────────────────────┼────────────────────┐        │
│   │           SERVICE LAYER                  │        │
│   │                                          │        │
│   │  OrderService      BillingService        │        │
│   │  LoyaltyService    HrService             │        │
│   │  RegisterShiftService  Inventory         │        │
│   │  SubscriptionService   QRCodeService     │        │
│   │  CustomerSessionService                  │        │
│   └──────────────────────────────────────────┘        │
│                        │                              │
└────────────────────────┼──────────────────────────────┘
                         │  Prepared Statements (mysqli)
                         ▼
              ┌─────────────────────┐
              │   MySQL 8.0+        │
              │                     │
              │   55+ tables        │
              │   Tenant-scoped     │
              │   InnoDB + UTF8MB4  │
              └─────────────────────┘
```

**Key architectural decisions:**

- **No framework** — plain PHP for maximum hosting compatibility (XAMPP, cPanel, shared hosting)
- **No Composer** — zero dependency management; all code is self-contained
- **No build step** — edit PHP/JS/CSS and refresh the browser
- **Auto-provisioning schema** — `config.php` creates/upgrades all tables on first run
- **AJAX polling** — realtime updates via periodic JSON API calls (not WebSockets)
- **Tailwind via CDN** — no build pipeline for CSS, but requires internet access

---

## 📡 API Reference

All API endpoints live in `api/` and return JSON responses:

```json
// Success
{ "success": true, "message": "...", "data": { ... } }

// Error
{ "success": false, "message": "..." }
```

### Endpoint Map

| Group | Endpoint | Purpose | Auth |
| :--- | :--- | :--- | :--- |
| **Health** | `health.php` | Health check | None |
| **Customer** | `get-order-status.php` | Live order status | Session/Token |
| | `call-waiter.php` | Call waiter to table | Session/Token |
| **Menu** | `menu.php` | Menu payload | Tenant |
| | `menu-status.php` | Menu availability | Tenant |
| | `toggle-stock.php` | Toggle item availability | Staff |
| | `menu-stream.php` | Menu realtime stream | Staff |
| **Orders** | `orders.php` | Order CRUD | Staff |
| | `update-order.php` | Update order details | Staff |
| | `orders-stream.php` | Order stream + status updates + table status | Staff |
| **Kitchen** | `kitchen-stream.php` | Kitchen ticket stream | KDS |
| **Tables** | `tables-stream.php` | Table status stream | Staff |
| **Payments** | `table-payment.php` | Bill calculation + payment processing + refunds + split billing | Staff |
| | `payment-stream.php` | Payment activity stream | Staff |
| | `payment-settings.php` | Payment gateway config | Staff |
| **Inventory** | `inventory.php` | Inventory CRUD | Staff |
| | `inventory-stream.php` | Inventory data stream | Staff |
| **Assets** | `assets.php` | Asset CRUD | Staff |
| | `asset-stream.php` | Asset data stream | Staff |
| **Dashboard** | `dashboard-stream.php` | Operations KPIs + live orders + search | Staff |
| **Other** | `categories-stream.php` | Category data stream | Staff |
| | `landing-stream.php` | Landing page data | Staff |
| | `security-stream.php` | Security event stream | Staff |

> For detailed API documentation, see [`docs/API.md`](docs/API.md).

---

## 🗄️ Database Overview

RMS uses a single MySQL database with **55+ tables** organized across these domains. All tables are auto-created on first run — no manual SQL imports required for core functionality.

```text
                        DATABASE SCHEMA
                             │
     ┌───────────┬───────────┼───────────┬───────────┐
     │           │           │           │           │
  Identity    Menu/Orders  Financial   Operations  Platform
     │           │           │           │           │
 admin_users  categories   payment_*   inventory_*  restaurants
 user_sessions menu_items  customers   suppliers    subscriptions
              menu_addons  loyalty_*   purchase_*   subscription_plans
              tables       orders      goods_*      restaurant_requests
              dining_*     order_items recipes      notifications
              waiter_calls             recipe_items
              reservations             stock_audits
                                       inventory_waste
                                       assets + 6 related
                                       employees + 8 HR tables
                                       shifts (register)
                                       cash_movements
                                       expenses
                                       audit_logs
```

| Domain | Tables | Description |
| :--- | :---: | :--- |
| **Identity & Auth** | 2 | Staff accounts, sessions |
| **Menu** | 3 | Categories, items, add-ons |
| **Floor** | 3 | Tables, dining sessions, waiter calls |
| **Orders** | 2 | Orders, order items |
| **Payments** | 3 | Gateways, settings, transactions |
| **Customers & Loyalty** | 3 | Customers, loyalty transactions, loyalty settings |
| **Inventory** | 12 | Items, categories, units, suppliers, POs, goods receipts, transactions, recipes, waste, audits, alerts |
| **Assets** | 7 | Assets, categories, maintenance, transfers, depreciation, logs, warranties |
| **HR & Payroll** | 10 | Employees, shift templates, shifts, attendance, HR settings, salary history, advances, payroll periods, payrolls, HR audit logs |
| **Register Shifts** | 2 | Shifts, cash movements |
| **Content** | 1 | Landing page settings |
| **Audit** | 1 | Audit logs |
| **SaaS Platform** | 5 | Restaurants, plans, subscriptions, requests, notifications |
| **Other** | 2 | Reservations, expenses |

> For detailed schema documentation, see [`docs/DATABASE.md`](docs/DATABASE.md).

---

## 🚀 Installation

### Requirements

| Requirement | Version |
| :--- | :--- |
| PHP | 8.1 or higher |
| PHP Extensions | `mysqli`, `mbstring` |
| MySQL | 8.0+ or MariaDB equivalent |
| Apache | 2.4 with `mod_rewrite` and `mod_headers` |
| XAMPP | Works out of the box ✅ |

### Step-by-Step Setup

#### 1. Clone the Repository

```bash
git clone https://github.com/mohit282-cpu/Rms_SaaS.git
```

Place the project in your web root (e.g., `htdocs/Rms_SaaS` for XAMPP).

#### 2. Configure Environment

```bash
cp .env.example .env
```

Edit `.env` with your database credentials:

```env
APP_NAME="My Restaurant"
APP_ENV=local
APP_DEBUG=true
APP_URL="http://localhost/Rms_SaaS"

DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=qr_restaurant
DB_USERNAME=root
DB_PASSWORD=

JWT_SECRET=CHANGE_ME_TO_A_RANDOM_64_CHAR_STRING
SESSION_LIFETIME=7200
```

> ⚠️ **Change `JWT_SECRET`** to a random string in production. If left as the default, the system auto-generates a secure random secret on first boot and stores it in `storage/.app_secret`.

#### 3. Create the Database

Create an empty MySQL database:

```sql
CREATE DATABASE qr_restaurant CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

**That's it.** The schema is **auto-created** on first request. When you load any page, `config.php` detects missing tables and creates all 55+ tables automatically.

#### 4. Run Tenant Constraint Migration (Recommended)

For multi-tenant uniqueness constraints:

```bash
php database/migrate.php
```

This upgrades unique indexes to be tenant-scoped (e.g., `table_number` becomes unique per `restaurant_id`, not globally).

#### 5. Access the Application

#### 5. Access the Application

| Surface | URL | Authentication Identity |
| :--- | :--- | :--- |
| 🌐 Public Site / Landing | `http://localhost/Rms_SaaS/` | Public Access / QR Scanning |
| 🔑 Restaurant Admin Portal | `http://localhost/Rms_SaaS/admin/login.php` | Email Address + Password |
| 👑 Super Admin Portal | `http://localhost/Rms_SaaS/super-admin/login.php` | `sovryxrms29@gmail.com` + Password |
| 👨‍🍳 Kitchen Display (KDS) | `http://localhost/Rms_SaaS/kitchen-dashboard.php` | KDS Security PIN |

#### 6. First-Time Setup

1. Log in to the **Restaurant Admin Portal** (`admin/login.php`) using your **Email Address** and Password (default: `owner@rms-demo.com` or your provisioned email)
2. Complete the **Setup Wizard** if prompted — configure your restaurant name, create initial tables
3. Go to **Floor & Tables** → generate and print **QR codes** for your tables
4. Configure the **KDS password** in Settings (default: `kitchen123` — **change immediately**)

---

## 🌍 Production Deployment

### Production Environment Checklist

```text
✅ HTTPS configured (required for Secure cookies and HSTS)
✅ APP_ENV=production in .env
✅ APP_DEBUG=false in .env
✅ Strong random JWT_SECRET set
✅ Dedicated MySQL user (not root) with limited privileges
✅ KDS password changed from default
✅ storage/ and uploads/ directories writable by web server
✅ storage/.app_secret file has 0600 permissions
✅ .htaccess in place (blocks .env, .sql, .log files)
✅ PHP display_errors = Off (auto-configured in production mode)
✅ Database backup schedule configured
✅ error_log writing to storage/logs/
```

### Deployment Steps

1. **Upload** the project to a PHP 8.1+ host (cPanel, VPS, cloud)
2. **Verify** Apache `mod_rewrite` + `mod_headers` are enabled; `.htaccess` is included
3. **Create** the MySQL database and user with appropriate privileges
4. **Configure** `.env` with production values:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   JWT_SECRET=<64-character-random-string>
   DB_PASSWORD=<strong-password>
   ```
5. **Run** `php database/migrate.php` to apply tenant constraint upgrades
6. **Serve** over **HTTPS** — `Secure` cookies and HSTS require it
7. **Set** file permissions: `storage/` and `uploads/` writable; `storage/.app_secret` restricted (0600)
8. **Schedule** regular MySQL backups — there is no built-in backup tooling

### What RMS Does NOT Require

- No Composer
- No Node.js or npm
- No Docker or containers
- No build step
- No cron jobs (though scheduled backups are recommended)

---

## 📁 Project Structure

```text
Rms_SaaS/
│
├── 📄 .env.example              Environment template (copy to .env)
├── 📄 .htaccess                 Routing, security headers, file protection
├── 📄 config.php                Bootstrap: env, DB, schema auto-create, helpers
├── 📄 manifest.json             PWA manifest (installable, no offline support)
│
├── 🌐 PUBLIC PAGES
│   ├── index.php                Landing page + SaaS onboarding form
│   ├── menu.php                 QR digital menu
│   ├── cart.php                 Shopping cart
│   ├── checkout.php             Order checkout
│   ├── place-order.php          Server-side order creation
│   ├── order-success.php        Live order tracker + settlement
│   ├── receipt.php              Digital receipt
│   ├── privacy-policy.php       Privacy policy
│   └── terms-of-service.php     Terms of service
│
├── 👨‍🍳 KITCHEN
│   ├── kitchen-dashboard.php    KDS live ticket wall
│   └── kitchen-menu.php         Kitchen-side menu + stock toggles
│
├── 🏪 admin/                    Restaurant admin portal (40 pages)
│   ├── index.php                Operations dashboard
│   ├── tables.php               Floor & tables + POS billing drawer
│   ├── orders.php               Order management
│   ├── menu-items.php           Menu item management
│   ├── categories.php           Menu categories
│   ├── customers.php            Customer CRM
│   ├── staff.php                Staff + HR + attendance + payroll
│   ├── shifts.php               Register/cashier shift management
│   ├── inventory.php            Inventory dashboard
│   ├── assets.php               Asset management
│   ├── expenses.php             Expense tracking
│   ├── reservations.php         Table reservations
│   ├── settings.php             Restaurant settings
│   ├── setup-wizard.php         First-time setup
│   └── ...                      (+ suppliers, purchasing, recipes, reports)
│
├── 👑 super-admin/              SaaS platform governance
│   ├── index.php                Platform dashboard
│   ├── restaurants.php          Tenant management
│   ├── subscriptions.php        Plan & subscription management
│   ├── requests.php             Onboarding pipeline
│   └── create-restaurant.php    Manual tenant provisioning
│
├── 📡 api/                      JSON API endpoints (23 endpoints)
│   ├── table-payment.php        Billing + payments + refunds
│   ├── orders-stream.php        Order stream + status updates
│   ├── dashboard-stream.php     Operations KPI stream
│   ├── kitchen-stream.php       Kitchen ticket stream
│   ├── tables-stream.php        Table status stream
│   └── ...                      (+ inventory, assets, menu, payments)
│
├── ⚙️ helpers/                  Service classes (19 services)
│   ├── Auth.php                 Authentication & session management
│   ├── AuthorizationService.php Tenant + permission enforcement
│   ├── BillingService.php       Server-side bill calculation
│   ├── OrderService.php         Order state machine + transitions
│   ├── LoyaltyService.php       Points earning, redemption, ledger
│   ├── HrService.php            Employee, shift, attendance, payroll
│   ├── RegisterShiftService.php Cashier shift + cash reconciliation
│   ├── Inventory.php            Stock management
│   ├── TenantContext.php        Multi-tenant context resolution
│   ├── PermissionService.php    RBAC role → permission mapping
│   ├── SubscriptionService.php  Plan limits & subscription gating
│   └── ...                      (+ CSRF, Security, RateLimiter, QR)
│
├── 🗄️ database/
│   ├── migrate.php              CLI migration runner
│   └── migrations/              SQL migration files
│
├── 📁 app/                      Autoloader, DatabaseService, Logger
├── 📁 css/ js/ images/          Static assets
├── 📁 uploads/                  User-uploaded files
├── 📁 storage/                  Logs, secrets (gitignored)
├── 📁 resources/                Error page templates
└── 📁 docs/                     Technical documentation
```

---

## 📊 Feature Status Matrix

Verified against the current source code:

| Feature | Status | Notes |
| :--- | :---: | :--- |
| Multi-Tenant SaaS | 🟢 | Full tenant isolation with `restaurant_id` scoping |
| Super Admin Platform | 🟢 | Tenant management, plans, onboarding, impersonation |
| Subscription Plans | 🟢 | 4 plans with table/staff limits, status enforcement |
| Restaurant Admin Portal | 🟢 | 40 pages covering full operations |
| Operations Dashboard | 🟢 | Real-time KPIs, live orders, activity feed |
| Floor & Tables | 🟢 | CRUD, zones, QR codes, full status lifecycle |
| QR Customer Ordering | 🟢 | Scan → menu → cart → checkout → tracking |
| Order Management | 🟢 | State machine with row locking, inventory deduction |
| Kitchen Display (KDS) | 🟢 | Live ticket wall, status buttons, 2s polling |
| POS & Billing | 🟢 | In-drawer billing, multi-order aggregation, server-authoritative |
| Cash Payments | 🟢 | With change calculation and server validation |
| Card / Digital Payments | 🟡 | Manual cashier confirmation only — no live gateway API |
| Refunds | 🟢 | Full/partial with inventory restock and loyalty reversal |
| Split Billing | 🟢 | Server-enforced split total ≤ bill total |
| NCR / Complimentary | 🟢 | With authorization logging |
| Receipt Generation | 🟢 | Digital receipts after payment |
| Customer CRM | 🟢 | Phone lookup, profiles, visit history |
| Loyalty Program | 🟢 | Earning, redemption, configurable rules, ledger |
| Inventory Management | 🟢 | Items, stock tracking, movements, audits, waste, alerts |
| Suppliers | 🟢 | Supplier directory with contact management |
| Purchase Orders | 🟢 | PO creation, goods receiving, PO status tracking |
| Recipes | 🟢 | Menu-to-inventory ingredient mapping |
| Asset Management | 🟢 | Full lifecycle — maintenance, warranties, depreciation, QR |
| Expense Tracking | 🟢 | Categorized expenses |
| Staff Management & RBAC | 🟢 | 9 roles, 100+ permissions, server-enforced |
| HR / Employees | 🟢 | Profiles, departments, salary management |
| Shift Scheduling (HR) | 🟢 | Templates, assignment, overtime threshold |
| Attendance | 🟢 | Clock in/out, grace periods, late detection, overtime |
| Payroll | 🟢 | Period management, calculation, approval, disbursement, payslips |
| Register / Cash Float | 🟢 | Open/close shifts, cash reconciliation, variance, locking |
| Reservations | 🟡 | Basic date/time/guest booking — no automated notifications |
| Landing Page Customizer | 🟢 | Editable public-facing restaurant page |
| PWA | 🟡 | Installable via manifest.json — no offline/service worker |
| Online Payment Gateways | ⚠️ | Configuration UI exists but no live API integration |
| Automated Notifications | 🔴 | No SMS/email notifications to customers |
| Hotel PMS | 🔴 | Not implemented — restaurant/F&B only |
| Multi-Language | 🔴 | Not implemented — English only |

**Legend:** 🟢 Implemented · 🟡 Partial · ⚠️ Limited / Manual · 🔴 Not Implemented

---

## ⚠️ Current Limitations

| # | Limitation | Details |
| :---: | :--- | :--- |
| 1 | **No live payment gateway integration** | Card and digital payments are manually confirmed by the cashier. No API calls, webhooks, or automated payment verification with eSewa, Khalti, Stripe, or any provider. |
| 2 | **AJAX polling, not WebSockets** | Real-time updates use periodic AJAX calls (2–3.5s intervals). Works well for small-medium operations but not designed for high-concurrency environments. |
| 3 | **Tailwind CSS via CDN** | Styling requires an active internet connection. No local CSS bundle. |
| 4 | **No offline PWA support** | The app is installable (manifest.json) but has no service worker. It does not work offline. |
| 5 | **No automated notifications** | No SMS, email, or push notifications to customers or staff. |
| 6 | **No full hotel PMS** | No room management, guest folios, housekeeping, or accommodation billing. |
| 7 | **English only** | No multi-language / i18n support. |
| 8 | **Timezone hardcoded** | System timezone is set to `Asia/Kathmandu`. Other timezones require a code change. |
| 9 | **No CI/CD pipeline** | Test suites exist as CLI scripts but there is no automated CI/CD integration. |
| 10 | **No LICENSE file** | No open-source license is specified. Assume all rights reserved until a license is added. |

---

## 🗺️ Roadmap

### Currently Implemented ✅

- Complete restaurant POS workflow (order → kitchen → bill → payment)
- Multi-tenant SaaS with Super Admin governance
- QR-based customer self-ordering
- Full inventory, asset, and HR management
- Register shift and cash reconciliation
- Customer CRM with loyalty program

### Potential Future Enhancements 🔮

These are areas the platform could grow into. They are **not currently implemented**:

- 🌐 **Live Payment Gateway Integration** — eSewa, Khalti, Stripe, Razorpay with webhooks
- 🏨 **Hotel PMS Module** — Room management, guest folios, housekeeping
- 📱 **Native Mobile Apps** — iOS and Android apps for staff and customers
- 🌍 **Multi-Language Support** — i18n for menus, UI, and receipts
- 📧 **Automated Notifications** — SMS/email for order confirmations, reservations, loyalty
- 📊 **Advanced Analytics** — Trend analysis, forecasting, customer segmentation
- 🔄 **WebSocket Realtime** — Replace AJAX polling with persistent connections
- 💰 **Accounting Integration** — Export to QuickBooks, Xero, or Tally

---

## 🤝 Support & Contributing

### 🐛 Reporting Issues

Open a [GitHub Issue](https://github.com/mohit282-cpu/Rms_SaaS/issues) for bugs and feature requests. Include:

- Steps to reproduce
- Expected vs actual behavior
- Browser and PHP version
- Error messages (from `storage/logs/`, not from browser)

### 🔒 Security Disclosures

**Do not open public issues for security vulnerabilities.** Report security findings privately to the repository maintainer.

### 💻 Development

No build tools are required. To develop:

1. Set up a local XAMPP environment
2. Configure `.env` with `APP_ENV=local` and `APP_DEBUG=true`
3. Edit PHP/JS/CSS files directly — refresh the browser to see changes
4. Use prepared statements for all database queries
5. Scope all queries by `restaurant_id` (tenant context)
6. Add CSRF tokens to all POST forms

```bash
# PHP syntax check
php -l <file>

# Run test suites (CLI only)
php docs/archive/tests/e2e_restaurant_os_test.php
php docs/archive/tests/register_shift_test.php
php docs/archive/tests/hr_management_test.php
php docs/archive/tests/customer_qr_checkout_test.php
php docs/archive/tests/security/qa_security_audit_suite.php
```

---

## 📜 License

**Not specified.** The repository does not contain a `LICENSE` file. Until a license is added, all rights are reserved by the repository owner.

---

<p align="center">
  <strong>Built for real restaurants. Powered by simplicity.</strong>
  <br><br>
  <img src="https://img.shields.io/badge/Made_with-PHP-777BB4?style=flat-square&logo=php" alt="Made with PHP">
  <img src="https://img.shields.io/badge/Database-MySQL-4479A1?style=flat-square&logo=mysql" alt="MySQL">
  <img src="https://img.shields.io/badge/Server-Apache-D22128?style=flat-square&logo=apache" alt="Apache">
</p>
