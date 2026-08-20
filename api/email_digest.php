<?php
/**
 * Daily Email Digest for Staff
 * Sends each active staff/admin a summary of their workload:
 * open tickets, due today, SLA breached, and unread replies.
 *
 * Run via cron:  php api/email_digest.php
 * Or manually:   triggered from Admin > Settings (admin only)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    requireLogin();
    if (!isAdmin()) jsonResponse(false, 'Permission denied.', [], 403);
    header('Content-Type: application/json');
}

$pdo  = db();
$sent = 0;
$skipped = 0;

$staff = $pdo->query(
    "SELECT id, name, email FROM users WHERE role IN ('staff','admin') AND is_active = 1"
)->fetchAll();

foreach ($staff as $member) {
    $uid = (int)$member['id'];

    $open = (int)$pdo->query(
        "SELECT COUNT(*) FROM tickets WHERE assigned_to = {$uid} AND status NOT IN ('resolved','closed')"
    )->fetchColumn();

    $dueToday = (int)$pdo->query(
        "SELECT COUNT(*) FROM tickets WHERE assigned_to = {$uid} AND status NOT IN ('resolved','closed') AND due_date = CURDATE()"
    )->fetchColumn();

    $breachedRows = $pdo->query(
        "SELECT ticket_code, subject FROM tickets
         WHERE assigned_to = {$uid} AND sla_breached = 1 AND status NOT IN ('resolved','closed')
         ORDER BY created_at ASC LIMIT 10"
    )->fetchAll();

    $unreadReplies = (int)$pdo->query(
        "SELECT COUNT(*) FROM notifications
         WHERE user_id = {$uid} AND is_read = 0 AND type = 'replied'"
    )->fetchColumn();

    // Nothing to report — skip to avoid noise
    if ($open === 0 && $dueToday === 0 && empty($breachedRows) && $unreadReplies === 0) {
        $skipped++;
        continue;
    }

    $breachedHtml = '';
    if ($breachedRows) {
        $breachedHtml = '<p><strong style="color:#dc2626">SLA-breached tickets:</strong></p><ul>';
        foreach ($breachedRows as $b) {
            $breachedHtml .= '<li><strong>' . htmlspecialchars($b['ticket_code']) . '</strong> — '
                           . htmlspecialchars(mb_strimwidth($b['subject'], 0, 60, '...')) . '</li>';
        }
        $breachedHtml .= '</ul>';
    }

    $body = '<p>Hi ' . htmlspecialchars($member['name']) . ', here is your daily helpdesk summary:</p>'
        . '<ul>'
        . '<li><strong>' . $open . '</strong> open ticket(s) assigned to you</li>'
        . '<li><strong>' . $dueToday . '</strong> ticket(s) due today</li>'
        . '<li><strong>' . count($breachedRows) . '</strong> SLA-breached ticket(s)</li>'
        . '<li><strong>' . $unreadReplies . '</strong> unread repl(ies)</li>'
        . '</ul>'
        . $breachedHtml
        . '<a class="btn" href="' . APP_URL . '/views/tickets/index.php">Open My Tickets</a>';

    $html = mailTemplate('Your Daily Helpdesk Digest — ' . date('M d, Y'), $body);
    if (sendMail(['email' => $member['email'], 'name' => $member['name']],
                 '[Helpdesk] Daily Digest — ' . date('M d, Y'), $html)) {
        $sent++;
    }
}

$summary = "Daily digest done. Sent: {$sent}, skipped (nothing to report): {$skipped}."
         . (MAIL_ENABLED ? '' : ' NOTE: MAIL_ENABLED is false — emails were logged, not sent.');

if ($isCli) {
    echo $summary . PHP_EOL;
    exit(0);
}
jsonResponse(true, $summary, ['sent' => $sent, 'skipped' => $skipped]);
