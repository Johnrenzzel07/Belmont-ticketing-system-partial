<?php
/**
 * SLA Escalation & Alerts (Automation)
 *
 * - Marks tickets sla_breached=1 when past their SLA window.
 * - Escalates tickets (bumps priority, notifies assignee + IT Manager/admins)
 *   when within 1 hour of breaching SLA.
 * - Logs everything to escalation_logs.
 *
 * Run via cron:  php api/sla_escalation.php
 * Or via web:    GET /api/sla_escalation.php (logged-in staff; also called on page load)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    requireLogin();
    header('Content-Type: application/json');
}

$pdo = db();
$escalated = 0;
$breached  = 0;

$priorityBump = ['low' => 'medium', 'medium' => 'high', 'high' => 'critical', 'critical' => 'critical'];

// ---- 1. Mark newly breached tickets ----
$stmt = $pdo->query(
    "SELECT id, ticket_code, priority, assigned_to, user_id, created_at, sla_hours
     FROM tickets
     WHERE sla_breached = 0
       AND status NOT IN ('resolved','closed')
       AND (
           (sla_hours IS NOT NULL AND TIMESTAMPDIFF(MINUTE, created_at, NOW()) > sla_hours * 60)
           OR (due_date IS NOT NULL AND due_date < CURDATE())
       )"
);
foreach ($stmt->fetchAll() as $t) {
    $pdo->prepare("UPDATE tickets SET sla_breached = 1, updated_at = updated_at WHERE id = ?")->execute([$t['id']]);
    $pdo->prepare(
        "INSERT INTO escalation_logs (ticket_id, action, old_priority, new_priority, details)
         VALUES (?, 'breached', ?, ?, ?)"
    )->execute([$t['id'], $t['priority'], $t['priority'], "Ticket {$t['ticket_code']} exceeded its SLA window."]);

    if ($t['assigned_to']) {
        createNotification((int)$t['assigned_to'], (int)$t['id'], 'escalated',
            "SLA BREACHED: Ticket {$t['ticket_code']} has exceeded its SLA window.");
    }
    $breached++;
}

// ---- 2. Escalate tickets within 1 hour of breaching ----
$stmt = $pdo->query(
    "SELECT t.id, t.ticket_code, t.priority, t.assigned_to, t.sla_hours, t.created_at
     FROM tickets t
     WHERE t.sla_breached = 0
       AND t.status IN ('open','in_progress')
       AND t.sla_hours IS NOT NULL
       AND TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) >= (t.sla_hours * 60) - 60
       AND TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) <= (t.sla_hours * 60)
       AND NOT EXISTS (
           SELECT 1 FROM escalation_logs el
           WHERE el.ticket_id = t.id AND el.action = 'escalated'
       )"
);
foreach ($stmt->fetchAll() as $t) {
    $oldPriority = $t['priority'];
    $newPriority = $priorityBump[$oldPriority] ?? $oldPriority;

    if ($newPriority !== $oldPriority) {
        $pdo->prepare("UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$newPriority, $t['id']]);
    }

    $pdo->prepare(
        "INSERT INTO escalation_logs (ticket_id, action, old_priority, new_priority, details)
         VALUES (?, 'escalated', ?, ?, ?)"
    )->execute([
        $t['id'], $oldPriority, $newPriority,
        "Ticket {$t['ticket_code']} is within 1 hour of SLA breach. Priority: {$oldPriority} -> {$newPriority}.",
    ]);

    // Notify assigned staff
    if ($t['assigned_to']) {
        createNotification((int)$t['assigned_to'], (int)$t['id'], 'escalated',
            "SLA WARNING: Ticket {$t['ticket_code']} breaches SLA in under 1 hour. Priority escalated to " . ucfirst($newPriority) . ".");
    }
    // Notify all admins (IT Manager role)
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
    foreach ($admins as $admin) {
        if ((int)$admin['id'] !== (int)$t['assigned_to']) {
            createNotification((int)$admin['id'], (int)$t['id'], 'escalated',
                "SLA WARNING: Ticket {$t['ticket_code']} is about to breach SLA and was escalated to " . ucfirst($newPriority) . ".");
        }
    }

    // Group email to the entire handling department (shared inbox behavior)
    sendDeptMailForTicket($pdo, (int)$t['id'],
        "[{$t['ticket_code']}] SLA WARNING — escalated to " . ucfirst($newPriority),
        mailTemplate(
            "SLA Escalation: {$t['ticket_code']}",
            '<p>Ticket <strong>' . htmlspecialchars($t['ticket_code']) . '</strong> will breach its SLA in under 1 hour.</p>'
            . '<p>Priority was escalated from <strong>' . ucfirst($oldPriority) . '</strong> to <strong>'
            . ucfirst($newPriority) . '</strong>.</p>'
            . '<a class="btn" href="' . APP_URL . '/views/tickets/view.php?id=' . (int)$t['id'] . '">Open Ticket Now</a>'
        ));

    $escalated++;
}

$summary = "SLA check complete. {$escalated} escalated, {$breached} newly breached.";

if ($isCli) {
    echo $summary . PHP_EOL;
    exit(0);
}
jsonResponse(true, $summary, ['escalated' => $escalated, 'breached' => $breached]);
