-- ============================================================
-- Migration 003: Advanced Features (AI + Automation)
-- Run once against belmont_helpdesk database
-- Features: smart suggestions, auto-routing, reply suggestions,
--           SLA escalation, auto-close, duplicate detection,
--           KB deflections, ticket merge/link, watchers, settings
-- ============================================================

USE `belmont_helpdesk`;

-- ------------------------------------------------------------
-- Extend notifications ENUM with new automation types
-- ------------------------------------------------------------
ALTER TABLE `notifications`
  MODIFY COLUMN `type` ENUM(
    'assigned','updated','replied','status_changed','new_ticket',
    'escalated','auto_close_warning','auto_closed','merged','watching'
  ) NOT NULL;

-- ------------------------------------------------------------
-- 1. SUBJECT SUGGESTIONS (AI-Powered Smart Ticket Creation)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subject_suggestions` (
  `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id`        INT UNSIGNED DEFAULT NULL,
  `subject`              VARCHAR(255) NOT NULL,
  `description_template` TEXT DEFAULT NULL,
  `keywords`             VARCHAR(255) DEFAULT NULL,
  `is_active`            TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ss_dept` (`department_id`),
  CONSTRAINT `fk_ss_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `subject_suggestions` (`department_id`, `subject`, `description_template`, `keywords`) VALUES
-- IT Department (1)
(1, 'POS System Not Responding',
 'Terminal / Store location: \nWhat were you doing when it stopped responding: \nError message shown (if any): \nHow long has this been happening: ',
 'pos,terminal,freeze,not responding'),
(1, 'Network Connectivity Issue',
 'Affected location / device: \nIs it affecting one PC or multiple: \nWhen did the issue start: \nWhat have you already tried: ',
 'network,internet,wifi,lan,connect'),
(1, 'Software Installation Request',
 'Software name and version: \nBusiness justification: \nPC name / asset tag: \nNeeded by (date): ',
 'install,software,application,program'),
(1, 'Password Reset Request',
 'System / application affected: \nUsername: \nLast successful login (approx.): ',
 'password,reset,login,locked'),
(1, 'NetSuite / ERP Error',
 'Module (e.g. invoicing, inventory): \nExact error message: \nSteps to reproduce: \nExpected result: \nActual result: ',
 'netsuite,erp,upload,batch,sync'),
(1, 'Printer Not Working',
 'Printer name / location: \nIssue (no print, paper jam, quality): \nError lights or messages: ',
 'printer,print,toner,paper'),
(1, 'Email Access Problem',
 'Email address affected: \nIssue (cannot send, cannot receive, login): \nError message: ',
 'email,outlook,mailbox,send,receive'),
-- Accounting (2)
(2, 'Invoice Discrepancy',
 'Invoice number(s): \nVendor / customer: \nExpected amount: \nActual amount shown: \nSupporting documents attached: ',
 'invoice,discrepancy,amount,billing'),
(2, 'Payroll System Error',
 'Pay period affected: \nEmployee(s) affected: \nDescription of the error: \nExpected computation: ',
 'payroll,salary,overtime,computation'),
(2, 'Accounts Payable Issue',
 'Voucher number(s): \nVendor: \nIssue description: \nDue date: ',
 'voucher,payable,payment,ap'),
(2, 'Expense Report Question',
 'Report / reference number: \nQuestion or issue: ',
 'expense,reimbursement,report'),
-- Audit (3)
(3, 'Audit Document Request',
 'Documents requested: \nPeriod covered: \nPurpose: \nNeeded by (date): ',
 'audit,document,request,records'),
(3, 'Audit Finding Clarification',
 'Audit reference: \nFinding number: \nClarification needed: ',
 'finding,clarification,compliance'),
-- HR (4)
(4, 'New Employee Account Setup',
 'Employee name: \nPosition / department: \nStart date: \nSystems needed (email, NetSuite, POS, etc.): ',
 'new employee,onboarding,account,setup'),
(4, 'Employee Records Update',
 'Employee name / ID: \nWhat needs to be updated: \nSupporting documents attached: ',
 'records,update,employee,201'),
(4, 'Leave / Attendance Inquiry',
 'Employee name: \nDate(s) concerned: \nInquiry details: ',
 'leave,attendance,absence,timekeeping'),
-- Merchandising (5)
(5, 'Inventory Sync Error',
 'Store / location affected: \nItem(s) or SKU(s): \nWhat the system shows vs actual: \nWhen was the last successful sync: ',
 'inventory,sync,stock,count'),
(5, 'Product Listing Update',
 'SKU / product code: \nWhat needs to change (size, description, price): \nNew value: \nEffective date: ',
 'product,listing,sku,size,description'),
(5, 'Price Change Request',
 'SKU / product code(s): \nCurrent price: \nNew price: \nEffective date: \nApproved by: ',
 'price,change,markdown,markup'),
-- Store (6)
(6, 'POS Terminal Issue',
 'Store branch: \nTerminal number: \nIssue description: \nError message (if any): \nIs the store able to transact: ',
 'pos,terminal,store,cashier'),
(6, 'Barcode Scanner Not Working',
 'Store branch: \nCounter / terminal: \nWhat happens when scanning: \nHave you tried another scanner: ',
 'barcode,scanner,scan,reader'),
(6, 'Receipt Printer Problem',
 'Store branch: \nTerminal number: \nIssue (no print, faded, jam): ',
 'receipt,printer,print'),
-- Support (7)
(7, 'General Inquiry',
 'Please describe your inquiry in detail: ',
 'inquiry,question,help'),
(7, 'Facility / Equipment Request',
 'Item / equipment needed: \nLocation: \nJustification: \nNeeded by (date): ',
 'equipment,facility,request');

-- ------------------------------------------------------------
-- 2. ROUTING RULES (Auto Department Classification)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `routing_rules` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id` INT UNSIGNED NOT NULL,
  `keyword`       VARCHAR(100) NOT NULL,
  `weight`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_rr_dept` (`department_id`),
  KEY `idx_rr_keyword` (`keyword`),
  CONSTRAINT `fk_rr_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `routing_rules` (`department_id`, `keyword`, `weight`) VALUES
