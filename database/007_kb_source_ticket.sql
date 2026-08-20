-- Track which ticket generated each KB article (prevents duplicates)
USE `belmont_helpdesk`;

ALTER TABLE `kb_articles`
  ADD COLUMN IF NOT EXISTS `source_ticket_id` INT UNSIGNED DEFAULT NULL AFTER `author_id`;

-- Index (ignore error if already exists)
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'kb_articles' AND index_name = 'idx_kb_source_ticket'
);
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `kb_articles` ADD KEY `idx_kb_source_ticket` (`source_ticket_id`)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
