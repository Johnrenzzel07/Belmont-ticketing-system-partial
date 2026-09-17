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
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    $now  = time();
    $then = strtotime($datetime);
    if ($then === false) {
        return 'N/A';
    }
    $diff = $now - $then;
    if ($diff < 0)    return 'Just now';
    if ($diff < 60)   return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
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

/**
 * Load active categories with department labels for duplicate names.
 *
 * @return array<int, array<string, mixed>>
 */
function loadCategoriesWithLabels(PDO $pdo): array
{
    $categories = $pdo->query(
        "SELECT c.id, c.name, c.department_id, d.name AS department_name
         FROM categories c
         LEFT JOIN departments d ON d.id = c.department_id
         WHERE c.is_active = 1
         ORDER BY c.name, d.name"
    )->fetchAll();

    $categoryNameCounts = [];
    foreach ($categories as $c) {
        $key = strtolower(trim($c['name']));
        $categoryNameCounts[$key] = ($categoryNameCounts[$key] ?? 0) + 1;
    }
    foreach ($categories as &$c) {
        $needsDept = ($categoryNameCounts[strtolower(trim($c['name']))] ?? 0) > 1;
        $c['label'] = ($needsDept && !empty($c['department_name']))
            ? $c['name'] . ' — ' . $c['department_name']
            : $c['name'];
    }
    unset($c);

    return $categories;
}

function categoriesToClientJson(array $categories): string
{
    return json_encode(array_map(static fn($c) => [
        'id'              => (int)$c['id'],
        'name'            => $c['name'],
        'label'           => $c['label'] ?? $c['name'],
        'department_id'   => (int)($c['department_id'] ?? 0),
        'department_name' => $c['department_name'] ?? '',
    ], $categories), JSON_UNESCAPED_UNICODE);
}

function categoryLabelById(array $categories, int $categoryId): string
{
    foreach ($categories as $c) {
        if ((int)$c['id'] === $categoryId) {
            return $c['label'] ?? $c['name'];
        }
    }
    return '';
}

function parseCategoryInput(string $input): array
{
    if (preg_match('/^(.+?)\s+[—\-]\s+(.+)$/', trim($input), $m)) {
        return ['name' => trim($m[1]), 'department_name' => trim($m[2])];
    }
    return ['name' => trim($input), 'department_name' => null];
}

function resolveMigrationCategoryId(PDO $pdo, ?int $categoryId, string $categoryName, ?int $deptId): ?int
{
    if ($categoryId) {
        $check = $pdo->prepare('SELECT id FROM categories WHERE id = ? AND is_active = 1');
        $check->execute([$categoryId]);
        if ($check->fetch()) {
            return $categoryId;
        }
    }

    $parsed = parseCategoryInput($categoryName);
    $name = $parsed['name'];
    if ($name === '') {
        return null;
    }

    if ($parsed['department_name']) {
        $stmt = $pdo->prepare(
            "SELECT c.id FROM categories c
             JOIN departments d ON d.id = c.department_id
             WHERE LOWER(c.name) = LOWER(?) AND LOWER(d.name) = LOWER(?) AND c.is_active = 1
             LIMIT 1"
        );
        $stmt->execute([$name, $parsed['department_name']]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int)$existing['id'];
        }
    }

    if ($deptId) {
        $stmt = $pdo->prepare(
            'SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND department_id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$name, $deptId]);
        $existing = $stmt->fetch();
        if ($existing) {
            return (int)$existing['id'];
        }
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$name]);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int)$existing['id'];
    }

    $pdo->prepare('INSERT INTO categories (department_id, name) VALUES (?, ?)')
        ->execute([$deptId ?: null, $name]);

    return (int)$pdo->lastInsertId();
}

