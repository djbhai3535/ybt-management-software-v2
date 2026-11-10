-- ============================================================
-- DJ CRM Software 5.2.2 — Migration 010: SaaS Platform, Advertisements & WhatsApp Support
-- Safe, Non-Destructive, 100% Idempotent MySQL / MariaDB Script
-- Compatible with MySQL 8.0+ / MariaDB 10.6+ on Hostinger Premium
-- Fully Re-runnable after partial execution
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Ensure advertisements table exists
CREATE TABLE IF NOT EXISTS `advertisements` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `company_name` VARCHAR(255) DEFAULT NULL,
  `placement` VARCHAR(50) NOT NULL DEFAULT 'dashboard_top',
  `image_url` VARCHAR(500) DEFAULT NULL,
  `target_url` VARCHAR(500) DEFAULT NULL,
  `ad_code` TEXT DEFAULT NULL,
  `target_plans` VARCHAR(255) DEFAULT 'all',
  `priority` INT UNSIGNED DEFAULT 0,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `impressions_count` INT UNSIGNED DEFAULT 0,
  `clicks_count` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ads_placement_active` (`placement`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ensure advertisements columns exist (idempotent, safe without strict column ordering)
SET @col_ad_co = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'company_name');
SET @sql_ad_co = IF(@col_ad_co = 0, 'ALTER TABLE `advertisements` ADD COLUMN `company_name` VARCHAR(255) DEFAULT NULL', 'DO 0');
PREPARE stmt_ad_co FROM @sql_ad_co; EXECUTE stmt_ad_co; DEALLOCATE PREPARE stmt_ad_co;

SET @col_ad_code = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'ad_code');
SET @sql_ad_code = IF(@col_ad_code = 0, 'ALTER TABLE `advertisements` ADD COLUMN `ad_code` TEXT DEFAULT NULL', 'DO 0');
PREPARE stmt_ad_code FROM @sql_ad_code; EXECUTE stmt_ad_code; DEALLOCATE PREPARE stmt_ad_code;

SET @col_ad_plans = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'target_plans');
SET @sql_ad_plans = IF(@col_ad_plans = 0, 'ALTER TABLE `advertisements` ADD COLUMN `target_plans` VARCHAR(255) DEFAULT ''all''', 'DO 0');
PREPARE stmt_ad_plans FROM @sql_ad_plans; EXECUTE stmt_ad_plans; DEALLOCATE PREPARE stmt_ad_plans;

SET @col_ad_pri = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'priority');
SET @sql_ad_pri = IF(@col_ad_pri = 0, 'ALTER TABLE `advertisements` ADD COLUMN `priority` INT UNSIGNED DEFAULT 0', 'DO 0');
PREPARE stmt_ad_pri FROM @sql_ad_pri; EXECUTE stmt_ad_pri; DEALLOCATE PREPARE stmt_ad_pri;

-- Ensure placement column supports flexible placements (VARCHAR 50 instead of restricted ENUM)
SET @col_ad_pl = (SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'placement');
SET @sql_ad_pl = IF(@col_ad_pl LIKE 'enum%', 'ALTER TABLE `advertisements` MODIFY COLUMN `placement` VARCHAR(50) NOT NULL DEFAULT ''dashboard_top''', 'DO 0');
PREPARE stmt_ad_pl FROM @sql_ad_pl; EXECUTE stmt_ad_pl; DEALLOCATE PREPARE stmt_ad_pl;

-- 3. Ensure subscription_plans table exists
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
  `has_ads` TINYINT(1) DEFAULT 0,
  `trial_days` INT UNSIGNED DEFAULT 0,
  `features_json` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `sort_order` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_plans_slug` (`slug`),
  INDEX `idx_plans_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ensure subscription_plans columns exist
SET @col_plan_ads = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plans' AND COLUMN_NAME = 'has_ads');
SET @sql_plan_ads = IF(@col_plan_ads = 0, 'ALTER TABLE `subscription_plans` ADD COLUMN `has_ads` TINYINT(1) DEFAULT 0', 'DO 0');
PREPARE stmt_plan_ads FROM @sql_plan_ads; EXECUTE stmt_plan_ads; DEALLOCATE PREPARE stmt_plan_ads;

SET @col_plan_trial = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plans' AND COLUMN_NAME = 'trial_days');
SET @sql_plan_trial = IF(@col_plan_trial = 0, 'ALTER TABLE `subscription_plans` ADD COLUMN `trial_days` INT UNSIGNED DEFAULT 0', 'DO 0');
PREPARE stmt_plan_trial FROM @sql_plan_trial; EXECUTE stmt_plan_trial; DEALLOCATE PREPARE stmt_plan_trial;

SET @col_plan_mu = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plans' AND COLUMN_NAME = 'max_users');
SET @sql_plan_mu = IF(@col_plan_mu = 0, 'ALTER TABLE `subscription_plans` ADD COLUMN `max_users` INT UNSIGNED DEFAULT 1', 'DO 0');
PREPARE stmt_plan_mu FROM @sql_plan_mu; EXECUTE stmt_plan_mu; DEALLOCATE PREPARE stmt_plan_mu;

-- 4. Ensure Standard / Free Trial Plan exists with 3 Team Seats
INSERT INTO `subscription_plans` 
(`id`, `slug`, `name`, `badge`, `description`, `price_monthly`, `price_annual`, `currency`, `max_users`, `max_customers`, `max_invoices_per_month`, `ai_enabled`, `ai_monthly_limit`, `offline_sync_enabled`, `has_ads`, `trial_days`, `features_json`, `is_active`, `sort_order`) 
VALUES
(1, 'free', 'Free Starter Trial', 'Pilot', 'Standard offline desktop CRM with ledger and billing (3 team seats trial)', 0.00, 0.00, 'PKR', 3, 500, 100, 0, 0, 1, 1, 30, '["offline_sqlite","customer_ledger","invoice_billing","desktop_sync","team_3_seats"]', 1, 1)
ON DUPLICATE KEY UPDATE 
  `max_users` = GREATEST(`max_users`, 3),
  `trial_days` = IF(`trial_days` = 0, 30, `trial_days`),
  `has_ads` = 1,
  `name` = IF(`slug` = 'free', 'Free Starter Trial', `name`);

-- 5. Ensure platform_settings table exists
CREATE TABLE IF NOT EXISTS `platform_settings` (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Ensure is_secret column exists on platform_settings
SET @col_ps_secret = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_settings' AND COLUMN_NAME = 'is_secret');
SET @sql_ps_secret = IF(@col_ps_secret = 0, 'ALTER TABLE `platform_settings` ADD COLUMN `is_secret` TINYINT(1) NOT NULL DEFAULT 0 AFTER `description`', 'DO 0');
PREPARE stmt_ps_secret FROM @sql_ps_secret; EXECUTE stmt_ps_secret; DEALLOCATE PREPARE stmt_ps_secret;

-- 7. Ensure default Platform Settings exist (Non-destructive: NEVER overwrites existing values)
INSERT INTO `platform_settings` (`key`, `value`, `description`) VALUES
('whatsapp_support_number', '923000000000', 'Central WhatsApp Support hotline'),
('platform_name', 'DJ CRM Software', 'Official platform brand name'),
('trial_duration_days', '30', 'Standard new registration trial duration in days'),
('trial_max_users', '3', 'Trial plan maximum user seats limit')
ON DUPLICATE KEY UPDATE `key` = `key`;

-- Mark sensitive keys as secret if present
UPDATE `platform_settings` SET `is_secret` = 1 WHERE `key` = 'central_ai_key_encrypted';

-- 8. Ensure schema_migrations table exists
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` VARCHAR(50) PRIMARY KEY,
  `description` VARCHAR(255) DEFAULT NULL,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_sm_desc = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations' AND COLUMN_NAME = 'description');
SET @sql_sm_desc = IF(@col_sm_desc = 0, 'ALTER TABLE `schema_migrations` ADD COLUMN `description` VARCHAR(255) DEFAULT NULL', 'DO 0');
PREPARE stmt_sm_desc FROM @sql_sm_desc; EXECUTE stmt_sm_desc; DEALLOCATE PREPARE stmt_sm_desc;

-- 9. Record version in schema_migrations (executed ONLY after all operations succeed)
INSERT INTO `schema_migrations` (`version`, `description`) 
VALUES ('5.2.2', 'DJ CRM Software 5.2.2 — Complete Super Admin SaaS Platform, Advertisements & WhatsApp Support')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `applied_at` = NOW();

SET FOREIGN_KEY_CHECKS = 1;
