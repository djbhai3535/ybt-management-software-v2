-- ============================================================
-- YBT Management Software — SQLite Local Offline Schema Update
-- Migration: 002_sqlite_desktop_sync_compat.sql
-- Adds missing dashboard/analytics columns and ancillary tables
-- ============================================================

-- 1. Invoices missing columns
ALTER TABLE `invoices` ADD COLUMN `amount_paid` REAL DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `outstanding` REAL DEFAULT 0.00;
ALTER TABLE `invoices` ADD COLUMN `status` TEXT DEFAULT 'draft';
ALTER TABLE `invoices` ADD COLUMN `due_date` TEXT;

-- 2. Customers missing columns
ALTER TABLE `customers` ADD COLUMN `company_name` TEXT;

-- 3. Expenses missing columns
ALTER TABLE `expenses` ADD COLUMN `status` TEXT DEFAULT 'active';

-- 4. Leads Table for Sales Pipeline
CREATE TABLE IF NOT EXISTS `leads` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `name` TEXT NOT NULL,
    `company` TEXT,
    `email` TEXT,
    `phone` TEXT,
    `deal_value` REAL DEFAULT 0.00,
    `stage` TEXT DEFAULT 'lead',
    `source` TEXT,
    `created_at` TEXT,
    `updated_at` TEXT
);

-- 5. Tasks Table for Action Items
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `title` TEXT NOT NULL,
    `description` TEXT,
    `priority` TEXT DEFAULT 'normal',
    `status` TEXT DEFAULT 'pending',
    `due_date` TEXT,
    `created_at` TEXT,
    `updated_at` TEXT
);

-- 6. Activity Logs Table
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `user_id` INTEGER,
    `action` TEXT,
    `entity_type` TEXT,
    `entity_id` INTEGER,
    `details` TEXT,
    `ip_address` TEXT,
    `created_at` TEXT
);

-- 7. Users Table
CREATE TABLE IF NOT EXISTS `users` (
    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
    `business_id` INTEGER NOT NULL,
    `role_id` INTEGER DEFAULT 1,
    `name` TEXT NOT NULL,
    `email` TEXT NOT NULL,
    `phone` TEXT,
    `password` TEXT,
    `is_owner` INTEGER DEFAULT 0,
    `is_active` INTEGER DEFAULT 1
);
