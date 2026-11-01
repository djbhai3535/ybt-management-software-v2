# PHASE 1 AUDIT REPORT: YBT MANAGEMENT SOFTWARE
## Transformation into Multi-Tenant Mobile Shop Management & Khata SaaS

**Date:** November 1, 2026  
**Project Path:** `C:\CRM-Software`  
**Current Branch:** `phase1/mobile-shop-saas-audit` (branched from `phase4-3/native-windows-installer`, commit `cacf9fb`)  
**Safety Backup Created:** `C:\CRM-Software_backup_phase1_audit_pre`  
**Status:** Read-Only Audit Complete. No new features implemented. Ready for approval.

---

## Executive Summary

YBT Management Software is evolving into a commercial, multi-tenant **Mobile Shop Management & Khata SaaS** application with:
1. Self-service shopkeeper registration (automatic tenant creation as Owner).
2. Complete shop management dashboard (Today's Sales, Cash Collection, Udhaar/Khata Outstanding, Low Stock).
3. Role-based employee access (Owner, Manager, Staff with configurable permissions).
4. Customer Ledger (Khata) providing **"You Gave" (Debit/Udhaar)** and **"You Got" (Credit/Jama)** running balance streams integrated with Sales, Inventory, Invoices, and Payments.
5. Strict tenant isolation (Fail-closed cryptographic boundaries).
6. Offline-first Windows desktop application powered by bundled portable PHP 8.3 and SQLite, with two-way Cloud REST API synchronization.
7. Architecture prepared for native Android client integration.

During this Phase 1 audit, we conducted a 100% empirical investigation of the codebase, SQLite migrations, tenant architecture, and runtime pages. We successfully reproduced the exact known error in the Manager/Staff section (`no such table: staff_invitations`), identified 12 additional missing tables and column divergences in SQLite, and established a detailed map of reusable modules, technical risks, and the step-by-step implementation sequence.

---

## 1. Current Architecture

### 1.1 Dual-Engine Hybrid Runtime
The application employs an enterprise dual-engine architecture:
- **Cloud SaaS Mode (Online):** PHP 8.3 running on Apache/Nginx with MySQL 8.0/MariaDB, multi-tenant schema with `businesses.tenant_uuid`, and REST API v1 (`/api/v1/`).
- **Desktop Mode (Offline):** Native Tauri v2 Windows shell (`ybt-management-software.exe`) supervising a bundled portable PHP 8.3.36 runtime on dynamic loopback ports (`127.0.0.1:{port}`), using an embedded SQLite 3 database in WAL mode stored at `%APPDATA%\YBT Management Software\storage\desktop\ybt_local.sqlite`.
- **Packaging:** Nullsoft Scriptable Install System (NSIS) setup installer (`YBT Management Software_1.0.0_x64-setup.exe`, ~19.5 MiB) and WiX MSI installer (~28.4 MiB).

```mermaid
flowchart TD
    subgraph Client_Tier [Client Tier]
        TauriShell[Tauri v2 Native Windows Shell]
        WebView[WebView2 Chromium Engine]
        FutureAndroid[Future Android Mobile App]
    end

    subgraph Desktop_Runtime [Local Desktop Runtime (No XAMPP)]
        PHP_MicroEngine[Bundled Portable PHP 8.3 Engine]
        SqliteClient[SqliteClient & WAL Database]
        SyncEngine[Offline SyncEngine & Operation Queue]
        LocalSession[Persistent First-Party Session Storage]
    end

    subgraph Cloud_Tier [Cloud Multi-Tenant SaaS Tier]
        CloudAPI[Cloud REST API v1 /api/v1/*]
        MySQL_DB[(Cloud Multi-Tenant MySQL 8.0)]
        TenantBoundary[TenantContext Fail-Closed Scoping]
    end

    TauriShell -->|Spawns & Monitors| PHP_MicroEngine
    WebView -->|First-Party HTTP Navigation| PHP_MicroEngine
    PHP_MicroEngine --> SqliteClient
    PHP_MicroEngine --> LocalSession
    SyncEngine -->|Two-Way REST Sync /api/v1/sync/*| CloudAPI
    FutureAndroid -.->|JSON REST API| CloudAPI
    CloudAPI --> TenantBoundary
    TenantBoundary --> MySQL_DB
```

### 1.2 Tenant Isolation Architecture
- **Cryptographic Boundary:** `TenantContext` (`includes/tenant-context.php`) implements fail-closed enforcement. Any query accessing tenant tables without calling `TenantContext::set()` throws a `TenancyException` immediately.
- **Tenant Scope:** Every business entity has a globally unique `tenant_uuid` and `business_id`.
- **Database Driver Singleton:** `Database::getInstance()` (`includes/db.php`) automatically checks MySQL connectivity on port 3306. If MySQL is unavailable (desktop mode / offline), it seamlessly falls back to local SQLite with zero downtime and initializes custom SQLite functions (`CURDATE()`, `NOW()`).

---

## 2. Existing Database Schema

### 2.1 MySQL Cloud Database (`database/schema.sql` + `database/migrations/`)
The MySQL schema contains 22 production tables:
1. `businesses` — Multi-tenant shop registry (`id`, `tenant_uuid`, `name`, `status`, `onboarding_step`).
2. `users` — Shop owners, managers, staff (`id`, `business_id`, `role_id`, `name`, `email`, `password`, `is_owner`, `is_active`, `verification_status`).
3. `roles` — Tenant-scoped and system roles (`owner`, `manager`, `staff`).
4. `permissions` — Granular capability keys (`dashboard.view`, `customers.*`, `invoices.*`, etc.).
5. `role_permissions` — Many-to-many permission matrix.
6. `email_verifications` — Secure email activation tokens.
7. `password_resets` — Secure password recovery tokens.
8. `staff_invitations` — Employee onboarding and invitation tokens.
9. `customers` — Shop customer profiles (`id`, `uuid`, `business_id`, `name`, `phone`, `gstin`, `company_name`).
10. `products` — Inventory items (`id`, `uuid`, `business_id`, `name`, `sku`, `barcode`, `purchase_price`, `selling_price`, `stock_quantity`, `unit`).
11. `invoices` — Sales invoices (`id`, `uuid`, `business_id`, `customer_id`, `invoice_number`, `grand_total`, `amount_paid`, `outstanding`, `status`, `due_date`).
12. `invoice_items` — Line items per invoice.
13. `payments` — Customer collections (`id`, `uuid`, `business_id`, `customer_id`, `payment_number`, `amount`, `method`, `reference`, `status`).
14. `payment_allocations` — Allocations of payments against invoices.
15. `expenses` — Operating expenses (`id`, `uuid`, `business_id`, `category_id`, `amount`, `method`, `status`).
16. `expense_categories` — Expense classification categories.
17. `quotations` & `quotation_items` — Formal price quotations and estimates.
18. `tasks` — Daily action items and team assignments.
19. `leads` — Sales leads and CRM pipeline.
20. `activity_logs` — Comprehensive audit trail.
21. `backup_records` — Database backup history.
22. `ai_provider_configs` — Local/cloud AI assistant configuration.

### 2.2 SQLite Local Database (`desktop/database/migrations/`)
The SQLite schema is currently incomplete compared to MySQL.
- **Migration 001 (`001_sqlite_initial_schema.sql`):** Created 10 tables:
  `local_sessions`, `businesses`, `customers`, `products`, `invoices`, `payments`, `expenses`, `pending_operations`, `sync_metadata`, `sync_tombstones`.
- **Migration 002 (`002_sqlite_desktop_sync_compat.sql`):** Added compatibility columns:
  `invoices` (`amount_paid`, `outstanding`, `status`, `due_date`), `customers` (`company_name`), `expenses` (`status`), and created `leads`, `tasks`, `activity_logs`, `users`.

---

## 3. Missing Tables & Migration Problems

### 3.1 Empirical Root Cause of Known Error
When an Owner or Manager visits `managers.php`, the following query fails:
```php
// services/staff-service.php line 219
SELECT si.*, r.name as role_name 
FROM staff_invitations si 
JOIN roles r ON si.role_id = r.id 
WHERE si.business_id = ?
```
**Exact Error Output:**
```text
FATAL: SQLSTATE[HY000]: General error: 1 no such table: staff_invitations in db.php:110
```
`staff_invitations` was added to MySQL in `database/migrations/002_phase3_auth_and_onboarding.sql`, but was **never added** to SQLite migrations in `desktop/database/migrations/`.

### 3.2 Complete Inventory of Missing SQLite Tables
Our diagnostic scan revealed that 12 tables present in MySQL are missing from SQLite:

| # | Missing Table | Required By Page / Service | Impact if Missing |
|---|---|---|---|
| 1 | `staff_invitations` | `managers.php`, `accept-invitation.php`, `StaffService` | **Current Known Error:** Crash on opening Manager/Staff section |
| 2 | `roles` | `managers.php`, `profile.php`, `register.php`, `Auth` | Crash when fetching employee role names or permissions |
| 3 | `permissions` | `managers.php`, `Permissions` | Crash when displaying permission matrix to Shop Owner |
| 4 | `role_permissions` | `managers.php`, `Permissions` | Crash when Owner saves or updates employee permissions |
| 5 | `payment_allocations` | `payments.php`, `customer-view.php`, `PaymentService` | Crash when viewing payments list or recording invoice payments |
| 6 | `quotations` | `quotations.php`, `customer-view.php` | Crash on viewing quotations and customer history tab |
| 7 | `quotation_items` | `quotations.php`, `quotation-create.php` | Crash on creating or displaying formal price quotations |
| 8 | `expense_categories` | `expenses.php` | Crash when loading or logging operating expenses |
| 9 | `invoice_items` | `invoice-view.php`, `invoice-create.php` | Invoice items cannot be displayed or printed offline |
| 10 | `backup_records` | `settings.php` | Crash when opening Settings page backup tab |
| 11 | `email_verifications` | `verify-email.php`, `register.php` | Crash when verifying email tokens |
| 12 | `password_resets` | `forgot-password.php`, `reset-password.php` | Crash when initiating password recovery |

### 3.3 Column Divergences Between MySQL and SQLite
In addition to missing tables, several existing SQLite tables differ in column names:
- **`payments` table:**
  - MySQL has: `payment_number`, `method`, `reference`, `status`, `notes`, `created_by`.
  - SQLite has: `payment_method`, `transaction_reference` (missing `payment_number`, `notes`, `created_by`).
- **`customers` table:**
  - SQLite is missing: `billing_address`, `billing_city`, `billing_state`, `billing_pincode`, `shipping_address`, `company_name` (added in 002).
- **`invoices` table:**
  - SQLite is missing: `place_of_supply`, `cgst_total`, `sgst_total`, `igst_total`, `tax_total`, `terms`.
- **`tasks` table:**
  - SQLite is missing: `assigned_to` column (causes crash in `tasks.php`).
- **`businesses` table:**
  - SQLite is missing: `email`, `whatsapp`, `status`, `onboarding_step`.

### 3.4 Migration Execution Flaws
1. **Desktop Migration Isolation:** Migrations for Cloud MySQL (`database/migrations/`) and Desktop SQLite (`desktop/database/migrations/`) were maintained separately without a schema synchronization check, causing SQLite to lag behind.
2. **Fresh Installation vs Existing Installation:**
   - On a fresh installation, `SqliteClient::runMigrations()` runs all migrations sequentially and seeds demo accounts in `%APPDATA%`.
   - On existing installations where the SQLite database already exists, newly added migrations run via `SqliteClient::runMigrations()`, but any `ALTER TABLE` statements will crash if not wrapped with column-existence checks (which we resolved via statement-by-statement execution in `SqliteClient.php`).

---

## 4. Existing Role & Tenant Architecture

### 4.1 Shopkeeper Registration & Onboarding
- **`register.php`:**
  - Creates a new `businesses` record with a globally unique `tenant_uuid` and `status = 'pending_verification'`.
  - Automatically creates 3 tenant-scoped roles:
    1. **Shop Owner (`owner`):** Full administrative, financial, and permission control.
    2. **Shop Manager (`manager`):** Daily billing, customer management, inventory operations.
    3. **Shop Staff (`staff`):** Point-of-sale, customer queries, and repair intake.
  - Automatically creates the registering user with `is_owner = 1` and assigns them the `Shop Owner` role.
  - Generates email verification token.
- **`onboarding.php`:**
  - 3-step wizard:
    1. Basic Shop Profile (Name, Phone, Address, Logo).
    2. Business Preferences (Currency, Invoice Prefix, GSTIN/Tax ID).
    3. Initial Inventory / Opening Balance.
  - Successfully updates `businesses` and marks `onboarding_completed = 1`.

### 4.2 Role & Permission Matrix
- **Owner Privileges:** Full access to all modules, financial metrics, profit margins, manager invitation, and permission matrix configuration.
- **Manager Privileges:** Configurable by Owner in `managers.php`. Defaults to viewing dashboard, customers, products, invoices, payments, and expenses.
- **Staff Privileges:** Restricted point-of-sale operations, creating invoices/sales, accepting payments, and viewing customer balances. Sensitive executive reports and manager invitation are hidden.

---

## 5. Existing Working vs Broken Features (Empirical Audit)

Our automated diagnostic execution across all 26 application pages on the SQLite runtime produced the following definitive breakdown:

| Module / Page | Status | Error Details (if broken) | Mobile Shop SaaS Reusability |
|---|---|---|---|
| `dashboard.php` | **WORKING (OK)** | None. Clean KPI render. | **100% Reusable.** Core shop metrics (Today's Sales, Cash Inflows, Receivables). |
| `customers.php` | **WORKING (OK)** | None. Lists customers with balances. | **100% Reusable.** Customer list and search. |
| `customer-view.php` | **BROKEN** | `no such table: quotations` | **100% Reusable** after fixing table. Primary home for **Customer Khata Ledger**. |
| `products.php` | **WORKING (OK)** | None. Lists stock, price, SKU, barcode. | **100% Reusable.** Mobile phones, accessories, repair parts inventory. |
| `invoices.php` | **WORKING (OK)** | None. Clean invoice history table. | **100% Reusable.** Sales invoices and credit bills. |
| `invoice-create.php` | **BROKEN** | `no such column: billing_city` | **100% Reusable** after adding column. POS billing and "You Gave" credit generation. |
| `invoice-view.php` | **BROKEN** | `no such column: b.email` | **100% Reusable** after adding column. Printable / shareable GST invoice. |
| `payments.php` | **BROKEN** | `no such table: payment_allocations` | **100% Reusable** after adding table. Cash/UPI recording & "You Got" Khata entries. |
| `expenses.php` | **BROKEN** | `no such table: expense_categories` | **100% Reusable** after adding table. Shop rent, electricity, tea, staff salary expenses. |
| `leads.php` | **WORKING (OK)** | None. Pipeline stages render cleanly. | **Reusable** for mobile inquiry tracking & device repair status. |
| `quotations.php` | **BROKEN** | `no such table: quotations` | **Reusable** for formal enterprise/bulk device repair estimates. |
| `quotation-create.php` | **BROKEN** | `no such column: status` | **Reusable** after adding column. |
| `tasks.php` | **BROKEN** | `no such column: t.assigned_to` | **Reusable** for staff task reminders (e.g. "Order battery for Customer X"). |
| `reports.php` | **BROKEN** | `no such column: place_of_supply` | **100% Reusable** for daily cash reports, P&L, and GST tax filings. |
| `managers.php` | **BROKEN** | `no such table: staff_invitations` | **100% Reusable** for Owner managing Staff & permissions. |
| `settings.php` | **BROKEN** | `no such table: backup_records` | **100% Reusable** for Shop Profile & local database backup. |
| `profile.php` | **BROKEN** | `no such table: roles` | **Reusable** for updating shopkeeper password and profile. |
| `ai-assistant.php` | **BROKEN** | `no such table: ai_provider_configs` | **Optional / Secondary.** AI assistant advisory. |
| `onboarding.php` | **WORKING (OK)** | None. Step wizard renders cleanly. | **100% Reusable.** First-time shop setup. |
| `register.php` | **WORKING (OK)** | Form renders cleanly. | **100% Reusable.** New shopkeeper signup portal. |
| `login.php` | **WORKING (OK)** | Verified CSRF and session auth. | **100% Reusable.** Multi-tenant login portal. |
| `forgot-password.php` | **WORKING (OK)** | Form renders cleanly. | **Reusable** for cloud password recovery. |
| `resend-verification.php`| **WORKING (OK)** | Form renders cleanly. | **Reusable** for cloud email resend. |
| `reset-password.php` | **WORKING (OK)** | Token form renders cleanly. | **Reusable** for password reset completion. |
| `verify-email.php` | **BROKEN** | `no such table: email_verifications` | **Reusable** in cloud mode; bypass in local offline mode. |
| `accept-invitation.php` | **WORKING (OK)** | Form renders cleanly. | **100% Reusable** for staff member onboarding. |

---

## 6. Features That Can Be Reused for Mobile Shop & Khata SaaS

The existing codebase contains a massive amount of high-quality, reusable business logic:

1. **Authentication & Session Security:**
   - Dual-engine auth (`includes/auth.php`) supports both Cloud MySQL and Desktop SQLite.
   - Robust CSRF protection (`includes/csrf.php`) with persistent first-party session cookies.
   - Bcrypt password hashing and brute-force lockout safeguards.

2. **Multi-Tenant Foundation:**
   - Cryptographic `TenantContext` ensures strict zero-leakage isolation.
   - Each registration creates an independent tenant with its own roles and permissions.

3. **Shop Inventory & Barcode Support:**
   - `products.php` already tracks SKU, Barcode, Selling Price, Cost Price, and Stock Quantity.
   - Ideal for mobile shops to track phones, screen protectors, cables, chargers, and parts.

4. **Invoicing & GST Tax Calculation:**
   - `invoice-create.php` and `services/invoice-service.php` calculate CGST, SGST, IGST, discounts, and generate formal tax invoices.
   - Automatically computes receivables and payment status.

5. **Customer Ledger ("You Gave" / "You Got" Khata):**
   - The foundation is already present:
     - **"You Gave" (Debit / Udhaar):** Created whenever a credit invoice or device sale is recorded.
     - **"You Got" (Credit / Jama):** Created whenever a payment (Cash, UPI, Bank) is logged.
   - What needs to be added is simply a unified **Customer Khata Ledger table/view** (`customer_ledger` or integrated view in `customer-view.php`) that streams both columns chronologically with a live running balance.

6. **Two-Way Offline Synchronization Engine:**
   - `desktop/src/SyncEngine.php` already implements:
     - Operation queuing (`pending_operations`).
     - Tombstone tracking for deleted entities (`sync_tombstones`).
     - Push and Pull mechanisms against `/api/v1/sync/push` and `/api/v1/sync/pull`.
     - Conflict resolution using Last-Write-Wins (LWW) timestamp comparisons.

7. **Native Windows Desktop Runtime:**
   - Tauri v2 architecture with bundled portable PHP 8.3 and embedded SQLite.
   - Complete installer pipeline generating certified NSIS `.exe` and WiX `.msi` packages.
   - Clean background process supervision with zero orphan processes.

---

## 7. Risks & Technical Dependencies

1. **Schema Divergence Risk:**
   - **Risk:** Maintaining different schemas for MySQL and SQLite causes runtime crashes when code queries tables or columns that only exist in one database.
   - **Mitigation:** Unify all SQLite schema migrations into a single, comprehensive schema mirror (`003_sqlite_complete_saas_schema.sql`) that covers all 22 MySQL tables and identical column definitions.

2. **Offline Email / Invitation Dependency:**
   - **Risk:** Staff invitations currently attempt to send emails via SMTP (`MailerService`). In offline desktop mode, an internet connection and SMTP server are not available.
   - **Mitigation:** In desktop/offline mode, when an Owner invites a staff member or manager, display an instant copyable activation link or local pin code in addition to queuing an email for when cloud sync is online.

3. **Offline Self-Registration:**
   - **Risk:** `register.php` in cloud mode requires email verification before activation. If a shopkeeper installs the desktop application offline and wants to create their shop immediately, they cannot receive an email.
   - **Mitigation:** Implement offline-first auto-activation: when registering in desktop offline mode, immediately activate the local tenant, create the Owner session in `local_sessions`, and queue the registration for cloud synchronization.

4. **Customer Khata Ledger Financial Integrity:**
   - **Risk:** Discrepancies between Invoice grand totals, partial payments, and ledger running balances.
   - **Mitigation:** Enforce atomic database transactions (`beginTransaction` / `commit`) whenever an invoice or payment is logged, writing the ledger entry simultaneously.

---

## 8. Recommended Implementation Sequence

To transform YBT Management Software into the finalized Mobile Shop Management & Khata SaaS safely without regressions, we recommend the following 5-phase sequence:

### Phase 2: Schema Unification & SQLite Mirroring
- Create `desktop/database/migrations/003_sqlite_complete_saas_schema.sql` adding all missing tables:
  `staff_invitations`, `roles`, `permissions`, `role_permissions`, `payment_allocations`, `invoice_items`, `quotations`, `quotation_items`, `expense_categories`, `backup_records`, `email_verifications`, `password_resets`.
- Align all divergent columns (`payments.payment_number`, `customers.billing_city`, `invoices.place_of_supply`, `tasks.assigned_to`, `businesses.email`).
- Verify that every application page renders with HTTP 200 on SQLite.
- Fix the Manager/Staff section error completely.

### Phase 3: Mobile Shopkeeper Self-Registration & Onboarding
- Enhance `register.php` with offline-first support:
  - If online: sends email verification.
  - If offline: instantly activates shop tenant locally, creates Owner session, and queues cloud registration sync.
- Enhance `onboarding.php` with mobile-shop specific fields (Shop GSTIN, Mobile Brands Dealt With, Repair Services offered).

### Phase 4: Customer Ledger ("You Gave" / "You Got" Khata)
- Create `customer_ledger` schema in both MySQL and SQLite:
  - Fields: `id`, `uuid`, `business_id`, `customer_id`, `transaction_type` (`gave_udhaar` vs `got_jama`), `reference_type` (`invoice`, `payment`, `opening_balance`, `manual_khata`), `reference_id`, `amount`, `running_balance`, `notes`, `entry_date`, `created_by`, `sync_status`.
- Build Khata interface in `customer-view.php` and dedicated `khata.php`:
  - Chronological transaction list showing "You Gave" (Red/Debit) and "You Got" (Green/Credit).
  - Prominent Running Balance Banner (e.g., "Customer Owes ₹4,500" or "Settled").
  - Quick Action Buttons: "+ You Gave (Udhaar)" and "+ You Got (Jama)".
  - WhatsApp statement generation and PDF printable Khata slip.
- Seamlessly wire Invoices (creates "You Gave") and Payments (creates "You Got") into the ledger.

### Phase 5: Manager & Staff Permissions & Desktop Rebuild
- Enable Owner to create Shop Managers and Staff with custom permissions in `managers.php`.
- Support offline invitation generation (copyable local activation code).
- Update `SyncEngine.php` to push/pull ledger operations and staff accounts.
- Rebuild the native Tauri desktop application and NSIS/MSI installers.
- Run complete master regression test suite with XAMPP completely stopped.

---

## Approval Request

The read-only audit is complete. No code modifications or database mutations have been made.

Please review this audit report. Once approved, we will proceed to **Phase 2: Schema Unification & SQLite Mirroring** to eliminate the `staff_invitations` error and align all database tables.
