-- ============================================================
-- YBT Management Software — Phase 3 Migration Rollback
-- 002_phase3_auth_and_onboarding_rollback.sql
-- Description: Reverses Phase 3 Auth & Onboarding Additive Schema
-- ============================================================

USE `ybt_management`;

-- 1. Drop Tables
DROP TABLE IF EXISTS `staff_invitations`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `email_verifications`;

-- 2. Drop Additive Columns
ALTER TABLE `businesses`
  DROP COLUMN IF EXISTS `onboarding_step`,
  DROP COLUMN IF EXISTS `status`;

ALTER TABLE `users`
  DROP COLUMN IF EXISTS `onboarding_completed`,
  DROP COLUMN IF EXISTS `verification_status`,
  DROP COLUMN IF EXISTS `email_verified_at`;