-- IT Department
(1, 'password', 3), (1, 'login', 2), (1, 'network', 3), (1, 'internet', 3),
(1, 'wifi', 3), (1, 'computer', 2), (1, 'laptop', 2), (1, 'printer', 2),
(1, 'email', 2), (1, 'software', 2), (1, 'install', 2), (1, 'netsuite', 3),
(1, 'erp', 3), (1, 'system error', 2), (1, 'server', 3), (1, 'access', 1),
(1, 'account locked', 3), (1, 'vpn', 3), (1, 'virus', 3), (1, 'slow', 1),
-- Accounting
(2, 'invoice', 3), (2, 'payroll', 3), (2, 'salary', 3), (2, 'voucher', 3),
(2, 'payment', 2), (2, 'billing', 3), (2, 'expense', 2), (2, 'reimbursement', 3),
(2, 'accounts payable', 3), (2, 'overtime computation', 3), (2, 'tax', 2),
-- Audit
(3, 'audit', 3), (3, 'compliance', 3), (3, 'finding', 2), (3, 'irregularity', 3),
-- HR
(4, 'employee', 2), (4, 'onboarding', 3), (4, 'new hire', 3), (4, 'leave', 2),
(4, 'attendance', 2), (4, '201 file', 3), (4, 'resignation', 3), (4, 'benefits', 2),
(4, 'hr', 2),
-- Merchandising
(5, 'inventory', 3), (5, 'stock', 2), (5, 'sku', 3), (5, 'product listing', 3),
(5, 'price change', 3), (5, 'merchandise', 3), (5, 'size desc', 3), (5, 'markdown', 3),
-- Store
(6, 'pos', 3), (6, 'cashier', 3), (6, 'barcode', 3), (6, 'scanner', 2),
(6, 'receipt', 2), (6, 'store branch', 2), (6, 'checkout', 3), (6, 'terminal', 2);

