# MISSING FEATURES & ERRORS AUDIT
## YBT Management Software — Forensic Gap Analysis

**Date:** November 2, 2026  
**Project Path:** `C:\CRM-Software`  
**Current Git Branch:** `stage4/invoice-pdf-thermal-whatsapp`  
**Latest Git Commit:** `1630404`  

---

### 1. Existing Errors & Root Cause Analysis

#### Error 1: Desktop App Reverted to Outdated October 31 Build
* **Symptom:** The user installs or runs `YBT Management Software.lnk` on Windows, but the UI shows outdated pages, missing features (no Super Admin, no Khata ledger view, no thermal printing, no WhatsApp button), and appears unchanged.
* **Root Cause:** The NSIS setup installer `desktop\src-tauri\target\release\bundle\nsis\YBT Management Software_1.0.0_x64-setup.exe` was compiled on November 1 at 9:08 AM. Running this installer unpacks its embedded October 31st resource files directly into `%LOCALAPPDATA%\YBT Management Software\`, overwriting any newer PHP scripts with legacy versions.
* **Impact:** Critical. The user cannot see any updates developed after November 1, 9:08 AM in the installed desktop app.
* **Resolution Required:** After updating the source codebase, update the installed desktop directory and rebuild the Tauri release bundle so the installer packages the latest code.

---

#### Error 2: `status` Column Missing in `users` Table During SQL Queries
* **Symptom:** Direct queries executing `SELECT ... status FROM users` trigger fatal PDO exception: `SQLSTATE[HY000]: General error: 1 no such column: status`.
* **Root Cause:** The `users` table uses `is_active` (INTEGER: 1/0) and `verification_status` (TEXT: 'verified'/'pending'), whereas some admin queries and audit scripts expect a unified `status` column ('active', 'suspended', 'pending').
* **Impact:** Moderate. Can cause fatal errors when querying user account status from the Admin Panel.
* **Resolution Required:** Add a safe schema migration adding a virtual or physical `status` column to `users` with backward compatibility for `is_active`.

---

#### Error 3: Confusing Quick Demo Accounts on Public Login Page
* **Symptom:** `login.php` prominently renders "Quick Demo Access" with one-click buttons for "Owner" (`owner@ybt.com`) and "Manager" (`manager@ybt.com`).
* **Root Cause:** Hardcoded HTML and JavaScript (`quickLogin('owner@ybt.com', 'password123')`) left in `login.php`.
* **Impact:** Major UX defect. Gives the impression of a mock/prototype rather than a commercial SaaS application. Conflicts directly with universal self-registration.
* **Resolution Required:** Completely remove the demo bar from `login.php`. Ensure returning shopkeepers log in with their actual registered credentials.

---

#### Error 4: Email Verification Does Not Use Verification Codes
* **Symptom:** Registration does not prompt the user for an email verification code. Instead, in desktop mode it bypasses verification completely (`status = active`), and in cloud mode it generates a link written to `storage/logs/mail.log`.
* **Root Cause:** `register.php` lines 75 & 154 explicitly check `$isDesktopOffline` and auto-verify without sending an email. `MailerService` only generates URL token links (`verify-email.php?token=...`), not 6-digit numeric OTP verification codes.
* **Impact:** Severe security and workflow disconnect. Does not satisfy the requirement: *"Send a verification code to the user's email. Allow the user to enter the verification code. Verify the email address securely."*
* **Resolution Required:** Implement a 6-digit numeric OTP generator, a verification code entry screen, and rate-limited verification validation.

---

#### Error 5: Offline Sync Is Disconnected from CRUD Services
* **Symptom:** Performing actions (adding customers, creating invoices, recording payments, adjusting inventory) while offline does not enqueue operations into the sync queue.
* **Root Cause:** `desktop/src/SyncEngine.php` exists as a standalone helper, but was never hooked into `CustomerService`, `InvoiceService`, `PaymentService`, or `StockService`. Normal services execute raw SQLite statements without calling `SyncEngine->queueOperation()`.
* **Impact:** Critical. The application has no real two-way synchronization between offline desktop instances and the cloud backend.
* **Resolution Required:** Instrument service CRUD methods to generate UUIDs and enqueue operations into `pending_operations` whenever offline.

---

### 2. Comprehensive Feature Matrix: Promised vs Actual

#### Phase 1: Universal Registration and Authentication
| Feature Requirement | Status | Detailed Forensic Finding |
|---|---|---|
| Initial Screen: Login, Register, Forgot Password | ⚠️ Partial | Routes exist (`login.php`, `register.php`, `forgot-password.php`), but login displays confusing demo buttons. |
| Registration: Full Name, Email, Password, Confirm Password | ✅ Done | Implemented and validated in `register.php`. |
| Registration: Business Name, Country | ✅ Done | Implemented with multi-country presets. |
| Registration: Business Type Selection | ❌ Missing | Not present in `register.php`. Business type is not captured during signup. |
| Registration: Optional Phone Number | ⚠️ Defect | Phone number is currently marked mandatory (`*`) instead of optional. |
| 6-Digit Email Verification Code Entry Screen | ❌ Missing | System generates link tokens, not numeric verification codes. Bypassed in desktop mode. |
| Email Verification Code Expiration & Rate Limiting | ❌ Missing | No OTP expiration or resend cooldown UI implemented. |
| Returning User Data Retrieval (Customers, Invoices, Khata) | ✅ Done | Returning users retrieve their tenant-scoped records via `TenantContext`. |
| Forgot Password: Email Verification Code Reset Flow | ❌ Missing | Current flow uses link tokens (`reset-password.php?token=...`) rather than OTP code verification. |

---

#### Phase 2: Secure Admin Panel
| Feature Requirement | Status | Detailed Forensic Finding |
|---|---|---|
| Separate, Secure Admin Panel | ⚠️ Partial | `admin/index.php` exists, but there is no dedicated `admin/login.php` route. Admin authenticates via the user login page. |
| Separately Provisioned Administrator Account | ✅ Done | `is_super_admin = 1` flag prevents normal users from gaining admin rights. |
| No Hardcoded Admin Credentials in Code or Repo | ✅ Done | Verified: No plaintext admin passwords hardcoded in public source files. |
| View All Registered Businesses & Status | ✅ Done | `admin/tenants.php` allows searching, filtering, and viewing tenant profiles. |
| Activate, Suspend & Restore Tenant Accounts | ✅ Done | Implemented in `admin/tenants.php` with audit trail logging. |
| Support Requests & Ticket Management | ❌ Missing | No support ticketing tables, routes, or workflows exist. |
| Login & Authentication Problem Resolution | ❌ Missing | Admin cannot view failed login attempts, locked accounts, or assist users with login issues. |
| Platform System Health, Sync Telemetry & Error Logs | ❌ Missing | Admin panel lacks real-time sync telemetry and error log viewing. |
| Database & Backup Management from Admin | ❌ Missing | Admin panel has no UI to trigger or verify database backups. |

---

#### Phase 3: Complete Customer Management and Ledger
| Feature Requirement | Status | Detailed Forensic Finding |
|---|---|---|
| Customer Search, Add, Profile | ✅ Done | Implemented in `customers.php` and `customer-view.php`. |
| Import Phone Contacts with Permission | ❌ Missing | No contact import functionality exists (neither CSV/vCard nor Web Contacts API). |
| Country Selection with Flag & Dialing Code | ❌ Missing | Customer form has basic text fields for city/state, but no country flag or dialing code picker. |
| Customer Privacy Message: *"Only you can see these entries"* | ❌ Missing | Privacy notice is not displayed on customer management screens. |
| Customer Khata: You Gave (Debit) & You Got (Credit) | ✅ Done | Implemented in `customer-ledger` and `KhataService::getStatement`. |
| Current Running Balance Calculation | ✅ Done | Mathematical running balance calculation verified. |
| Date-Range Filters & Customer Statement | ✅ Done | Available on `customer-view.php` and `customer-ledger-print.php`. |
| Printable / Exportable PDF Statement | ✅ Done | Rendered via `customer-ledger-print.php`. |
| Sidebar Navigation: Direct Link to Customer Ledger / Khata | ❌ Missing | Not present in `includes/sidebar.php`. Feature is hidden under `customer-view.php`. |
| Transaction Entry: AI Extraction from Invoice Image | ❌ Missing | No OCR or computer vision extraction exists. |
| Transaction Entry: AI Extraction from Voice Note | ❌ Missing | No audio recording or speech-to-text transaction parsing exists. |
| Manual Transaction Entry Drawer (Items, Categories, Barcodes) | ⚠️ Partial | Transactions are created via full invoices (`invoice-create.php`), not a quick ledger drawer on customer view. |
| Customer Reminders (WhatsApp / SMS) | ⚠️ Partial | WhatsApp click-to-chat exists for invoices, but not for direct Khata balance reminders. SMS is not integrated. |

---

#### Phase 4: Complete Inventory and Accounting Integration
| Feature Requirement | Status | Detailed Forensic Finding |
|---|---|---|
| Configurable Business Types (Medical, General, Wholesale, Mobile) | ❌ Missing | `products.php` is hardcoded for "Mobile Parts & Inventory" with phone brands and repair part categories. |
| Medical Store Support (Batch numbers, Expiry dates, Formulas) | ❌ Missing | Schema and UI have no fields for batch number, expiry date, or drug composition. |
| General / Grocery Store Support (Units, Pack sizes, FMCG) | ❌ Missing | Products have basic `unit` field, but no pack/bundle hierarchy. |
| Wholesale Support (Bulk pricing tiers, Carton quantities) | ❌ Missing | System only supports single unit selling price; no wholesale quantity discount tiers. |
| Automatic Stock Deduction on Confirmed Sale | ✅ Done | Implemented in `StockService::deductForInvoice` (tested & verified). |
| Automatic Stock Restock on Invoice Cancellation | ✅ Done | Implemented in `StockService::restoreForCancelledInvoice` (tested & verified). |
| Purchase Restock & Supplier Returns | ⚠️ Partial | Methods exist in `StockService`, but there is no dedicated Suppliers or Purchase Orders page. |
| Low Stock Alerts & Minimum Stock Threshold | ✅ Done | Implemented with `min_stock_alert` in `products.php`. |
| Complete Stock Movement Audit Trail | ✅ Done | Implemented in `stock_movements` table. |
| Expense Management & Categorization | ⚠️ Partial | `expenses.php` exists, but has no integration with Khata ledger or supplier payouts. |

---

#### Phase 5: Real Offline and Online Operation
| Feature Requirement | Status | Detailed Forensic Finding |
|---|---|---|
| Offline Local Operation (SQLite Desktop) | ✅ Done | Entire application runs offline without network connectivity on SQLite. |
| Offline Login with Previously Authenticated Credentials | ✅ Done | Local credentials verified against local SQLite database. |
| Real-Time Sync Queue for Offline Operations | ❌ Defect | `pending_operations` table exists, but production services do not populate it during normal user actions. |
| 2-Way Sync Engine with Cloud MySQL Backend | ❌ Missing | No active cloud synchronization server endpoint exists; `SyncEngine` was only run in isolation tests. |
| Conflict Resolution & Duplicate Prevention | ❌ Missing | No conflict resolution logic (e.g., Last-Write-Wins or CRDT) is active in production code. |
| Multi-Device Cloud Synchronization | ❌ Missing | Cannot sync data across multiple devices running the application. |
| Sync Status Indicator with Live Retry Mechanism | ⚠️ Partial | Header displays static badge; no live background worker or retry mechanism. |

---

#### Phase 6: Complete MIS and Android Preparation
| Feature Requirement | Status | Detailed Forensic Finding |
|---|---|---|
| Windows Desktop Production Packaging | ⚠️ Defect | Installer binary is outdated (Nov 1, 9:08 AM) and overwrites newer files on installation. |
| Business-Specific Configuration Engine | ❌ Missing | No settings panel to configure business industry, custom fields, or terminology. |
| REST API for Future Android Integration | ⚠️ Partial | `/api/v1/` exists with basic endpoints, but lacks authentication tokens and sync endpoints for mobile apps. |

---

### 3. Summary of Gaps

```text
CRITICAL DEFECTS:
1. Tauri installer executable out of sync with source codebase.
2. Hardcoded mobile shop terminology preventing use by other businesses.
3. Offline sync engine disconnected from active services.
4. Demo buttons on login page obstructing production multi-tenant auth.
5. Email verification code flow missing (using text log URL links instead).

MISSING HIGH-VALUE MODULES:
1. Business Type Configuration (Pharmacy, General Store, Wholesale, Mobile).
2. Direct Sidebar "Khata / Customer Ledger" navigation item.
3. Supplier & Purchase Management module.
4. AI Invoice Image & Voice Note extraction tools.
5. Support Tickets & Telemetry in Admin Panel.
```
