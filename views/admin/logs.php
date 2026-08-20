<?php
/**
 * Activity Logs (Admin only)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$pdo    = db();
$page   = max(1,(int)($_GET['page'] ?? 1));
$perPage = 50;

$total  = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
$pagInfo = paginate($total, $perPage, $page);

$logs = $pdo->prepare(
    "SELECT l.*, u.name AS actor_name, t.ticket_code
     FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     LEFT JOIN tickets t ON t.id = l.ticket_id
     ORDER BY l.created_at DESC
     LIMIT ? OFFSET ?"
);
$logs->execute([$perPage, $pagInfo['offset']]);
$logs = $logs->fetchAll();

$pageTitle = 'Activity Logs';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Activity Logs</h1>
    <small class="text-muted"><?= number_format($total) ?> total entries</small>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:.8rem">
            <thead><tr><th>Actor</th><th>Action</th><th>Ticket</th><th>Description</th><th>IP</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= e($log['actor_name'] ?? 'System') ?></td>
                <td><code><?= e($log['action']) ?></code></td>
                <td>
                    <?php if ($log['ticket_code']): ?>
                    <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $log['ticket_id'] ?>" class="text-primary">
                        <?= e($log['ticket_code']) ?>
                    </a>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td class="text-muted"><?= e(mb_strimwidth($log['description']??'',0,80,'...')) ?></td>
                <td class="text-muted"><?= e($log['ip_address']??'') ?></td>
                <td><span title="<?= e($log['created_at']) ?>"><?= timeAgo($log['created_at']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pagInfo['total_pages'] > 1): ?>
    <div class="card-footer d-flex justify-content-end">
        <nav><ul class="pagination mb-0 pagination-sm">
            <?php for($p=max(1,$pagInfo['current']-2); $p<=min($pagInfo['total_pages'],$pagInfo['current']+2); $p++): ?>
            <li class="page-item <?= $p===$pagInfo['current']?'active':'' ?>">
                <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
