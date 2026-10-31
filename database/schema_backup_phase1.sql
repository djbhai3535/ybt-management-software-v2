-- YBT Management Software — Database Schema
-- MySQL / MariaDB
-- Run: mysql -u root < database/schema.sql

CREATE DATABASE IF NOT EXISTS `ybt_management`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `ybt_management`;

-- ============================================================
-- CORE TABLES
-- ============================================================

CREATE TABLE `businesses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `legal_name` VARCHAR(255) DEFAULT NULL,
  `owner_name` VARCHAR(255) DEFAULT NULL,
  `type` VARCHAR(100) DEFAULT NULL,
  `industry` VARCHAR(100) DEFAULT NULL,
  `registration_number` VARCHAR(100) DEFAULT NULL,
  `established_date` DATE DEFAULT NULL,
  `logo_path` VARCHAR(500) DEFAULT NULL,
  `tagline` VARCHAR(255) DEFAULT NULL,
  `website` VARCHAR(255) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  -- Contact
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
  -- Tax
  `gst_registered` TINYINT(1) DEFAULT 0,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `pan` VARCHAR(20) DEFAULT NULL,
  `tax_type` VARCHAR(50) DEFAULT NULL,
  `default_tax_rate` DECIMAL(5,2) DEFAULT 18.00,
  `place_of_business` VARCHAR(100) DEFAULT NULL,
  -- Banking
  `bank_name` VARCHAR(255) DEFAULT NULL,
  `account_holder` VARCHAR(255) DEFAULT NULL,
  `account_number` VARCHAR(50) DEFAULT NULL,
  `ifsc_code` VARCHAR(20) DEFAULT NULL,
  `bank_branch` VARCHAR(255) DEFAULT NULL,
  `upi_id` VARCHAR(100) DEFAULT NULL,
  `payment_instructions` TEXT DEFAULT NULL,
  -- Invoice defaults
  `invoice_prefix` VARCHAR(20) DEFAULT 'INV-',
  `invoice_next_number` INT UNSIGNED DEFAULT 1,
  `quotation_prefix` VARCHAR(20) DEFAULT 'QT-',
  `quotation_next_number` INT UNSIGNED DEFAULT 1,
  `default_due_days` INT UNSIGNED DEFAULT 30,
  `default_currency` VARCHAR(10) DEFAULT 'INR',
  `default_notes` TEXT DEFAULT NULL,
  `default_terms` TEXT DEFAULT NULL,
  `default_footer` TEXT DEFAULT NULL,
  `show_logo_on_invoice` TINYINT(1) DEFAULT 1,
  `show_signature` TINYINT(1) DEFAULT 0,
  `amount_in_words` TINYINT(1) DEFAULT 1,
  -- Meta
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_businesses_demo` (`is_demo`)
) ENGINE=InnoDB;

