-- ============================================================
-- YBT Management Software — Phase 3 Migration
-- 002_phase3_auth_and_onboarding.sql
-- Description: Shopkeeper Registration, Email Verification, Password Resets & Staff Invitations
-- Safe & Non-Destructive: Does not drop, rename, or truncate existing data.
-- ============================================================

USE `ybt_management`;

-- 1. Email Verifications Table
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `business_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `token_type` ENUM('register', 'email_change') DEFAULT 'register',
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email_verif_lookup` (`token_hash`),
  INDEX `idx_email_verif_user` (`user_id`, `expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 2. Password Resets Table
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pwd_reset_lookup` (`token_hash`),
  INDEX `idx_pwd_reset_user` (`user_id`, `expires_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. Staff Invitations Table
CREATE TABLE IF NOT EXISTS `staff_invitations` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `invited_by` INT UNSIGNED NOT NULL,
  `status` ENUM('pending', 'accepted', 'expired', 'cancelled') DEFAULT 'pending',
  `expires_at` TIMESTAMP NOT NULL,
  `accepted_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_staff_inv_lookup` (`token_hash`),
  INDEX `idx_staff_inv_biz` (`business_id`, `status`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invited_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 4. Additive Columns to Users
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email_verified_at` TIMESTAMP NULL DEFAULT NULL AFTER `email`,
  ADD COLUMN IF NOT EXISTS `verification_status` ENUM('pending', 'verified', 'suspended') DEFAULT 'verified' AFTER `email_verified_at`,
  ADD COLUMN IF NOT EXISTS `onboarding_completed` TINYINT(1) DEFAULT 1 AFTER `is_owner`;

-- 5. Additive Columns to Businesses
ALTER TABLE `businesses`
  ADD COLUMN IF NOT EXISTS `status` ENUM('active', 'pending_verification', 'suspended') DEFAULT 'active' AFTER `name`,
  ADD COLUMN IF NOT EXISTS `onboarding_step` TINYINT UNSIGNED DEFAULT 3 AFTER `status`;

-- 6. Backfill existing accounts so they never experience lockouts
UPDATE `users` SET 
  `verification_status` = 'verified', 
  `email_verified_at` = IFNULL(`email_verified_at`, NOW()), 
  `onboarding_completed` = 1 
WHERE `verification_status` IS NULL OR `verification_status` = 'pending';

UPDATE `businesses` SET 
  `status` = 'active', 
  `onboarding_step` = 3 
WHERE `status` IS NULL OR `status` = 'pending_verification';
