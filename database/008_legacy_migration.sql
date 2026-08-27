-- ============================================================
-- Migration 008: Legacy Ticket Migration
-- Run once against belmont_helpdesk database
-- ============================================================

USE `belmont_helpdesk`;

ALTER TABLE `tickets`
  ADD COLUMN `legacy_ticket_number` VARCHAR(20) DEFAULT NULL AFTER `ticket_code`,
  ADD COLUMN `is_legacy`              TINYINT(1) NOT NULL DEFAULT 0 AFTER `legacy_ticket_number`,
  ADD COLUMN `migrated_by`            INT UNSIGNED DEFAULT NULL AFTER `is_legacy`,
  ADD COLUMN `migrated_at`            DATETIME DEFAULT NULL AFTER `migrated_by`,
  ADD UNIQUE KEY `uq_legacy_ticket_number` (`legacy_ticket_number`),
  ADD KEY `idx_ticket_legacy` (`is_legacy`),
  ADD CONSTRAINT `fk_ticket_migrated_by` FOREIGN KEY (`migrated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;