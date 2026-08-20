<?php
/**
 * Notifications API
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['count' => 0, 'notifications' => []]);
    exit;
}

$pdo    = db();
$user   = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? 'get';

header('Content-Type: application/json');

if ($action === 'mark_read') {
    markNotificationsRead($user['id']);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'mark_one') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
            ->execute([$id, $user['id']]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// Unread count for the badge
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unreadStmt->execute([$user['id']]);
$unreadCount = (int)$unreadStmt->fetchColumn();

// Latest 5 notifications (read or unread) for the dropdown list
$stmt = $pdo->prepare(
    "SELECT n.*, t.ticket_code,
     TIMESTAMPDIFF(MINUTE, n.created_at, NOW()) AS minutes_ago
     FROM notifications n
     LEFT JOIN tickets t ON t.id = n.ticket_id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC LIMIT 5"
);
$stmt->execute([$user['id']]);
$notifs = $stmt->fetchAll();

foreach ($notifs as &$n) {
    $mins = (int)$n['minutes_ago'];
    if ($mins < 1)         $n['created_at_ago'] = 'Just now';
    elseif ($mins < 60)    $n['created_at_ago'] = $mins . ' min ago';
    elseif ($mins < 1440)  $n['created_at_ago'] = floor($mins / 60) . ' hr ago';
    else                   $n['created_at_ago'] = floor($mins / 1440) . ' days ago';
}
unset($n);

echo json_encode([
    'count'         => $unreadCount,
    'notifications' => $notifs,
]);
