-- ============================================================
-- DJ CRM Software — Cloud Migration 006: Customer Ledger Sync Parity
-- MySQL / MariaDB Compatible, Safe, Idempotent, Non-Destructive
-- ============================================================

SET @col_ver = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND COLUMN_NAME = 'sync_version');
SET @sql_ver = IF(@col_ver = 0, 'ALTER TABLE `customer_ledger` ADD COLUMN `sync_version` BIGINT UNSIGNED DEFAULT 1', 'SELECT 1');
PREPARE stmt_ver FROM @sql_ver; EXECUTE stmt_ver; DEALLOCATE PREPARE stmt_ver;

SET @col_item = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND COLUMN_NAME = 'item_details');
SET @sql_item = IF(@col_item = 0, 'ALTER TABLE `customer_ledger` ADD COLUMN `item_details` TEXT DEFAULT NULL', 'SELECT 1');
PREPARE stmt_item FROM @sql_item; EXECUTE stmt_item; DEALLOCATE PREPARE stmt_item;

SET @col_img = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND COLUMN_NAME = 'image_attachment');
SET @sql_img = IF(@col_img = 0, 'ALTER TABLE `customer_ledger` ADD COLUMN `image_attachment` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_img FROM @sql_img; EXECUTE stmt_img; DEALLOCATE PREPARE stmt_img;

SET @col_voice = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND COLUMN_NAME = 'voice_attachment');
SET @sql_voice = IF(@col_voice = 0, 'ALTER TABLE `customer_ledger` ADD COLUMN `voice_attachment` VARCHAR(255) DEFAULT NULL', 'SELECT 1');
PREPARE stmt_voice FROM @sql_voice; EXECUTE stmt_voice; DEALLOCATE PREPARE stmt_voice;

-- Indexes for high-speed tenant delta synchronization
SET @idx_ver = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND INDEX_NAME = 'idx_cust_ledger_sync_ver');
SET @sql_idx = IF(@idx_ver = 0, 'ALTER TABLE `customer_ledger` ADD INDEX `idx_cust_ledger_sync_ver` (`business_id`, `sync_version`)', 'SELECT 1');
PREPARE stmt_idx FROM @sql_idx; EXECUTE stmt_idx; DEALLOCATE PREPARE stmt_idx;

SET @idx_uuid = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_ledger' AND INDEX_NAME = 'idx_cust_ledger_sync_uuid');
SET @sql_uuid = IF(@idx_uuid = 0, 'ALTER TABLE `customer_ledger` ADD INDEX `idx_cust_ledger_sync_uuid` (`business_id`, `sync_uuid`)', 'SELECT 1');
PREPARE stmt_uuid FROM @sql_uuid; EXECUTE stmt_uuid; DEALLOCATE PREPARE stmt_uuid;
