-- ============================================================
-- DJ CRM Software — Migration 009: SaaS Subscriptions Schema Parity
-- Safe, Non-Destructive, 100% Idempotent MySQL / MariaDB Script
-- Preserves all existing businesses, users, customers, and ledger records.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Ensure businesses.subscription_status exists
SET @col_b_status = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'subscription_status');
SET @sql_b_status = IF(@col_b_status = 0, 'ALTER TABLE `businesses` ADD COLUMN `subscription_status` VARCHAR(50) DEFAULT ''active'' AFTER `status`', 'SELECT 1');
PREPARE stmt_b_status FROM @sql_b_status; EXECUTE stmt_b_status; DEALLOCATE PREPARE stmt_b_status;

-- 2. Ensure businesses.subscription_expires_at exists
SET @col_b_exp = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'subscription_expires_at');
SET @sql_b_exp = IF(@col_b_exp = 0, 'ALTER TABLE `businesses` ADD COLUMN `subscription_expires_at` DATETIME DEFAULT NULL AFTER `subscription_status`', 'SELECT 1');
PREPARE stmt_b_exp FROM @sql_b_exp; EXECUTE stmt_b_exp; DEALLOCATE PREPARE stmt_b_exp;

-- 3. Ensure businesses.current_plan_slug exists
SET @col_b_plan = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'businesses' AND COLUMN_NAME = 'current_plan_slug');
SET @sql_b_plan = IF(@col_b_plan = 0, 'ALTER TABLE `businesses` ADD COLUMN `current_plan_slug` VARCHAR(50) DEFAULT ''standard'' AFTER `subscription_expires_at`', 'SELECT 1');
PREPARE stmt_b_plan FROM @sql_b_plan; EXECUTE stmt_b_plan; DEALLOCATE PREPARE stmt_b_plan;

-- 4. Set default active status for any existing businesses where subscription_status is NULL
UPDATE `businesses` SET `subscription_status` = 'active' WHERE `subscription_status` IS NULL OR `subscription_status` = '';

-- 5. Ensure subscription_plans table exists
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `badge` VARCHAR(50) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `price_monthly` DECIMAL(10,2) DEFAULT 0.00,
  `price_annual` DECIMAL(10,2) DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'PKR',
  `max_users` INT UNSIGNED DEFAULT 1,
  `max_customers` INT UNSIGNED DEFAULT 500,
  `max_invoices_per_month` INT UNSIGNED DEFAULT 100,
  `ai_enabled` TINYINT(1) DEFAULT 0,
  `ai_monthly_limit` INT UNSIGNED DEFAULT 0,
  `offline_sync_enabled` TINYINT(1) DEFAULT 1,
  `features_json` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `sort_order` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_plans_slug` (`slug`),
  INDEX `idx_plans_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Ensure business_subscriptions table exists
CREATE TABLE IF NOT EXISTS `business_subscriptions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `billing_cycle` ENUM('monthly', 'annual', 'lifetime') DEFAULT 'monthly',
  `status` ENUM('pending_approval', 'active', 'expired', 'rejected', 'cancelled') DEFAULT 'active',
  `amount_paid` DECIMAL(10,2) DEFAULT 0.00,
  `starts_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `payment_reference` VARCHAR(100) DEFAULT NULL,
  `proof_attachment` VARCHAR(255) DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_biz_sub_status` (`business_id`, `status`),
  INDEX `idx_biz_sub_plan` (`plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Seed standard subscription plans if empty
INSERT IGNORE INTO `subscription_plans` 
(`id`, `slug`, `name`, `badge`, `description`, `price_monthly`, `price_annual`, `currency`, `max_users`, `max_customers`, `max_invoices_per_month`, `ai_enabled`, `ai_monthly_limit`, `offline_sync_enabled`, `features_json`, `is_active`, `sort_order`) 
VALUES
(1, 'free', 'Free Starter', 'Pilot', 'Standard offline desktop CRM with ledger and billing', 0.00, 0.00, 'PKR', 2, 500, 100, 0, 0, 1, '["offline_sqlite","customer_ledger","invoice_billing","desktop_sync"]', 1, 1),
(2, 'standard', 'Standard Business', 'Popular', 'Ideal for single-shop retailers and billing terminals', 3000.00, 30000.00, 'PKR', 3, 2000, 500, 0, 0, 1, '["offline_sqlite","customer_ledger","invoice_billing","desktop_sync","whatsapp_billing"]', 1, 2),
(3, 'professional', 'Professional Growth', 'Growth', 'Unlimited invoicing, stock movements, and multi-terminal sync', 6000.00, 60000.00, 'PKR', 10, 10000, 5000, 1, 100, 1, '["all_starter_features","unlimited_invoices","stock_movements","thermal_print","priority_support"]', 1, 3),
(4, 'enterprise', 'Enterprise Fleet', 'VIP', 'Multi-branch sync, dedicated cloud instance and full support', 15000.00, 150000.00, 'PKR', 50, 50000, 25000, 1, 500, 1, '["all_pro_features","multi_branch","unlimited_terminals","dedicated_support"]', 1, 4);

-- 8. Record version in schema_migrations
INSERT INTO `schema_migrations` (`version`, `description`) 
VALUES ('5.2.1', 'DJ CRM Software 5.2.1 — Business Subscription Status & Schema Parity Migration')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