CREATE TABLE `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `slug` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_system` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_role_business` (`business_id`, `slug`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `permissions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(100) NOT NULL UNIQUE,
  `group_name` VARCHAR(50) NOT NULL,
  `label` VARCHAR(255) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `is_sensitive` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE `role_permissions` (
  `role_id` INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `avatar_path` VARCHAR(500) DEFAULT NULL,
  `is_owner` TINYINT(1) DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  `failed_login_count` INT UNSIGNED DEFAULT 0,
  `locked_until` TIMESTAMP NULL DEFAULT NULL,
  `password_reset_token` VARCHAR(255) DEFAULT NULL,
  `password_reset_expires` TIMESTAMP NULL DEFAULT NULL,
  `remember_token` VARCHAR(255) DEFAULT NULL,
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_email` (`email`),
  INDEX `idx_users_business` (`business_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `user_preferences` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `pref_key` VARCHAR(100) NOT NULL,
  `pref_value` TEXT DEFAULT NULL,
  UNIQUE KEY `uk_user_pref` (`user_id`, `pref_key`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `business_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `setting_group` VARCHAR(50) DEFAULT 'general',
  UNIQUE KEY `uk_biz_setting` (`business_id`, `setting_key`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- CRM TABLES
-- ============================================================

CREATE TABLE `customers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `type` ENUM('individual','business') DEFAULT 'individual',
  `name` VARCHAR(255) NOT NULL,
  `company_name` VARCHAR(255) DEFAULT NULL,
  `contact_person` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `alt_phone` VARCHAR(50) DEFAULT NULL,
  `billing_address_line1` VARCHAR(255) DEFAULT NULL,
  `billing_address_line2` VARCHAR(255) DEFAULT NULL,
  `billing_city` VARCHAR(100) DEFAULT NULL,
  `billing_state` VARCHAR(100) DEFAULT NULL,
  `billing_postal_code` VARCHAR(20) DEFAULT NULL,
  `billing_country` VARCHAR(100) DEFAULT 'India',
  `shipping_address_line1` VARCHAR(255) DEFAULT NULL,
  `shipping_address_line2` VARCHAR(255) DEFAULT NULL,
  `shipping_city` VARCHAR(100) DEFAULT NULL,
  `shipping_state` VARCHAR(100) DEFAULT NULL,
  `shipping_postal_code` VARCHAR(20) DEFAULT NULL,
  `shipping_country` VARCHAR(100) DEFAULT NULL,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `pan` VARCHAR(20) DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `tags` VARCHAR(500) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('active','archived') DEFAULT 'active',
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_customers_business` (`business_id`),
  INDEX `idx_customers_status` (`status`),
  INDEX `idx_customers_name` (`name`),
  INDEX `idx_customers_demo` (`is_demo`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `leads` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `contact_name` VARCHAR(255) DEFAULT NULL,
  `company_name` VARCHAR(255) DEFAULT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `source` VARCHAR(100) DEFAULT NULL,
  `stage` VARCHAR(50) DEFAULT 'new',
  `deal_value` DECIMAL(15,2) DEFAULT NULL,
  `expected_close_date` DATE DEFAULT NULL,
  `assigned_to` INT UNSIGNED DEFAULT NULL,
  `follow_up_date` DATE DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('active','won','lost','archived') DEFAULT 'active',
  `converted_customer_id` INT UNSIGNED DEFAULT NULL,
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_leads_business` (`business_id`),
  INDEX `idx_leads_stage` (`stage`),
  INDEX `idx_leads_status` (`status`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`converted_customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `products` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `type` ENUM('product','service') DEFAULT 'product',
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `category` VARCHAR(100) DEFAULT NULL,
  `sku` VARCHAR(100) DEFAULT NULL,
  `unit` VARCHAR(50) DEFAULT 'piece',
  `selling_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `cost_price` DECIMAL(15,2) DEFAULT NULL,
  `tax_rate` DECIMAL(5,2) DEFAULT NULL,
  `hsn_sac` VARCHAR(20) DEFAULT NULL,
  `status` ENUM('active','inactive') DEFAULT 'active',
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_products_business` (`business_id`),
  INDEX `idx_products_status` (`status`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `tasks` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `priority` ENUM('low','medium','high','urgent') DEFAULT 'medium',
  `status` ENUM('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `due_date` DATETIME DEFAULT NULL,
  `reminder_date` DATETIME DEFAULT NULL,
  `completed_at` DATETIME DEFAULT NULL,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `lead_id` INT UNSIGNED DEFAULT NULL,
  `assigned_to` INT UNSIGNED DEFAULT NULL,
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_tasks_business` (`business_id`),
  INDEX `idx_tasks_status` (`status`),
  INDEX `idx_tasks_due` (`due_date`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- FINANCIAL TABLES
-- ============================================================

CREATE TABLE `quotations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `quotation_number` VARCHAR(50) NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `quotation_date` DATE NOT NULL,
  `validity_date` DATE DEFAULT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `grand_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `notes` TEXT DEFAULT NULL,
  `terms` TEXT DEFAULT NULL,
  `status` ENUM('draft','sent','accepted','rejected','expired','converted') DEFAULT 'draft',
  `converted_invoice_id` INT UNSIGNED DEFAULT NULL,
  -- Snapshot
  `business_snapshot` JSON DEFAULT NULL,
  `customer_snapshot` JSON DEFAULT NULL,
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_quotation_number` (`business_id`, `quotation_number`),
  INDEX `idx_quotations_customer` (`customer_id`),
  INDEX `idx_quotations_status` (`status`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `quotation_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `quotation_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(500) NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `unit` VARCHAR(50) DEFAULT 'piece',
  `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_percent` DECIMAL(5,2) DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `hsn_sac` VARCHAR(20) DEFAULT NULL,
  `line_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `sort_order` INT DEFAULT 0,
  FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `invoice_number` VARCHAR(50) NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `quotation_id` INT UNSIGNED DEFAULT NULL,
  `invoice_type` ENUM('standard','gst','non_gst','proforma') DEFAULT 'standard',
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `subtotal` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `tax_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `grand_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `outstanding` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) DEFAULT 'INR',
  `notes` TEXT DEFAULT NULL,
  `terms` TEXT DEFAULT NULL,
  `footer` TEXT DEFAULT NULL,
  `payment_instructions` TEXT DEFAULT NULL,
  `status` ENUM('draft','issued','partially_paid','paid','overdue','void','cancelled') DEFAULT 'draft',
  -- GST fields
  `place_of_supply` VARCHAR(100) DEFAULT NULL,
  `cgst_total` DECIMAL(15,2) DEFAULT 0.00,
  `sgst_total` DECIMAL(15,2) DEFAULT 0.00,
  `igst_total` DECIMAL(15,2) DEFAULT 0.00,
  -- Snapshots (preserve business/customer details at issue time)
  `business_snapshot` JSON DEFAULT NULL,
  `customer_snapshot` JSON DEFAULT NULL,
  `pdf_path` VARCHAR(500) DEFAULT NULL,
  `is_demo` TINYINT(1) DEFAULT 0,
  `issued_at` TIMESTAMP NULL DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_invoice_number` (`business_id`, `invoice_number`),
  INDEX `idx_invoices_customer` (`customer_id`),
  INDEX `idx_invoices_status` (`status`),
  INDEX `idx_invoices_date` (`invoice_date`),
  INDEX `idx_invoices_due` (`due_date`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `invoice_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(500) NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `unit` VARCHAR(50) DEFAULT 'piece',
  `unit_price` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `discount_percent` DECIMAL(5,2) DEFAULT 0.00,
  `discount_amount` DECIMAL(15,2) DEFAULT 0.00,
  `tax_rate` DECIMAL(5,2) DEFAULT 0.00,
  `tax_amount` DECIMAL(15,2) DEFAULT 0.00,
  `cgst_rate` DECIMAL(5,2) DEFAULT 0.00,
  `cgst_amount` DECIMAL(15,2) DEFAULT 0.00,
  `sgst_rate` DECIMAL(5,2) DEFAULT 0.00,
  `sgst_amount` DECIMAL(15,2) DEFAULT 0.00,
  `igst_rate` DECIMAL(5,2) DEFAULT 0.00,
  `igst_amount` DECIMAL(15,2) DEFAULT 0.00,
  `hsn_sac` VARCHAR(20) DEFAULT NULL,
  `line_total` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `sort_order` INT DEFAULT 0,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `payment_number` VARCHAR(50) DEFAULT NULL,
  `payment_date` DATE NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `method` ENUM('cash','bank_transfer','upi','card','cheque','other') DEFAULT 'cash',
  `reference` VARCHAR(255) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('completed','reversed','pending') DEFAULT 'completed',
  `reversed_at` TIMESTAMP NULL DEFAULT NULL,
  `reversal_reason` TEXT DEFAULT NULL,
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_payments_business` (`business_id`),
  INDEX `idx_payments_customer` (`customer_id`),
  INDEX `idx_payments_date` (`payment_date`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `payment_allocations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `payment_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_payment_invoice` (`payment_id`, `invoice_id`),
  FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `expense_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `color` VARCHAR(10) DEFAULT '#64748B',
  `is_demo` TINYINT(1) DEFAULT 0,
  UNIQUE KEY `uk_expense_cat` (`business_id`, `name`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `expenses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `expense_date` DATE NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `method` ENUM('cash','bank_transfer','upi','card','cheque','other') DEFAULT 'cash',
  `vendor` VARCHAR(255) DEFAULT NULL,
  `reference` VARCHAR(255) DEFAULT NULL,
  `receipt_path` VARCHAR(500) DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `status` ENUM('active','archived') DEFAULT 'active',
  `is_demo` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_expenses_business` (`business_id`),
  INDEX `idx_expenses_date` (`expense_date`),
  INDEX `idx_expenses_category` (`category_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- SYSTEM TABLES
-- ============================================================

CREATE TABLE `files` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `size_bytes` INT UNSIGNED DEFAULT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `uploaded_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT DEFAULT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_notifications_user` (`user_id`, `is_read`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `activity_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_activity_business` (`business_id`),
  INDEX `idx_activity_user` (`user_id`),
  INDEX `idx_activity_entity` (`entity_type`, `entity_id`),
  INDEX `idx_activity_created` (`created_at`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `backup_records` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `type` ENUM('full','database','files') DEFAULT 'full',
  `filename` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `size_bytes` BIGINT UNSIGNED DEFAULT NULL,
  `status` ENUM('completed','failed','in_progress') DEFAULT 'completed',
  `notes` VARCHAR(500) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `schema_migrations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `version` VARCHAR(20) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- AI TABLES
-- ============================================================

CREATE TABLE `ai_provider_configs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `provider_type` VARCHAR(50) NOT NULL,
  `display_name` VARCHAR(100) NOT NULL,
  `base_url` VARCHAR(500) DEFAULT NULL,
  `api_key_encrypted` TEXT DEFAULT NULL,
  `model_id` VARCHAR(100) DEFAULT NULL,
  `organization_id` VARCHAR(100) DEFAULT NULL,
  `custom_headers` JSON DEFAULT NULL,
  `connect_timeout` INT UNSIGNED DEFAULT 10,
  `request_timeout` INT UNSIGNED DEFAULT 60,
  `max_tokens` INT UNSIGNED DEFAULT 4096,
  `temperature` DECIMAL(3,2) DEFAULT 0.70,
  `is_active` TINYINT(1) DEFAULT 0,
  `is_default` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_provider_business` (`business_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `ai_access_policies` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `ai_enabled` TINYINT(1) DEFAULT 0,
  `allowed_modules` JSON DEFAULT NULL,
  `financial_data_access` TINYINT(1) DEFAULT 0,
  `sensitive_field_masking` TINYINT(1) DEFAULT 1,
  `action_proposals_allowed` TINYINT(1) DEFAULT 0,
  `bulk_operations_allowed` TINYINT(1) DEFAULT 0,
  `memory_enabled` TINYINT(1) DEFAULT 0,
  `history_retention_days` INT UNSIGNED DEFAULT 90,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_ai_policy_business` (`business_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `ai_conversations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(255) DEFAULT 'New Conversation',
  `provider_config_id` INT UNSIGNED DEFAULT NULL,
  `is_archived` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_conv_user` (`user_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`provider_config_id`) REFERENCES `ai_provider_configs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `ai_messages` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `conversation_id` INT UNSIGNED NOT NULL,
  `role` ENUM('user','assistant','system','tool') NOT NULL,
  `content` MEDIUMTEXT DEFAULT NULL,
  `tool_calls` JSON DEFAULT NULL,
  `tool_results` JSON DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `tokens_used` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_msg_conv` (`conversation_id`),
  FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `ai_memory_entries` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `memory_type` ENUM('user_preference','business_context','conversation_summary') NOT NULL,
  `content` TEXT NOT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ai_memory_business` (`business_id`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `ai_prompt_versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `prompt_type` VARCHAR(50) DEFAULT 'system',
  `content` MEDIUMTEXT NOT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) DEFAULT 0,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `ai_action_proposals` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `conversation_id` INT UNSIGNED DEFAULT NULL,
  `requested_by` INT UNSIGNED NOT NULL,
  `action_type` VARCHAR(100) NOT NULL,
  `target_entity` VARCHAR(50) DEFAULT NULL,
  `target_id` INT UNSIGNED DEFAULT NULL,
  `proposal_data` JSON NOT NULL,
  `status` ENUM('pending','approved','rejected','expired','executed') DEFAULT 'pending',
  `approval_token` VARCHAR(255) DEFAULT NULL,
  `token_expires_at` TIMESTAMP NULL DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `executed_at` TIMESTAMP NULL DEFAULT NULL,
  `result` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_proposal_status` (`status`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`),
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `ai_approval_events` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `proposal_id` INT UNSIGNED NOT NULL,
  `event_type` ENUM('submitted','approved','rejected','expired','executed','failed') NOT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `details` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`proposal_id`) REFERENCES `ai_action_proposals`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `ai_usage_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `business_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `provider_config_id` INT UNSIGNED DEFAULT NULL,
  `model_id` VARCHAR(100) DEFAULT NULL,
  `prompt_tokens` INT UNSIGNED DEFAULT 0,
  `completion_tokens` INT UNSIGNED DEFAULT 0,
  `total_tokens` INT UNSIGNED DEFAULT 0,
  `request_type` VARCHAR(50) DEFAULT NULL,
  `duration_ms` INT UNSIGNED DEFAULT NULL,
  `status` VARCHAR(20) DEFAULT 'success',
  `error_message` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ai_usage_business` (`business_id`, `created_at`),
  FOREIGN KEY (`business_id`) REFERENCES `businesses`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`provider_config_id`) REFERENCES `ai_provider_configs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- SEED: Permissions
-- ============================================================

INSERT INTO `permissions` (`key`, `group_name`, `label`, `description`, `is_sensitive`) VALUES
-- Dashboard
('dashboard.view', 'Dashboard', 'View Dashboard', 'Access dashboard analytics', 0),
-- Customers
('customers.view', 'Customers', 'View Customers', 'View customer list and details', 0),
('customers.create', 'Customers', 'Create Customers', 'Add new customers', 0),
('customers.edit', 'Customers', 'Edit Customers', 'Modify customer details', 0),
('customers.archive', 'Customers', 'Archive Customers', 'Archive customer records', 0),
('customers.export', 'Customers', 'Export Customers', 'Export customer data', 0),
-- Leads
('leads.view', 'Leads', 'View Leads', 'View leads and pipeline', 0),
('leads.create', 'Leads', 'Create Leads', 'Add new leads', 0),
('leads.edit', 'Leads', 'Edit Leads', 'Modify lead details', 0),
('leads.archive', 'Leads', 'Archive Leads', 'Archive lead records', 0),
('leads.change_stage', 'Leads', 'Change Lead Stage', 'Move leads between pipeline stages', 0),
-- Products
('products.view', 'Products', 'View Products', 'View products and services', 0),
('products.create', 'Products', 'Create Products', 'Add new products/services', 0),
('products.edit', 'Products', 'Edit Products', 'Modify product details', 0),
('products.archive', 'Products', 'Archive Products', 'Archive product records', 0),
('products.edit_prices', 'Products', 'Edit Prices', 'Modify product pricing', 1),
-- Tasks
('tasks.view', 'Tasks', 'View Tasks', 'View task list', 0),
('tasks.create', 'Tasks', 'Create Tasks', 'Add new tasks', 0),
('tasks.edit', 'Tasks', 'Edit Tasks', 'Modify task details', 0),
('tasks.assign', 'Tasks', 'Assign Tasks', 'Assign tasks to managers', 0),
('tasks.complete', 'Tasks', 'Complete Tasks', 'Mark tasks as completed', 0),
-- Quotations
('quotations.view', 'Quotations', 'View Quotations', 'View quotation list', 0),
('quotations.create', 'Quotations', 'Create Quotations', 'Create new quotations', 0),
('quotations.edit', 'Quotations', 'Edit Quotations', 'Modify quotation details', 0),
('quotations.approve', 'Quotations', 'Approve Quotations', 'Approve quotations', 1),
('quotations.archive', 'Quotations', 'Archive Quotations', 'Archive quotation records', 0),
-- Invoices
('invoices.view', 'Invoices', 'View Invoices', 'View invoice list and details', 0),
('invoices.create', 'Invoices', 'Create Invoices', 'Create draft invoices', 0),
('invoices.issue', 'Invoices', 'Issue Invoices', 'Issue invoices to customers', 1),
('invoices.void', 'Invoices', 'Void Invoices', 'Void/cancel invoices', 1),
('invoices.print', 'Invoices', 'Print Invoices', 'Print invoices', 0),
('invoices.export', 'Invoices', 'Export Invoices', 'Export invoice data', 0),
-- Payments
('payments.view', 'Payments', 'View Payments', 'View payment records', 0),
('payments.record', 'Payments', 'Record Payments', 'Record new payments', 1),
('payments.allocate', 'Payments', 'Allocate Payments', 'Allocate payments to invoices', 1),
('payments.reverse', 'Payments', 'Reverse Payments', 'Reverse payment records', 1),
-- Expenses
('expenses.view', 'Expenses', 'View Expenses', 'View expense records', 0),
('expenses.create', 'Expenses', 'Create Expenses', 'Record new expenses', 0),
('expenses.edit', 'Expenses', 'Edit Expenses', 'Modify expense records', 0),
('expenses.archive', 'Expenses', 'Archive Expenses', 'Archive expense records', 0),
-- Reports
('reports.view', 'Reports', 'View Reports', 'Access report section', 0),
('reports.export', 'Reports', 'Export Reports', 'Export report data', 0),
-- AI
('ai.access', 'AI', 'Access AI', 'Use AI assistant', 0),
('ai.search', 'AI', 'AI Search', 'Use AI to search records', 0),
('ai.financial', 'AI', 'AI Financial Data', 'Allow AI to access financial data', 1),
('ai.propose_actions', 'AI', 'AI Action Proposals', 'Allow AI to prepare action proposals', 1),
-- Settings
('settings.access', 'Settings', 'Access Settings', 'View settings panel', 0),
('settings.edit', 'Settings', 'Edit Settings', 'Modify settings', 1),
-- Managers
('managers.view', 'Managers', 'View Managers', 'View manager accounts', 0),
('managers.manage', 'Managers', 'Manage Managers', 'Create/edit manager accounts', 1),
-- Backup
('backup.create', 'Backup', 'Create Backup', 'Create data backups', 1),
('backup.download', 'Backup', 'Download Backup', 'Download backup files', 1),
('backup.restore', 'Backup', 'Restore Backup', 'Restore from backup', 1),
('backup.reset', 'Backup', 'Reset Data', 'Reset/clear data', 1);

-- Schema version
INSERT INTO `schema_migrations` (`version`, `description`) VALUES ('1.0.0', 'Initial schema');
