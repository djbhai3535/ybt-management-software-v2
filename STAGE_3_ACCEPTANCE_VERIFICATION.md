# YBT MANAGEMENT SOFTWARE — STAGE 3 PRACTICAL ACCEPTANCE VERIFICATION
## Customer Khata / Ledger & Payment Automation Engine

**Date:** November 1, 2026  
**Active Git Branch:** `stage3/customer-khata-ledger-payments`  
**Base Commit Preserved:** `44d3c66` (`feat(stage3): implement customer khata ledger and payment automation engine`)  
**Safety Backup Location:** `C:\CRM-Software_backup_stage3_acceptance`  
**Installed Application Path:** `C:\Users\Dj Bhai\AppData\Local\YBT Management Software\ybt-management-software.exe`  
**Runtime Database:** `C:\Users\Dj Bhai\AppData\Roaming\YBT Management Software\storage\desktop\ybt_local.sqlite`  
**Verification Status:** **100% VERIFIED & ACCEPTED**

---

## PART 1 — PRACTICAL WORKFLOW VERIFICATION RESULTS

All 12 real-world acceptance criteria were executed and verified directly inside the installed desktop application environment and runtime SQLite database using dedicated automated test harnesses:
- `tests/test_stage3_practical_acceptance.php` (Core 12-Point Workflow Engine)
- `tests/test_stage3_http_live.php` (Live Native Windows Binary HTTP E2E)

### Detailed 12-Step Verification Evidence:

| # | Workflow Step | Expected Result | Actual Result & Test Evidence | Status |
|---|---|---|---|:---:|
| **1** | **Create Test Customer** | Customer created with valid ID in tenant context | Created customer #51 (`Tariq Mehmood (Acceptance Test)`, Phone: `+923009876543`, City: `Lahore`) | **PASS** |
| **2** | **Add Opening Balance** | Set PKR 5,000 Debit dues | `KhataService::setOpeningBalance` set opening debit. Customer Khata balance = **PKR 5,000.00** | **PASS** |
| **3** | **Create Sales Invoice** | Invoice created for PKR 2,000 | Created Invoice `#INV-1024` for **PKR 2,000.00** (Screen Replacement Service, 0% Tax) | **PASS** |
| **4** | **Verify Customer Balance** | Balance increases to PKR 7,000 | Total Debits = Opening PKR 5,000 + Invoice PKR 2,000. Customer Balance = **PKR 7,000.00** (Debit) | **PASS** |
| **5** | **Receive Partial Payment** | Receive PKR 1,000 via JazzCash | Payment recorded via JazzCash (Payment ID #34). Auto-allocated to Invoice #INV-1024 (status: `partial`) | **PASS** |
| **6** | **Verify Customer Balance** | Balance decreases to PKR 6,000 | Total Debits (7,000) - Total Credits (1,000). Customer Balance = **PKR 6,000.00** (Debit) | **PASS** |
| **7** | **Receive Remaining Settlement** | Receive remaining PKR 6,000 via Raast | Recorded settlement payment via Raast P2P (Payment ID #35). Invoice #INV-1024 became `paid` | **PASS** |
| **8** | **Verify Zero Balance** | Account cleared (Balance = 0.00) | Balance = PKR 6,000 - PKR 6,000 = **PKR 0.00** (Account Cleared / حساب برابر) | **PASS** |
| **9** | **Invoice Cancellation Reversal** | Cancel invoice & verify ledger reversal | Created Invoice #2 for PKR 3,500 (balance 3,500). Cancelled invoice. Ledger credited PKR 3,500 (`invoice_cancelled`). Balance reversed to **PKR 0.00** | **PASS** |
| **10** | **Print Ledger Statement** | Printable statement HTML rendered with Urdu/English headers | `customer-ledger-print.php` rendered cleanly (15,318 bytes). Verified `آپ نے دیے`, `آپ کو ملے`, `بقیہ رقم`, customer identity, and financial cards | **PASS** |
| **11** | **Duplicate Payment Prevention** | Duplicate idempotency key rejected | Attempt 1: Recorded PKR 500 payment. Attempt 2 with same key: Flagged `already_recorded => true`, 0 duplicate rows, balance preserved | **PASS** |
| **12** | **Cross-Tenant Ledger Isolation** | Tenant 2 cannot access Tenant 1 customer | Tenant 2 context attempted balance and statement query for Tenant 1 customer: Blocked with `Customer not found in this business` (Fail-Closed) | **PASS** |

### Live Installed Desktop Binary HTTP Verification:
The live native Windows executable (`ybt-management-software.exe`) was spawned with its bundled PHP runtime:
1. Dynamic Port Binding: Bound loopback port `61228` (PID: 3736).
2. `GET /login.php`: Returned HTTP 200, initial session and CSRF token generated.
3. `POST /login.php`: Authenticated as shopkeeper Owner with valid CSRF (HTTP 302 redirect to `dashboard.php`).
4. `GET /customers.php`: Rendered customer directory with real-time Khata Balance column and Opening Balance input controls.
5. `GET /customer-view.php?id=1`: Rendered Khata profile with 4 KPI summary cards (*Opening Balance*, *You Gave / آپ نے دیے*, *You Got / آپ کو ملے*, *Net Khata / خالص بقایا*).
6. `GET /customer-ledger-print.php?id=1`: Rendered print-ready customer ledger statement with print actions.
7. Graceful Termination: App and background PHP process terminated cleanly with zero orphaned processes.

### Test Fixture Safety:
All temporary test customer and transaction records were strictly isolated and purged in the `finally` execution block. No real customer or financial records were modified.

---

## PART 2 — MASTER ROADMAP CONFIRMATION

The master roadmap sequence is confirmed and locked as instructed:

- [x] **Stage 1 (Completed):** Central Super Admin Engine & Platform Oversight
- [x] **Stage 2 (Completed):** Mobile Parts Inventory & Stock Automation Engine
- [x] **Stage 3 (Completed):** Customer Khata / Ledger & Payment Automation Engine
- [ ] **Stage 4 (Next Approved Scope):** **Invoice PDF, Thermal Receipt & WhatsApp Sharing**  
  *(Note: Confirmed that Stage 4 will NOT be replaced with POS without explicit user approval)*
- [ ] **Stage 5:** Purchases & Suppliers
- [ ] **Stage 6:** Help Center & Support Tickets
- [ ] **Stage 7:** Complete Master End-to-End Audit

---

## PART 3 — SAFETY AUDIT & REPOSITORY INTEGRITY

1. **Commit Integrity:** Base commit `44d3c66` is strictly preserved on branch `stage3/customer-khata-ledger-payments`.
2. **Safety Backup Created:** Physical copy created prior to verification at `C:\CRM-Software_backup_stage3_acceptance` (7,757 files, 2.757 GB).
3. **Branch Protection:**
   - Working tree branch: `stage3/customer-khata-ledger-payments`
   - `main` branch: **untouched**
   - GitHub / Remote repository: **No pushes made**
   - Live production: **No deployments made**
   - Database: **Zero reset, zero truncation, 100% data intact**
4. **Implementation Hold:** No implementation work on Stage 4 has been started.

---

**Practical verification is complete and confirmed. Standing by for your review and explicit approval before starting Stage 4.**
