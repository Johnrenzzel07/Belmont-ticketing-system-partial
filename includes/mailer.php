<?php
/**
 * Mailer Helper
 * Sends through SMTP using values from Admin > Settings (with config/database.php as fallback).
 */

function mailLastError(): string
{
    return (string)($GLOBALS['_mail_last_error'] ?? '');
}

function mailSetError(string $message): void
{
    $GLOBALS['_mail_last_error'] = $message;
    error_log('[Mailer] ' . $message);
}

function mailSetting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
                $cache[$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (Exception $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function mailConfig(): array
{
    $enabled = mailSetting('mail_enabled', '');
    return [
        'enabled'  => $enabled !== '' ? $enabled === '1' : (defined('MAIL_ENABLED') && MAIL_ENABLED),
        'host'     => mailSetting('smtp_host', defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com'),
        'port'     => (int)mailSetting('smtp_port', defined('SMTP_PORT') ? (string)SMTP_PORT : '587'),
        'secure'   => mailSetting('smtp_secure', 'tls'),
        'user'     => mailSetting('smtp_user', defined('SMTP_USER') ? SMTP_USER : ''),
        'pass'     => mailSetting('smtp_pass', defined('SMTP_PASS') ? SMTP_PASS : ''),
        'from'     => mailSetting('mail_from', defined('MAIL_FROM') ? MAIL_FROM : ''),
        'fromName' => mailSetting('mail_from_name', defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : COMPANY),
    ];
}

function smtpRead($fp): string
{
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtpCmd($fp, ?string $cmd, int $expect): bool
{
    if ($cmd !== null) {
        fwrite($fp, $cmd . "\r\n");
    }
    $resp = smtpRead($fp);
    $code = (int)substr($resp, 0, 3);
    if ($code !== $expect) {
        mailSetError("SMTP unexpected reply (wanted {$expect}): " . trim($resp));
        return false;
    }
    return true;
}

function sendViaSmtp(array $cfg, string $toEmail, string $toName, string $subject, string $html, ?string $replyTo): bool
{
    $host = $cfg['host'];
    $port = (int)$cfg['port'] ?: 587;
    $secure = strtolower((string)$cfg['secure']);
    $prefix = ($secure === 'ssl' || $port === 465) ? 'ssl://' : 'tcp://';
    $remote = $prefix . $host . ':' . $port;

    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ],
    ]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        mailSetError("Could not connect to {$remote}: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($fp, 20);

    if (!smtpCmd($fp, null, 220)) { fclose($fp); return false; }
    if (!smtpCmd($fp, 'EHLO belmont-helpdesk', 250)) { fclose($fp); return false; }

    if ($secure === 'tls' || ($prefix === 'tcp://' && $port === 587)) {
        if (!smtpCmd($fp, 'STARTTLS', 220)) { fclose($fp); return false; }
        $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
            mailSetError('STARTTLS failed. Check host/port/encryption.');
            fclose($fp);
            return false;
        }
        if (!smtpCmd($fp, 'EHLO belmont-helpdesk', 250)) { fclose($fp); return false; }
    }

    if ($cfg['user'] !== '') {
        if (!smtpCmd($fp, 'AUTH LOGIN', 334)) { fclose($fp); return false; }
        if (!smtpCmd($fp, base64_encode($cfg['user']), 334)) { fclose($fp); return false; }
        if (!smtpCmd($fp, base64_encode($cfg['pass']), 235)) { fclose($fp); return false; }
    }

    $from = $cfg['from'] ?: $cfg['user'];
    $fromName = $cfg['fromName'] ?: $from;
    if (!smtpCmd($fp, 'MAIL FROM:<' . $from . '>', 250)) { fclose($fp); return false; }
    fwrite($fp, 'RCPT TO:<' . $toEmail . ">\r\n");
    $rcpt = smtpRead($fp);
    $rcptCode = (int)substr($rcpt, 0, 3);
    if ($rcptCode !== 250 && $rcptCode !== 251) {
        mailSetError('SMTP recipient rejected: ' . trim($rcpt));
        fclose($fp);
        return false;
    }
    if (!smtpCmd($fp, 'DATA', 354)) { fclose($fp); return false; }

    $headers  = 'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"'), $from) . "\r\n";
    $headers .= 'To: ' . sprintf('"%s" <%s>', addcslashes($toName ?: $toEmail, '"'), $toEmail) . "\r\n";
    if ($replyTo) {
        $headers .= 'Reply-To: ' . $replyTo . "\r\n";
    }
    $fromHost = substr(strrchr($from, '@') ?: '@localhost', 1);
    $headers .= 'Message-ID: <' . bin2hex(random_bytes(8)) . '.' . time() . '@' . $fromHost . ">\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $unsubUrl = defined('APP_URL') ? APP_URL . '/views/profile/index.php' : '';
    if ($unsubUrl !== '') {
        $headers .= 'List-Unsubscribe: <' . $unsubUrl . '>' . "\r\n";
        $headers .= 'List-Unsubscribe-Post: List-Unsubscribe=One-Click' . "\r\n";
    }
    $headers .= 'X-Mailer: Belmont Helpdesk' . "\r\n";
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $headers .= 'Subject: ' . $encSubject . "\r\n";

    $plain = trim(html_entity_decode(strip_tags(str_replace(
        ['<br>', '<br/>', '<br />', '</p>', '</h2>', '</h1>'],
        "\n",
        $html
    )), ENT_QUOTES, 'UTF-8'));
    $plain = preg_replace("/[ \t]+/", ' ', $plain) ?? $plain;
    $plain = preg_replace("/\n{3,}/", "\n\n", $plain) ?? $plain;

    $boundary = 'b_' . bin2hex(random_bytes(8));
    $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";

    $payload  = '--' . $boundary . "\r\n";
    $payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $payload .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $payload .= str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $plain)) . "\r\n\r\n";
    $payload .= '--' . $boundary . "\r\n";
    $payload .= "Content-Type: text/html; charset=UTF-8\r\n";
    $payload .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $payload .= str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $html)) . "\r\n";
    $payload .= '--' . $boundary . "--\r\n";

    $payload = preg_replace('/^\./m', '..', $payload);

    fwrite($fp, $headers . "\r\n" . $payload . "\r\n.\r\n");
    if (!smtpCmd($fp, null, 250)) { fclose($fp); return false; }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return true;
}

