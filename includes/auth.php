<?php
/**
 * Auth Middleware / Session Helper
 */

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'] ?? '', $roles, true)) {
        http_response_code(403);
        include __DIR__ . '/../views/errors/403.php';
        exit;
    }
}

function isAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

/**
 * Department Admin: staff-level powers (tickets, reports, CSAT) but
 * strictly scoped to their own department's data.
 */
function isDeptAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'dept_admin';
}

function isStaff(): bool {
    return in_array($_SESSION['user_role'] ?? '', ['admin', 'dept_admin', 'staff'], true);
}

/**
 * Returns the department id that all staff-level queries must be scoped to,
 * or null when the user may see system-wide data (admin/staff).
 */
function deptScopeId(): ?int {
    if (!isDeptAdmin()) return null;
    $deptId = (int)($_SESSION['user_dept'] ?? 0);
    return $deptId ?: 0; // 0 = dept_admin with no department: matches nothing
}

function currentUser(): array {
    return [
        'id'         => $_SESSION['user_id']   ?? 0,
        'name'       => $_SESSION['user_name'] ?? '',
        'email'      => $_SESSION['user_email']?? '',
        'role'       => $_SESSION['user_role'] ?? 'user',
        'avatar'     => $_SESSION['user_avatar']?? null,
        'dept_id'    => $_SESSION['user_dept']  ?? null,
    ];
}

function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_avatar']= $user['avatar'];
    $_SESSION['user_dept']  = $user['department_id'];

    // Update last_login
    $stmt = db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $stmt->execute([$user['id']]);

    // Log activity
    logActivity($user['id'], null, 'login', 'User logged in', getClientIp());
}

function logoutUser(): void {
    if (isLoggedIn()) {
        logActivity($_SESSION['user_id'], null, 'logout', 'User logged out', getClientIp());
    }
    session_unset();
    session_destroy();
}

function logActivity(int $userId, ?int $ticketId, string $action, string $desc = '', string $ip = ''): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_logs (user_id, ticket_id, action, description, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId ?: null, $ticketId, $action, $desc, $ip]);
    } catch (Exception $e) {
        // Non-fatal
    }
}

function getClientIp(): string {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            return filter_var(
                explode(',', $_SERVER[$key])[0],
                FILTER_VALIDATE_IP
            ) ?: '';
        }
    }
    return '';
}

function createNotification(int $userId, ?int $ticketId, string $type, string $message): void {
    try {
        // Respect the user's in-app notification preference
        $pref = db()->prepare('SELECT notify_inapp FROM users WHERE id = ?');
        $pref->execute([$userId]);
        $notifyInapp = $pref->fetchColumn();
        if ($notifyInapp !== false && (int)$notifyInapp === 0) {
            return; // user opted out of in-app notifications
        }

        $stmt = db()->prepare(
            'INSERT INTO notifications (user_id, ticket_id, type, message) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $ticketId, $type, $message]);
    } catch (Exception $e) {
        // Non-fatal
    }
}

/**
 * Broadcast an in-app notification to every active member of a department.
 * Used for the "one shared email per department, many accounts" workflow:
 * everything that would go to the department inbox is delivered to each member.
 *
 * @param int      $deptId        Department to broadcast to
 * @param int|null $ticketId      Related ticket (nullable)
 * @param string   $type          Notification type (must exist in the notifications ENUM)
 * @param string   $message       Message body
 * @param int      $excludeUserId Actor to skip (so you don't notify yourself)
 */
function notifyDepartment(PDO $pdo, int $deptId, ?int $ticketId, string $type, string $message, int $excludeUserId = 0): void {
    if (!$deptId) return;
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM users WHERE department_id = ? AND is_active = 1' .
            ($excludeUserId ? ' AND id != ?' : '')
        );
        $stmt->execute($excludeUserId ? [$deptId, $excludeUserId] : [$deptId]);
        foreach ($stmt->fetchAll() as $member) {
            createNotification((int)$member['id'], $ticketId, $type, $message);
        }
    } catch (Exception $e) {
        // Non-fatal
    }
}

