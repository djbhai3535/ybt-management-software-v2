# Architecture Decision Records (ADRs)

## ADR 001: Desktop Application Architecture Selection

### Context
We must provide a commercial Windows Desktop application for shopkeepers that functions both online and offline while protecting the existing investment in PHP 8.3 code, PDO database abstraction, and custom CSS/JS.

### Evaluation of Options

| Criterion | Option A: Electron + Embedded Portable PHP & Local MariaDB | Option B: Tauri (Rust) + Local SQLite + Cloud Sync API (Recommended) | Option C: Electron + Local SQLite & Embedded Node Backend |
| :--- | :--- | :--- | :--- |
| **Existing Code Compatibility** | **100% Direct:** Runs existing PHP scripts locally without rewriting. | **High:** Existing PHP serves as Cloud API; UI assets reused in WebView. | **Medium:** Requires rewriting PHP queries into JavaScript/Node.js OR running PHP. |
| **Installer Size** | **Heavy:** 140MB – 180MB (Chromium + PHP Runtime + MariaDB binary). | **Ultra-Light:** 8MB – 15MB (Uses Windows WebView2 + compiled Rust binary). | **Heavy:** 100MB – 130MB. |
| **Memory Footprint** | 300MB – 500MB RAM. | **35MB – 60MB RAM.** | 250MB – 400MB RAM. |
| **Database Compatibility** | Full MySQL syntax compatibility (ENUM, TIMESTAMP ON UPDATE, JSON). | Requires SQLite-compatible dialect for local store; Cloud runs full MySQL. | Requires SQLite or local engine. |
| **Offline Reliability** | High, but heavy service management (managing mysqld.exe background process). | **Very High:** SQLite is embedded in-process; zero background daemon crashes. | Medium. |
| **Security & Windows Integration** | Vulnerable to local port snooping if local PHP server is bound to localhost. | **Exceptional:** Direct OS-level Windows DPAPI, secure sandbox, native auto-updates. | Good. |

### Decision: Option B (Tauri + Local SQLite + Cloud API) as Long-Term Commercial Architecture, with Option A Supported as Rapid Packaging Fallback
For the commercial SaaS product, **Option B (Tauri)** is the superior engineering choice. It eliminates background database crashes (such as orphaned `mysqld.exe` processes on client PCs) and delivers a lightweight 10MB installer.

---

## ADR 002: Authenticated Encryption Upgrade (AES-256-GCM)

### Context
Phase 1 audit identified that `includes/functions.php` used unauthenticated `AES-256-CBC` with `openssl_random_pseudo_bytes()`. CBC without HMAC is vulnerable to padding oracle and bit-flipping attacks. Furthermore, a hardcoded key existed in `config/config.php`.

### Decision
1. Upgrade primary encryption to **AES-256-GCM** (Galois/Counter Mode), which provides authenticated encryption with associated data (AEAD) and an integrity authentication tag.
2. Generate cryptographically secure IVs using `random_bytes(12)`.
3. Support environment variable `YBT_ENCRYPTION_KEY` via `.env` / server environment.
4. **Backward Compatibility Guarantee:** Existing encrypted data (using legacy CBC format) will continue to decrypt safely. A format prefix (`gcm:`) distinguishes newly encrypted values from legacy payloads.

---

## ADR 003: Multi-Tenancy Strategy: Row-Level Isolation with Server Enforcement

### Context
We evaluated whether to use a separate database per shop versus a shared database with row-level isolation (`business_id`).

### Decision
Adopt **Shared Database with Strict Row-Level Isolation (TenantContext)**.
- **Justification:** The existing 33 tables already feature `business_id` foreign keys and indices. Maintaining thousands of separate database schemas in small retail operations creates immense operational overhead (running migrations on thousands of databases).
- **Enforcement:** Enforce tenancy strictly through `TenantContext::id()`. Unscoped queries fail closed with runtime exceptions.

---

## ADR 004: Financial Transaction Conflict Resolution Policy

### Context
In retail shops, invoice numbers and payment reconciliations are legally sensitive. Last-Write-Wins (LWW) would destroy accounting integrity.

### Decision
Adopt **Server-Authoritative Sequential Numbering with Client-Side Temporary Identifiers**:
1. When offline, invoices receive temporary UUID-based identifiers: `OFF-{device_id}-{timestamp}`.
2. Invoices are printed and handed to customers with offline receipt notices.
3. Upon network reconnection, the server allocates the next sequential legal invoice number (`INV-XXXX`) inside an atomic database transaction.
4. The server returns the canonical number, and the client updates its local record.
5. Invoices and payments are strictly **append-only**.
