-- ============================================================
-- Migration 002: Reply Templates, KB, Password Reset
-- Run once against belmont_helpdesk database
-- ============================================================

-- ---- Reply Templates (Canned Responses) ----
CREATE TABLE IF NOT EXISTS `reply_templates` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_by` INT UNSIGNED NOT NULL,
  `title`      VARCHAR(120) NOT NULL,
  `body`       TEXT NOT NULL,
  `is_global`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_rt_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a few starter templates
INSERT INTO `reply_templates` (`created_by`, `title`, `body`, `is_global`) VALUES
(1, 'Acknowledging Receipt',
 'Thank you for submitting your ticket. We have received your request and it is currently being reviewed by our team. We will respond within the SLA window.',
 1),
(1, 'Request for More Information',
 'Thank you for reaching out. To better assist you, could you please provide the following additional information?\n\n- [Specific detail needed]\n- [Error message / screenshot]\n\nWe look forward to your response.',
 1),
(1, 'Issue Resolved - Closing Ticket',
 'We are pleased to inform you that the issue has been resolved. This ticket will now be marked as resolved.\n\nIf the issue persists or you have any further questions, please feel free to reopen this ticket or submit a new one.\n\nThank you for your patience.',
 1),
(1, 'Scheduled Maintenance Notice',
 'This issue is related to a scheduled maintenance window. The system will be restored to full functionality by [date/time]. We apologise for any inconvenience caused.',
 1),
(1, 'Escalating to Senior Team',
 'After reviewing your request, we have escalated this ticket to our senior technical team for further investigation. You will be updated as soon as we have more information.',
 1);

-- ---- Knowledge Base Categories ----
CREATE TABLE IF NOT EXISTS `kb_categories` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(120) NOT NULL,
  `icon`       VARCHAR(60) DEFAULT 'bi-folder',
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `kb_categories` (`name`, `icon`, `sort_order`) VALUES
('Getting Started',     'bi-rocket-takeoff',   1),
('Account & Access',    'bi-person-lock',       2),
('Hardware & Devices',  'bi-pc-display',        3),
('Software & Systems',  'bi-window-stack',      4),
('Network',             'bi-wifi',              5),
('POS & Retail',        'bi-receipt',           6),
('General FAQ',         'bi-question-circle',   7);

-- ---- Knowledge Base Articles ----
CREATE TABLE IF NOT EXISTS `kb_articles` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kb_cat_id`    INT UNSIGNED DEFAULT NULL,
  `author_id`    INT UNSIGNED NOT NULL,
  `title`        VARCHAR(255) NOT NULL,
  `slug`         VARCHAR(255) NOT NULL,
  `body`         TEXT NOT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `views`        INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kb_slug` (`slug`),
  FULLTEXT KEY `ft_kb_search` (`title`, `body`),
  KEY `idx_kb_cat` (`kb_cat_id`),
  CONSTRAINT `fk_kb_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_kb_cat` FOREIGN KEY (`kb_cat_id`) REFERENCES `kb_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed sample articles
INSERT INTO `kb_articles` (`kb_cat_id`, `author_id`, `title`, `slug`, `body`, `is_published`) VALUES
(1, 1, 'How to Submit a Support Ticket', 'how-to-submit-a-support-ticket',
 'To submit a support ticket:\n\n1. Log in to the Belmont Helpdesk portal.\n2. Click "New Ticket" in the sidebar.\n3. Fill in the Subject, Description, and Priority.\n4. Attach any relevant screenshots or files.\n5. Click "Submit Ticket".\n\nYour ticket will be reviewed by the IT team and you will receive updates via the portal.',
 1),
(2, 1, 'How to Reset Your Password', 'how-to-reset-your-password',
 'If you have forgotten your password:\n\n1. Go to the login page.\n2. Click "Forgot Password?" below the login form.\n3. Enter your registered email address.\n4. Check your email for a password reset link.\n5. Click the link and enter your new password.\n\nThe reset link expires in 1 hour. If you do not receive the email, check your spam folder.',
 1),
(3, 1, 'POS Terminal Freezing - Troubleshooting', 'pos-terminal-freezing-troubleshooting',
 'If your POS terminal is freezing:\n\n**Step 1 - Restart the application**\nClose and reopen the POS software.\n\n**Step 2 - Restart the terminal**\nPower off the terminal and wait 30 seconds before turning it back on.\n\n**Step 3 - Check connectivity**\nEnsure the terminal is connected to the network.\n\n**Step 4 - Contact IT**\nIf the issue persists after the above steps, submit a support ticket with the terminal ID and a description of what you were doing when it froze.',
 1);

-- ---- Password Reset Tokens ----
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used`       TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_token` (`token`),
  KEY `idx_pr_user` (`user_id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
