-- ============================================================
-- DJ CRM Software 5.2 — Migration 008: Platform Admin & Parity Upgrade
-- Safe, Non-Destructive, 100% Idempotent MySQL / MariaDB Script
-- ============================================================

-- 1. Create Advertisements Table
CREATE TABLE IF NOT EXISTS `advertisements` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `placement` ENUM('dashboard_top', 'sidebar_bottom', 'login_banner') NOT NULL DEFAULT 'dashboard_top',
  `image_url` VARCHAR(500) DEFAULT NULL,
  `target_url` VARCHAR(500) DEFAULT NULL,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `impressions_count` INT UNSIGNED DEFAULT 0,
  `clicks_count` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ads_placement_active` (`placement`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Ensure ai_provider_configs.is_default exists
SET @col_ai_def = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_provider_configs' AND COLUMN_NAME = 'is_default');
SET @sql_ai_def = IF(@col_ai_def = 0, 'ALTER TABLE `ai_provider_configs` ADD COLUMN `is_default` TINYINT(1) DEFAULT 0 AFTER `is_active`', 'SELECT 1');
PREPARE stmt_ai_def FROM @sql_ai_def; EXECUTE stmt_ai_def; DEALLOCATE PREPARE stmt_ai_def;

-- 3. Ensure customers.city compatibility column exists
SET @col_cust_city = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'city');
SET @sql_cust_city = IF(@col_cust_city = 0, 'ALTER TABLE `customers` ADD COLUMN `city` VARCHAR(100) DEFAULT NULL AFTER `billing_city`', 'SELECT 1');
PREPARE stmt_cust_city FROM @sql_cust_city; EXECUTE stmt_cust_city; DEALLOCATE PREPARE stmt_cust_city;

-- 4. Ensure customers.address compatibility column exists
SET @col_cust_addr = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'address');
SET @sql_cust_addr = IF(@col_cust_addr = 0, 'ALTER TABLE `customers` ADD COLUMN `address` TEXT DEFAULT NULL AFTER `billing_address_line1`', 'SELECT 1');
PREPARE stmt_cust_addr FROM @sql_cust_addr; EXECUTE stmt_cust_addr; DEALLOCATE PREPARE stmt_cust_addr;

-- 5. Ensure customer_ledger attachments exist
SET @col_cl_item = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND COLUMN_NAME = 'item_details');
SET @sql_cl_item = IF(@col_cl_item = 0, 'ALTER TABLE `customer_ledger` ADD COLUMN `item_details` TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt_cl_item FROM @sql_cl_item; EXECUTE stmt_cl_item; DEALLOCATE PREPARE stmt_cl_item;

SET @col_cl_img = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND COLUMN_NAME = 'image_attachment');
SET @sql_cl_img = IF(@col_cl_img = 0, 'ALTER TABLE `customer_ledger` ADD COLUMN `image_attachment` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_cl_img FROM @sql_cl_img; EXECUTE stmt_cl_img; DEALLOCATE PREPARE stmt_cl_img;

SET @col_cl_voice = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND COLUMN_NAME = 'voice_attachment');
SET @sql_cl_voice = IF(@col_cl_voice = 0, 'ALTER TABLE `customer_ledger` ADD COLUMN `voice_attachment` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_cl_voice FROM @sql_cl_voice; EXECUTE stmt_cl_voice; DEALLOCATE PREPARE stmt_cl_voice;

-- 6. Ensure users.is_super_admin exists
SET @col_u_sa = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_super_admin');
SET @sql_u_sa = IF(@col_u_sa = 0, 'ALTER TABLE `users` ADD COLUMN `is_super_admin` TINYINT(1) DEFAULT 0 AFTER `is_owner`', 'SELECT 1');
PREPARE stmt_u_sa FROM @sql_u_sa; EXECUTE stmt_u_sa; DEALLOCATE PREPARE stmt_u_sa;

-- 7. Record Version in schema_migrations
INSERT INTO `schema_migrations` (`version`, `description`) 
VALUES ('5.2.1', 'DJ CRM Software 5.2.1 — Live Website Production Hotfix & Layout Parity')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
