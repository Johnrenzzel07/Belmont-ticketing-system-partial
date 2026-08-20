<?php
/**
 * Auto-Close Automation
 *
 * - Warns requesters when a ticket has been Resolved for N-warn days with no response.
 * - Auto-closes tickets Resolved for N days with no requester response.
 * - Controlled by settings: auto_close_enabled, auto_close_days, auto_close_warn_days.
 *
 * Run via cron:  php api/auto_close.php
 * Or via web:    GET /api/auto_close.php (logged-in; also called on staff page load)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    requireLogin();
    header('Content-Type: application/json');
}

$pdo = db();

if (getSetting($pdo, 'auto_close_enabled', '1') !== '1') {
    $msg = 'Auto-close is disabled in settings.';
    if ($isCli) { echo $msg . PHP_EOL; exit(0); }
    jsonResponse(true, $msg, ['warned' => 0, 'closed' => 0]);
}

$closeDays = max(1, (int)getSetting($pdo, 'auto_close_days', '5'));
$warnDays  = max(1, (int)getSetting($pdo, 'auto_close_warn_days', '3'));
if ($warnDays >= $closeDays) $warnDays = max(1, $closeDays - 1);

$warned = 0;
$closed = 0;

/**
 * A requester "responded" if they posted a non-internal reply after resolved_at.
 */
$noResponseCondition =
    "NOT EXISTS (
        SELECT 1 FROM ticket_replies r
        WHERE r.ticket_id = t.id
          AND r.user_id = t.user_id
          AND r.is_internal = 0
          AND r.created_at > t.resolved_at
    )";

// ---- 1. Warning at warn-day mark ----
$stmt = $pdo->query(
    "SELECT t.id, t.ticket_code, t.user_id
     FROM tickets t
     WHERE t.status = 'resolved'
       AND t.resolved_at IS NOT NULL
       AND t.resolved_at <= DATE_SUB(NOW(), INTERVAL {$warnDays} DAY)
       AND t.resolved_at >  DATE_SUB(NOW(), INTERVAL {$closeDays} DAY)
       AND {$noResponseCondition}
       AND NOT EXISTS (
           SELECT 1 FROM auto_close_logs acl
           WHERE acl.ticket_id = t.id AND acl.action = 'warned'
       )"
);
foreach ($stmt->fetchAll() as $t) {
    $remaining = $closeDays - $warnDays;
    createNotification((int)$t['user_id'], (int)$t['id'], 'auto_close_warning',
        "Ticket {$t['ticket_code']} was resolved {$warnDays} day(s) ago. It will be closed automatically in {$remaining} day(s) if no further response is received.");

    // Email the requester only
    $reqRow = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $reqRow->execute([$t['user_id']]);
    if ($req = $reqRow->fetch()) {
        sendMail(['email' => $req['email'], 'name' => $req['name']],
            "[{$t['ticket_code']}] Your resolved ticket will close in {$remaining} day(s)",
            mailTemplate(
                "Ticket {$t['ticket_code']} — Closing Soon",
                '<p>Your ticket <strong>' . htmlspecialchars($t['ticket_code']) . '</strong> was resolved '
                . $warnDays . ' day(s) ago. If everything is OK, no action is needed — it will close automatically in '
                . $remaining . ' day(s).</p>'
                . '<p>If your issue is NOT resolved, simply reply on the ticket to keep it open.</p>'
                . '<a class="btn" href="' . APP_URL . '/views/tickets/view.php?id=' . (int)$t['id'] . '">View Ticket</a>'
            ));
    }

    $pdo->prepare("INSERT IGNORE INTO auto_close_logs (ticket_id, action) VALUES (?, 'warned')")
        ->execute([$t['id']]);
    $warned++;
}

// ---- 2. Auto-close at close-day mark ----
$stmt = $pdo->query(
    "SELECT t.id, t.ticket_code, t.user_id
     FROM tickets t
     WHERE t.status = 'resolved'
       AND t.resolved_at IS NOT NULL
       AND t.resolved_at <= DATE_SUB(NOW(), INTERVAL {$closeDays} DAY)
       AND {$noResponseCondition}"
);
foreach ($stmt->fetchAll() as $t) {
    $pdo->prepare(
        "UPDATE tickets SET status = 'closed', closed_at = NOW(), updated_at = NOW() WHERE id = ?"
    )->execute([$t['id']]);

    triggerTicketKbLearning($pdo, (int)$t['id'], null);

    createNotification((int)$t['user_id'], (int)$t['id'], 'auto_closed',
        "Ticket {$t['ticket_code']} was automatically closed after {$closeDays} day(s) in resolved status with no further response.");

    // Email the requester only
    $reqRow = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $reqRow->execute([$t['user_id']]);
    if ($req = $reqRow->fetch()) {
        sendMail(['email' => $req['email'], 'name' => $req['name']],
            "[{$t['ticket_code']}] Ticket closed automatically",
            mailTemplate(
                "Ticket {$t['ticket_code']} — Closed",
                '<p>Your ticket <strong>' . htmlspecialchars($t['ticket_code']) . '</strong> was automatically closed after '
                . $closeDays . ' day(s) in resolved status with no further response.</p>'
                . '<p>If you still need help, you may submit a new ticket anytime.</p>'
                . '<a class="btn" href="' . APP_URL . '/views/tickets/create.php">Submit New Ticket</a>'
            ));
    }

    $pdo->prepare("INSERT IGNORE INTO auto_close_logs (ticket_id, action) VALUES (?, 'closed')")
        ->execute([$t['id']]);

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, ticket_id, action, description, ip_address)
             VALUES (NULL, ?, 'auto_closed', ?, 'system')"
        )->execute([$t['id'], "Auto-closed after {$closeDays} days resolved with no response."]);
    } catch (Exception $e) { /* non-fatal */ }

    $closed++;
}

$summary = "Auto-close complete. {$warned} warned, {$closed} closed.";

if ($isCli) {
    echo $summary . PHP_EOL;
    exit(0);
}
jsonResponse(true, $summary, ['warned' => $warned, 'closed' => $closed]);
