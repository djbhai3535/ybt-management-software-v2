# Database Migration Plan & Complete Entity Relationship Map

## 1. Existing 33 Tables Categorization

A comprehensive audit of [database/schema.sql](file:///C:/CRM-Software/database/schema.sql) reveals 33 existing tables. They fall strictly into two architectural categories:

### 1.1 Tenant-Owned (Business-Scoped) Tables (29 Tables)
All tenant-owned tables have a mandatory `business_id INT UNSIGNED` foreign key referencing `businesses(id) ON DELETE CASCADE`.

| Functional Group | Tables |
| :--- | :--- |
| **Authentication & Staff** | `roles`, `role_permissions`, `users`, `user_preferences`, `business_settings` |
| **CRM & Sales Pipeline** | `customers`, `leads`, `tasks` |
| **Catalog & Products** | `products` |
| **Billing & Financials** | `quotations`, `quotation_items`, `invoices`, `invoice_items`, `payments`, `payment_allocations`, `expense_categories`, `expenses` |
| **Storage & Audit** | `files`, `notifications`, `activity_logs`, `backup_records` |
| **AI Digital Operations** | `ai_provider_configs`, `ai_access_policies`, `ai_conversations`, `ai_messages`, `ai_memory_entries`, `ai_action_proposals`, `ai_approval_events`, `ai_usage_logs` |

### 1.2 System / Global Tables (4 Tables)
| Table | Scope & Lifecycle |
| :--- | :--- |
| `businesses` | Global registry of tenant accounts. |
| `permissions` | Master catalog of system-wide RBAC capability keys (`invoices.create`, `ai.financial`, etc.). |
| `schema_migrations` | Version tracking of executed database migration scripts. |
| `ai_prompt_versions` | Versioned system prompt templates for LLM agents. |

---

## 2. Phase 2 Additive Schema Design

The Phase 2 schema modifications are **100% additive and non-destructive**. No tables or columns are removed, renamed, or truncated.

### 2.1 New Table: `api_tokens`
Manages bearer tokens for desktop clients and API consumers.
```sql
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL DEFAULT 'desktop_session',
  `device_id` VARCHAR(100) DEFAULT NULL,
  `abilities` TEXT DEFAULT NULL,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_api_tokens_biz_user` (`business_id`, `user_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 2.2 New Table: `sync_devices`
Tracks authorized hardware/software terminals per business.
```sql
CREATE TABLE IF NOT EXISTS `sync_devices` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `device_id` VARCHAR(100) NOT NULL,
  `device_name` VARCHAR(255) NOT NULL,
  `platform` VARCHAR(50) DEFAULT 'windows',
  `client_version` VARCHAR(50) DEFAULT '1.0.0',
  `is_active` TINYINT(1) DEFAULT 1,
  `last_sync_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_biz_device` (`business_id`, `device_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 2.3 New Table: `api_idempotency_keys`
Protects against duplicate transaction processing caused by network retries.
```sql
CREATE TABLE IF NOT EXISTS `api_idempotency_keys` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `endpoint` VARCHAR(255) NOT NULL,
  `request_hash` VARCHAR(64) NOT NULL,
  `response_code` SMALLINT UNSIGNED NOT NULL,
  `response_body` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  UNIQUE KEY `uk_biz_idempotency` (`business_id`, `idempotency_key`),
  INDEX `idx_idempotency_expiry` (`expires_at`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 2.4 New Table: `sync_tombstones`
Ensures deletions on one terminal are reliably propagated to all other devices.
```sql
CREATE TABLE IF NOT EXISTS `sync_tombstones` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_uuid` CHAR(36) NOT NULL,
  `deleted_by` INT UNSIGNED DEFAULT NULL,
  `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tombstone_record` (`business_id`, `entity_type`, `entity_uuid`),
  INDEX `idx_tombstone_cursor` (`business_id`, `deleted_at`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 2.5 Additive Synchronization Metadata Columns
Columns added to synchronizable tables (`customers`, `products`, `invoices`, `payments`, `expenses`, `leads`, `tasks`):
- `sync_uuid` CHAR(36) NULL UNIQUE
- `sync_version` BIGINT UNSIGNED DEFAULT 1
- `client_updated_at` TIMESTAMP NULL
- `is_deleted` TINYINT(1) DEFAULT 0

---

## 3. Migration Rollback Strategy

Every forward migration has an accompanying reverse script:
- Forward: `database/migrations/001_phase2_sync_and_security.sql`
- Rollback: `database/migrations/001_phase2_sync_and_security_rollback.sql`

Rollback safely drops the newly created synchronization tables and removes the added metadata columns without touching legacy data or indices.
