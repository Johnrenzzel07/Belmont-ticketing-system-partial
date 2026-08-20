<?php
/**
 * Delete Ticket (Admin only)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);
if ($id) {
    $ticket = $pdo->prepare("SELECT ticket_code FROM tickets WHERE id=?")->execute([$id])
        ? (function() use ($pdo,$id) { $s=$pdo->prepare("SELECT ticket_code FROM tickets WHERE id=?"); $s->execute([$id]); return $s->fetch(); })()
        : null;

    if ($ticket) {
        $pdo->prepare("DELETE FROM tickets WHERE id=?")->execute([$id]);
        logActivity((int)currentUser()['id'], null, 'ticket_deleted',
            "Deleted ticket {$ticket['ticket_code']}", getClientIp());
        flashMessage('success', "Ticket {$ticket['ticket_code']} deleted.");
    }
}
header('Location: ' . APP_URL . '/views/tickets/index.php');
exit;
