-- ============================================================
-- Migration 004: Department Shared Email + Notification Prefs
-- Run once against belmont_helpdesk database (safe to re-run)
-- ============================================================

USE `belmont_helpdesk`;

-- ---- Department shared inbox email ----
ALTER TABLE `departments`
  ADD COLUMN IF NOT EXISTS `shared_email` VARCHAR(255) DEFAULT NULL AFTER `description`;

-- ---- Per-user notification preferences ----
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `notify_email` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_active`,
  ADD COLUMN IF NOT EXISTS `notify_inapp` TINYINT(1) NOT NULL DEFAULT 1 AFTER `notify_email`;

-- ---- Seed the real Belmont department shared emails ----
UPDATE `departments` SET `shared_email` = 'it@belmont.ph'            WHERE `name` = 'IT Department'  AND (`shared_email` IS NULL OR `shared_email` = '');
UPDATE `departments` SET `shared_email` = 'accounting@belmont.ph'    WHERE `name` = 'Accounting'     AND (`shared_email` IS NULL OR `shared_email` = '');
UPDATE `departments` SET `shared_email` = 'audit@belmont.ph'         WHERE `name` = 'Audit'          AND (`shared_email` IS NULL OR `shared_email` = '');
UPDATE `departments` SET `shared_email` = 'hr@belmont.ph'            WHERE `name` = 'HR'             AND (`shared_email` IS NULL OR `shared_email` = '');
UPDATE `departments` SET `shared_email` = 'merchandising@belmont.ph' WHERE `name` = 'Merchandising'  AND (`shared_email` IS NULL OR `shared_email` = '');
UPDATE `departments` SET `shared_email` = 'store@belmont.ph'         WHERE `name` = 'Store'          AND (`shared_email` IS NULL OR `shared_email` = '');
UPDATE `departments` SET `shared_email` = 'support@belmont.ph'       WHERE `name` = 'Support'        AND (`shared_email` IS NULL OR `shared_email` = '');

-- ---- Helpful index for department member lookups ----
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_user_dept_active` (`department_id`, `is_active`);
