<?php
/**
 * Admin Settings
 * Automation controls: auto-close, manual triggers for digests & escalation.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireRole('admin');

$pdo  = db();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf'] ?? '';
    if (empty($token) || $token !== ($_SESSION['csrf_token'] ?? '')) {
        flashMessage('danger', 'Invalid CSRF token.');
    } else {
        $form = $_POST['form'] ?? 'auto_close';

        if ($form === 'mail') {
            $enabled = !empty($_POST['mail_enabled']) ? '1' : '0';
            $host    = trim($_POST['smtp_host'] ?? '');
            $port    = (string)max(1, min(65535, (int)($_POST['smtp_port'] ?? 587)));
            $secure  = in_array($_POST['smtp_secure'] ?? 'tls', ['tls', 'ssl', 'none'], true)
                ? $_POST['smtp_secure'] : 'tls';
            $smtpUser = trim($_POST['smtp_user'] ?? '');
            $from     = trim($_POST['mail_from'] ?? '');
            $fromName = trim($_POST['mail_from_name'] ?? '');
            $newPass  = (string)($_POST['smtp_pass'] ?? '');

            setSetting($pdo, 'mail_enabled', $enabled);
            setSetting($pdo, 'smtp_host', $host);
            setSetting($pdo, 'smtp_port', $port);
            setSetting($pdo, 'smtp_secure', $secure);
            setSetting($pdo, 'smtp_user', $smtpUser);
            setSetting($pdo, 'mail_from', $from);
            setSetting($pdo, 'mail_from_name', $fromName !== '' ? $fromName : COMPANY . ' Helpdesk');
            if ($newPass !== '') {
                setSetting($pdo, 'smtp_pass', $newPass);
            }

            logActivity($user['id'], null, 'settings_updated',
                'Outgoing email settings updated (enabled=' . $enabled . ')', getClientIp());
            flashMessage('success', 'Email settings saved. Use “Send test email” to verify.');
        } elseif ($form === 'mail_test') {
            $to = $user['email'] ?? '';
            $ok = sendMail(
                ['email' => $to, 'name' => $user['name'] ?? ''],
                'Test email — ' . APP_NAME,
                mailTemplate(
                    'Outgoing email is working',
                    '<p>This is a test message from <strong>' . e(APP_NAME) . '</strong>.</p>'
                    . '<p>If you received this, SMTP is configured correctly.</p>'
                ),
                null,
                true
            );
            if ($ok) {
                flashMessage('success', 'Test email sent to ' . e($to) . '. Check that inbox (and spam).');
            } else {
                flashMessage('danger', 'Test email failed: ' . (mailLastError() ?: 'Unknown error. Save SMTP settings first.'));
            }
        } else {
            $enabled   = !empty($_POST['auto_close_enabled']) ? '1' : '0';
            $closeDays = max(1, min(60, (int)($_POST['auto_close_days'] ?? 5)));
            $warnDays  = max(1, min(59, (int)($_POST['auto_close_warn_days'] ?? 3)));
            if ($warnDays >= $closeDays) $warnDays = max(1, $closeDays - 1);

            setSetting($pdo, 'auto_close_enabled', $enabled);
            setSetting($pdo, 'auto_close_days', (string)$closeDays);
            setSetting($pdo, 'auto_close_warn_days', (string)$warnDays);

            logActivity($user['id'], null, 'settings_updated',
                "Auto-close: enabled={$enabled}, close={$closeDays}d, warn={$warnDays}d", getClientIp());
            flashMessage('success', 'Settings saved successfully.');
        }
    }
    header('Location: ' . APP_URL . '/views/admin/settings.php');
    exit;
}

$autoCloseEnabled = getSetting($pdo, 'auto_close_enabled', '1') === '1';
$autoCloseDays    = (int)getSetting($pdo, 'auto_close_days', '5');
$autoCloseWarn    = (int)getSetting($pdo, 'auto_close_warn_days', '3');
$mailCfg          = mailConfig();
$mailPassSet      = mailSetting('smtp_pass', defined('SMTP_PASS') ? SMTP_PASS : '') !== '';

// Recent automation activity
$recentAutoCloses = $pdo->query(
    "SELECT acl.*, t.ticket_code FROM auto_close_logs acl
     JOIN tickets t ON t.id = acl.ticket_id
     ORDER BY acl.created_at DESC LIMIT 8"
)->fetchAll();

$recentEscalations = $pdo->query(
    "SELECT el.*, t.ticket_code FROM escalation_logs el
     JOIN tickets t ON t.id = el.ticket_id
     ORDER BY el.created_at DESC LIMIT 8"
)->fetchAll();

$routingStats = $pdo->query(
    "SELECT COUNT(*) AS total, SUM(accepted) AS accepted FROM auto_routing_logs"
)->fetch();

$pageTitle = 'Settings';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">System Settings</h1>
        <p class="page-subtitle">Automation, escalation, and scheduled task controls.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <!-- Auto-Close Settings -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-stopwatch me-2 text-primary"></i>Ticket Auto-Close Automation</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="form" value="auto_close">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="autoCloseEnabled"
                               name="auto_close_enabled" value="1" <?= $autoCloseEnabled ? 'checked' : '' ?>>
                        <label class="form-check-label fw-600" for="autoCloseEnabled">
                            Enable Auto-Close
                        </label>
                        <div class="form-text">
                            Automatically close tickets that stay in Resolved status with no requester response.
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Warn after (days)</label>
                            <input type="number" name="auto_close_warn_days" class="form-control"
                                   min="1" max="59" value="<?= $autoCloseWarn ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Close after (days)</label>
                            <input type="number" name="auto_close_days" class="form-control"
                                   min="1" max="60" value="<?= $autoCloseDays ?>">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Settings
                    </button>
                </form>
            </div>
        </div>

        <!-- Outgoing Email / SMTP -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-envelope me-2 text-primary"></i>Outgoing Email (SMTP)</div>
            <div class="card-body">
                <p class="text-muted" style="font-size:.8rem">
                    Used for ticket CC alerts, department notices, password resets, and digests.
                    For Gmail, use an <strong>App Password</strong> (not your normal Gmail password).
                </p>
                <form method="POST" class="mb-2">
                    <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="form" value="mail">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="mailEnabled"
                               name="mail_enabled" value="1" <?= !empty($mailCfg['enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label fw-600" for="mailEnabled">Enable outgoing email</label>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label class="form-label">SMTP host</label>
                            <input type="text" name="smtp_host" class="form-control"
                                   value="<?= e($mailCfg['host']) ?>" placeholder="smtp.gmail.com">
                        </div>
                        <div class="col-5">
                            <label class="form-label">Port</label>
                            <input type="number" name="smtp_port" class="form-control"
                                   value="<?= (int)$mailCfg['port'] ?>" min="1" max="65535">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Encryption</label>
                        <select name="smtp_secure" class="form-select">
                            <option value="tls" <?= $mailCfg['secure'] === 'tls' ? 'selected' : '' ?>>TLS (port 587 — Gmail)</option>
                            <option value="ssl" <?= $mailCfg['secure'] === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                            <option value="none" <?= $mailCfg['secure'] === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">SMTP username</label>
                        <input type="text" name="smtp_user" class="form-control"
                               value="<?= e($mailCfg['user']) ?>" placeholder="your-helpdesk@gmail.com" autocomplete="off">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">SMTP password<?= $mailPassSet ? ' <span class="text-muted fw-400">(saved — leave blank to keep)</span>' : '' ?></label>
                        <input type="password" name="smtp_pass" class="form-control" value=""
                               placeholder="<?= $mailPassSet ? '••••••••' : 'Gmail App Password' ?>" autocomplete="new-password">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">From email</label>
                        <input type="email" name="mail_from" class="form-control"
                               value="<?= e($mailCfg['from']) ?>" placeholder="your-helpdesk@gmail.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">From name</label>
                        <input type="text" name="mail_from_name" class="form-control"
                               value="<?= e($mailCfg['fromName']) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Email Settings
                    </button>
                </form>
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="form" value="mail_test">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-send me-1"></i>Send test email to <?= e($user['email'] ?? '') ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Manual Automation Triggers -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-lightning me-2 text-primary"></i>Run Automation Now</div>
            <div class="card-body">
                <p class="text-muted" style="font-size:.8rem">
                    These tasks also run automatically. Use the buttons below to trigger them manually.
                    For scheduled runs, add cron jobs for:
                    <code>api/sla_escalation.php</code>, <code>api/auto_close.php</code>,
                    <code>api/email_digest.php</code>, <code>api/weekly_report.php</code>.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-primary btn-sm run-task" data-task="sla_escalation">
                        <i class="bi bi-arrow-up-circle me-1"></i>SLA Escalation Check
                    </button>
                    <button class="btn btn-outline-primary btn-sm run-task" data-task="auto_close">
                        <i class="bi bi-stopwatch me-1"></i>Auto-Close Check
                    </button>
                    <button class="btn btn-outline-primary btn-sm run-task" data-task="email_digest">
                        <i class="bi bi-envelope me-1"></i>Send Daily Digest
                    </button>
                    <button class="btn btn-outline-primary btn-sm run-task" data-task="weekly_report">
                        <i class="bi bi-envelope-paper me-1"></i>Send Weekly Report
                    </button>
                </div>
                <div id="taskResult" class="mt-2 small text-muted"></div>
            </div>
        </div>

        <!-- AI Routing Stats -->
        <div class="card">
            <div class="card-header"><i class="bi bi-robot me-2 text-primary"></i>AI Auto-Routing</div>
            <div class="card-body">
                <?php
                $totalRouted = (int)($routingStats['total'] ?? 0);
                $acceptedCnt = (int)($routingStats['accepted'] ?? 0);
                ?>
                <div class="d-flex gap-4">
                    <div class="text-center">
                        <div style="font-size:1.4rem;font-weight:700"><?= number_format($totalRouted) ?></div>
                        <div class="text-muted" style="font-size:.72rem">Routing Decisions</div>
                    </div>
                    <div class="text-center">
                        <div style="font-size:1.4rem;font-weight:700;color:#10b981">
                            <?= $totalRouted > 0 ? round($acceptedCnt / $totalRouted * 100) : 0 ?>%
                        </div>
                        <div class="text-muted" style="font-size:.72rem">Accepted by Users</div>
                    </div>
                </div>
                <p class="text-muted mt-2 mb-0" style="font-size:.78rem">
                    Routing keywords are managed in the <code>routing_rules</code> and
                    <code>priority_rules</code> database tables.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <!-- Recent Escalations -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-arrow-up-circle me-2 text-danger"></i>Recent SLA Escalations</div>
            <div class="card-body p-0">
                <?php if (empty($recentEscalations)): ?>
                <div class="text-center text-muted py-4" style="font-size:.82rem">No escalations recorded yet.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentEscalations as $el): ?>
                    <li class="list-group-item py-2 px-3" style="font-size:.8rem">
                        <div class="d-flex justify-content-between">
                            <span>
                                <span class="badge bg-<?= $el['action'] === 'breached' ? 'danger' : 'warning' ?> me-1">
                                    <?= ucfirst($el['action']) ?>
                                </span>
                                <code><?= e($el['ticket_code']) ?></code>
                            </span>
                            <small class="text-muted"><?= timeAgo($el['created_at']) ?></small>
                        </div>
                        <div class="text-muted mt-1" style="font-size:.74rem"><?= e($el['details'] ?? '') ?></div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Auto-Closes -->
        <div class="card">
            <div class="card-header"><i class="bi bi-stopwatch me-2 text-secondary"></i>Recent Auto-Close Activity</div>
            <div class="card-body p-0">
                <?php if (empty($recentAutoCloses)): ?>
                <div class="text-center text-muted py-4" style="font-size:.82rem">No auto-close activity yet.</div>
                <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentAutoCloses as $acl): ?>
                    <li class="list-group-item d-flex justify-content-between py-2 px-3" style="font-size:.8rem">
                        <span>
                            <span class="badge bg-<?= $acl['action'] === 'closed' ? 'secondary' : 'info' ?> me-1">
                                <?= ucfirst($acl['action']) ?>
                            </span>
                            <code><?= e($acl['ticket_code']) ?></code>
                        </span>
                        <small class="text-muted"><?= timeAgo($acl['created_at']) ?></small>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<script>
$('.run-task').on('click', function() {
    const $btn = $(this);
    const task = $btn.data('task');
    const orig = $btn.html();
    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Running...');
    $.get(APP_URL + '/api/' + task + '.php', function(r) {
        $('#taskResult').html('<i class="bi bi-check-circle text-success me-1"></i>' + (r.message || 'Done.'));
        showToast(r.success ? 'success' : 'danger', r.message || 'Task complete.');
    }, 'json').fail(function() {
        showToast('danger', 'Task failed. Check server logs.');
    }).always(function() {
        $btn.prop('disabled', false).html(orig);
    });
});
</script>
<?php $extraScripts = ob_get_clean(); ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
