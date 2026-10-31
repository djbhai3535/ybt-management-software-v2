# YBT Management Software — Windows Desktop & Offline-First Architecture Blueprint

**Document Version:** 1.0.0  
**Phase:** Phase 4 Foundation  
**Target Platform:** Windows 10 / Windows 11 (64-bit)  
**Shell Framework:** Tauri v2 (Rust + WebView2) with Portable PHP Micro-Engine  
**Local Database:** Embedded SQLite 3 (`pdo_sqlite` with WAL mode)  
**Cloud Backend:** Centralized PHP 8.3 + MariaDB/MySQL SaaS REST API v1  

---

## 1. Executive Summary & Problem Statement

YBT Management Software is a commercial SaaS and CRM built for mobile retail and repair shops. In real-world retail environments, especially across South Asia and emerging markets, internet connectivity can be intermittent, unstable, or subject to sudden outages.

A retail POS, repair intake, and customer billing application must **never halt operations** due to internet disconnection. When connectivity is restored, all offline transactions must synchronize seamlessly with the cloud without data loss, duplicate invoice generation, or cross-tenant data leakage.

This document formalizes the architecture of the **Windows Desktop Offline-First Edition**.

---

## 2. Evaluation of Architectural Options

| Architectural Option | UI / Code Reuse | Offline POS / Repairs | Security & Tenant Isolation | Regression Risk | Final Decision |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Option A: Full Rewrite in React/Rust** | 0% (Total rewrite) | Native (Rust SQLite) | High | Critical (Destroys 33+ tables of proven logic) | **REJECTED** |
| **Option B: Pure Webview Remote Wrapper** | 100% | 0% (Fails when offline) | High (Server-side) | Zero | **REJECTED** |
| **Option C: Tauri Shell + Embedded Local PHP Engine + SQLite (Selected)** | **100% UI & Backend Reuse** | **100% Offline Capable** | **High (Encrypted SQLite + Local Tenant Token)** | **Lowest (Preserves all existing code)** | **ACCEPTED** |
| **Option D: Electron Wrapper with Full Node Stack** | 80% | 100% | Medium (Heavy RAM 400MB+) | Medium | **REJECTED** (Too heavy vs Tauri's ~40MB) |

### Selected Architecture: The Tauri-Embedded Micro-Engine (Option C)

#### Why Tauri + Portable Micro-Engine?
1. **Lightweight Footprint:** Tauri uses Windows' native **Microsoft Edge WebView2** runtime, resulting in an installer of ~15-25 MB (versus Electron's 120MB+) and consuming only ~40-60 MB of RAM (versus Electron's 400MB+).
2. **100% Feature Preservation:** The existing PHP 8.3 CRM code, HTML5 templates, Tabler icons, CSS styling, and Vanilla JavaScript controllers run unmodified against a bundled, hardened micro-PHP service listening strictly on an internal loopback port (`127.0.0.1`).
3. **Embedded Local SQLite Storage:** Local transactions are committed to an embedded SQLite database (`ybt_local.sqlite`) using `pdo_sqlite` in WAL (Write-Ahead Logging) mode, delivering sub-millisecond query performance.
4. **Physical Tenant Isolation:** When a shopkeeper logs in on the desktop, their local data is stored in `storage/tenants/<tenant_uuid>/shop.sqlite`. One shopkeeper's database file is physically separate from any other shopkeeper who might share the machine.

---

## 3. High-Level System Architecture Diagram

```
+-----------------------------------------------------------------------------------+
|                        WINDOWS DESKTOP APPLICATION SHELL                          |
|                                                                                   |
|  +-------------------------------------+   +-----------------------------------+  |
|  |     Tauri v2 / WebView2 Shell       |   |       Windows OS Integration      |  |
|  |  - Native Windows Window (Frameless)|   |  - DPAPI Credential Vault         |  |
|  |  - System Tray & Offline Indicator |   |  - Local AppData Storage          |  |
|  |  - Silent Thermal Printer Bridge    |   |  - Native Notifications           |  |
|  +------------------+------------------+   +-----------------+-----------------+  |
|                     |                                        |                    |
|                     v                                        v                    |
|  +-----------------------------------------------------------------------------+  |
|  |             Bundled Hardened PHP 8.3 Micro-Engine (127.0.0.1 Loopback)      |  |
|  |  - Bound strictly to internal random port; rejects external network traffic |  |
|  |  - FastCGI / Internal HTTP dispatch                                         |  |
|  |  - Session & CSRF security guards                                           |  |
|  +------------------+----------------------------------------+-----------------+  |
|                     |                                        |                    |
|                     v                                        v                    |
|  +-------------------------------------+   +-----------------------------------+  |
|  |      Local Database Layer (SQLite)  |   |     Offline Synchronization Engine |  |
|  |  - ybt_shop_<uuid>.sqlite           |   |  - Change Capture Queue           |  |
|  |  - WAL mode (Concurrent reads/writes|   |  - Network Reachability Monitor   |  |
|  |  - Full local indexes & foreign keys|   |  - Push & Pull Batch Orchestrator |  |
|  +-------------------------------------+   +-----------------+-----------------+  |
+--------------------------------------------------------------|--------------------+
                                                               | (Encrypted HTTPS / TLS 1.3)
                                                               v
+-----------------------------------------------------------------------------------+
|                           CENTRAL CLOUD SAAS PLATFORM                             |
|                                                                                   |
|  +-----------------------------------------------------------------------------+  |
|  |              Central Cloud REST API v1 (/api/v1/sync/push, /pull)           |  |
|  |  - Bearer Token Authentication (api_tokens)                                 |  |
|  |  - Fail-Closed Tenant Context Verification                                  |  |
|  |  - Idempotency Gate (api_idempotency_keys)                                  |  |
|  |  - Tombstone & Conflict Resolution Manager                                  |  |
|  +-------------------------------------+---------------------------------------+  |
|                                        |                                          |
|                                        v                                          |
|  +-----------------------------------------------------------------------------+  |
|  |                 Cloud Multi-Tenant MariaDB / MySQL Database                 |  |
|  |  - Central Shopkeeper Data, Invoices, Repairs, Billing & Reports             |  |
|  +-----------------------------------------------------------------------------+  |
+-----------------------------------------------------------------------------------+
```

---

## 4. Communication & Synchronization Blueprint

### 4.1 First-Time Setup & Cloud Authentication
1. **Initial Launch:** The application detects no active local tenant session.
2. **Cloud Login:** The shopkeeper enters credentials. The app sends `POST /api/v1/auth/login` with `device_id`, `device_name`, and `platform = 'windows'`.
3. **Session Provisioning:**
   - Server returns Bearer `access_token`, tenant profile, and business details.
   - Client creates the local SQLite database for this shopkeeper: `storage/tenants/<tenant_uuid>/shop.sqlite`.
   - Client executes the SQLite schema migration.
   - Client pulls the base dataset via `POST /api/v1/sync/pull` (`since_version = 0`).
   - Client saves an encrypted local session record in `local_sessions` (password hash verified with `password_verify` for offline unlock).

### 4.2 Offline Operation
- All reads and writes target the local SQLite database.
- Every insert, update, or delete automatically pushes a record into `pending_operations`:
  ```sql
  INSERT INTO pending_operations (
      operation_uuid, business_id, entity_type, entity_uuid,
      operation_type, payload_json, created_at, status
  ) VALUES (
      'd7a1...', 1, 'customer', 'c81f...',
      'INSERT', '{"name":"John Doe","phone":"..."}', datetime('now'), 'pending'
  );
  ```
- Temporary invoices use the client temp number convention (e.g. `TEMP-INV-<device_id>-<seq>`) to guarantee offline billing continuity.

### 4.3 Bidirectional Synchronization Flow
1. **Network Probe:** The background sync worker pings `/api/v1/health` every 15-30 seconds.
2. **Push Phase (Client -> Cloud):**
   - Transmits pending operations in batches of 50 via `POST /api/v1/sync/push`.
   - Headers: `Authorization: Bearer <token>`, `X-Device-Id: <uuid>`, `X-Idempotency-Key: <op_uuid>`.
   - Cloud validates tenant context, inserts/updates MySQL records, creates tombstones for deletions, updates sync version, and returns success mappings.
   - Client marks operations as `synced` in local SQLite.
3. **Pull Phase (Cloud -> Client):**
   - Client queries `POST /api/v1/sync/pull` with `since_version = <last_pulled_version>`.
   - Cloud returns all changes committed by other devices or web portal since that version.
   - Client applies changes inside a local SQLite transaction and updates local `last_sync_version`.

### 4.4 Conflict Resolution Policy
- **Entity Level:** Last-Write-Wins (LWW) based on NTP-synchronized UTC timestamps (`updated_at`), with the Cloud Server timestamp acting as final arbiter.
- **Financial / Invoice Safety:** Invoices are append-only. Once an invoice is finalized, it cannot be modified offline, preventing financial conflicts.
- **Conflict Journal:** Any conflicting overwrite is logged in `sync_conflicts` for review by the shop owner.

---

## 5. Security & Tenant Isolation

1. **Physical File-Level Isolation:** Each shopkeeper's SQLite database is stored in a separate directory named after the `tenant_uuid`.
2. **Local Credential Protection:**
   - Raw API bearer tokens are encrypted on Windows using **DPAPI (Data Protection API)** via Windows Crypt32.
   - SQLite files can be protected with SQLite encryption (SQLCipher) or Windows BitLocker / NTFS EFS.
3. **Local Offline Authentication Guard:**
   - Passwords are validated using Argon2id / bcrypt against the local hashed credentials stored during online login.
   - When the device reconnects to the internet, the token is verified against `/api/v1/auth/me`. If the account was revoked or suspended on the cloud, the local session is immediately locked.
4. **Offline Revocation Limitation:**
   - *Security Note:* If a device is disconnected from the internet, a server-initiated revocation cannot reach the device until connectivity is restored. To mitigate this, local sessions enforce a maximum offline grace period (default: 14 days), after which internet re-authentication is mandatory.

---

## 6. Windows Packaging & Distribution

1. **Installer Engine:** Inno Setup 6 / WiX Toolset.
2. **Bundled Payload:**
   - Tauri application executable (`YBT Management Software.exe`).
   - Portable minimal PHP 8.3 micro-runtime (~18 MB compressed, stripped of unused extensions, hardened with `php.ini` disabling `eval`, `exec`, and file uploads outside AppData).
   - Base SQLite template and assets.
3. **App Data Directory:** `%LOCALAPPDATA%\YBTManagement\`.
4. **Silent Updates:** Tauri built-in updater signed with Ed25519 private key.
