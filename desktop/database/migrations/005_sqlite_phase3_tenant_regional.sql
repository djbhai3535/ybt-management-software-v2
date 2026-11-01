-- ============================================================
-- YBT Management Software — Phase 3 Tenant Regional & International Parity
-- Migration: 005_sqlite_phase3_tenant_regional.sql
-- Description: Adds default_currency, tax_number, wallet fields, industry, user timestamps/UUIDs, api_tokens, and sync queue parity
-- Idempotent & Non-Destructive
-- ============================================================

-- 1. Businesses Regional & Currency Parity
ALTER TABLE `businesses` ADD COLUMN `default_currency` TEXT DEFAULT 'PKR';
ALTER TABLE `businesses` ADD COLUMN `tax_number` TEXT;
ALTER TABLE `businesses` ADD COLUMN `tax_label` TEXT DEFAULT 'NTN / STRN';
ALTER TABLE `businesses` ADD COLUMN `wallet_id` TEXT;
ALTER TABLE `businesses` ADD COLUMN `wallet_type` TEXT;
ALTER TABLE `businesses` ADD COLUMN `number_system` TEXT DEFAULT 'subcontinent';
ALTER TABLE `businesses` ADD COLUMN `industry` TEXT;
ALTER TABLE `businesses` ADD COLUMN `is_demo` INTEGER DEFAULT 0;
ALTER TABLE `businesses` ADD COLUMN `gst_registered` INTEGER DEFAULT 0;

-- 2. Users Parity for Stable Sync UUIDs and Timestamps
ALTER TABLE `users` ADD COLUMN `uuid` TEXT;
ALTER TABLE `users` ADD COLUMN `created_at` TEXT DEFAULT NULL;
ALTER TABLE `users` ADD COLUMN `updated_at` TEXT DEFAULT NULL;

-- 3. Staff Invitations Parity
ALTER TABLE `staff_invitations` ADD COLUMN `uuid` TEXT;
ALTER TABLE `staff_invitations` ADD COLUMN `phone` TEXT;
ALTER TABLE `staff_invitations` ADD COLUMN `updated_at` TEXT DEFAULT NULL;

-- 4. Activity Logs Parity
ALTER TABLE `activity_logs` ADD COLUMN `description` TEXT;
ALTER TABLE `activity_logs` ADD COLUMN `old_values` TEXT;
ALTER TABLE `activity_logs` ADD COLUMN `new_values` TEXT;
ALTER TABLE `activity_logs` ADD COLUMN `user_agent` TEXT;

-- 5. API Tokens Parity
CREATE TABLE IF NOT EXISTS `api_tokens` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `token_hash` TEXT NOT NULL UNIQUE,
    `name` TEXT NOT NULL DEFAULT 'desktop_session',
    `device_id` TEXT DEFAULT NULL,
    `abilities` TEXT DEFAULT NULL,
    `last_used_at` TEXT DEFAULT NULL,
    `expires_at` TEXT NOT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS `idx_api_tokens_user` ON `api_tokens` (`user_id`);

-- 6. Sync Operations Queue Parity
CREATE TABLE IF NOT EXISTS `sync_operations_queue` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `operation_uuid` TEXT NOT NULL UNIQUE,
    `business_id` INTEGER NOT NULL,
    `device_id` TEXT NOT NULL,
    `entity_type` TEXT NOT NULL,
    `entity_uuid` TEXT NOT NULL,
    `operation_type` TEXT NOT NULL,
    `payload` TEXT NOT NULL,
    `client_timestamp` TEXT NOT NULL,
    `status` TEXT DEFAULT 'pending',
    `retry_count` INTEGER DEFAULT 0,
    `error_message` TEXT DEFAULT NULL,
    `synced_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS `idx_sync_queue_biz_op` ON `sync_operations_queue` (`business_id`, `operation_uuid`);