/**
 * Send an email.
 *
 * @param string|array $to      Single email or ['email'=>'...','name'=>'...']
 * @param string       $subject Email subject
 * @param string       $html    HTML body
 * @param string|null  $replyTo Optional Reply-To address (e.g. department shared inbox)
 * @param bool         $force   Skip the recipient's notify_email opt-out (used by the test button)
 */
function sendMail(string|array $to, string $subject, string $html, ?string $replyTo = null, bool $force = false): bool
{
    $toEmail = is_array($to) ? ($to['email'] ?? '') : $to;
    $toName  = is_array($to) ? ($to['name']  ?? '') : '';
    $GLOBALS['_mail_last_error'] = '';

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        mailSetError('Invalid recipient email.');
        return false;
    }

    if (!$force) {
        try {
            $pref = db()->prepare('SELECT notify_email FROM users WHERE email = ? LIMIT 1');
            $pref->execute([$toEmail]);
            $notifyEmail = $pref->fetchColumn();
            if ($notifyEmail !== false && (int)$notifyEmail === 0) {
                mailSetError('Recipient has email notifications turned off in their profile.');
                return false;
            }
        } catch (Exception $e) {
            // Non-fatal — fall through and attempt to send
        }
    }

    $cfg = mailConfig();
    if (!$cfg['enabled']) {
        mailSetError('Outgoing email is disabled. Enable it in Settings.');
        error_log("[Mailer disabled] Would send to: " . $toEmail . " | Subject: $subject");
        return false;
    }
    if ($cfg['host'] === '' || $cfg['from'] === '') {
        mailSetError('SMTP host and From address are required.');
        return false;
    }

    return sendViaSmtp($cfg, $toEmail, $toName, $subject, $html, $replyTo);
}

/**
 * Send an email to EVERY active member of a department.
 *
 * The company has one shared inbox per department (e.g. it@belmont.ph) but
 * multiple individual accounts. This delivers the same message to every
 * member individually, with the department's shared email set as Reply-To
 * so any reply lands in the shared inbox.
 *
 * @param int    $deptId  Department ID
 * @param string $subject Email subject
 * @param string $html    HTML body
 * @param int    $excludeUserId Optional actor to skip
 * @return int Number of emails sent (or queued/logged)
 */
function sendDeptMail(int $deptId, string $subject, string $html, int $excludeUserId = 0): int
{
    if (!$deptId) return 0;
    $pdo = db();

    $sharedEmail = null;
    try {
        $d = $pdo->prepare('SELECT shared_email FROM departments WHERE id = ?');
        $d->execute([$deptId]);
        $sharedEmail = $d->fetchColumn() ?: null;
    } catch (Exception $e) { /* non-fatal */ }

    $stmt = $pdo->prepare(
        'SELECT name, email FROM users WHERE department_id = ? AND is_active = 1' .
        ($excludeUserId ? ' AND id != ?' : '')
    );
    $stmt->execute($excludeUserId ? [$deptId, $excludeUserId] : [$deptId]);

    $sent = 0;
    foreach ($stmt->fetchAll() as $member) {
        if (sendMail(['email' => $member['email'], 'name' => $member['name']], $subject, $html, $sharedEmail)) {
            $sent++;
        }
    }
    return $sent;
}

/**
 * Email ticket watchers who are NOT in the ticket's department.
 * Department members already get sendDeptMailForTicket(); this covers CC'd
 * colleagues in other departments so they still learn about updates.
 */
