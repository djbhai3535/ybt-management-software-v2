-- ============================================================
-- DJ CRM Software 5.2 — Authoritative Fresh Installation Schema
-- Target: MySQL 8.0+ / MariaDB 10.4+ / Hostinger Premium Shared Hosting
-- 48 Total Production Tables | Clean Production Seeds Only | 0 Mock Data
-- Generated: 2026-11-09 06:10:45
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;

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

-- ============================================================
-- DJ CRM Software — Clean Cloud Pilot Database Schema (MySQL / MariaDB)
-- Version: 1.0.1
-- Compatible with: MySQL 8.0+ / MariaDB 10.6+ (Hostinger Premium Hosting)
-- 
-- STRICT ISOLATION RULES:
-- 1. NO 'CREATE DATABASE' or 'USE' statements (imports into any host-assigned DB).
-- 2. ZERO mock customer records (no Rajesh Sharma, Nova Retail, Apex Infotech, etc.).
-- 3. ZERO personal developer credentials (mdilshadm282@gmail.com).
-- 4. 100% column parity with PHP application services, register.php, and admin portal.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1. TENANT MANAGEMENT & CORE BUSINESS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `businesses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tenant_uuid` CHAR(36) DEFAULT NULL,
  `name` VARCHAR(255) NOT NULL,
  `status` ENUM('active', 'pending_verification', 'suspended') DEFAULT 'active',
  `onboarding_step` TINYINT UNSIGNED DEFAULT 3,
  `legal_name` VARCHAR(255) DEFAULT NULL,
  `owner_name` VARCHAR(255) DEFAULT NULL,
  `type` VARCHAR(100) DEFAULT NULL,
  `industry` VARCHAR(100) DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `registration_number` VARCHAR(100) DEFAULT NULL,
  `established_date` DATE DEFAULT NULL,
  `logo_path` VARCHAR(500) DEFAULT NULL,
  `logo_url` TEXT DEFAULT NULL,
  `tagline` VARCHAR(255) DEFAULT NULL,
  `website` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  -- Contact Information
  `email` VARCHAR(255) DEFAULT NULL,
  `alt_email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `alt_phone` VARCHAR(50) DEFAULT NULL,
  `whatsapp` VARCHAR(50) DEFAULT NULL,
  `landline` VARCHAR(50) DEFAULT NULL,
  -- Address
  `address_line1` VARCHAR(255) DEFAULT NULL,
  `address_line2` VARCHAR(255) DEFAULT NULL,
  `landmark` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `district` VARCHAR(100) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `postal_code` VARCHAR(20) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT 'India',
  -- Tax Details
  `gst_registered` TINYINT(1) DEFAULT 0,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `pan` VARCHAR(20) DEFAULT NULL,
  `tax_type` VARCHAR(50) DEFAULT NULL,
  `default_tax_rate` DECIMAL(5,2) DEFAULT 18.00,
  `place_of_business` VARCHAR(100) DEFAULT NULL,
  -- Banking Details
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `account_holder` VARCHAR(255) DEFAULT NULL,
  `account_number` VARCHAR(50) DEFAULT NULL,
  `ifsc_code` VARCHAR(20) DEFAULT NULL,
  `bank_branch` VARCHAR(255) DEFAULT NULL,
  `upi_id` VARCHAR(100) DEFAULT NULL,
  `payment_instructions` TEXT DEFAULT NULL,
  -- Invoice Configuration
  `invoice_prefix` VARCHAR(20) DEFAULT 'INV-',
  `invoice_next_number` INT UNSIGNED DEFAULT 1,
  `quotation_prefix` VARCHAR(20) DEFAULT 'QT-',
  `quotation_next_number` INT UNSIGNED DEFAULT 1,
  `default_due_days` INT UNSIGNED DEFAULT 30,
  `currency` VARCHAR(10) DEFAULT 'INR',
  `default_currency` VARCHAR(10) DEFAULT 'INR',
  `default_notes` TEXT DEFAULT NULL,
  `default_terms` TEXT DEFAULT NULL,
  `default_footer` TEXT DEFAULT NULL,
  `show_logo_on_invoice` TINYINT(1) DEFAULT 1,
  `show_signature` TINYINT(1) DEFAULT 0,
  `amount_in_words` TINYINT(1) DEFAULT 1,
  -- SaaS, Sync & Status
  `current_plan_slug` VARCHAR(50) DEFAULT 'free',
  `sync_enabled` TINYINT(1) DEFAULT 1,
  `suspended_at` DATETIME DEFAULT NULL,
  `suspended_reason` TEXT DEFAULT NULL,
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_businesses_uuid` (`tenant_uuid`),
  INDEX `idx_businesses_status` (`status`),
  INDEX `idx_businesses_plan` (`current_plan_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. ROLES & RBAC PERMISSIONS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_system` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_roles_biz` (`business_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `group_name` VARCHAR(100) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_sensitive` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_permissions_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_role_permission` (`role_id`, `permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. USERS, PREFERENCES & AUTHENTICATION
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `verification_status` ENUM('pending', 'verified', 'suspended') DEFAULT 'verified',
  `phone` VARCHAR(50) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `avatar_path` VARCHAR(500) DEFAULT NULL,
  `is_owner` TINYINT(1) DEFAULT 0,
  `is_super_admin` TINYINT(1) DEFAULT 0,
  `onboarding_completed` TINYINT(1) DEFAULT 1,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_users_biz` (`business_id`),
  INDEX `idx_users_email` (`email`),
  INDEX `idx_users_role` (`role_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL UNIQUE,
  `theme` VARCHAR(20) DEFAULT 'system',
  `language` VARCHAR(10) DEFAULT 'en',
  `email_notifications` TINYINT(1) DEFAULT 1,
  `desktop_notifications` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `setting_group` VARCHAR(50) DEFAULT 'general',
  `is_encrypted` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_business_setting` (`business_id`, `setting_key`),
  INDEX `idx_settings_group` (`business_id`, `setting_group`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. DESKTOP CLIENT API, TOKENS & OFFLINE SYNC INFRASTRUCTURE
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL DEFAULT 'desktop_session',
  `device_id` VARCHAR(100) DEFAULT NULL,
  `abilities` TEXT DEFAULT NULL,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_api_tokens_lookup` (`token_hash`),
  INDEX `idx_api_tokens_biz_user` (`business_id`, `user_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_devices` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `device_id` VARCHAR(100) NOT NULL,
  `device_name` VARCHAR(255) NOT NULL,
  `platform` VARCHAR(50) DEFAULT 'windows',
  `client_version` VARCHAR(50) DEFAULT '1.0.1',
  `is_active` TINYINT(1) DEFAULT 1,
  `last_sync_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_biz_device` (`business_id`, `device_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `api_idempotency_keys` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `endpoint` VARCHAR(255) NOT NULL,
  `request_hash` VARCHAR(64) NOT NULL,
  `response_code` SMALLINT UNSIGNED NOT NULL,
  `response_body` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  UNIQUE KEY `uk_biz_idempotency` (`business_id`, `idempotency_key`),
  INDEX `idx_idempotency_expiry` (`expires_at`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_operations_queue` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `operation_uuid` CHAR(36) NOT NULL UNIQUE,
  `business_id` INT UNSIGNED NOT NULL,
  `device_id` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_uuid` CHAR(36) NOT NULL,
  `operation_type` ENUM('insert', 'update', 'delete') NOT NULL,
  `payload` LONGTEXT NOT NULL,
  `client_timestamp` TIMESTAMP NOT NULL,
  `status` ENUM('pending', 'in_flight', 'synced', 'conflict', 'failed') DEFAULT 'pending',
  `retry_count` INT UNSIGNED DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sync_queue_biz_status` (`business_id`, `status`),
  INDEX `idx_sync_queue_entity` (`entity_type`, `entity_uuid`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sync_tombstones` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_uuid` CHAR(36) NOT NULL,
  `deleted_by` INT UNSIGNED DEFAULT NULL,
  `deleted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_tombstone_record` (`business_id`, `entity_type`, `entity_uuid`),
  INDEX `idx_tombstone_cursor` (`business_id`, `deleted_at`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. CUSTOMERS & KHATA / LEDGER
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `customers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sync_uuid` CHAR(36) DEFAULT NULL,
  `business_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `company_name` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `alt_phone` VARCHAR(50) DEFAULT NULL,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `pan` VARCHAR(20) DEFAULT NULL,
  -- Billing Address
  `billing_address_line1` VARCHAR(255) DEFAULT NULL,
  `billing_address_line2` VARCHAR(255) DEFAULT NULL,
  `billing_city` VARCHAR(100) DEFAULT NULL,
  `billing_state` VARCHAR(100) DEFAULT NULL,
  `billing_postal_code` VARCHAR(20) DEFAULT NULL,
  `billing_country` VARCHAR(100) DEFAULT 'India',
  -- Shipping Address
  `shipping_address_line1` VARCHAR(255) DEFAULT NULL,
  `shipping_city` VARCHAR(100) DEFAULT NULL,
  `shipping_state` VARCHAR(100) DEFAULT NULL,
  `shipping_postal_code` VARCHAR(20) DEFAULT NULL,
  `shipping_country` VARCHAR(100) DEFAULT 'India',
  -- Financial & Khata
  `category` VARCHAR(100) DEFAULT NULL,
  `credit_limit` DECIMAL(15,2) DEFAULT NULL,
  `opening_balance` DECIMAL(15,2) DEFAULT 0.00,
  `opening_balance_type` VARCHAR(20) DEFAULT 'debit',
  `current_balance` DECIMAL(15,2) DEFAULT 0.00,
  `balance` DECIMAL(15,2) DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('active', 'inactive', 'archived') DEFAULT 'active',
  -- Sync Parity
  `sync_version` BIGINT UNSIGNED DEFAULT 1,
  `client_updated_at` TIMESTAMP NULL DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_customers_biz` (`business_id`),
  INDEX `idx_customers_name` (`business_id`, `name`),
  INDEX `idx_customers_phone` (`business_id`, `phone`),
  INDEX `idx_customers_sync` (`business_id`, `sync_version`),
  INDEX `idx_customers_uuid` (`sync_uuid`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customer_ledger` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid` VARCHAR(36) NOT NULL UNIQUE,
  `business_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `entry_type` VARCHAR(50) NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `reference_id` INT UNSIGNED DEFAULT NULL,
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `entry_date` DATE NOT NULL,
  `debit` DECIMAL(15,2) DEFAULT 0.00,
  `credit` DECIMAL(15,2) DEFAULT 0.00,
  `running_balance` DECIMAL(15,2) DEFAULT 0.00,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `item_details` TEXT DEFAULT NULL,
  `image_attachment` VARCHAR(255) DEFAULT NULL,
  `voice_attachment` VARCHAR(255) DEFAULT NULL,
  `idempotency_key` VARCHAR(100) UNIQUE DEFAULT NULL,
  `sync_uuid` VARCHAR(36) DEFAULT NULL,
  `sync_status` VARCHAR(20) DEFAULT 'pending',
  `sync_version` BIGINT UNSIGNED DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_cust_ledger_cust_date` (`business_id`, `customer_id`, `entry_date`, `id`),
  INDEX `idx_cust_ledger_ref` (`business_id`, `reference_type`, `reference_id`),
  INDEX `idx_cust_ledger_uuid` (`uuid`),
  INDEX `idx_cust_ledger_sync_ver` (`business_id`, `sync_version`),
  INDEX `idx_cust_ledger_sync_uuid` (`business_id`, `sync_uuid`),
  INDEX `idx_cust_ledger_idemp` (`idempotency_key`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `leads` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sync_uuid` CHAR(36) DEFAULT NULL,
  `business_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `contact_name` VARCHAR(255) DEFAULT NULL,
  `company_name` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `deal_value` DECIMAL(15,2) DEFAULT NULL,
  `stage` VARCHAR(50) DEFAULT 'new',
  `source` VARCHAR(100) DEFAULT NULL,
  `assigned_to` INT UNSIGNED DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `sync_version` BIGINT UNSIGNED DEFAULT 1,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_leads_biz_stage` (`business_id`, `stage`),
  INDEX `idx_leads_assigned` (`assigned_to`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. PRODUCTS, INVENTORY & STOCK AUTOMATION
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sync_uuid` VARCHAR(64) DEFAULT NULL,
  `business_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('product', 'service') DEFAULT 'product',
  `sku` VARCHAR(100) DEFAULT NULL,
  `barcode` VARCHAR(100) DEFAULT NULL,
  `hsn_sac` VARCHAR(20) DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `brand` VARCHAR(100) DEFAULT NULL,
  `model` VARCHAR(100) DEFAULT NULL,
  `part_type` VARCHAR(100) DEFAULT 'General Part',
  `selling_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `cost_price` DECIMAL(15,2) DEFAULT NULL,
  `sale_price` DECIMAL(15,2) DEFAULT 0.00,
  `purchase_price` DECIMAL(15,2) DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `unit` VARCHAR(50) DEFAULT 'pcs',
  `stock_quantity` DECIMAL(12,3) DEFAULT 0.000,
  `min_stock_alert` DECIMAL(12,3) DEFAULT 0.000,
  `supplier_name` VARCHAR(150) DEFAULT NULL,
  `allow_negative_stock` TINYINT(1) DEFAULT 0,
  `description` TEXT DEFAULT NULL,
  `image_path` VARCHAR(500) DEFAULT NULL,
  `status` ENUM('active', 'inactive', 'archived') DEFAULT 'active',
  `is_active` TINYINT(1) DEFAULT 1,
  -- Sync Parity
  `sync_status` VARCHAR(20) DEFAULT 'synced',
  `sync_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `client_updated_at` TIMESTAMP NULL DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_products_biz` (`business_id`),
  INDEX `idx_products_name` (`business_id`, `name`),
  INDEX `idx_products_sku` (`business_id`, `sku`),
  INDEX `idx_products_barcode` (`business_id`, `barcode`),
  INDEX `idx_products_sync_ver` (`business_id`, `sync_version`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid` VARCHAR(64) DEFAULT NULL,
  `business_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `type` ENUM('purchase', 'sale', 'return_customer', 'return_supplier', 'adjustment', 'cancellation_restore') NOT NULL,
  `quantity` DECIMAL(12, 3) NOT NULL,
  `quantity_before` DECIMAL(12, 3) NOT NULL,
  `quantity_after` DECIMAL(12, 3) NOT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `reference_id` INT UNSIGNED DEFAULT NULL,
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `idempotency_key` VARCHAR(100) DEFAULT NULL,
  `sync_status` VARCHAR(20) NOT NULL DEFAULT 'synced',
  `sync_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `sync_uuid` VARCHAR(64) DEFAULT NULL,
  INDEX `idx_stock_mov_biz_prod` (`business_id`, `product_id`),
  INDEX `idx_stock_mov_ref` (`reference_type`, `reference_id`),
  INDEX `idx_stock_mov_date` (`created_at`),
  INDEX `idx_stock_mov_sync_ver` (`business_id`, `sync_version`),
  INDEX `idx_stock_mov_idemp` (`business_id`, `idempotency_key`),
  INDEX `idx_stock_mov_uuid` (`business_id`, `sync_uuid`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `assigned_to` INT UNSIGNED DEFAULT NULL,
  `related_type` VARCHAR(50) DEFAULT NULL,
  `related_id` INT UNSIGNED DEFAULT NULL,
  `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
  `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
  `due_date` DATE DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_tasks_biz_status` (`business_id`, `status`),
  INDEX `idx_tasks_assigned` (`assigned_to`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. QUOTATIONS, INVOICES & PAYMENTS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `quotations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `quotation_number` VARCHAR(50) NOT NULL,
  `issue_date` DATE NOT NULL,
  `valid_until` DATE DEFAULT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'sent', 'accepted', 'rejected', 'expired', 'converted') DEFAULT 'draft',
  `notes` TEXT DEFAULT NULL,
  `terms` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_quotations_biz` (`business_id`),
  INDEX `idx_quotations_customer` (`customer_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quotation_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quotation_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `item_description` TEXT DEFAULT NULL,
  `hsn_sac` VARCHAR(20) DEFAULT NULL,
  `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `unit` VARCHAR(50) DEFAULT 'pcs',
  `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_percent` DECIMAL(5,2) DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  INDEX `idx_quotation_items_q` (`quotation_id`),
  FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sync_uuid` CHAR(36) DEFAULT NULL,
  `business_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL,
  `issue_date` DATE NOT NULL,
  `due_date` DATE DEFAULT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `balance_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('draft', 'issued', 'partially_paid', 'paid', 'void', 'overdue') DEFAULT 'issued',
  `stock_deducted` TINYINT(1) DEFAULT 0,
  `stock_restored` TINYINT(1) DEFAULT 0,
  `notes` TEXT DEFAULT NULL,
  `terms` TEXT DEFAULT NULL,
  -- Sync Parity
  `sync_version` BIGINT UNSIGNED DEFAULT 1,
  `client_updated_at` TIMESTAMP NULL DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_invoices_biz` (`business_id`),
  INDEX `idx_invoices_customer` (`customer_id`),
  INDEX `idx_invoices_status` (`business_id`, `status`),
  INDEX `idx_invoices_sync` (`business_id`, `sync_version`),
  INDEX `idx_invoices_uuid` (`sync_uuid`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `item_description` TEXT DEFAULT NULL,
  `hsn_sac` VARCHAR(20) DEFAULT NULL,
  `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `unit` VARCHAR(50) DEFAULT 'pcs',
  `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_percent` DECIMAL(5,2) DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  INDEX `idx_invoice_items_inv` (`invoice_id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sync_uuid` CHAR(36) DEFAULT NULL,
  `business_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `payment_number` VARCHAR(50) NOT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'cash',
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  -- Sync Parity
  `sync_version` BIGINT UNSIGNED DEFAULT 1,
  `client_updated_at` TIMESTAMP NULL DEFAULT NULL,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_payments_biz` (`business_id`),
  INDEX `idx_payments_customer` (`customer_id`),
  INDEX `idx_payments_sync` (`business_id`, `sync_version`),
  INDEX `idx_payments_uuid` (`sync_uuid`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_allocations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `payment_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_allocations_payment` (`payment_id`),
  INDEX `idx_allocations_invoice` (`invoice_id`),
  FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_expense_cats_biz` (`business_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `sync_uuid` CHAR(36) DEFAULT NULL,
  `business_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `expense_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `payment_method` VARCHAR(50) DEFAULT 'cash',
  `payee` VARCHAR(255) DEFAULT NULL,
  `reference_number` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `receipt_path` VARCHAR(500) DEFAULT NULL,
  -- Sync Parity
  `sync_version` BIGINT UNSIGNED DEFAULT 1,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_expenses_biz` (`business_id`),
  INDEX `idx_expenses_category` (`category_id`),
  INDEX `idx_expenses_date` (`business_id`, `expense_date`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. SAAS SUPER ADMIN, SUBSCRIPTION ENGINE & PLATFORM SETTINGS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(50) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `badge` VARCHAR(50) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `price_monthly` DECIMAL(10,2) DEFAULT 0.00,
  `price_annual` DECIMAL(10,2) DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'INR',
  `max_users` INT UNSIGNED DEFAULT 1,
  `max_customers` INT UNSIGNED DEFAULT 500,
  `max_invoices_per_month` INT UNSIGNED DEFAULT 100,
  `ai_enabled` TINYINT(1) DEFAULT 0,
  `ai_monthly_limit` INT UNSIGNED DEFAULT 0,
  `offline_sync_enabled` TINYINT(1) DEFAULT 1,
  `features_json` TEXT DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `sort_order` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_plans_slug` (`slug`),
  INDEX `idx_plans_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `business_subscriptions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `plan_id` INT UNSIGNED NOT NULL,
  `billing_cycle` ENUM('monthly', 'annual', 'lifetime') DEFAULT 'monthly',
  `status` ENUM('pending_approval', 'active', 'expired', 'rejected', 'cancelled') DEFAULT 'active',
  `amount_paid` DECIMAL(10,2) DEFAULT 0.00,
  `starts_at` DATETIME DEFAULT NULL,
  `expires_at` DATETIME DEFAULT NULL,
  `payment_method` VARCHAR(50) DEFAULT NULL,
  `payment_reference` VARCHAR(100) DEFAULT NULL,
  `proof_attachment` VARCHAR(255) DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_biz_sub_status` (`business_id`, `status`),
  INDEX `idx_biz_sub_plan` (`plan_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `platform_settings` (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- ------------------------------------------------------------
-- 9. AUDIT, FILES, NOTIFICATIONS & AI INTEGRATION
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `files` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `related_type` VARCHAR(50) DEFAULT NULL,
  `related_id` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_files_biz` (`business_id`),
  INDEX `idx_files_related` (`related_type`, `related_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `link` VARCHAR(500) DEFAULT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notifications_user` (`user_id`, `is_read`),
  INDEX `idx_notifications_biz` (`business_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_activity_biz` (`business_id`, `created_at`),
  INDEX `idx_activity_entity` (`entity_type`, `entity_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_records` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL,
  `backup_type` ENUM('full', 'database', 'files') DEFAULT 'full',
  `status` ENUM('in_progress', 'completed', 'failed') DEFAULT 'completed',
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_backups_biz` (`business_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version` VARCHAR(50) PRIMARY KEY,
  `description` VARCHAR(255) DEFAULT NULL,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_provider_configs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `provider_slug` VARCHAR(50) NOT NULL,
  `display_name` VARCHAR(100) NOT NULL,
  `api_key_encrypted` TEXT DEFAULT NULL,
  `api_endpoint` VARCHAR(500) DEFAULT NULL,
  `model_name` VARCHAR(100) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 0,
  `is_default` TINYINT(1) DEFAULT 0,
  `settings_json` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ai_provider` (`business_id`, `provider_slug`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_access_policies` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `can_use_ai` TINYINT(1) DEFAULT 1,
  `can_access_financials` TINYINT(1) DEFAULT 0,
  `can_propose_actions` TINYINT(1) DEFAULT 0,
  `max_daily_requests` INT UNSIGNED DEFAULT 50,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ai_policy` (`business_id`, `role_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) DEFAULT 'New Conversation',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_conv_user` (`business_id`, `user_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT UNSIGNED NOT NULL,
  `sender` ENUM('user', 'assistant', 'system') NOT NULL,
  `message` TEXT NOT NULL,
  `token_count` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_messages_conv` (`conversation_id`),
  FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_memory_entries` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `memory_key` VARCHAR(100) NOT NULL,
  `memory_value` TEXT NOT NULL,
  `category` VARCHAR(50) DEFAULT 'general',
  `confidence` DECIMAL(3,2) DEFAULT 1.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_memory_biz` (`business_id`, `category`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_prompt_versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `prompt_slug` VARCHAR(50) NOT NULL,
  `version` VARCHAR(20) NOT NULL,
  `system_prompt` TEXT NOT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_prompt_version` (`prompt_slug`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_action_proposals` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `action_type` VARCHAR(50) NOT NULL,
  `payload_json` TEXT NOT NULL,
  `status` ENUM('pending', 'approved', 'rejected', 'executed') DEFAULT 'pending',
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `expires_at` TIMESTAMP NOT NULL,
  `executed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_action_proposals_token` (`token`),
  INDEX `idx_action_proposals_biz` (`business_id`, `status`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_approval_events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `proposal_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `event_type` ENUM('approved', 'rejected') NOT NULL,
  `reason` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`proposal_id`) REFERENCES `ai_action_proposals`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_usage_logs` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `provider_config_id` INT UNSIGNED DEFAULT NULL,
  `tokens_prompt` INT UNSIGNED DEFAULT 0,
  `tokens_completion` INT UNSIGNED DEFAULT 0,
  `cost_estimate` DECIMAL(8,4) DEFAULT 0.0000,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_usage_business` (`business_id`, `created_at`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`provider_config_id`) REFERENCES `ai_provider_configs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SYSTEM SEED DATA (CORE ROLES, PERMISSIONS & PLANS ONLY)
-- NO MOCK BUSINESSES, CUSTOMERS, INVOICES, OR REAL CREDENTIALS
-- ============================================================

-- 1. Standard System Permissions (44 Permissions)
INSERT IGNORE INTO `permissions` (`key`, `group_name`, `label`, `description`, `is_sensitive`) VALUES
('dashboard.view', 'Dashboard', 'View Dashboard', 'Access dashboard analytics', 0),
('customers.view', 'Customers', 'View Customers', 'View customer list and details', 0),
('customers.create', 'Customers', 'Create Customers', 'Add new customers', 0),
('customers.edit', 'Customers', 'Edit Customers', 'Modify customer details', 0),
('customers.archive', 'Customers', 'Archive Customers', 'Archive customer records', 0),
('customers.export', 'Customers', 'Export Customers', 'Export customer data', 0),
('leads.view', 'Leads', 'View Leads', 'View leads and pipeline', 0),
('leads.create', 'Leads', 'Create Leads', 'Add new leads', 0),
('leads.edit', 'Leads', 'Edit Leads', 'Modify lead details', 0),
('leads.archive', 'Leads', 'Archive Leads', 'Archive lead records', 0),
('leads.change_stage', 'Leads', 'Change Lead Stage', 'Move leads between pipeline stages', 0),
('products.view', 'Products', 'View Products', 'View products and services', 0),
('products.create', 'Products', 'Create Products', 'Add new products/services', 0),
('products.edit', 'Products', 'Edit Products', 'Modify product details', 0),
('products.archive', 'Products', 'Archive Products', 'Archive product records', 0),
('products.edit_prices', 'Products', 'Edit Prices', 'Modify product pricing', 1),
('tasks.view', 'Tasks', 'View Tasks', 'View task list', 0),
('tasks.create', 'Tasks', 'Create Tasks', 'Add new tasks', 0),
('tasks.edit', 'Tasks', 'Edit Tasks', 'Modify task details', 0),
('tasks.assign', 'Tasks', 'Assign Tasks', 'Assign tasks to managers', 0),
('tasks.complete', 'Tasks', 'Complete Tasks', 'Mark tasks as completed', 0),
('quotations.view', 'Quotations', 'View Quotations', 'View quotation list', 0),
('quotations.create', 'Quotations', 'Create Quotations', 'Create new quotations', 0),
('quotations.edit', 'Quotations', 'Edit Quotations', 'Modify quotation details', 0),
('quotations.approve', 'Quotations', 'Approve Quotations', 'Approve quotations', 1),
('quotations.archive', 'Quotations', 'Archive Quotations', 'Archive quotation records', 0),
('invoices.view', 'Invoices', 'View Invoices', 'View invoice list and details', 0),
('invoices.create', 'Invoices', 'Create Invoices', 'Create draft invoices', 0),
('invoices.issue', 'Invoices', 'Issue Invoices', 'Issue invoices to customers', 1),
('invoices.void', 'Invoices', 'Void Invoices', 'Void/cancel invoices', 1),
('invoices.print', 'Invoices', 'Print Invoices', 'Print invoices', 0),
('invoices.export', 'Invoices', 'Export Invoices', 'Export invoice data', 0),
('payments.view', 'Payments', 'View Payments', 'View payment records', 0),
('payments.record', 'Payments', 'Record Payments', 'Record new payments', 1),
('payments.allocate', 'Payments', 'Allocate Payments', 'Allocate payments to invoices', 1),
('payments.reverse', 'Payments', 'Reverse Payments', 'Reverse payment records', 1),
('expenses.view', 'Expenses', 'View Expenses', 'View expense records', 0),
('expenses.create', 'Expenses', 'Create Expenses', 'Record new expenses', 0),
('expenses.edit', 'Expenses', 'Edit Expenses', 'Modify expense records', 0),
('expenses.archive', 'Expenses', 'Archive Expenses', 'Archive expense records', 0),
('reports.view', 'Reports', 'View Reports', 'Access report section', 0),
('reports.export', 'Reports', 'Export Reports', 'Export report data', 0),
('ai.access', 'AI', 'Access AI', 'Use AI assistant', 0),
('ai.search', 'AI', 'AI Search', 'Use AI to search records', 0),
('ai.financial', 'AI', 'AI Financial Data', 'Allow AI to access financial data', 1),
('ai.propose_actions', 'AI', 'AI Action Proposals', 'Allow AI to prepare action proposals', 1),
('settings.access', 'Settings', 'Access Settings', 'View settings panel', 0),
('settings.edit', 'Settings', 'Edit Settings', 'Modify settings', 1),
('managers.view', 'Managers', 'View Managers', 'View manager accounts', 0),
('managers.manage', 'Managers', 'Manage Managers', 'Create/edit manager accounts', 1),
('backup.create', 'Backup', 'Create Backup', 'Create data backups', 1),
('backup.download', 'Backup', 'Download Backup', 'Download backup files', 1),
('backup.restore', 'Backup', 'Restore Backup', 'Restore from backup', 1),
('backup.reset', 'Backup', 'Reset Data', 'Reset/clear data', 1);

-- 2. Standard SaaS Subscription Plans
INSERT IGNORE INTO `subscription_plans` 
(`id`, `slug`, `name`, `badge`, `description`, `price_monthly`, `price_annual`, `currency`, `max_users`, `max_customers`, `max_invoices_per_month`, `ai_enabled`, `ai_monthly_limit`, `offline_sync_enabled`, `features_json`, `is_active`, `sort_order`) 
VALUES
(1, 'free', 'Free Trial / Starter', 'Pilot', 'Standard offline desktop CRM with ledger and billing', 0.00, 0.00, 'INR', 2, 500, 100, 0, 0, 1, '["offline_sqlite","customer_ledger","invoice_billing","desktop_sync"]', 1, 1),
(2, 'starter', 'Small Business', 'Popular', 'Ideal for single-shop retailers and billing terminals', 499.00, 4999.00, 'INR', 3, 2000, 500, 0, 0, 1, '["offline_sqlite","customer_ledger","invoice_billing","desktop_sync","whatsapp_billing"]', 1, 2),
(3, 'professional', 'Professional Growth', 'Growth', 'Unlimited invoicing, stock movements, and multi-terminal sync', 999.00, 9999.00, 'INR', 10, 10000, 5000, 1, 100, 1, '["all_starter_features","unlimited_invoices","stock_movements","thermal_print","priority_support"]', 1, 3),
(4, 'enterprise', 'Enterprise Fleet', 'VIP', 'Multi-branch sync, dedicated cloud instance and full support', 2499.00, 24999.00, 'INR', 50, 50000, 25000, 1, 500, 1, '["all_pro_features","multi_branch","unlimited_terminals","dedicated_support"]', 1, 4);

-- 3. Default Platform Settings
INSERT IGNORE INTO `platform_settings` (`key`, `value`, `description`) VALUES
('app_name', 'DJ CRM Software', 'Platform branding title'),
('app_version', '1.0.1', 'Core platform version'),
('registration_enabled', '1', 'Public self-registration status'),
('default_trial_days', '30', 'Free trial duration for new business accounts'),
('support_whatsapp', '+923000000000', 'Platform customer support contact');

-- 4. Initial Schema Migration Tracking
INSERT IGNORE INTO `schema_migrations` (`version`, `description`) VALUES 
('1.0.1', 'DJ CRM Software Consolidated Clean Cloud Pilot Schema');

SET FOREIGN_KEY_CHECKS = 1;

-- Record Initial Version
INSERT IGNORE INTO `schema_migrations` (`version`, `description`) VALUES
('1.0.0', 'Clean pilot baseline'),
('5.1.0', 'DJ CRM Software 5.1 production baseline'),
('5.2.0', 'DJ CRM Software 5.2 production release');

SET FOREIGN_KEY_CHECKS = 1;
