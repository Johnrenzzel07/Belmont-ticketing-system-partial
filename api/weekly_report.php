<?php
/**
 * Weekly System Report for Admins
 * System-wide stats for the last 7 days: total tickets, resolution rate,
 * avg resolution time, CSAT average, top requester departments.
 *
 * Run via cron:  php api/weekly_report.php
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

$pdo = db();

$total = (int)$pdo->query(
    "SELECT COUNT(*) FROM tickets WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetchColumn();

$resolved = (int)$pdo->query(
    "SELECT COUNT(*) FROM tickets
     WHERE resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetchColumn();

$resolutionRate = $total > 0 ? round($resolved / $total * 100) : 0;

$avgResolutionHrs = $pdo->query(
    "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60, 1)
     FROM tickets
     WHERE resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetchColumn();
$avgResolutionHrs = $avgResolutionHrs !== null ? (float)$avgResolutionHrs : 0;

$csatAvg = $pdo->query(
    "SELECT ROUND(AVG(csat_rating), 1) FROM tickets
     WHERE csat_rating IS NOT NULL AND resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetchColumn();

$slaCompliance = $pdo->query(
    "SELECT ROUND(SUM(sla_breached = 0) / COUNT(*) * 100)
     FROM tickets WHERE resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
)->fetchColumn();

$topDepts = $pdo->query(
    "SELECT d.name, COUNT(*) AS cnt
     FROM tickets t
     JOIN departments d ON d.id = t.department_id
     WHERE t.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     GROUP BY d.id ORDER BY cnt DESC LIMIT 5"
)->fetchAll();

$deptHtml = '';
if ($topDepts) {
    $deptHtml = '<p><strong>Top requester departments:</strong></p><ul>';
    foreach ($topDepts as $d) {
        $deptHtml .= '<li>' . htmlspecialchars($d['name']) . ' — ' . (int)$d['cnt'] . ' ticket(s)</li>';
    }
    $deptHtml .= '</ul>';
}

$body = '<p>Weekly helpdesk performance report for the period '
    . date('M d', strtotime('-7 days')) . ' – ' . date('M d, Y') . ':</p>'
    . '<ul>'
    . '<li><strong>' . $total . '</strong> new ticket(s) created</li>'
    . '<li><strong>' . $resolved . '</strong> ticket(s) resolved (' . $resolutionRate . '% resolution rate)</li>'
    . '<li><strong>' . $avgResolutionHrs . 'h</strong> average resolution time</li>'
    . '<li><strong>' . ($slaCompliance !== null && $slaCompliance !== false ? $slaCompliance . '%' : 'N/A') . '</strong> SLA compliance</li>'
    . '<li><strong>' . ($csatAvg ?: 'N/A') . '</strong> average CSAT rating</li>'
    . '</ul>'
    . $deptHtml
    . '<a class="btn" href="' . APP_URL . '/views/reports/index.php">View Full Reports</a>';

$html = mailTemplate('Weekly Helpdesk Report — ' . date('M d, Y'), $body);

$sent = 0;
$admins = $pdo->query("SELECT name, email FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
foreach ($admins as $admin) {
    if (sendMail(['email' => $admin['email'], 'name' => $admin['name']],
                 '[Helpdesk] Weekly Report — ' . date('M d, Y'), $html)) {
        $sent++;
    }
}

$summary = "Weekly report done. Sent to {$sent} admin(s)."
         . (MAIL_ENABLED ? '' : ' NOTE: MAIL_ENABLED is false — emails were logged, not sent.');

if ($isCli) {
    echo $summary . PHP_EOL;
    exit(0);
}
jsonResponse(true, $summary, ['sent' => $sent]);
