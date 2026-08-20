-- ============================================================
-- Belmont Helpdesk Ticketing System - Database Schema
-- Company: Cebu Belmont, Inc.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `belmont_helpdesk`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `belmont_helpdesk`;

-- ============================================================
-- DEPARTMENTS
-- ============================================================
CREATE TABLE `departments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(128) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dept_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `departments` (`id`, `name`, `description`) VALUES
(1,  'IT Department',   'Information Technology support and systems'),
(2,  'Accounting',      'Finance and accounting inquiries'),
(3,  'Audit',           'Internal and external audit'),
(4,  'HR',              'Human Resources'),
(5,  'Merchandising',   'Merchandise planning and operations'),
(6,  'Store',           'Retail store operations'),
(7,  'Support',         'General support department');

-- ============================================================
-- CATEGORIES (Help Topics)
-- ============================================================
CREATE TABLE `categories` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `name`          VARCHAR(128) NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cat_dept` (`department_id`),
  CONSTRAINT `fk_cat_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categories` (`id`, `department_id`, `name`) VALUES
(1,  1, 'Hardware Issue'),
(2,  1, 'Software Issue'),
(3,  1, 'Network / Connectivity'),
(4,  1, 'Account / Access'),
(5,  1, 'NetSuite / ERP'),
(6,  2, 'Billing Inquiry'),
(7,  2, 'Payroll'),
(8,  2, 'Accounts Payable'),
(9,  3, 'Audit Request'),
(10, 4, 'Employee Records'),
(11, 4, 'Onboarding'),
(12, 5, 'Inventory'),
(13, 5, 'Product Listing'),
(14, 6, 'POS Issue'),
(15, 7, 'General Inquiry'),
(16, 7, 'Other');

-- ============================================================
-- USERS
-- ============================================================
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `name`          VARCHAR(128) NOT NULL,
  `email`         VARCHAR(255) NOT NULL,
  `password`      VARCHAR(255) NOT NULL,
  `role`          ENUM('admin','staff','user') NOT NULL DEFAULT 'user',
  `phone`         VARCHAR(20) DEFAULT NULL,
  `avatar`        VARCHAR(255) DEFAULT NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `last_login`    DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_email` (`email`),
  KEY `idx_user_dept` (`department_id`),
  KEY `idx_user_role` (`role`),
  CONSTRAINT `fk_user_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin: admin@belmont.ph / Admin@123
-- Default staff: it@belmont.ph / Staff@123
INSERT INTO `users` (`id`, `department_id`, `name`, `email`, `password`, `role`) VALUES
(1, 1, 'System Administrator', 'admin@belmont.ph',
  '$2y$10$EeoKyYux/56BVHmB6Jdfy.jgAwpQhhGGUtSplmh3VoWNK8Dk2FZnG', 'admin'),
(2, 1, 'Jonathan (IT Staff)',  'it@belmont.ph',
  '$2y$10$EeoKyYux/56BVHmB6Jdfy.jgAwpQhhGGUtSplmh3VoWNK8Dk2FZnG', 'staff'),
(3, 1, 'Stephene (IT Staff)',  'stephene@belmont.ph',
  '$2y$10$EeoKyYux/56BVHmB6Jdfy.jgAwpQhhGGUtSplmh3VoWNK8Dk2FZnG', 'staff'),
(4, 2, 'Mirz Accounting',     'accounting@belmont.ph',
  '$2y$10$EeoKyYux/56BVHmB6Jdfy.jgAwpQhhGGUtSplmh3VoWNK8Dk2FZnG', 'user'),
(5, 4, 'HR Manager',          'hr@belmont.ph',
  '$2y$10$EeoKyYux/56BVHmB6Jdfy.jgAwpQhhGGUtSplmh3VoWNK8Dk2FZnG', 'user'),
(6, 5, 'Merchandising Staff', 'merchandising@belmont.ph',
  '$2y$10$EeoKyYux/56BVHmB6Jdfy.jgAwpQhhGGUtSplmh3VoWNK8Dk2FZnG', 'user'),
(7, 6, 'Store Coordinator',   'store@belmont.ph',
  '$2y$10$EeoKyYux/56BVHmB6Jdfy.jgAwpQhhGGUtSplmh3VoWNK8Dk2FZnG', 'user');

-- NOTE: All passwords above are bcrypt of "password"
-- Replace with proper hashes in production!

