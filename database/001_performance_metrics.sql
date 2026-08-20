-- ============================================================
-- Migration 001: Performance Metrics & CSAT
-- Run once against belmont_helpdesk database
-- ============================================================

ALTER TABLE `tickets`
  ADD COLUMN `resolved_at`    DATETIME DEFAULT NULL AFTER `closed_at`,
  ADD COLUMN `first_reply_at` DATETIME DEFAULT NULL AFTER `resolved_at`,
  ADD COLUMN `sla_hours`      SMALLINT UNSIGNED DEFAULT NULL AFTER `first_reply_at`,
  ADD COLUMN `sla_breached`   TINYINT(1) NOT NULL DEFAULT 0 AFTER `sla_hours`,
  ADD COLUMN `csat_rating`    TINYINT UNSIGNED DEFAULT NULL AFTER `sla_breached`,
  ADD COLUMN `csat_comment`   TEXT DEFAULT NULL AFTER `csat_rating`;

-- Back-fill sla_hours for existing tickets based on priority
UPDATE `tickets` SET `sla_hours` = CASE priority
  WHEN 'critical' THEN 4
  WHEN 'high'     THEN 8
  WHEN 'medium'   THEN 24
  WHEN 'low'      THEN 48
  ELSE 24
END;

-- Back-fill resolved_at for already-resolved tickets using updated_at as estimate
UPDATE `tickets`
SET `resolved_at` = `updated_at`
WHERE `status` IN ('resolved','closed') AND `resolved_at` IS NULL;

-- Back-fill sla_breached for historical resolved tickets
UPDATE `tickets`
SET `sla_breached` = 1
WHERE `resolved_at` IS NOT NULL
  AND `sla_hours` IS NOT NULL
  AND TIMESTAMPDIFF(HOUR, `created_at`, `resolved_at`) > `sla_hours`;

-- Mark open tickets that have already exceeded SLA
UPDATE `tickets`
SET `sla_breached` = 1
WHERE `status` NOT IN ('resolved','closed')
  AND `sla_hours` IS NOT NULL
  AND TIMESTAMPDIFF(HOUR, `created_at`, NOW()) > `sla_hours`;
