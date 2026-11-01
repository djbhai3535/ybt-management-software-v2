-- ============================================================
-- YBT Management Software — Phase 3 Tenant Regional & International Parity
-- Migration: 005_sqlite_phase3_tenant_regional.sql
-- Description: Adds default_currency, tax_number, wallet fields, industry, and user timestamps/UUIDs
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
ALTER TABLE `users` ADD COLUMN `created_at` TEXT DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `users` ADD COLUMN `updated_at` TEXT DEFAULT CURRENT_TIMESTAMP;

-- 3. Staff Invitations Parity
ALTER TABLE `staff_invitations` ADD COLUMN `uuid` TEXT;
ALTER TABLE `staff_invitations` ADD COLUMN `phone` TEXT;
