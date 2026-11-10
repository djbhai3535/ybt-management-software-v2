-- ============================================================
-- DJ CRM Software 5.2.2 — Migration 010: SaaS Platform, Advertisements & WhatsApp Support
-- Safe, Non-Destructive, 100% Idempotent MySQL / MariaDB Script
-- Compatible with MySQL 8.0+ / MariaDB 10.6+ on Hostinger Premium
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Ensure advertisements table exists with all required columns
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

-- 2. Add company_name to advertisements if missing
SET @col_ad_co = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'company_name');
SET @sql_ad_co = IF(@col_ad_co = 0, 'ALTER TABLE `advertisements` ADD COLUMN `company_name` VARCHAR(255) DEFAULT NULL AFTER `title`', 'SELECT 1');
PREPARE stmt_ad_co FROM @sql_ad_co; EXECUTE stmt_ad_co; DEALLOCATE PREPARE stmt_ad_co;

-- 3. Add ad_code to advertisements if missing
SET @col_ad_code = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'ad_code');
SET @sql_ad_code = IF(@col_ad_code = 0, 'ALTER TABLE `advertisements` ADD COLUMN `ad_code` TEXT DEFAULT NULL AFTER `target_url`', 'SELECT 1');
PREPARE stmt_ad_code FROM @sql_ad_code; EXECUTE stmt_ad_code; DEALLOCATE PREPARE stmt_ad_code;

-- 4. Add target_plans to advertisements if missing
SET @col_ad_plans = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'target_plans');
SET @sql_ad_plans = IF(@col_ad_plans = 0, 'ALTER TABLE `advertisements` ADD COLUMN `target_plans` VARCHAR(255) DEFAULT ''all'' AFTER `ad_code`', 'SELECT 1');
PREPARE stmt_ad_plans FROM @sql_ad_plans; EXECUTE stmt_ad_plans; DEALLOCATE PREPARE stmt_ad_plans;

-- 5. Add priority to advertisements if missing
SET @col_ad_pri = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'advertisements' AND COLUMN_NAME = 'priority');
SET @sql_ad_pri = IF(@col_ad_pri = 0, 'ALTER TABLE `advertisements` ADD COLUMN `priority` INT UNSIGNED DEFAULT 0 AFTER `target_plans`', 'SELECT 1');
PREPARE stmt_ad_pri FROM @sql_ad_pri; EXECUTE stmt_ad_pri; DEALLOCATE PREPARE stmt_ad_pri;

-- 6. Add has_ads to subscription_plans if missing
SET @col_plan_ads = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plans' AND COLUMN_NAME = 'has_ads');
SET @sql_plan_ads = IF(@col_plan_ads = 0, 'ALTER TABLE `subscription_plans` ADD COLUMN `has_ads` TINYINT(1) DEFAULT 0 AFTER `offline_sync_enabled`', 'SELECT 1');
PREPARE stmt_plan_ads FROM @sql_plan_ads; EXECUTE stmt_plan_ads; DEALLOCATE PREPARE stmt_plan_ads;

-- 7. Add trial_days to subscription_plans if missing
SET @col_plan_trial = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscription_plans' AND COLUMN_NAME = 'trial_days');
SET @sql_plan_trial = IF(@col_plan_trial = 0, 'ALTER TABLE `subscription_plans` ADD COLUMN `trial_days` INT UNSIGNED DEFAULT 0 AFTER `has_ads`', 'SELECT 1');
PREPARE stmt_plan_trial FROM @sql_plan_trial; EXECUTE stmt_plan_trial; DEALLOCATE PREPARE stmt_plan_trial;

-- 8. Ensure Standard / Free Trial Plan exists with 3 Team Seats
INSERT INTO `subscription_plans` 
(`id`, `slug`, `name`, `badge`, `description`, `price_monthly`, `price_annual`, `currency`, `max_users`, `max_customers`, `max_invoices_per_month`, `ai_enabled`, `ai_monthly_limit`, `offline_sync_enabled`, `has_ads`, `trial_days`, `features_json`, `is_active`, `sort_order`) 
VALUES
(1, 'free', 'Free Starter Trial', 'Pilot', 'Standard offline desktop CRM with ledger and billing (3 team seats trial)', 0.00, 0.00, 'PKR', 3, 500, 100, 0, 0, 1, 1, 30, '["offline_sqlite","customer_ledger","invoice_billing","desktop_sync","team_3_seats"]', 1, 1)
ON DUPLICATE KEY UPDATE 
  `max_users` = GREATEST(`max_users`, 3),
  `name` = IF(`slug` = 'free', 'Free Starter Trial', `name`);

-- 9. Ensure default Platform Settings exist
INSERT IGNORE INTO `platform_settings` (`key`, `value`, `description`, `is_secret`) VALUES
('whatsapp_support_number', '923000000000', 'Central WhatsApp Support hotline', 0),
('platform_name', 'DJ CRM Software', 'Official platform brand name', 0),
('trial_duration_days', '30', 'Standard new registration trial duration in days', 0),
('trial_max_users', '3', 'Trial plan maximum user seats limit', 0);

-- 10. Record version in schema_migrations
INSERT INTO `schema_migrations` (`version`, `description`) 
VALUES ('5.2.2', 'DJ CRM Software 5.2.2 – Complete Super Admin SaaS Platform, Advertisements & WhatsApp Support')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

SET FOREIGN_KEY_CHECKS = 1;
