-- ============================================================
-- YBT Management Software — Extended SQLite Parity
-- Migration: 004_sqlite_extended_parity.sql
-- Description: Adds product status, business bank fields, and AI tables
-- Idempotent & Non-Destructive
-- ============================================================

-- 1. Products Parity
ALTER TABLE `products` ADD COLUMN `status` TEXT DEFAULT 'active';
ALTER TABLE `products` ADD COLUMN `description` TEXT;

-- 2. Business Bank & Invoicing Parity
ALTER TABLE `businesses` ADD COLUMN `bank_name` TEXT;
ALTER TABLE `businesses` ADD COLUMN `account_holder` TEXT;
ALTER TABLE `businesses` ADD COLUMN `account_number` TEXT;
ALTER TABLE `businesses` ADD COLUMN `ifsc_code` TEXT;
ALTER TABLE `businesses` ADD COLUMN `bank_branch` TEXT;
ALTER TABLE `businesses` ADD COLUMN `upi_id` TEXT;
ALTER TABLE `businesses` ADD COLUMN `pan` TEXT;
ALTER TABLE `businesses` ADD COLUMN `website` TEXT;
ALTER TABLE `businesses` ADD COLUMN `tagline` TEXT;
ALTER TABLE `businesses` ADD COLUMN `address_line1` TEXT;
ALTER TABLE `businesses` ADD COLUMN `address_line2` TEXT;
ALTER TABLE `businesses` ADD COLUMN `landmark` TEXT;
ALTER TABLE `businesses` ADD COLUMN `postal_code` TEXT;
ALTER TABLE `businesses` ADD COLUMN `invoice_next_number` INTEGER DEFAULT 1001;
ALTER TABLE `businesses` ADD COLUMN `quotation_prefix` TEXT DEFAULT 'QT-';
ALTER TABLE `businesses` ADD COLUMN `quotation_next_number` INTEGER DEFAULT 1001;
ALTER TABLE `businesses` ADD COLUMN `default_due_days` INTEGER DEFAULT 15;
ALTER TABLE `businesses` ADD COLUMN `default_notes` TEXT;
ALTER TABLE `businesses` ADD COLUMN `default_terms` TEXT;

-- 3. AI Conversations & Messages Tables
CREATE TABLE IF NOT EXISTS `ai_conversations` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `user_id` INTEGER NOT NULL,
    `title` TEXT DEFAULT 'New Conversation',
    `provider_config_id` INTEGER,
    `is_archived` INTEGER DEFAULT 0,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS `idx_ai_conv_user` ON `ai_conversations` (`user_id`);

CREATE TABLE IF NOT EXISTS `ai_messages` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `role` TEXT NOT NULL,
    `content` TEXT,
    `tool_calls` TEXT,
    `tool_results` TEXT,
    `metadata` TEXT,
    `tokens_used` INTEGER,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS `idx_ai_msg_conv` ON `ai_messages` (`conversation_id`);

CREATE TABLE IF NOT EXISTS `ai_action_proposals` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `conversation_id` INTEGER,
    `requested_by` INTEGER NOT NULL,
    `action_type` TEXT NOT NULL,
    `target_entity` TEXT,
    `target_id` INTEGER,
    `proposal_data` TEXT NOT NULL,
    `status` TEXT DEFAULT 'pending',
    `approval_token` TEXT,
    `token_expires_at` TEXT,
    `approved_by` INTEGER,
    `approved_at` TEXT,
    `executed_at` TEXT,
    `result` TEXT,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`)
);
CREATE INDEX IF NOT EXISTS `idx_ai_prop_biz_status` ON `ai_action_proposals` (`business_id`, `status`);
