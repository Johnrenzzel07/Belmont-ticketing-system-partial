<?php
/**
 * All Notifications Page
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$pdo  = db();
$user = currentUser();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// Mark all read if requested via form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
    markNotificationsRead($user['id']);
    header('Location: ' . APP_URL . '/views/notifications.php');
    exit;
}

// Auto-mark all as read when the page is visited (before counting unread)
markNotificationsRead($user['id']);

// Count total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$countStmt->execute([$user['id']]);
$totalCount = (int)$countStmt->fetchColumn();

// unread is always 0 now since we just marked them all
$unreadCount = 0;

$pagination = paginate($totalCount, $perPage, $page);

// Fetch notifications (all, now all marked read)
$stmt = $pdo->prepare(
    "SELECT n.*, t.ticket_code,
     TIMESTAMPDIFF(MINUTE, n.created_at, NOW()) AS minutes_ago
     FROM notifications n
     LEFT JOIN tickets t ON t.id = n.ticket_id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([$user['id'], $perPage, $offset]);
$notifications = $stmt->fetchAll();

$iconMap = [
    'assigned'       => 'bi-person-check',
    'updated'        => 'bi-pencil-square',
    'replied'        => 'bi-chat-dots',
    'status_changed' => 'bi-arrow-repeat',
    'new_ticket'     => 'bi-ticket',
];

$pageTitle = 'Notifications';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Notifications</li>
            </ol>
        </nav>
        <h1 class="page-title">Notifications
            <?php if ($unreadCount > 0): ?>
            <span class="badge bg-danger ms-2" style="font-size:.55em;vertical-align:middle"><?= $unreadCount ?> unread</span>
            <?php endif; ?>
        </h1>
    </div>
    <?php if ($unreadCount > 0): ?>
    <form method="POST">
        <input type="hidden" name="action" value="mark_read">
        <button type="submit" class="btn btn-outline-secondary btn-sm">Mark all as read</button>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <?php if (empty($notifications)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
        <p>No notifications yet.</p>
    </div>
    <?php else: ?>
    <div class="notif-full-list">
        <?php foreach ($notifications as $n):
            $mins = (int)$n['minutes_ago'];
            if ($mins < 1)         $ago = 'Just now';
            elseif ($mins < 60)    $ago = $mins . ' min ago';
            elseif ($mins < 1440)  $ago = floor($mins / 60) . ' hr ago';
            elseif ($mins < 10080) $ago = floor($mins / 1440) . ' days ago';
            else                   $ago = date('M d, Y', strtotime($n['created_at']));
            $icon = $iconMap[$n['type']] ?? 'bi-bell';
        ?>
        <a href="<?= $n['ticket_id'] ? APP_URL . '/views/tickets/view.php?id=' . $n['ticket_id'] : '#' ?>"
           class="notif-full-item <?= $n['is_read'] ? 'is-read' : 'is-unread' ?>">
            <div class="notif-full-icon <?= $n['is_read'] ? '' : 'unread' ?>">
                <i class="bi <?= $icon ?>"></i>
            </div>
            <div class="notif-full-body">
                <div class="notif-full-msg"><?= e($n['message']) ?></div>
                <?php if ($n['ticket_code']): ?>
                <div class="notif-full-ticket"><?= e($n['ticket_code']) ?></div>
                <?php endif; ?>
            </div>
            <div class="notif-full-time"><?= $ago ?></div>
            <?php if (!$n['is_read']): ?>
            <span class="notif-unread-dot"></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="d-flex justify-content-center align-items-center gap-2 py-3">
        <ul class="pagination mb-0 pagination-sm">
            <li class="page-item <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current'] - 1])) ?>">Previous</a>
            </li>
            <?php
                $start = max(1, $pagination['current'] - 2);
                $end   = min($pagination['total_pages'], $start + 4);
                for ($p = $start; $p <= $end; $p++):
            ?>
            <li class="page-item <?= $p === $pagination['current'] ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $pagination['current'] + 1])) ?>">Next</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
