-- ============================================================
-- YBT Management Software — Stage 1 Super Admin Engine & Announcements
-- Migration: 006_sqlite_stage1_super_admin.sql
-- Description: Adds is_super_admin, business suspension columns, and platform announcements
-- Idempotent & Non-Destructive: Safe for existing databases
-- ============================================================

-- 1. Super Admin Flag on Users
ALTER TABLE `users` ADD COLUMN `is_super_admin` INTEGER DEFAULT 0;

-- 2. Businesses Suspension Details
ALTER TABLE `businesses` ADD COLUMN `suspended_at` TEXT DEFAULT NULL;
ALTER TABLE `businesses` ADD COLUMN `suspended_reason` TEXT DEFAULT NULL;

-- 3. Platform Announcements Table
CREATE TABLE IF NOT EXISTS `platform_announcements` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `title` TEXT NOT NULL,
    `content` TEXT NOT NULL,
    `type` TEXT DEFAULT 'info',
    `target` TEXT DEFAULT 'all',
    `is_active` INTEGER DEFAULT 1,
    `created_by` INTEGER DEFAULT NULL,
    `starts_at` TEXT DEFAULT NULL,
    `expires_at` TEXT DEFAULT NULL,
    `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TEXT DEFAULT NULL
);
CREATE INDEX IF NOT EXISTS `idx_announcements_active` ON `platform_announcements` (`is_active`, `starts_at`, `expires_at`);
