<?php
/**
 * Department Inbox
 * Shared-inbox view: every member of a department sees all tickets
 * routed to their department (mirrors the one-email-per-department setup).
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo  = db();
$user = currentUser();

$deptId = (int)($user['dept_id'] ?? 0);
if (!$deptId) {
    flashMessage('warning', 'You are not assigned to a department. Contact your administrator.');
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

// Department info
$deptStmt = $pdo->prepare("SELECT name, shared_email FROM departments WHERE id = ?");
$deptStmt->execute([$deptId]);
$dept = $deptStmt->fetch();

// ---- Filters ----
$status   = $_GET['status']   ?? '';
$priority = $_GET['priority'] ?? '';
$q        = trim($_GET['q']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;

$where  = ['t.department_id = ?'];
$params = [$deptId];

if ($status === 'all') {
    // show everything
} elseif ($status !== '') {
    $where[]  = 't.status = ?';
    $params[] = $status;
} else {
    $where[]  = "t.status IN ('open','in_progress','pending')";
}
if ($priority) { $where[] = 't.priority = ?'; $params[] = $priority; }
if ($q) {
    $where[]  = '(t.subject LIKE ? OR t.ticket_code LIKE ? OR u.name LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
$whereStr = implode(' AND ', $where);

// Count
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM tickets t LEFT JOIN users u ON u.id = t.user_id WHERE $whereStr"
);
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();
$pagination = paginate($totalCount, $perPage, $page);

// Data
$dataStmt = $pdo->prepare(
    "SELECT t.*, u.name AS requester_name, u.avatar AS requester_avatar,
            a.name AS assignee_name, c.name AS cat_name,
            (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.is_internal = 0) AS reply_count
     FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     LEFT JOIN users a ON a.id = t.assigned_to
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE $whereStr
     ORDER BY
       FIELD(t.priority,'critical','high','medium','low'),
       t.created_at DESC
     LIMIT ? OFFSET ?"
);
$dataStmt->execute(array_merge($params, [$perPage, $pagination['offset']]));
$tickets = $dataStmt->fetchAll();

// Quick stats for this department
$deptStats = $pdo->prepare(
    "SELECT
        SUM(status = 'open')                              AS open_cnt,
        SUM(status = 'in_progress')                       AS prog_cnt,
        SUM(status = 'pending')                           AS pend_cnt,
        SUM(status NOT IN ('resolved','closed') AND assigned_to IS NULL) AS unassigned_cnt
     FROM tickets WHERE department_id = ?"
);
$deptStats->execute([$deptId]);
$deptStats = $deptStats->fetch();

$pageTitle = 'Department Inbox';
include __DIR__ . '/../../includes/header.php';

$priorityClasses = ['low'=>'priority-low','medium'=>'priority-medium','high'=>'priority-high','critical'=>'priority-critical'];
$statusClasses   = ['open'=>'status-open','in_progress'=>'status-in_progress','pending'=>'status-pending','resolved'=>'status-resolved','closed'=>'status-closed'];
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-inbox me-2 text-primary"></i><?= e($dept['name'] ?? 'Department') ?> Inbox</h1>
        <p class="page-subtitle">
            <?= number_format($totalCount) ?> ticket<?= $totalCount != 1 ? 's' : '' ?>
            <?php if ($status === ''): ?><span class="text-muted">&mdash; showing active only</span><?php endif; ?>
            <?php if (!empty($dept['shared_email'])): ?>
                &bull; Shared inbox: <code style="font-size:.78rem"><?= e($dept['shared_email']) ?></code>
            <?php endif; ?>
        </p>
    </div>
    <a href="<?= APP_URL ?>/views/tickets/create.php" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> New Ticket
    </a>
</div>

<!-- Quick stats -->
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center">
            <span class="text-muted" style="font-size:.75rem">Open</span>
            <span class="badge bg-info"><?= (int)($deptStats['open_cnt'] ?? 0) ?></span>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center">
            <span class="text-muted" style="font-size:.75rem">In Progress</span>
            <span class="badge bg-primary"><?= (int)($deptStats['prog_cnt'] ?? 0) ?></span>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center">
            <span class="text-muted" style="font-size:.75rem">Pending</span>
            <span class="badge bg-warning"><?= (int)($deptStats['pend_cnt'] ?? 0) ?></span>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card"><div class="card-body py-2 d-flex justify-content-between align-items-center">
            <span class="text-muted" style="font-size:.75rem">Unassigned</span>
            <span class="badge bg-danger"><?= (int)($deptStats['unassigned_cnt'] ?? 0) ?></span>
        </div></div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-lg-4 col-md-6">
                <div class="table-search position-relative">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search department tickets..." value="<?= e($q) ?>">
                </div>
            </div>
            <div class="col-lg-2 col-md-3">
                <select name="status" class="form-select">
                    <option value="" <?= $status==='' ? 'selected' : '' ?>>Active (Default)</option>
                    <option value="all" <?= $status==='all' ? 'selected' : '' ?>>All Status</option>
                    <?php foreach(['open','in_progress','pending','resolved','closed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status===$s ? 'selected' : '' ?>>
                        <?= ucwords(str_replace('_',' ',$s)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <select name="priority" class="form-select">
                    <option value="">All Priority</option>
                    <?php foreach(['critical','high','medium','low'] as $p): ?>
                    <option value="<?= $p ?>" <?= $priority===$p ? 'selected' : '' ?>><?= ucfirst($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/views/tickets/dept_inbox.php" class="btn btn-outline-secondary ms-1">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Tickets Table -->
<div class="card table-card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th style="width:110px">Ticket #</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Requester</th>
                    <th>Assigned To</th>
                    <th>Replies</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($tickets)): ?>
                <tr>
                    <td colspan="8" class="py-5">
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="bi bi-inbox"></i></div>
                            <h5>No Tickets in Your Department Inbox</h5>
                            <p>Tickets routed to <?= e($dept['name'] ?? 'your department') ?> will appear here.</p>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tickets as $t): ?>
                <tr class="clickable-row" data-href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $t['id'] ?>">
                    <td><code class="text-primary" style="font-size:.75rem"><?= e($t['ticket_code']) ?></code></td>
                    <td class="ticket-subject">
                        <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $t['id'] ?>">
                            <?= e(mb_strimwidth($t['subject'], 0, 55, '...')) ?>
                        </a>
                        <?php if ($t['cat_name']): ?>
                        <br><small class="text-muted"><?= e($t['cat_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="priority-badge <?= $priorityClasses[$t['priority']] ?? 'priority-medium' ?>"><?= ucfirst($t['priority']) ?></span></td>
                    <td><span class="status-badge <?= $statusClasses[$t['status']] ?? 'status-open' ?>"><?= ucwords(str_replace('_',' ',$t['status'])) ?></span></td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <?= renderAvatar($t['requester_avatar'] ?? null, $t['requester_name'] ?? '?') ?>
                            <small><?= e($t['requester_name'] ?? 'N/A') ?></small>
                        </div>
                    </td>
                    <td>
                        <?php if ($t['assignee_name']): ?>
                            <small><?= e($t['assignee_name']) ?></small>
                        <?php else: ?>
                            <span class="badge bg-danger" style="font-size:.62rem">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($t['reply_count'] > 0): ?>
                        <span class="badge bg-secondary rounded-pill"><?= $t['reply_count'] ?></span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><span title="<?= e($t['created_at']) ?>" class="text-muted"><?= timeAgo($t['created_at']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pagination['total_pages'] > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Showing <?= min($pagination['offset']+1, $totalCount) ?>–<?= min($pagination['offset']+$perPage, $totalCount) ?>
            of <?= number_format($totalCount) ?> tickets
        </small>
        <nav>
            <ul class="pagination mb-0 pagination-sm">
                <li class="page-item <?= !$pagination['has_prev'] ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$pagination['current']-1])) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php
                $start = max(1, $pagination['current'] - 2);
                $end   = min($pagination['total_pages'], $start + 4);
                for ($p = $start; $p <= $end; $p++):
                ?>
                <li class="page-item <?= $p === $pagination['current'] ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= !$pagination['has_next'] ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$pagination['current']+1])) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
