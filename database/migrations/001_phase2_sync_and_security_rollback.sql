-- ============================================================
-- YBT Management Software — Phase 2 Migration Rollback
-- 001_phase2_sync_and_security_rollback.sql
-- Description: Reverses Phase 2 Sync & Security Additive Schema
-- ============================================================

USE `ybt_management`;

-- 1. Drop Sync Tables
DROP TABLE IF EXISTS `sync_tombstones`;
DROP TABLE IF EXISTS `sync_operations_queue`;
DROP TABLE IF EXISTS `api_idempotency_keys`;
DROP TABLE IF EXISTS `sync_devices`;
DROP TABLE IF EXISTS `api_tokens`;

-- 2. Drop Additive Metadata Columns
ALTER TABLE `tasks`
  DROP COLUMN IF EXISTS `sync_uuid`,
  DROP COLUMN IF EXISTS `sync_version`,
  DROP COLUMN IF EXISTS `client_updated_at`,
  DROP COLUMN IF EXISTS `is_deleted`;

ALTER TABLE `leads`
  DROP COLUMN IF EXISTS `sync_uuid`,
  DROP COLUMN IF EXISTS `sync_version`,
  DROP COLUMN IF EXISTS `client_updated_at`,
  DROP COLUMN IF EXISTS `is_deleted`;

ALTER TABLE `expenses`
  DROP COLUMN IF EXISTS `sync_uuid`,
  DROP COLUMN IF EXISTS `sync_version`,
  DROP COLUMN IF EXISTS `client_updated_at`,
  DROP COLUMN IF EXISTS `is_deleted`;

ALTER TABLE `payments`
  DROP COLUMN IF EXISTS `sync_uuid`,
  DROP COLUMN IF EXISTS `sync_version`,
  DROP COLUMN IF EXISTS `client_updated_at`,
  DROP COLUMN IF EXISTS `is_deleted`;

ALTER TABLE `invoices`
  DROP COLUMN IF EXISTS `sync_uuid`,
  DROP COLUMN IF EXISTS `client_temp_number`,
  DROP COLUMN IF EXISTS `sync_version`,
  DROP COLUMN IF EXISTS `client_updated_at`,
  DROP COLUMN IF EXISTS `is_deleted`;

ALTER TABLE `products`
  DROP COLUMN IF EXISTS `sync_uuid`,
  DROP COLUMN IF EXISTS `sync_version`,
  DROP COLUMN IF EXISTS `client_updated_at`,
  DROP COLUMN IF EXISTS `is_deleted`;

ALTER TABLE `customers`
  DROP COLUMN IF EXISTS `sync_uuid`,
  DROP COLUMN IF EXISTS `sync_version`,
  DROP COLUMN IF EXISTS `client_updated_at`,
  DROP COLUMN IF EXISTS `is_deleted`;

ALTER TABLE `businesses`
  DROP COLUMN IF EXISTS `tenant_uuid`,
  DROP COLUMN IF EXISTS `sync_enabled`;