/**
 * Extract an uploaded file list from a standard or nested $_FILES entry.
 *
 * @param array|null $filesEntry e.g. $_FILES['attachments'] or $_FILES['thread_files']
 * @param int|string|null $subIndex index for nested thread row uploads
 * @return array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function extractUploadedFileList(?array $filesEntry, $subIndex = null): array
{
    if (empty($filesEntry) || empty($filesEntry['name'])) {
        return [];
    }

    $results = [];

    if ($subIndex !== null) {
        $names = null;
        $types = null;
        $tmps  = null;
        $errs  = null;
        $sizes = null;

        if (isset($filesEntry['name'][$subIndex])) {
            $names = $filesEntry['name'][$subIndex];
            $types = $filesEntry['type'][$subIndex] ?? [];
            $tmps  = $filesEntry['tmp_name'][$subIndex] ?? [];
            $errs  = $filesEntry['error'][$subIndex] ?? [];
            $sizes = $filesEntry['size'][$subIndex] ?? [];
        } elseif (is_numeric($subIndex)) {
            $nameVals = array_values($filesEntry['name']);
            $pos = (int)$subIndex;
            if (isset($nameVals[$pos])) {
                $names = $nameVals[$pos];
                $typeVals = isset($filesEntry['type']) ? array_values($filesEntry['type']) : [];
                $tmpVals  = isset($filesEntry['tmp_name']) ? array_values($filesEntry['tmp_name']) : [];
                $errVals  = isset($filesEntry['error']) ? array_values($filesEntry['error']) : [];
                $sizeVals = isset($filesEntry['size']) ? array_values($filesEntry['size']) : [];
                $types = $typeVals[$pos] ?? [];
                $tmps  = $tmpVals[$pos] ?? [];
                $errs  = $errVals[$pos] ?? [];
                $sizes = $sizeVals[$pos] ?? [];
            }
        }

        if ($names === null) {
            return [];
        }

        if (is_array($names)) {
            foreach ($names as $k => $name) {
                $err = $errs[$k] ?? UPLOAD_ERR_NO_FILE;
                $tmp = $tmps[$k] ?? '';
                if ($err === UPLOAD_ERR_OK && !empty($name) && !empty($tmp)) {
                    $results[] = [
                        'name'     => $name,
                        'type'     => $types[$k] ?? 'application/octet-stream',
                        'tmp_name' => $tmp,
                        'error'    => $err,
                        'size'     => (int)($sizes[$k] ?? 0),
                    ];
                }
            }
        } elseif (($errs ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && !empty($names) && !empty($tmps)) {
            $results[] = [
                'name'     => $names,
                'type'     => is_string($types) ? $types : 'application/octet-stream',
                'tmp_name' => is_string($tmps) ? $tmps : '',
                'error'    => is_int($errs) ? $errs : UPLOAD_ERR_OK,
                'size'     => is_numeric($sizes) ? (int)$sizes : 0,
            ];
        }
        return $results;
    }

    if (is_array($filesEntry['name'])) {
        foreach ($filesEntry['name'] as $k => $name) {
            $err = $filesEntry['error'][$k] ?? UPLOAD_ERR_NO_FILE;
            $tmp = $filesEntry['tmp_name'][$k] ?? '';
            if ($err === UPLOAD_ERR_OK && !empty($name) && !empty($tmp)) {
                $results[] = [
                    'name'     => $name,
                    'type'     => $filesEntry['type'][$k] ?? 'application/octet-stream',
                    'tmp_name' => $tmp,
                    'error'    => $err,
                    'size'     => (int)($filesEntry['size'][$k] ?? 0),
                ];
            }
        }
    } elseif (($filesEntry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && !empty($filesEntry['name']) && !empty($filesEntry['tmp_name'])) {
        $results[] = [
            'name'     => $filesEntry['name'],
            'type'     => $filesEntry['type'] ?? 'application/octet-stream',
            'tmp_name' => $filesEntry['tmp_name'],
            'error'    => (int)$filesEntry['error'],
            'size'     => (int)$filesEntry['size'],
        ];
    }

    return $results;
}

/**
 * Save one uploaded file to disk and record it in `attachments`.
 *
 * @return array{id:int,filename:string,stored_name:string,mime_type:string,file_size:int}|null
 */