function emailWatchersOutsideDept(PDO $pdo, int $ticketId, string $subject, string $html, int $excludeUserId = 0): int
{
    if (!$ticketId) return 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT u.name, u.email
             FROM ticket_watchers w
             JOIN users u ON u.id = w.user_id
             JOIN tickets t ON t.id = w.ticket_id
             WHERE w.ticket_id = ?
               AND u.is_active = 1
               AND (t.department_id IS NULL OR u.department_id IS NULL OR u.department_id != t.department_id)"
            . ($excludeUserId ? ' AND u.id != ?' : '')
        );
        $stmt->execute($excludeUserId ? [$ticketId, $excludeUserId] : [$ticketId]);
        $sent = 0;
        foreach ($stmt->fetchAll() as $watcher) {
            if (sendMail(['email' => $watcher['email'], 'name' => $watcher['name']], $subject, $html)) {
                $sent++;
            }
        }
        return $sent;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Send a department-wide email for a specific ticket.
 * Looks up the ticket's department and broadcasts to all its members.
 */
function sendDeptMailForTicket(PDO $pdo, int $ticketId, string $subject, string $html, int $excludeUserId = 0): int
{
    try {
        $stmt = $pdo->prepare('SELECT department_id FROM tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        $deptId = (int)$stmt->fetchColumn();
        if (!$deptId) return 0;
        return sendDeptMail($deptId, $subject, $html, $excludeUserId);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Blue call-to-action button that stays readable in Gmail (inline styles).
 */
function mailButton(string $url, string $label): string
{
    $url   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:18px 0 10px">'
        . '<tr><td align="left" bgcolor="#1d4ed8" style="border-radius:8px;background-color:#1d4ed8">'
        . '<a href="' . $url . '" target="_blank"'
        . ' style="display:inline-block;padding:12px 26px;font-family:Arial,Helvetica,sans-serif;'
        . 'font-size:15px;font-weight:700;line-height:1.2;color:#ffffff;text-decoration:none;'
        . 'background-color:#1d4ed8;border-radius:8px;border:1px solid #1d4ed8">'
        . '<span style="color:#ffffff;text-decoration:none">' . $label . '</span>'
        . '</a></td></tr></table>';
}

/**
 * Build a standard branded email template.
 */
function mailTemplate(string $title, string $body): string
{
    $company = htmlspecialchars(COMPANY);
    $appName = htmlspecialchars(APP_NAME);
    $title   = htmlspecialchars($title);
    $profileUrl = defined('APP_URL') ? APP_URL . '/views/profile/index.php' : '';

    $body = preg_replace_callback(
        '/<a([^>]*?)class="btn"([^>]*?)href="([^"]+)"([^>]*)>(.*?)<\/a>/is',
        static function (array $m): string {
            return mailButton(html_entity_decode($m[3], ENT_QUOTES, 'UTF-8'), trim(strip_tags($m[5])));
        },
        $body
    ) ?? $body;
    $body = preg_replace_callback(
        '/<a([^>]*?)href="([^"]+)"([^>]*?)class="btn"([^>]*)>(.*?)<\/a>/is',
        static function (array $m): string {
            return mailButton(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'), trim(strip_tags($m[5])));
        },
        $body
    ) ?? $body;

    $manage = $profileUrl !== ''
        ? '<a href="' . htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') . '" style="color:#1d4ed8;text-decoration:underline">Manage email notifications</a>'
        : 'Manage email notifications in your profile';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<title>{$title}</title>
</head>
<body style="margin:0;padding:0;background-color:#e8eef7;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#e8eef7;padding:24px 12px">
  <tr>
    <td align="center">
      <table role="presentation" width="580" cellspacing="0" cellpadding="0" border="0" style="max-width:580px;width:100%;background-color:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #dbe4f0">
        <tr>
          <td style="background-color:#1d4ed8;background:linear-gradient(135deg,#1d4ed8,#2563eb);padding:26px 32px;color:#ffffff">
            <h1 style="margin:0;font-size:20px;line-height:1.3;font-weight:700;color:#ffffff;font-family:Arial,Helvetica,sans-serif">{$appName}</h1>
            <p style="margin:6px 0 0;font-size:13px;line-height:1.4;color:#dbeafe;font-family:Arial,Helvetica,sans-serif">{$company}</p>
          </td>
        </tr>
        <tr>
          <td style="padding:28px 32px 12px;background-color:#ffffff">
            <h2 style="margin:0 0 14px;font-size:18px;line-height:1.4;font-weight:700;color:#0f172a;font-family:Arial,Helvetica,sans-serif">{$title}</h2>
            <div style="font-size:15px;line-height:1.7;color:#334155;font-family:Arial,Helvetica,sans-serif">
              {$body}
            </div>
          </td>
        </tr>
        <tr>
          <td style="padding:16px 32px 22px;background-color:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.6;color:#64748b;text-align:center;font-family:Arial,Helvetica,sans-serif">
            This message was sent by {$appName} for {$company}.<br>
            {$manage}<br>
            Cebu Belmont, Inc. &mdash; Cebu, Philippines
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}
