-- =========================================================================
-- ACCBOS — Accounting Connector for BOS
-- Phase 1 schema. Compatible with MySQL 5.7+ and MariaDB 10.3+.
-- Run on a fresh database, e.g.:
--   CREATE DATABASE accbos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   USE accbos;
--   SOURCE database/schema.sql;
-- =========================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------------------------
-- admins
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS `admins`;
CREATE TABLE `admins` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `username`      VARCHAR(60)  NOT NULL UNIQUE,
    `full_name`     VARCHAR(150) NOT NULL,
    `email`         VARCHAR(150) NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          VARCHAR(40)  NOT NULL DEFAULT 'admin',
    `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
    `last_login_at` DATETIME NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample admin user.
-- Password is "change_this_password" hashed with PHP password_hash(PASSWORD_DEFAULT).
-- Replace immediately after first login.
INSERT INTO `admins` (`username`, `full_name`, `email`, `password_hash`, `role`)
VALUES (
    'admin',
    'System Administrator',
    'admin@example.com',
    '$2y$12$s9N7hDISF.T1/hG00J2.qOXvNc9Hxfbv9E7/AI15VMCARHfqVeoTu',
    'super_admin'
);

-- -------------------------------------------------------------------------
-- companies
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
    `company_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `company_name`      VARCHAR(200) NOT NULL,
    `registration_no`   VARCHAR(80)  NULL,
    `contact_person`    VARCHAR(150) NULL,
    `phone`             VARCHAR(40)  NULL,
    `email`             VARCHAR(150) NULL,
    `accounting_system` ENUM('sql_account','autocount','ubs','bukku','manual_csv')
                        NOT NULL DEFAULT 'sql_account',
    `api_base_url`      VARCHAR(255) NULL,
    `api_access_key`    VARCHAR(255) NULL,
    `api_secret_key`    TEXT NULL,
    `api_region`        VARCHAR(60)  NULL,
    `api_service`       VARCHAR(60)  NULL,
    `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_companies_status` (`status`),
    INDEX `idx_companies_accounting` (`accounting_system`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- sales_orders (header)
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_orders`;
CREATE TABLE `sales_orders` (
    `id`                  INT AUTO_INCREMENT PRIMARY KEY,
    `company_id`          INT NOT NULL,
    `customer_code`       VARCHAR(60)  NOT NULL,
    `customer_name`       VARCHAR(200) NOT NULL,
    `customer_phone`      VARCHAR(40)  NULL,
    `customer_email`      VARCHAR(150) NULL,
    `doc_date`            DATE NOT NULL,
    `required_date`       DATE NULL,
    `reference_no`        VARCHAR(80)  NULL,
    `remark`              VARCHAR(500) NULL,
    `local_status`        ENUM('draft','ready_to_push','pushed','failed','cancelled')
                          NOT NULL DEFAULT 'draft',
    `accounting_status`   ENUM('pending','success','failed') NOT NULL DEFAULT 'pending',
    `accounting_doc_no`   VARCHAR(80)  NULL,
    `accounting_response` LONGTEXT NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_so_company` (`company_id`),
    INDEX `idx_so_local_status` (`local_status`),
    INDEX `idx_so_accounting_status` (`accounting_status`),
    INDEX `idx_so_reference` (`reference_no`),
    CONSTRAINT `fk_so_company` FOREIGN KEY (`company_id`)
        REFERENCES `companies` (`company_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- sales_order_items (detail)
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS `sales_order_items`;
CREATE TABLE `sales_order_items` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `sales_order_id`  INT NOT NULL,
    `item_code`       VARCHAR(80)  NOT NULL,
    `item_description` VARCHAR(255) NULL,
    `qty`             DECIMAL(15,4) NOT NULL DEFAULT 0,
    `unit_price`      DECIMAL(15,4) NOT NULL DEFAULT 0,
    `discount`        DECIMAL(15,4) NOT NULL DEFAULT 0,
    `tax_code`        VARCHAR(20)  NULL,
    `amount`          DECIMAL(15,4) NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_soi_order` (`sales_order_id`),
    CONSTRAINT `fk_soi_order` FOREIGN KEY (`sales_order_id`)
        REFERENCES `sales_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- api_logs
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS `api_logs`;
CREATE TABLE `api_logs` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `company_id`       INT NULL,
    `module`           VARCHAR(100) NULL,
    `action`           VARCHAR(100) NULL,
    `endpoint`         VARCHAR(255) NULL,
    `request_payload`  LONGTEXT NULL,
    `response_payload` LONGTEXT NULL,
    `http_status`      INT NULL,
    `status`           ENUM('success','failed') NOT NULL DEFAULT 'failed',
    `error_message`    TEXT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_apilogs_company` (`company_id`),
    INDEX `idx_apilogs_status` (`status`),
    INDEX `idx_apilogs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- sync_queue
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS `sync_queue`;
CREATE TABLE `sync_queue` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `company_id`    INT NOT NULL,
    `module`        VARCHAR(100) NOT NULL,
    `record_id`     INT NOT NULL,
    `action`        VARCHAR(100) NOT NULL,
    `status`        ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
    `attempt_count` INT NOT NULL DEFAULT 0,
    `last_error`    TEXT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_queue_status` (`status`),
    INDEX `idx_queue_company_module` (`company_id`, `module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------
-- system_settings
-- -------------------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `setting_key`   VARCHAR(100) PRIMARY KEY,
    `setting_value` TEXT NULL,
    `updated_at`    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('app_name', 'ACCBOS'),
    ('default_currency', 'MYR'),
    ('default_tax_code', 'SST'),
    ('phase', '1');

SET FOREIGN_KEY_CHECKS = 1;