function saveUploadedAttachment(
    PDO $pdo,
    int $ticketId,
    ?int $replyId,
    int $userId,
    array $file
): ?array {
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $maxSize = defined('UPLOAD_MAX_SIZE') ? UPLOAD_MAX_SIZE : (10 * 1024 * 1024);
    if ($file['size'] > $maxSize || $file['size'] <= 0) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
        'pdf',
        'doc', 'docx', 'rtf',
        'xls', 'xlsx', 'csv',
        'ppt', 'pptx',
        'txt', 'zip', 'rar', '7z', 'tar', 'gz',
    ];
    if (!in_array($ext, $allowedExts, true)) {
        return null;
    }

    $mime = $file['type'] ?? 'application/octet-stream';
    if ($mime === 'application/octet-stream' || $mime === '') {
        $mimeMap = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'bmp'  => 'image/bmp',
            'svg'  => 'image/svg+xml',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv'  => 'text/csv',
            'txt'  => 'text/plain',
            'zip'  => 'application/zip',
            'rar'  => 'application/x-rar-compressed',
            '7z'   => 'application/x-7z-compressed',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    }

    if (defined('UPLOAD_ALLOWED') && is_array(UPLOAD_ALLOWED) && !in_array($mime, UPLOAD_ALLOWED, true)) {
        return null;
    }

    $uploadDir = rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads/'), '/\\') . DIRECTORY_SEPARATOR;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $stored)) {
        return null;
    }

    $pdo->prepare(
        "INSERT INTO attachments (ticket_id, reply_id, user_id, filename, stored_name, mime_type, file_size)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $ticketId,
        $replyId,
        $userId,
        $file['name'],
        $stored,
        $mime,
        $file['size'],
    ]);

    return [
        'id'          => (int)$pdo->lastInsertId(),
        'filename'    => $file['name'],
        'stored_name' => $stored,
        'mime_type'   => $mime,
        'file_size'   => (int)$file['size'],
    ];
}

/**
 * Save multiple uploaded files for a ticket or reply.
 *
 * @param array<int, array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
 * @return array<int, array{id:int,filename:string,stored_name:string,mime_type:string,file_size:int}>
 */
function saveUploadedAttachments(
    PDO $pdo,
    int $ticketId,
    ?int $replyId,
    int $userId,
    array $files
): array {
    $saved = [];
    foreach ($files as $file) {
        $att = saveUploadedAttachment($pdo, $ticketId, $replyId, $userId, $file);
        if ($att) {
            $saved[] = $att;
        }
    }
    return $saved;
}

function attachmentPublicUrl(string $storedName): string
{
    return APP_URL . '/uploads/' . ltrim($storedName, '/');
}

/**
 * Thumbnail that opens the ticket image preview modal.
 */
function attachmentImagePreviewHtml(string $storedName, string $filename): string
{
    $fileUrl = htmlspecialchars(attachmentPublicUrl($storedName), ENT_QUOTES, 'UTF-8');
    $filenameEsc = htmlspecialchars($filename, ENT_QUOTES, 'UTF-8');

    return '<a href="#" role="button" class="reply-attachment-img-wrap ticket-image-preview"'
        . ' data-image-src="' . $fileUrl . '" data-image-title="' . $filenameEsc . '"'
        . ' aria-label="View ' . $filenameEsc . '">'
        . '<img src="' . $fileUrl . '" alt="' . $filenameEsc . '" class="reply-attachment-img" loading="lazy">'
        . '</a>';
}

/**
 * Render attachment thumbnails/links for ticket threads.
 */
function renderAttachmentListHtml(array $attachments): string
{
    if (empty($attachments)) {
        return '';
    }

    $html = '<div class="reply-attachments">';
    foreach ($attachments as $att) {
        $isImage = strpos($att['mime_type'], 'image/') === 0;
        $fileUrl = htmlspecialchars(attachmentPublicUrl($att['stored_name']), ENT_QUOTES, 'UTF-8');
        $filename = htmlspecialchars($att['filename'], ENT_QUOTES, 'UTF-8');
        $ext = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION));
        $iconClass = match ($ext) {
            'pdf' => 'bi-file-earmark-pdf text-danger',
            'doc', 'docx' => 'bi-file-earmark-word text-primary',
            'xls', 'xlsx' => 'bi-file-earmark-excel text-success',
            'jpg', 'jpeg', 'png', 'gif', 'webp' => 'bi-file-earmark-image text-info',
            default => 'bi-file-earmark text-muted',
        };

        if ($isImage) {
            $html .= attachmentImagePreviewHtml($att['stored_name'], $att['filename']);
        } else {
            $sizeKb = round(((int)$att['file_size']) / 1024, 1);
            $html .= '<a href="' . $fileUrl . '" target="_blank" class="reply-attachment-file">'
                . '<i class="bi ' . $iconClass . '"></i>'
                . '<span>' . $filename . '</span>'
                . '<small class="text-muted">' . $sizeKb . 'KB</small>'
                . '</a>';
        }
    }
    $html .= '</div>';

    return $html;
}