-- ============================================================
-- TICKETS
-- ============================================================
CREATE TABLE `tickets` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_code`   VARCHAR(20)  NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED DEFAULT NULL,
  `category_id`   INT UNSIGNED DEFAULT NULL,
  `assigned_to`   INT UNSIGNED DEFAULT NULL,
  `subject`       VARCHAR(255) NOT NULL,
  `description`   TEXT NOT NULL,
  `priority`      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `status`        ENUM('open','in_progress','pending','resolved','closed') NOT NULL DEFAULT 'open',
  `source`        ENUM('web','email','phone','walk-in') NOT NULL DEFAULT 'web',
  `due_date`      DATE DEFAULT NULL,
  `closed_at`     DATETIME DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_code` (`ticket_code`),
  KEY `idx_ticket_user`   (`user_id`),
  KEY `idx_ticket_dept`   (`department_id`),
  KEY `idx_ticket_cat`    (`category_id`),
  KEY `idx_ticket_assign` (`assigned_to`),
  KEY `idx_ticket_status` (`status`),
  KEY `idx_ticket_priority` (`priority`),
  CONSTRAINT `fk_ticket_user`   FOREIGN KEY (`user_id`)       REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ticket_dept`   FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ticket_cat`    FOREIGN KEY (`category_id`)   REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ticket_assign` FOREIGN KEY (`assigned_to`)   REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample ticket data (imported/adapted from Belmont osTicket)
INSERT INTO `tickets` (`ticket_code`, `user_id`, `department_id`, `category_id`, `assigned_to`, `subject`, `description`, `priority`, `status`, `created_at`) VALUES
('TK-000001', 4, 1, 5, 2, 'NetSuite Upload Issue - April Collection',
 'Unable to upload April collection data to NetSuite. Getting error on batch import.',
 'high', 'closed', '2022-04-15 09:00:00'),
('TK-000002', 5, 4, 10, 3, 'New Employee Account Setup',
 'Need to create NetSuite and system accounts for new employee APERALES. Username: APERALES, initial password: 1.',
 'medium', 'closed', '2022-01-28 08:00:00'),
('TK-000003', 6, 5, 12, 2, 'Inventory Sync Error - Store 3',
 'Data sync between head office and Store 3 is failing. User needs to be created and synced.',
 'high', 'closed', '2023-09-11 04:00:00'),
('TK-000004', 4, 1, 4, 2, 'Password Reset Request',
 'Cannot log in to the system. Need password reset.',
 'medium', 'resolved', '2025-02-28 03:00:00'),
('TK-000005', 7, 6, 14, 3, 'POS System Not Responding',
 'POS terminal at Store Branch is freezing during peak hours. Needs immediate attention.',
 'critical', 'in_progress', '2025-05-20 10:00:00'),
('TK-000006', 5, 4, 11, 2, 'Onboarding Documents Missing',
 'New hire paperwork not received by HR. Please check and resend.',
 'low', 'open', '2025-05-22 08:30:00'),
('TK-000007', 6, 5, 13, 3, 'Product Size Description Update',
 'Need to create new size desc 3000L in the merchandise system.',
 'medium', 'resolved', '2026-02-03 08:00:00'),
('TK-000008', 4, 2, 8, 2, 'Accounts Payable Discrepancy',
 'Voucher upload to NetSuite failed. Multiple vouchers missing from last month batch.',
 'high', 'resolved', '2026-03-30 06:00:00'),
('TK-000009', 7, 6, 14, 3, 'Barcode Scanner Not Working',
 'Barcode scanner at checkout counter is not reading items. May need replacement.',
 'medium', 'open', '2026-05-24 09:00:00'),
('TK-000010', 5, 4, 7, 2, 'Payroll Computation Query',
 'Asking for clarification on overtime computation for the current pay period.',
 'low', 'pending', '2026-05-25 07:00:00');

-- ============================================================
-- TICKET REPLIES (Threaded Conversations)
-- ============================================================
CREATE TABLE `ticket_replies` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `message`    TEXT NOT NULL,
  `is_internal`TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reply_ticket` (`ticket_id`),
  KEY `idx_reply_user`   (`user_id`),
  CONSTRAINT `fk_reply_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reply_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ticket_replies` (`ticket_id`, `user_id`, `message`, `created_at`) VALUES
(1, 2, 'Hi Ma\'am Mirz, Done uploading the not-uploaded to NetSuite. Please verify on your side and after you verify, if you don\'t have any concern regarding this matter you may close this ticket. Please be guided also that BA Card is still ongoing for an update with the technical team from NetSuite and sir Donald. Thanks and God Bless - Rodel Ripdos', '2022-04-16 09:00:00'),
(2, 2, 'Account now been created. Username: APERALES / Password: 1. Note: User needs to change password at first login. - Jonathan', '2022-01-28 10:00:00'),
(3, 3, 'User created and sync data to store 3. Closing ticket on your behalf. - Phene', '2023-09-11 05:00:00'),
(4, 2, 'Good morning Sir Prax! As per your confirmation the user is now created and I will now close this ticket. Thank you! Regards, Chris Vergara', '2025-02-28 04:00:00'),
(5, 3, 'Investigating the POS freezing issue. Will need to access the terminal remotely. Please keep the terminal on.', '2025-05-21 09:00:00'),
(7, 3, 'Good afternoon ma\'am, done creating new size desc 3000L. - kent', '2026-02-03 09:00:00'),
(8, 2, 'Done reuploading vouchers to NetSuite. - kent', '2026-03-30 07:00:00');

-- ============================================================
-- ATTACHMENTS
-- ============================================================
CREATE TABLE `attachments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT UNSIGNED DEFAULT NULL,
  `reply_id`    INT UNSIGNED DEFAULT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `filename`    VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `mime_type`   VARCHAR(100) NOT NULL,
  `file_size`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attach_ticket` (`ticket_id`),
  KEY `idx_attach_reply`  (`reply_id`),
  CONSTRAINT `fk_attach_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attach_reply`  FOREIGN KEY (`reply_id`)  REFERENCES `ticket_replies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attach_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NOTIFICATIONS
-- ============================================================
CREATE TABLE `notifications` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `ticket_id`   INT UNSIGNED DEFAULT NULL,
  `type`        ENUM('assigned','updated','replied','status_changed','new_ticket') NOT NULL,
  `message`     TEXT NOT NULL,
  `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user`   (`user_id`),
  KEY `idx_notif_ticket` (`ticket_id`),
  KEY `idx_notif_read`   (`is_read`),
  CONSTRAINT `fk_notif_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ACTIVITY LOGS
-- ============================================================
CREATE TABLE `activity_logs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED DEFAULT NULL,
  `ticket_id`   INT UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(64) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_user`   (`user_id`),
  KEY `idx_log_ticket` (`ticket_id`),
  CONSTRAINT `fk_log_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_log_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
