<?php
/**
 * Ticket List
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Tickets';
$pdo       = db();
$user      = currentUser();

// ---- Filters ----
$status   = $_GET['status']   ?? '';
$priority = $_GET['priority'] ?? '';
$dept     = $_GET['dept']     ?? '';
$q        = trim($_GET['q']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;

// Default to active statuses only; 'all' shows every status
$activeStatuses = ['open', 'in_progress', 'pending'];

// ---- Build Query ----
$where  = ['1=1'];
$params = [];

if (!isStaff()) {
    $where[]  = 't.user_id = ?';
    $params[] = $user['id'];
} elseif (deptScopeId() !== null) {
    // Department Admins only see their own department's tickets
    $where[]  = 't.department_id = ?';
    $params[] = deptScopeId();
}
if ($status === 'all') {
    // no status filter — show everything
} elseif ($status !== '') {
    $where[]  = 't.status = ?';
    $params[] = $status;
} else {
    // default: only show active (open, in_progress, pending)
    $placeholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    $where[]  = "t.status IN ($placeholders)";
    $params   = array_merge($params, $activeStatuses);
}
if ($priority) { $where[] = 't.priority = ?';    $params[] = $priority; }
if ($dept)     { $where[] = 't.department_id = ?'; $params[] = $dept; }
if ($q) {
    $where[]  = '(t.subject LIKE ? OR t.ticket_code LIKE ? OR u.name LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}

$whereStr = implode(' AND ', $where);

// Count
$countStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     WHERE $whereStr"
);
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

$pagination = paginate($totalCount, $perPage, $page);

// Data
$dataStmt = $pdo->prepare(
    "SELECT t.*, u.name AS requester_name, u.avatar AS requester_avatar,
            a.name AS assignee_name, a.avatar AS assignee_avatar,
            d.name AS dept_name, c.name AS cat_name,
            (SELECT COUNT(*) FROM ticket_replies r WHERE r.ticket_id = t.id AND r.is_internal = 0) AS reply_count,
            (
                SELECT 1
                FROM ticket_reads tr
                WHERE tr.user_id = {$user['id']} AND tr.ticket_id = t.id
                  AND (
                    (SELECT MAX(r2.created_at) FROM ticket_replies r2
                     WHERE r2.ticket_id = t.id AND r2.is_internal = 0
                     AND r2.user_id != {$user['id']}) > tr.last_read_at
                    OR tr.last_read_at IS NULL
                  )
            ) AS has_new_reply,
            IF((SELECT tr2.user_id FROM ticket_reads tr2
                WHERE tr2.user_id = {$user['id']} AND tr2.ticket_id = t.id LIMIT 1) IS NULL, 1, 0) AS never_read
     FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     LEFT JOIN users a ON a.id = t.assigned_to
     LEFT JOIN departments d ON d.id = t.department_id
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE $whereStr
     ORDER BY
       FIELD(t.priority,'critical','high','medium','low'),
       t.created_at DESC
     LIMIT ? OFFSET ?"
);
$dataStmt->execute(array_merge($params, [$perPage, $pagination['offset']]));
$tickets = $dataStmt->fetchAll();

// Depts for filter
$depts = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">
            <?= isStaff() ? 'All Tickets' : 'My Tickets' ?>
        </h1>
        <p class="page-subtitle">
            <?= number_format($totalCount) ?> ticket<?= $totalCount != 1 ? 's' : '' ?> found
            <?php if ($status === ''): ?>
                <span class="text-muted" style="font-size:.75rem">&mdash; showing active only</span>
            <?php endif; ?>
        </p>
    </div>
    <a href="<?= APP_URL ?>/views/tickets/create.php" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> New Ticket
    </a>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-lg-3 col-md-6">
                <div class="table-search position-relative">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search tickets..." value="<?= e($q) ?>">
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
                    <option value="<?= $p ?>" <?= $priority===$p ? 'selected' : '' ?>>
                        <?= ucfirst($p) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (isStaff()): ?>
            <div class="col-lg-2 col-md-4">
                <select name="dept" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($depts as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $dept == $d['id'] ? 'selected' : '' ?>>
                        <?= e($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/views/tickets/index.php" class="btn btn-outline-secondary ms-1">Clear</a>
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
                    <?php if (isStaff()): ?>
                    <th>Requester</th>
                    <th>Assigned To</th>
                    <?php endif; ?>
                    <th>Department</th>
                    <th>Replies</th>
                    <th>Created</th>
                    <th style="width:100px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($tickets)): ?>
                <tr>
                    <td colspan="10" class="py-5">
                        <div class="empty-state">
                            <div class="empty-state-icon"><i class="bi bi-ticket-detailed"></i></div>
                            <h5>No Tickets Found</h5>
                            <p>Try adjusting your filters or create a new ticket.</p>
                            <a href="<?= APP_URL ?>/views/tickets/create.php" class="btn btn-primary mt-2">
                                <i class="bi bi-plus-lg me-1"></i>New Ticket
                            </a>
                        </div>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tickets as $t): ?>
                <?php
                    $priorityClasses = [
                        'low'=>'priority-low','medium'=>'priority-medium',
                        'high'=>'priority-high','critical'=>'priority-critical'
                    ];
                    $statusClasses = [
                        'open'=>'status-open','in_progress'=>'status-in_progress',
                        'pending'=>'status-pending','resolved'=>'status-resolved','closed'=>'status-closed'
                    ];
                    $pCls    = $priorityClasses[$t['priority']] ?? 'priority-medium';
                    $sCls    = $statusClasses[$t['status']]    ?? 'status-open';
                    $unread  = $t['never_read'] || $t['has_new_reply'];
                ?>
                <tr class="clickable-row ticket-row <?= $unread ? 'ticket-unread' : '' ?>"
                    data-href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $t['id'] ?>">
                    <td>
                        <code class="fw-600" style="font-size:.75rem;color:<?= $unread ? 'var(--primary)' : 'var(--text-muted)' ?>">
                            <?= e($t['ticket_code']) ?>
                        </code>
                        <?php if ($unread): ?>
                        <span class="unread-dot" title="Unread"></span>
                        <?php endif; ?>
                    </td>
                    <td class="ticket-subject">
                        <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $t['id'] ?>"
                           style="<?= $unread ? 'font-weight:700;color:var(--text-primary)' : '' ?>">
                            <?= e(mb_strimwidth($t['subject'], 0, 60, '...')) ?>
                        </a>
                        <?php if ($t['cat_name']): ?>
                        <br><small class="text-muted"><?= e($t['cat_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="priority-badge <?= $pCls ?>"><?= ucfirst($t['priority']) ?></span></td>
                    <td>
                        <?php if (isStaff()): ?>
                        <select class="form-select form-select-sm ajax-status-select"
                                data-ticket-id="<?= $t['id'] ?>"
                                style="min-width:120px;font-size:.75rem">
                            <?php foreach(['open','in_progress','pending','resolved','closed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $t['status']===$s?'selected':'' ?>>
                                <?= ucwords(str_replace('_',' ',$s)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php else: ?>
                        <span class="status-badge <?= $sCls ?>"><?= ucwords(str_replace('_',' ',$t['status'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <?php if (isStaff()): ?>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <?= renderAvatar($t['requester_avatar'] ?? null, $t['requester_name'] ?? '?') ?>
                            <small><?= e($t['requester_name'] ?? 'N/A') ?></small>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1">
                            <?php if ($t['assignee_name']): ?>
                            <?= renderAvatar($t['assignee_avatar'] ?? null, $t['assignee_name'], 'avatar-sm', 'background:var(--success-subtle);color:var(--success)') ?>
                            <small><?= e($t['assignee_name']) ?></small>
                            <?php else: ?>
                            <small class="text-muted">Unassigned</small>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                    <td><small><?= e($t['dept_name'] ?? 'N/A') ?></small></td>
                    <td>
                        <?php if ($t['reply_count'] > 0): ?>
                        <span class="badge bg-secondary rounded-pill"><?= $t['reply_count'] ?></span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span title="<?= e($t['created_at']) ?>" class="text-muted">
                            <?= timeAgo($t['created_at']) ?>
                        </span>
                    </td>
                    <td>
                        <div class="d-flex gap-1" onclick="event.stopPropagation()">
                            <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $t['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if (isStaff() || $t['user_id'] == $user['id']): ?>
                            <a href="<?= APP_URL ?>/views/tickets/edit.php?id=<?= $t['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
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
