# DJ CRM Software — Version 5.1 Release Changelog

**Release Date:** November 2026  
**Build Target:** Production Shared Hosting (Hostinger Premium / Apache / LiteSpeed) & Desktop Sync  
**Package:** `DJ-CRM-Software-5.1.zip`  
**Database Schema:** `database/dj-crm-5.1-install.sql` (47 Tables, 0 Mock Data, Dual Compatibility)

---

## 1. Executive Summary

DJ CRM Software 5.1 is a complete, production-hardened rebuild engineered to resolve every known compatibility issue, runtime error, missing dependency, and database schema mismatch discovered during live pilot testing on Hostinger Premium (`https://apkashow.com`).

Unlike previous hotfix patches, Version 5.1 provides a full-application unified codebase and an authoritative SQL database installation schema with zero reliance on missing files or ambiguous columns.

---

## 2. Key Architectural Enhancements

### 2.1 Unified Dual-Column Database Compatibility
- **Problem Fixed:** The application code, report services, APIs, and desktop sync previously referenced inconsistent column names across modules (e.g. `total_amount` vs `grand_total`, `paid_amount` vs `amount_paid`, `balance_amount` vs `outstanding`, `issue_date` vs `invoice_date`, `payment_method` vs `method`, `payee` vs `vendor`, `is_deleted` vs `status`).
- **Solution:** 
  1. `database/dj-crm-5.1-install.sql` now defines all 47 database tables with native dual-column support across `invoices`, `quotations`, `payments`, `expenses`, and `users`.
  2. `includes/db.php` incorporates runtime column introspection (`getTableColumns`) and bidirectional data normalization (`normalizeTableData`). Any INSERT or UPDATE automatically populates both column variants synchronously.
  3. All dashboard, reports, invoice, payment, and expense SQL queries were audited and updated to ensure standard column usage with fallback expressions.

### 2.2 Hostinger `open_basedir` & File Structure Resilience
- Prior versions attempted to load `.env` from outside `public_html`, which triggered Hostinger PHP `open_basedir` restriction errors.
- `includes/bootstrap.php` now prioritized searching `public_html/.env` first before falling back to parent directories, and provides clean diagnostic exceptions if unreadable.
- All service dependencies (`services/report-service.php`, `includes/tenant-context.php`, `services/mailer-service.php`, etc.) are fully integrated in their standard directories.

### 2.3 Strict Database Engine Standards
- Removed all SQLite-specific syntax (such as `datetime('now')`) from web queries, standardizing on PHP's `date('Y-m-d H:i:s')` and MySQL/MariaDB standards.
- Hardened transaction rollbacks and PDO prepared statements across `services/invoice-service.php`, `services/payment-service.php`, and `services/customer-service.php`.
- Full decimal precision (`DECIMAL(12,2)`) across monetary balances, preventing floating-point rounding errors in ledger calculations.

### 2.4 Security & Session Hardening
- `.htaccess` updated to Version 5.1.0 with LiteSpeed/Apache directives blocking direct HTTP access to `.env`, `.git`, `.sql`, `.sqlite`, and `storage/`.
- Session security flags configured with `HttpOnly`, `SameSite=Lax`, and dynamic `Secure` cookie attributes under HTTPS.
- Dynamic cross-tenant query scoping enforced across every customer, invoice, and payment query.

---

## 3. Detailed File Changes

| File / Component | Type | Summary of Changes |
| :--- | :--- | :--- |
| `database/dj-crm-5.1-install.sql` | **New / Core** | Authoritative 47-table clean schema with dual compatibility columns, proper indexes, foreign keys, and empty seeds. |
| `includes/db.php` | **Core Fix** | Added bidirectional column normalization, cached schema column introspection, and safe prepared statement execution. |
| `dashboard.php` | **Bugfix** | Resolved `Unknown column 'grand_total'` and `missing report-service.php` errors; unified SQL aggregations. |
| `invoices.php` & `invoice-view.php` | **Bugfix** | Synchronized invoice totals, dual-column mapping for `total_amount`/`grand_total` and `balance_amount`/`outstanding`. |
| `quotations.php` & `quotation-create.php` | **Bugfix** | Standardized quotation column names and item references. |
| `expenses.php` | **Bugfix** | Unified `payment_method` & `method`, `payee` & `vendor`, `is_deleted` & `status`. |
| `payments.php` & `services/payment-service.php` | **Bugfix** | Standardized payment methods, dual references, and ledger balance updates. |
| `services/report-service.php` | **Complete** | Full multi-tenant report engine providing revenue, expenses, profit, sales, and khata analytics. |
| `services/customer-service.php` | **Bugfix** | Safe customer balance calculations and transaction-wrapped ledger postings. |
| `services/invoice-service.php` | **Bugfix** | Dual-column inserts and transaction-safe stock/ledger synchronization. |
| `services/khata-service.php` | **Bugfix** | Dual column support for customer ledger debits, credits, and running balance. |
| `config/config.php` | **Updated** | Version bump to 5.1.0, timezone set to `Asia/Karachi`, unified environment accessor. |
| `.htaccess` | **Security** | LiteSpeed/Apache protection rules for `.env`, sensitive file denial, and HTTPS redirect support. |
| `.env.example` | **Template** | Production template with empty secret placeholders and configuration instructions. |

---

## 4. Verification & Testing Summary

- **PHP Syntax Linting:** 100/100 PHP files passed `php -l` with 0 syntax errors.
- **SQL Schema Validation:** Validated across all 47 tables with 0 missing tables and 0 missing dual columns.
- **Functional Service Flows:** Automated end-to-end tests for `ReportService`, `CustomerService`, `InvoiceService`, `PaymentService`, and `KhataService` executed and verified.