-- ------------------------------------------------------------
-- 2b. PRIORITY RULES (Auto Priority Suggestion)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `priority_rules` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `keyword`   VARCHAR(100) NOT NULL,
  `priority`  ENUM('low','medium','high','critical') NOT NULL,
  `weight`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_pr_keyword` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `priority_rules` (`keyword`, `priority`, `weight`) VALUES
('urgent', 'critical', 3), ('asap', 'critical', 3), ('emergency', 'critical', 3),
('down', 'critical', 3), ('cannot transact', 'critical', 3), ('all stores', 'critical', 3),
('production', 'critical', 2), ('outage', 'critical', 3),
('not working', 'high', 2), ('cannot access', 'high', 2), ('cannot login', 'high', 2),
('error', 'high', 1), ('failed', 'high', 2), ('freezing', 'high', 2),
('broken', 'high', 2), ('immediately', 'high', 2), ('deadline', 'high', 2),
('slow', 'medium', 1), ('issue', 'medium', 1), ('problem', 'medium', 1),
('update', 'low', 1), ('request', 'low', 1), ('inquiry', 'low', 1),
('question', 'low', 1), ('clarification', 'low', 1), ('when possible', 'low', 2);

-- ------------------------------------------------------------
-- 2c. AUTO ROUTING LOGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auto_routing_logs` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`               INT UNSIGNED DEFAULT NULL,
  `suggested_department_id` INT UNSIGNED DEFAULT NULL,
  `suggested_priority`      ENUM('low','medium','high','critical') DEFAULT NULL,
  `confidence`              DECIMAL(5,2) NOT NULL DEFAULT 0,
  `accepted`                TINYINT(1) NOT NULL DEFAULT 0,
  `method`                  VARCHAR(40) NOT NULL DEFAULT 'keyword',
  `created_at`              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_arl_ticket` (`ticket_id`),
  CONSTRAINT `fk_arl_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_arl_dept` FOREIGN KEY (`suggested_department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. REPLY SUGGESTIONS (Smart Replies for Staff)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reply_suggestions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `keywords`    VARCHAR(255) NOT NULL,
  `title`       VARCHAR(120) NOT NULL,
  `body`        TEXT NOT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rs_cat` (`category_id`),
  CONSTRAINT `fk_rs_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `reply_suggestions` (`category_id`, `keywords`, `title`, `body`) VALUES
(14, 'pos,freeze,not responding,terminal', 'POS Troubleshooting Steps',
 'Hi {{requester_name}},\n\nThank you for reporting this. While we investigate, please try the following:\n\n1. Close and reopen the POS application.\n2. Power off the terminal, wait 30 seconds, then turn it back on.\n3. Confirm the terminal has network connectivity.\n\nIf the issue persists after these steps, let us know the terminal ID and we will access it remotely.\n\nBest regards,\n{{staff_name}}'),
(14, 'barcode,scanner,scan', 'Barcode Scanner Checklist',
 'Hi {{requester_name}},\n\nPlease check the following on the scanner:\n\n1. Ensure the cable is firmly connected to the terminal.\n2. Try a different USB port.\n3. Test with a known-good barcode.\n\nIf it still fails, we will arrange a replacement unit. Please confirm the store branch and counter number.\n\nBest regards,\n{{staff_name}}'),
(3, 'network,internet,wifi,connect', 'Network Issue - Initial Diagnostics',
 'Hi {{requester_name}},\n\nWe are looking into the connectivity issue. To help us isolate the cause, please confirm:\n\n1. Is the issue affecting only your PC or multiple users?\n2. Are you connected via LAN cable or WiFi?\n3. Did anything change recently (new equipment, moved desk, etc.)?\n\nBest regards,\n{{staff_name}}'),
(4, 'password,reset,login,locked', 'Password Reset Confirmation',
 'Hi {{requester_name}},\n\nYour password has been reset. A temporary password will be provided to you through a secure channel.\n\nFor security, please change it immediately at first login. Note that passwords must be at least 8 characters with a mix of letters and numbers.\n\nBest regards,\n{{staff_name}}'),
(5, 'netsuite,erp,upload,batch,sync', 'NetSuite Issue - Under Investigation',
 'Hi {{requester_name}},\n\nThank you for the report regarding ticket {{ticket_code}}. We are checking the NetSuite batch logs to identify why the upload failed.\n\nIn the meantime, please do not re-submit the batch to avoid duplicate entries. We will update you as soon as the investigation is complete.\n\nBest regards,\n{{staff_name}}'),
(1, 'hardware,replace,broken,printer', 'Hardware Assessment Scheduled',
 'Hi {{requester_name}},\n\nWe have logged your hardware concern. A technician will assess the unit. If repair is not feasible, we will process a replacement request.\n\nPlease keep the equipment available for inspection.\n\nBest regards,\n{{staff_name}}'),
(2, 'software,install,application,error', 'Software Issue - Next Steps',
 'Hi {{requester_name}},\n\nThank you for the details. Please try the following first:\n\n1. Restart the application.\n2. Restart your computer.\n3. Note the exact error message if it reappears.\n\nIf unresolved, we will connect remotely to investigate further.\n\nBest regards,\n{{staff_name}}'),
(7, 'payroll,salary,overtime,computation', 'Payroll Inquiry Acknowledged',
 'Hi {{requester_name}},\n\nThank you for raising this. We are reviewing the computation for the pay period in question and will coordinate with the payroll team.\n\nWe will get back to you with a detailed breakdown.\n\nBest regards,\n{{staff_name}}'),
(8, 'voucher,payable,upload,missing', 'AP Voucher Investigation',
 'Hi {{requester_name}},\n\nWe are checking the voucher batch in question. Please send the voucher numbers and the date of the original upload so we can trace them in the system.\n\nBest regards,\n{{staff_name}}'),
(10, 'employee,records,update,201', 'Records Update Received',
 'Hi {{requester_name}},\n\nWe have received your records update request. Processing typically takes 1-2 business days. We will confirm once the changes are reflected in the system.\n\nBest regards,\n{{staff_name}}'),
(12, 'inventory,sync,stock,count', 'Inventory Sync - Being Checked',
 'Hi {{requester_name}},\n\nWe are reviewing the sync logs between head office and the affected store. Please avoid manual stock adjustments until we confirm the root cause, to prevent data conflicts.\n\nWe will update you shortly.\n\nBest regards,\n{{staff_name}}'),
(13, 'product,listing,size,description,price', 'Product Listing Update Done',
 'Hi {{requester_name}},\n\nThe requested product listing change has been completed in the system. Please verify on your end and confirm so we can close ticket {{ticket_code}}.\n\nBest regards,\n{{staff_name}}'),
(NULL, 'thank,acknowledge,received', 'General Acknowledgement',
 'Hi {{requester_name}},\n\nThank you for submitting ticket {{ticket_code}}. We have received your request and it is now being reviewed. We will respond within the SLA window.\n\nBest regards,\n{{staff_name}}'),
(NULL, 'more info,details,clarify', 'Request for More Information',
 'Hi {{requester_name}},\n\nTo assist you better, could you please provide:\n\n1. A screenshot of the issue (if applicable)\n2. The exact error message\n3. The date and time the issue occurred\n\nWe look forward to your response.\n\nBest regards,\n{{staff_name}}'),
(NULL, 'resolved,fixed,done,closing', 'Issue Resolved - Closing',
 'Hi {{requester_name}},\n\nWe are pleased to confirm the issue in ticket {{ticket_code}} has been resolved. If the problem recurs, feel free to reopen this ticket or submit a new one.\n\nThank you for your patience.\n\nBest regards,\n{{staff_name}}');

-- ------------------------------------------------------------
-- 4. ESCALATION LOGS (SLA Automation)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `escalation_logs` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`    INT UNSIGNED NOT NULL,
  `action`       ENUM('warning','escalated','breached') NOT NULL,
  `old_priority` ENUM('low','medium','high','critical') DEFAULT NULL,
  `new_priority` ENUM('low','medium','high','critical') DEFAULT NULL,
  `details`      VARCHAR(255) DEFAULT NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_el_ticket` (`ticket_id`),
  KEY `idx_el_action` (`action`),
  CONSTRAINT `fk_el_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. AUTO CLOSE LOGS + SETTINGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auto_close_logs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL,
  `action`     ENUM('warned','closed') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_acl_ticket` (`ticket_id`),
  UNIQUE KEY `uq_acl_ticket_action` (`ticket_id`, `action`),
  CONSTRAINT `fk_acl_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(64) NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('auto_close_enabled', '1'),
('auto_close_days', '5'),
('auto_close_warn_days', '3')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- ------------------------------------------------------------
-- 6. DUPLICATE CHECKS LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `duplicate_checks` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT UNSIGNED NOT NULL,
  `subject`           VARCHAR(255) NOT NULL,
  `matched_ticket_id` INT UNSIGNED DEFAULT NULL,
  `similarity`        DECIMAL(5,2) NOT NULL DEFAULT 0,
  `proceeded`         TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dc_user` (`user_id`),
  KEY `idx_dc_matched` (`matched_ticket_id`),
  CONSTRAINT `fk_dc_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dc_ticket` FOREIGN KEY (`matched_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. KB DEFLECTIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `kb_deflections` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `kb_article_id` INT UNSIGNED DEFAULT NULL,
  `subject_typed` VARCHAR(255) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kbd_user` (`user_id`),
  KEY `idx_kbd_article` (`kb_article_id`),
  CONSTRAINT `fk_kbd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kbd_article` FOREIGN KEY (`kb_article_id`) REFERENCES `kb_articles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 10. TICKET LINKS (Merge & Related)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_links` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `primary_ticket_id` INT UNSIGNED NOT NULL,
  `linked_ticket_id`  INT UNSIGNED NOT NULL,
  `link_type`         ENUM('merged','related') NOT NULL DEFAULT 'related',
  `created_by`        INT UNSIGNED NOT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tl_pair` (`primary_ticket_id`, `linked_ticket_id`),
  KEY `idx_tl_linked` (`linked_ticket_id`),
  CONSTRAINT `fk_tl_primary` FOREIGN KEY (`primary_ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tl_linked`  FOREIGN KEY (`linked_ticket_id`)  REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tl_user`    FOREIGN KEY (`created_by`)        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 12. TICKET WATCHERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_watchers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `added_by`   INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tw_pair` (`ticket_id`, `user_id`),
  KEY `idx_tw_user` (`user_id`),
  CONSTRAINT `fk_tw_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tw_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tw_added`  FOREIGN KEY (`added_by`)  REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
