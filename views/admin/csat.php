<?php
/**
 * CSAT Ratings Page (Staff/Admin)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if (!isStaff()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$pdo  = db();
$user = currentUser();

// Department Admins only see their own department's ratings
$scopeDept = deptScopeId();
$deptCond  = $scopeDept !== null ? " AND department_id = {$scopeDept} "   : '';
$deptCondT = $scopeDept !== null ? " AND t.department_id = {$scopeDept} " : '';

// Summary stats
$summary = $pdo->query(
    "SELECT
        COUNT(*)                        AS total_rated,
        ROUND(AVG(csat_rating), 2)      AS avg_rating,
        SUM(csat_rating = 5)            AS five_star,
        SUM(csat_rating = 4)            AS four_star,
        SUM(csat_rating = 3)            AS three_star,
        SUM(csat_rating = 2)            AS two_star,
        SUM(csat_rating = 1)            AS one_star
     FROM tickets
     WHERE csat_rating IS NOT NULL {$deptCond}"
)->fetch();

// Pagination
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$totalStmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE csat_rating IS NOT NULL {$deptCond}");
$totalCount = (int)$totalStmt->fetchColumn();
$pagination = paginate($totalCount, $perPage, $page);

// Filter by rating
$filterRating = (int)($_GET['rating'] ?? 0);
$whereRating  = $filterRating ? "AND csat_rating = $filterRating" : '';

$stmt = $pdo->prepare(
    "SELECT t.id, t.ticket_code, t.subject, t.csat_rating, t.csat_comment,
            t.resolved_at, t.created_at,
            u.name AS requester_name,
            a.name AS assignee_name
     FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     LEFT JOIN users a ON a.id = t.assigned_to
     WHERE t.csat_rating IS NOT NULL $whereRating {$deptCondT}
     ORDER BY t.resolved_at DESC
     LIMIT ? OFFSET ?"
);
$stmt->execute([$perPage, $offset]);
$ratings = $stmt->fetchAll();

$pageTitle = 'CSAT Ratings';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item active">CSAT Ratings</li>
            </ol>
        </nav>
        <h1 class="page-title">Customer Satisfaction Ratings</h1>
        <p class="page-subtitle">Ratings submitted by requesters after ticket resolution</p>
    </div>
</div>

<?php if ($summary['total_rated'] > 0): ?>
<!-- Summary Cards -->
<div class="row g-3 mb-4">

    <!-- Average Rating -->
    <div class="col-sm-6 col-lg-3">
        <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:1.5rem 1.5rem 1.25rem;position:relative;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(245,158,11,.15)'" onmouseout="this.style.transform='';this.style.boxShadow='0 1px 3px rgba(0,0,0,.06)'">
            <div style="position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#f59e0b,#fbbf24);border-radius:16px 16px 0 0"></div>
            <div style="position:absolute;right:-10px;top:50%;transform:translateY(-50%);font-size:5rem;opacity:.07;color:#f59e0b;pointer-events:none;line-height:1">
                <i class="bi bi-trophy-fill"></i>
            </div>
            <div style="font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#f59e0b;margin-bottom:.5rem">Average Rating</div>
            <div style="font-size:2.4rem;font-weight:800;line-height:1;color:var(--text-primary);letter-spacing:-.03em"><?= number_format($summary['avg_rating'], 1) ?><span style="font-size:1rem;font-weight:500;color:var(--text-muted);margin-left:.25rem">/ 5</span></div>
            <div class="d-flex gap-1 mt-2">
                <?php $avg = round((float)($summary['avg_rating'] ?? 0)); ?>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="bi bi-star<?= $i <= $avg ? '-fill' : '' ?>" style="color:<?= $i <= $avg ? '#f59e0b' : '#e5e7eb' ?>;font-size:.75rem"></i>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- Total Ratings -->
    <div class="col-sm-6 col-lg-3">
        <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:1.5rem 1.5rem 1.25rem;position:relative;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(16,185,129,.15)'" onmouseout="this.style.transform='';this.style.boxShadow='0 1px 3px rgba(0,0,0,.06)'">
            <div style="position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#10b981,#34d399);border-radius:16px 16px 0 0"></div>
            <div style="position:absolute;right:-10px;top:50%;transform:translateY(-50%);font-size:5rem;opacity:.07;color:#10b981;pointer-events:none;line-height:1">
                <i class="bi bi-bar-chart-fill"></i>
            </div>
            <div style="font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#10b981;margin-bottom:.5rem">Total Ratings</div>
            <div style="font-size:2.4rem;font-weight:800;line-height:1;color:var(--text-primary);letter-spacing:-.03em"><?= number_format($summary['total_rated']) ?></div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:.5rem">from resolved tickets</div>
        </div>
    </div>

    <!-- Positive -->
    <div class="col-sm-6 col-lg-3">
        <?php $positive = (int)$summary['five_star'] + (int)$summary['four_star']; ?>
        <?php $posPct = $summary['total_rated'] > 0 ? round($positive / $summary['total_rated'] * 100) : 0; ?>
        <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:1.5rem 1.5rem 1.25rem;position:relative;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(99,102,241,.15)'" onmouseout="this.style.transform='';this.style.boxShadow='0 1px 3px rgba(0,0,0,.06)'">
            <div style="position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#6366f1,#818cf8);border-radius:16px 16px 0 0"></div>
            <div style="position:absolute;right:-10px;top:50%;transform:translateY(-50%);font-size:5rem;opacity:.07;color:#6366f1;pointer-events:none;line-height:1">
                <i class="bi bi-hand-thumbs-up-fill"></i>
            </div>
            <div style="font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#6366f1;margin-bottom:.5rem">Positive (4-5 Stars)</div>
            <div style="font-size:2.4rem;font-weight:800;line-height:1;color:var(--text-primary);letter-spacing:-.03em"><?= $posPct ?><span style="font-size:1rem;font-weight:500;color:var(--text-muted);margin-left:.1rem">%</span></div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:.5rem"><?= $positive ?> out of <?= $summary['total_rated'] ?> reviews</div>
        </div>
    </div>

    <!-- Negative -->
    <div class="col-sm-6 col-lg-3">
        <?php $negative = (int)$summary['one_star'] + (int)$summary['two_star']; ?>
        <?php $negPct = $summary['total_rated'] > 0 ? round($negative / $summary['total_rated'] * 100) : 0; ?>
        <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:16px;padding:1.5rem 1.5rem 1.25rem;position:relative;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:transform .2s,box-shadow .2s" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(239,68,68,.15)'" onmouseout="this.style.transform='';this.style.boxShadow='0 1px 3px rgba(0,0,0,.06)'">
            <div style="position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#ef4444,#f87171);border-radius:16px 16px 0 0"></div>
            <div style="position:absolute;right:-10px;top:50%;transform:translateY(-50%);font-size:5rem;opacity:.07;color:#ef4444;pointer-events:none;line-height:1">
                <i class="bi bi-hand-thumbs-down-fill"></i>
            </div>
            <div style="font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#ef4444;margin-bottom:.5rem">Negative (1-2 Stars)</div>
            <div style="font-size:2.4rem;font-weight:800;line-height:1;color:var(--text-primary);letter-spacing:-.03em"><?= $negPct ?><span style="font-size:1rem;font-weight:500;color:var(--text-muted);margin-left:.1rem">%</span></div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:.5rem"><?= $negative ?> out of <?= $summary['total_rated'] ?> reviews</div>
        </div>
    </div>

</div>

<!-- Rating Distribution -->
<div class="card mb-4">
    <div class="card-header">Rating Distribution</div>
    <div class="card-body">
        <?php
        $bars = [5 => $summary['five_star'], 4 => $summary['four_star'], 3 => $summary['three_star'], 2 => $summary['two_star'], 1 => $summary['one_star']];
        foreach ($bars as $star => $count):
            $pct = $summary['total_rated'] > 0 ? round($count / $summary['total_rated'] * 100) : 0;
            $color = $star >= 4 ? '#10b981' : ($star == 3 ? '#f59e0b' : '#ef4444');
        ?>
        <div class="d-flex align-items-center gap-2 mb-2">
            <a href="?rating=<?= $star ?>" class="text-decoration-none" style="width:50px;font-size:.82rem;color:var(--text-muted);white-space:nowrap">
                <?= $star ?> star<?= $star > 1 ? 's' : '' ?>
            </a>
            <div class="flex-grow-1" style="background:var(--border-light);border-radius:100px;height:10px;overflow:hidden">
                <div style="width:<?= $pct ?>%;height:100%;background:<?= $color ?>;border-radius:100px;transition:width .4s ease"></div>
            </div>
            <span style="width:45px;text-align:right;font-size:.82rem;font-weight:600"><?= $count ?> <span class="text-muted fw-normal">(<?= $pct ?>%)</span></span>
        </div>
        <?php endforeach; ?>
        <?php if ($filterRating): ?>
        <div class="mt-2">
            <a href="?" class="btn btn-sm btn-outline-secondary">Clear filter</a>
            <span class="ms-2 text-muted small">Showing <?= $filterRating ?>-star ratings only</span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Ratings List -->
<div class="card">
    <div class="card-header">
        <?php if ($filterRating): ?>
            <?= $filterRating ?>-Star Ratings
        <?php else: ?>
            All Ratings
        <?php endif; ?>
        <span class="text-muted fw-normal ms-1 small">(<?= number_format($totalCount) ?> total)</span>
    </div>

    <?php if (empty($ratings)): ?>
    <div class="text-center py-5 text-muted">
        <i class="bi bi-star fs-1 d-block mb-2"></i>
        <p>No ratings yet. Ratings appear after requesters review resolved tickets.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr>
                    <th>Ticket</th>
                    <th>Requester</th>
                    <th>Assigned To</th>
                    <th>Rating</th>
                    <th>Comment</th>
                    <th>Resolved</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ratings as $r): ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $r['id'] ?>" class="fw-600 text-decoration-none">
                            <?= e($r['ticket_code']) ?>
                        </a>
                        <div class="text-muted" style="font-size:.75rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <?= e($r['subject']) ?>
                        </div>
                    </td>
                    <td><?= e($r['requester_name'] ?? 'N/A') ?></td>
                    <td><?= e($r['assignee_name'] ?? 'Unassigned') ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="bi bi-star<?= $i <= $r['csat_rating'] ? '-fill' : '' ?>"
                               style="color:<?= $i <= $r['csat_rating'] ? '#f59e0b' : '#d1d5db' ?>;font-size:.9rem"></i>
                            <?php endfor; ?>
                        </div>
                        <div style="font-size:.72rem;color:var(--text-muted)"><?= $r['csat_rating'] ?>/5</div>
                    </td>
                    <td>
                        <?php if ($r['csat_comment']): ?>
                        <span style="font-size:.8rem;color:var(--text-secondary);font-style:italic">
                            "<?= e($r['csat_comment']) ?>"
                        </span>
                        <?php else: ?>
                        <span class="text-muted" style="font-size:.78rem">No comment</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="font-size:.78rem;white-space:nowrap">
                        <?= $r['resolved_at'] ? formatDateTime($r['resolved_at']) : '—' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
