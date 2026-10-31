# YBT Management Software — Windows Desktop Security Architecture

**Document Version:** 1.0.0  
**Target:** Windows Desktop Client & Offline Synchronization Engine  

---

## 1. Local Storage Security & Encryption

### 1.1 Local SQLite Protection
- In default Windows installations, SQLite database files are stored inside:
  `%LOCALAPPDATA%\YBTManagement\storage\tenants\<tenant_uuid>\shop.sqlite`
- **File System Permissions:** Windows NTFS Access Control Lists (ACLs) restrict access strictly to the currently logged-in Windows user SID and SYSTEM.
- **SQLite Encryption Layer (SQLCipher):** When full local encryption-at-rest is enabled, SQLCipher provides transparent 256-bit AES encryption of all database pages. The decryption key is derived using PBKDF2 with 256,000 iterations and sealed using Windows DPAPI.

### 1.2 Windows DPAPI Credential Vault
- Raw API Bearer tokens and local decryption keys are encrypted using the **Windows Data Protection API (DPAPI)** via `CryptProtectData()`.
- The encryption key is tied to the physical machine and user login credentials, meaning another Windows user on the same PC cannot decrypt the shopkeeper's session or API tokens.

---

## 2. Authentication & Session Lifecycles

### 2.1 First-Time Cloud Verification
- A shopkeeper cannot access the desktop application offline without first authenticating online against the Cloud SaaS API (`/api/v1/auth/login`).
- During this initial handshake:
  1. The server verifies email, password, and active account status.
  2. The server provisions a unique Bearer `access_token` and registers the device in `sync_devices`.
  3. The client receives the `tenant_uuid`, downloads the base schema, and seeds local configuration.

### 2.2 Offline Authentication
- For offline login, the client uses `password_verify()` against the bcrypt password hash stored in `local_sessions`.
- The client enforces an **Offline Grace Period (14 days)**. If the device has not connected to the cloud for 14 days, offline login is locked until internet re-authentication occurs.

### 2.3 Online Revocation Enforcement
- As soon as network connectivity is restored, the client pings `/api/v1/auth/me`.
- If the account has been suspended or the token revoked on the cloud, the server responds with HTTP 401 or 403.
- The client immediately transitions `local_sessions.status = 'suspended'` and closes the active session.

---

## 3. Synchronization & Multi-Tenant Security

### 3.1 Strict Fail-Closed Tenant Context
- Every synchronization push and pull request requires a valid Bearer token.
- The cloud server dynamically extracts `business_id` from the token record and sets `TenantContext::set($businessId)`.
- If a client attempts to inject another shop's `business_id` in a synchronization payload, the server rejects or overwrites it with the authenticated tenant ID.

### 3.2 Idempotency & Replay Attack Defense
- Every offline mutation is stamped with a unique `operation_uuid` (UUIDv4) and `entity_uuid`.
- The server checks `sync_operations_queue` before processing. If an operation was already processed, it responds with `already_synced` without re-executing business logic.
- Duplicate customer creation, duplicate invoice generation, or duplicate billing is strictly prevented.

### 3.3 Zero Secrets in Logs
- The sync engine, HTTP client, and database drivers strictly sanitize log output:
  - Authorization headers are redacted.
  - Passwords and tokens are never written to `mail.log`, `error.log`, or debug consoles.
