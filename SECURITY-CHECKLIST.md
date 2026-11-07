# DJ CRM Software 5.1 — Production Security Checklist

This document details the security safeguards implemented in DJ CRM Software 5.1 and provides recommended server-hardening steps for Hostinger Premium shared hosting.

---

## 1. Implemented Application-Level Safeguards

### 1.1 Credential & Secret Isolation
- [x] **No Hardcoded Passwords:** Codebase contains zero hardcoded database passwords, SMTP passwords, or API secret keys.
- [x] **Zero Mock Data in Deployment:** `database/dj-crm-5.1-install.sql` does not contain demo customers, dummy phone numbers, or mock transactions.
- [x] **Safe `.env.example`:** Only empty placeholders are shipped in the distribution archive.
- [x] **Diagnostic Logging Sanitization:** `MailerService` and error logs automatically mask recipient email addresses (e.g. `ab***z@domain.com`) and never print SMTP passwords or raw session tokens.

### 1.2 Access Control & Web Server Rules (`.htaccess`)
- [x] **Sensitive File Denial:** Apache and LiteSpeed web servers are configured to return `HTTP 403 Forbidden` for:
  - `.env` and `.env.*`
  - `.git` and git repositories
  - `.sql` database dumps
  - `.sqlite`, `.sqlite3`, `.db` files
  - Log files (`*.log`)
  - Backup archives (`*.zip`, `*.tar`, `*.gz`, `*.bak`)
- [x] **Directory Indexing Disabled:** `Options -Indexes` prevents public directory listings.
- [x] **HTTP Header Hardening:**
  - `X-Frame-Options: SAMEORIGIN` (Clickjacking mitigation)
  - `X-Content-Type-Options: nosniff` (MIME-sniffing prevention)
  - `X-XSS-Protection: 1; mode=block` (Reflected XSS filter)
  - `Referrer-Policy: strict-origin-when-cross-origin`

### 1.3 Multi-Tenant Isolation
- [x] **Tenant Scoping:** All critical queries across customers, invoices, payments, and expenses enforce `tenant_id = :tenant_id` bindings.
- [x] **Tenant Context Check:** `includes/tenant-context.php` ensures unauthenticated or unauthorized users cannot execute tenant-scoped queries.

### 1.4 Database & SQL Injection Mitigation
- [x] **PDO Prepared Statements:** Every dynamic parameter is bound via PDO positional or named placeholders.
- [x] **Whitelisted Identifiers:** Dynamic table names and sort keys are strictly validated against whitelists before query execution.
- [x] **Dual-Column Auto-Normalization:** In `includes/db.php`, introspected columns prevent SQL errors on column mismatches.

### 1.5 Authentication & Session Protections
- [x] **Password Hashing:** Passwords are encrypted using `PASSWORD_DEFAULT` (bcrypt with salt).
- [x] **Brute-Force Rate Limiting:** Login attempts are limited to 5 failures, after which accounts enter a 15-minute lockout period.
- [x] **Session Cookies:** Configured with `HttpOnly`, `SameSite=Lax`, and conditional `Secure` flags under SSL/TLS.
- [x] **CSRF Protection:** Critical forms and state-changing requests validate unique, per-session CSRF tokens.

---

## 2. Server-Side Hardening Steps for Hostinger

When deploying on Hostinger Premium:

1. **Verify File Permissions:**
   - Directories: `755` (standard read/execute)
   - Files: `644` (read-only for web server, not world-writable)
   - Storage directory (`storage/`): `755` (writable by PHP process)
   - Configuration file (`.env`): `600` or `644` (never `777`)

2. **PHP Configuration in Hostinger hPanel:**
   - PHP Version: `8.2` or `8.3`
   - `display_errors`: `Off` (prevents path and error leakage to visitors)
   - `log_errors`: `On` (logs runtime errors to Hostinger error log file)
   - Extensions enabled: `pdo_mysql`, `curl`, `mbstring`, `openssl`, `zip`, `gd`.

3. **Database Security:**
   - Restrict database user privileges to the specific application database (`u290903472_djcrm`).
   - Disallow external MySQL connections unless connecting via an authorized static IP.
