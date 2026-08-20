-- ============================================================
-- Migration 006: Department Admin role
-- dept_admin = manages tickets/reports/CSAT but ONLY for their
-- own department. No access to system-wide admin pages.
-- Safe to re-run.
-- ============================================================

USE `belmont_helpdesk`;

ALTER TABLE `users`
  MODIFY `role` ENUM('admin','dept_admin','staff','user') NOT NULL DEFAULT 'user';
