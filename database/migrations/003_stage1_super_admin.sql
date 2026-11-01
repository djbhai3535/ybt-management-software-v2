-- ============================================================
-- YBT Management Software — Stage 1 Super Admin Engine & Announcements
-- Migration: 003_stage1_super_admin.sql (MySQL Cloud)
-- ============================================================

ALTER TABLE `users` ADD COLUMN `is_super_admin` TINYINT(1) DEFAULT 0 AFTER `is_owner`;
ALTER TABLE `businesses` ADD COLUMN `suspended_at` DATETIME NULL DEFAULT NULL AFTER `status`;
ALTER TABLE `businesses` ADD COLUMN `suspended_reason` TEXT NULL DEFAULT NULL AFTER `suspended_at`;

CREATE TABLE IF NOT EXISTS `platform_announcements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `type` ENUM('info', 'warning', 'danger', 'success') DEFAULT 'info',
    `target` ENUM('all', 'active_only') DEFAULT 'all',
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `starts_at` DATE NULL DEFAULT NULL,
    `expires_at` DATE NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_announcements_active` (`is_active`, `starts_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
