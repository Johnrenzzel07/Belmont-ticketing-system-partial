<?php
/**
 * SLA Breach Checker
 * Call on any staff page load to auto-flag overdue tickets.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$pdo = db();

// Mark open/in-progress tickets past their SLA deadline (hours-based)
$stmt = $pdo->prepare(
    "UPDATE tickets
     SET sla_breached = 1
     WHERE sla_breached = 0
       AND status NOT IN ('resolved','closed')
       AND (
           (sla_hours IS NOT NULL AND TIMESTAMPDIFF(HOUR, created_at, NOW()) > sla_hours)
           OR
           (due_date IS NOT NULL AND due_date < CURDATE())
       )"
);
$stmt->execute();
$updated = $stmt->rowCount();

// Count currently open breached tickets
$count = $pdo->query(
    "SELECT COUNT(*) FROM tickets WHERE sla_breached=1 AND status NOT IN ('resolved','closed')"
)->fetchColumn();

jsonResponse(true, "SLA check complete. {$updated} newly breached.", ['breached_count' => (int)$count]);
