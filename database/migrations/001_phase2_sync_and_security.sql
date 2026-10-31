-- ============================================================
-- YBT Management Software — Phase 2 Migration
-- 001_phase2_sync_and_security.sql
-- Description: Additive Sync Foundation, API Token Auth, and Idempotency
-- Safe & Non-Destructive: Does not drop, rename, or truncate existing data.
-- ============================================================

USE `ybt_management`;

-- 1. API Tokens (Desktop Client & External Integration Bearer Tokens)
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
  INDEX `idx_api_tokens_lookup` (`token_hash`),
  INDEX `idx_api_tokens_biz_user` (`business_id`, `user_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Sync Devices (Hardware/Terminal Registry per Tenant)
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

-- 3. API Idempotency Keys (Network Retry & Duplicate Mutation Protection)
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

-- 4. Sync Operations Queue (Audit & Staging Log for Batched Push Operations)
CREATE TABLE IF NOT EXISTS `sync_operations_queue` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `operation_uuid` CHAR(36) NOT NULL UNIQUE,
  `business_id` INT UNSIGNED NOT NULL,
  `device_id` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_uuid` CHAR(36) NOT NULL,
  `operation_type` ENUM('insert', 'update', 'delete') NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `client_timestamp` TIMESTAMP NOT NULL,
  `status` ENUM('pending', 'in_flight', 'synced', 'conflict', 'failed') DEFAULT 'pending',
  `retry_count` INT UNSIGNED DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sync_queue_biz_status` (`business_id`, `status`),
  INDEX `idx_sync_queue_entity` (`entity_type`, `entity_uuid`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Sync Tombstones (Tracks Record Deletions Across Distributed Terminals)
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

-- 6. Additive Sync Columns to Existing Core Tables
-- Using IF NOT EXISTS pattern or safe procedural checks

-- Businesses
ALTER TABLE `businesses`
  ADD COLUMN IF NOT EXISTS `tenant_uuid` CHAR(36) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `sync_enabled` TINYINT(1) DEFAULT 1 AFTER `is_demo`;

-- Customers
ALTER TABLE `customers`
  ADD COLUMN IF NOT EXISTS `sync_uuid` CHAR(36) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `sync_version` BIGINT UNSIGNED DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `client_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `sync_version`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 AFTER `client_updated_at`,
  ADD INDEX IF NOT EXISTS `idx_customers_sync` (`business_id`, `sync_version`),
  ADD INDEX IF NOT EXISTS `idx_customers_uuid` (`sync_uuid`);

-- Products
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `sync_uuid` CHAR(36) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `sync_version` BIGINT UNSIGNED DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `client_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `sync_version`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 AFTER `client_updated_at`,
  ADD INDEX IF NOT EXISTS `idx_products_sync` (`business_id`, `sync_version`),
  ADD INDEX IF NOT EXISTS `idx_products_uuid` (`sync_uuid`);

-- Invoices
ALTER TABLE `invoices`
  ADD COLUMN IF NOT EXISTS `sync_uuid` CHAR(36) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `client_temp_number` VARCHAR(50) DEFAULT NULL AFTER `invoice_number`,
  ADD COLUMN IF NOT EXISTS `sync_version` BIGINT UNSIGNED DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `client_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `sync_version`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 AFTER `client_updated_at`,
  ADD INDEX IF NOT EXISTS `idx_invoices_sync` (`business_id`, `sync_version`),
  ADD INDEX IF NOT EXISTS `idx_invoices_uuid` (`sync_uuid`);

-- Payments
ALTER TABLE `payments`
  ADD COLUMN IF NOT EXISTS `sync_uuid` CHAR(36) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `sync_version` BIGINT UNSIGNED DEFAULT 1 AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `client_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `sync_version`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 AFTER `client_updated_at`,
  ADD INDEX IF NOT EXISTS `idx_payments_sync` (`business_id`, `sync_version`),
  ADD INDEX IF NOT EXISTS `idx_payments_uuid` (`sync_uuid`);

-- Expenses
ALTER TABLE `expenses`
  ADD COLUMN IF NOT EXISTS `sync_uuid` CHAR(36) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `sync_version` BIGINT UNSIGNED DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `client_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `sync_version`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 AFTER `client_updated_at`,
  ADD INDEX IF NOT EXISTS `idx_expenses_sync` (`business_id`, `sync_version`),
  ADD INDEX IF NOT EXISTS `idx_expenses_uuid` (`sync_uuid`);

-- Leads
ALTER TABLE `leads`
  ADD COLUMN IF NOT EXISTS `sync_uuid` CHAR(36) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `sync_version` BIGINT UNSIGNED DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `client_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `sync_version`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 AFTER `client_updated_at`,
  ADD INDEX IF NOT EXISTS `idx_leads_sync` (`business_id`, `sync_version`);

-- Tasks
ALTER TABLE `tasks`
  ADD COLUMN IF NOT EXISTS `sync_uuid` CHAR(36) DEFAULT NULL AFTER `id`,
  ADD COLUMN IF NOT EXISTS `sync_version` BIGINT UNSIGNED DEFAULT 1 AFTER `status`,
  ADD COLUMN IF NOT EXISTS `client_updated_at` TIMESTAMP NULL DEFAULT NULL AFTER `sync_version`,
  ADD COLUMN IF NOT EXISTS `is_deleted` TINYINT(1) DEFAULT 0 AFTER `client_updated_at`,
  ADD INDEX IF NOT EXISTS `idx_tasks_sync` (`business_id`, `sync_version`);