function getUnreadNotifications(int $userId): array {
    $stmt = db()->prepare(
        'SELECT n.*, t.ticket_code
         FROM notifications n
         LEFT JOIN tickets t ON t.id = n.ticket_id
         WHERE n.user_id = ? AND n.is_read = 0
         ORDER BY n.created_at DESC
         LIMIT 10'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function markNotificationsRead(int $userId): void {
    $stmt = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
    $stmt->execute([$userId]);
}

function generateTicketCode(): string {
    $stmt = db()->query('SELECT MAX(CAST(SUBSTRING(ticket_code, 4) AS UNSIGNED)) as max_num FROM tickets');
    $row  = $stmt->fetch();
    $next = ($row['max_num'] ?? 0) + 1;
    return 'TK-' . str_pad($next, 6, '0', STR_PAD_LEFT);
}

function sanitize(string $str): string {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * Turn a stored avatar value into a URL that works on the current device/host.
 * DB may hold a relative path (uploads/avatars/...) or a legacy full URL with localhost.
 */
function resolveAvatarUrl(?string $avatar): ?string
{
    if ($avatar === null || trim($avatar) === '') {
        return null;
    }

    if (preg_match('~/uploads/avatars/([^/?#]+)~i', $avatar, $m)) {
        return rtrim(APP_URL, '/') . '/uploads/avatars/' . $m[1];
    }

    return rtrim(APP_URL, '/') . '/' . ltrim($avatar, '/');
}

/**
 * Render an avatar bubble: real image if available, initials fallback otherwise.
 * @param string|null $avatarUrl  Stored avatar URL (from users.avatar)
 * @param string      $name       User's display name
 * @param string      $class      CSS class(es) to apply (default: avatar-sm)
 * @param string      $style      Extra inline styles
 */
function renderAvatar(?string $avatarUrl, string $name, string $class = 'avatar-sm', string $style = ''): string {
    $initials = strtoupper(substr(trim($name) ?: '?', 0, 2));
    $styleAttr = $style ? ' style="' . htmlspecialchars($style, ENT_QUOTES, 'UTF-8') . '"' : '';
    $resolved = resolveAvatarUrl($avatarUrl);
    if ($resolved) {
        $src = htmlspecialchars($resolved, ENT_QUOTES, 'UTF-8');
        $alt = htmlspecialchars($name,      ENT_QUOTES, 'UTF-8');
        return '<div class="' . $class . '"' . $styleAttr . '>'
             . '<img src="' . $src . '" alt="' . $alt . '" '
             . 'style="width:100%;height:100%;object-fit:cover;border-radius:50%;">'
             . '</div>';
    }
    return '<div class="' . $class . '"' . $styleAttr . '>'
         . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')
         . '</div>';
}


function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Normalize ticket description for display: drop leftover indent and put
 * the AI section labels on their own lines.
 */
function formatTicketDescription(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/^[ \t]+/m', '', $text) ?? $text;
    $labels = [
        'Issue Summary:',
        'Details / What Happened:',
        'Error Message (if any):',
        'What I Already Tried:',
        'Impact / Urgency:',
    ];
    foreach ($labels as $label) {
        $text = preg_replace('/\s*(' . preg_quote($label, '/') . ')/', "\n\n$1", $text) ?? $text;
    }
    return trim($text);
}

function flashMessage(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashMessages(): array {
    $msgs = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $msgs;
}

function formatDateTime(string $datetime): string {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') return 'N/A';
    return date('M d, Y h:i A', strtotime($datetime));
}

function formatDate(string $date): string {
    if (empty($date) || $date === '0000-00-00') return 'N/A';
    return date('M d, Y', strtotime($date));
}

function timeAgo(string $datetime): string {
    $now  = time();
    $then = strtotime($datetime);
    $diff = $now - $then;
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60)   . ' min ago';
    if ($diff < 86400)  return floor($diff/3600)  . ' hr ago';
    if ($diff < 604800) return floor($diff/86400) . ' days ago';
    return date('M d, Y', $then);
}

function getPriorityBadge(string $priority): string {
    $map = [
        'low'      => 'secondary',
        'medium'   => 'primary',
        'high'     => 'warning',
        'critical' => 'danger',
    ];
    $cls = $map[$priority] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . ucfirst($priority) . '</span>';
}

function getStatusBadge(string $status): string {
    $map = [
        'open'        => 'info',
        'in_progress' => 'primary',
        'pending'     => 'warning',
        'resolved'    => 'success',
        'closed'      => 'secondary',
    ];
    $label = str_replace('_', ' ', $status);
    $cls   = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . ucwords($label) . '</span>';
}

function paginate(int $total, int $perPage, int $current): array {
    $totalPages = max(1, (int) ceil($total / $perPage));
    $current    = max(1, min($current, $totalPages));
    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $current,
        'total_pages' => $totalPages,
        'offset'      => ($current - 1) * $perPage,
        'has_prev'    => $current > 1,
        'has_next'    => $current < $totalPages,
    ];
}

function jsonResponse(bool $success, string $message, array $data = [], int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function validateCsrf(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || $token !== ($_SESSION['csrf_token'] ?? '')) {
        jsonResponse(false, 'Invalid CSRF token.', [], 403);
    }
}

function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
