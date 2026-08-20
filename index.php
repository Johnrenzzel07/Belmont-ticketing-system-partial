<?php
/**
 * Dashboard (index.php)
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$pageTitle = 'Dashboard';
$user = currentUser();
$pdo = db();

// Department Admins only see their own department's data
$scopeDept = deptScopeId(); // int for dept_admin, null for everyone else
$deptCond  = $scopeDept !== null ? " AND department_id = {$scopeDept} "   : '';
$deptCondT = $scopeDept !== null ? " AND t.department_id = {$scopeDept} " : '';
$dashWhere = !isStaff()
    ? ' WHERE user_id = ' . (int) $user['id']
    : ($scopeDept !== null ? " WHERE department_id = {$scopeDept}" : '');

// ---- Ticket Statistics ----
$statsRaw = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'open')        AS open,
        SUM(status = 'in_progress') AS in_progress,
        SUM(status = 'pending')     AS pending,
        SUM(status = 'resolved')    AS resolved,
        SUM(status = 'closed')      AS closed,
        SUM(priority = 'critical')  AS critical,
        SUM(priority = 'high')      AS high
     FROM tickets
     " . $dashWhere
)->fetch();

$stats = [
    'total' => (int) ($statsRaw['total'] ?? 0),
    'open' => (int) ($statsRaw['open'] ?? 0),
    'in_progress' => (int) ($statsRaw['in_progress'] ?? 0),
    'pending' => (int) ($statsRaw['pending'] ?? 0),
    'resolved' => (int) ($statsRaw['resolved'] ?? 0),
    'closed' => (int) ($statsRaw['closed'] ?? 0),
    'critical' => (int) ($statsRaw['critical'] ?? 0),
    'high' => (int) ($statsRaw['high'] ?? 0),
];

// ---- Recent Tickets ----
$recentSql = 'SELECT t.*, u.name AS requester_name, a.name AS assignee_name,
                      d.name AS dept_name, c.name AS cat_name
               FROM tickets t
               LEFT JOIN users u ON u.id = t.user_id
               LEFT JOIN users a ON a.id = t.assigned_to
               LEFT JOIN departments d ON d.id = t.department_id
               LEFT JOIN categories c ON c.id = t.category_id
               ' . (!isStaff()
                    ? ' WHERE t.user_id = ' . (int) $user['id']
                    : ($scopeDept !== null ? " WHERE t.department_id = {$scopeDept}" : '')) . '
               ORDER BY t.created_at DESC
               LIMIT 10';
$recentTickets = $pdo->query($recentSql)->fetchAll();

// ---- Chart: Tickets per day (last 14 days) ----
$chartSql = "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
             FROM tickets
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) {$deptCond}
             GROUP BY day
             ORDER BY day ASC";
$chartData = $pdo->query($chartSql)->fetchAll();
$chartLabels = [];
$chartCounts = [];
foreach ($chartData as $row) {
    $chartLabels[] = date('M d', strtotime($row['day']));
    $chartCounts[] = (int) $row['cnt'];
}

// ---- Chart: Tickets by Status ----
$statusData = $pdo->query("SELECT status, COUNT(*) AS cnt FROM tickets WHERE 1=1 {$deptCond} GROUP BY status")->fetchAll();
$statusLabels = [];
$statusCounts = [];
foreach ($statusData as $row) {
    $statusLabels[] = ucwords(str_replace('_', ' ', $row['status']));
    $statusCounts[] = (int) $row['cnt'];
}

// ---- Chart: Tickets by Priority ----
$priorityData = $pdo->query("SELECT priority, COUNT(*) AS cnt FROM tickets WHERE 1=1 {$deptCond} GROUP BY priority ORDER BY FIELD(priority,'critical','high','medium','low')")->fetchAll();
$priorityLabels = [];
$priorityCounts = [];
foreach ($priorityData as $row) {
    $priorityLabels[] = ucfirst($row['priority']);
    $priorityCounts[] = (int) $row['cnt'];
}

// ---- Staff Workload (Admin/Staff only) ----
$staffStats = [];
if (isStaff()) {
    $staffStats = $pdo->query(
        "SELECT u.name, u.avatar, COUNT(t.id) AS total,
                SUM(t.status NOT IN ('resolved','closed')) AS open_count
         FROM users u
         LEFT JOIN tickets t ON t.assigned_to = u.id
         WHERE u.role IN ('staff','admin','dept_admin','user') AND u.is_active = 1"
         . ($scopeDept !== null ? " AND u.department_id = {$scopeDept}" : '') . "
         GROUP BY u.id
         HAVING total > 0
         ORDER BY total DESC LIMIT 6"
    )->fetchAll();
}

// ---- Advanced Analytics (Staff only) ----
$weekTrends  = null;
$heatmap     = [];
$deptPerf    = [];
$leaderboard = [];
if (isStaff()) {
    // This week vs last week trends
    $weekTrends = $pdo->query(
        "SELECT
            SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS tickets_this,
            SUM(created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS tickets_last,
            SUM(resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS resolved_this,
            SUM(resolved_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                AND resolved_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS resolved_last,
            SUM(resolved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND sla_breached = 0) AS sla_ok_this,
            SUM(resolved_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                AND resolved_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND sla_breached = 0) AS sla_ok_last
         FROM tickets WHERE 1=1 {$deptCond}"
    )->fetch();

    // Heatmap: ticket volume per day, last ~3 months
    $heatRows = $pdo->query(
        "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
         FROM tickets
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 89 DAY) {$deptCond}
         GROUP BY day"
    )->fetchAll();
    foreach ($heatRows as $hr) {
        $heatmap[$hr['day']] = (int)$hr['cnt'];
    }

    // Department performance
    $deptPerf = $pdo->query(
        "SELECT d.name,
                COUNT(t.id) AS total,
                SUM(t.status NOT IN ('resolved','closed')) AS open_count,
                ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL
                          THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.resolved_at) / 60 END), 1) AS avg_res_hours,
                ROUND(SUM(CASE WHEN t.resolved_at IS NOT NULL AND t.sla_breached = 0 THEN 1 ELSE 0 END)
                      / NULLIF(SUM(t.resolved_at IS NOT NULL), 0) * 100) AS sla_pct,
                (SELECT COUNT(*) FROM users u
                 WHERE u.department_id = d.id AND u.role IN ('staff','admin') AND u.is_active = 1) AS staff_count
         FROM departments d
         LEFT JOIN tickets t ON t.department_id = d.id
         WHERE d.is_active = 1"
         . ($scopeDept !== null ? " AND d.id = {$scopeDept}" : '') . "
         GROUP BY d.id
         HAVING total > 0
         ORDER BY total DESC"
    )->fetchAll();

    // Staff leaderboard
    $leaderboard = $pdo->query(
        "SELECT u.name, u.avatar,
                SUM(t.status IN ('resolved','closed')) AS resolved_count,
                ROUND(AVG(CASE WHEN t.first_reply_at IS NOT NULL
                          THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.first_reply_at) END)) AS avg_first_reply_mins,
                ROUND(AVG(t.csat_rating), 1) AS avg_csat
         FROM users u
         LEFT JOIN tickets t ON t.assigned_to = u.id
         WHERE u.role IN ('staff','admin','dept_admin','user') AND u.is_active = 1"
         . ($scopeDept !== null ? " AND u.department_id = {$scopeDept}" : '') . "
         GROUP BY u.id
         HAVING resolved_count > 0
         ORDER BY resolved_count DESC
         LIMIT 5"
    )->fetchAll();
}

/**
 * Render a trend chip (this week vs last week).
 */
function trendChip(int $current, int $previous, bool $upIsGood = true): string
{
    if ($previous == 0 && $current == 0) {
        return '<span class="text-muted" style="font-size:.7rem">&mdash; no change</span>';
    }
    $diff = $current - $previous;
    $pct  = $previous > 0 ? round(abs($diff) / $previous * 100) : 100;
    if ($diff == 0) {
        return '<span class="text-muted" style="font-size:.7rem">&mdash; same as last week</span>';
    }
    $up    = $diff > 0;
    $good  = $up === $upIsGood;
    $color = $good ? '#10b981' : '#ef4444';
    $arrow = $up ? '&#8593;' : '&#8595;';
    return '<span style="font-size:.72rem;font-weight:700;color:' . $color . '">'
         . $arrow . ' ' . $pct . '%</span> <span class="text-muted" style="font-size:.7rem">vs last week</span>';
}

// ---- CSAT Summary (Staff only) ----
$csatSummary    = null;
$csatRecent     = [];
if (isStaff()) {
    $csatSummary = $pdo->query(
        "SELECT COUNT(*) AS total_rated,
                ROUND(AVG(csat_rating), 1) AS avg_rating,
                SUM(csat_rating >= 4) AS positive
         FROM tickets WHERE csat_rating IS NOT NULL"
    )->fetch();

    $csatRecent = $pdo->query(
        "SELECT t.id, t.ticket_code, t.csat_rating, t.csat_comment,
                u.name AS requester_name
         FROM tickets t
         LEFT JOIN users u ON u.id = t.user_id
         WHERE t.csat_rating IS NOT NULL
         ORDER BY t.resolved_at DESC LIMIT 5"
    )->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">
            <?php if ($user['role'] === 'user'): ?>
                My Tickets Dashboard
            <?php else: ?>
                Dashboard
            <?php endif; ?>
        </h1>
        <p class="page-subtitle">
            Welcome back, <strong><?= e($user['name']) ?></strong> &mdash;
            <?= date('l, F j, Y') ?>
        </p>
    </div>
    <a href="<?= APP_URL ?>/views/tickets/create.php" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> New Ticket
    </a>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <a href="<?= APP_URL ?>/views/tickets/index.php" class="stat-card text-decoration-none">
        <div class="stat-card-top">
            <span class="stat-label">Total Tickets</span>
            <div class="stat-icon icon-primary"><i class="bi bi-ticket-detailed"></i></div>
        </div>
        <div class="stat-value"><?= $stats['total'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/views/tickets/index.php?status=open" class="stat-card text-decoration-none">
        <div class="stat-card-top">
            <span class="stat-label">Open</span>
            <div class="stat-icon icon-info"><i class="bi bi-folder2-open"></i></div>
        </div>
        <div class="stat-value"><?= $stats['open'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/views/tickets/index.php?status=in_progress" class="stat-card text-decoration-none">
        <div class="stat-card-top">
            <span class="stat-label">In Progress</span>
            <div class="stat-icon icon-warning"><i class="bi bi-arrow-repeat"></i></div>
        </div>
        <div class="stat-value"><?= $stats['in_progress'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/views/tickets/index.php?status=pending" class="stat-card text-decoration-none">
        <div class="stat-card-top">
            <span class="stat-label">Pending</span>
            <div class="stat-icon icon-secondary"><i class="bi bi-clock"></i></div>
        </div>
        <div class="stat-value"><?= $stats['pending'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/views/tickets/index.php?status=resolved" class="stat-card text-decoration-none">
        <div class="stat-card-top">
            <span class="stat-label">Resolved</span>
            <div class="stat-icon icon-success"><i class="bi bi-check-circle"></i></div>
        </div>
        <div class="stat-value"><?= $stats['resolved'] ?></div>
    </a>
    <a href="<?= APP_URL ?>/views/tickets/index.php?priority=critical" class="stat-card text-decoration-none">
        <div class="stat-card-top">
            <span class="stat-label">Critical</span>
            <div class="stat-icon icon-danger"><i class="bi bi-exclamation-octagon"></i></div>
        </div>
        <div class="stat-value"><?= $stats['critical'] ?></div>
    </a>
</div>

<!-- Charts Row — all 3 in one line -->
<div class="row g-3 mb-3">
    <!-- Ticket Activity: spans 6 cols on large screens -->
    <div class="col-lg-6">
        <div class="chart-card card h-100">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-graph-up me-2 text-primary"></i>Ticket Activity (Last 14 Days)</span>
            </div>
            <div class="card-body">
                <canvas id="activityChart" style="width:100%;height:220px"></canvas>
            </div>
        </div>
    </div>
    <!-- Tickets by Status: 3 cols -->
    <div class="col-lg-3 col-md-6">
        <div class="chart-card card h-100">
            <div class="card-header">
                <i class="bi bi-pie-chart me-2 text-primary"></i>Tickets by Status
            </div>
            <div class="card-body">
                <canvas id="statusChart" style="width:100%;height:220px"></canvas>
            </div>
        </div>
    </div>
    <!-- Tickets by Priority: 3 cols -->
    <div class="col-lg-3 col-md-6">
        <div class="chart-card card h-100">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2 text-primary"></i>Tickets by Priority
            </div>
            <div class="card-body">
                <canvas id="priorityChart" style="width:100%;height:220px"></canvas>
            </div>
        </div>
    </div>
</div>

<?php if (isStaff() && $weekTrends): ?>
    <!-- Weekly Trend Indicators -->
    <div class="row g-3 mb-3">
        <?php
        $tThis = (int)($weekTrends['tickets_this'] ?? 0);
        $tLast = (int)($weekTrends['tickets_last'] ?? 0);
        $rThis = (int)($weekTrends['resolved_this'] ?? 0);
        $rLast = (int)($weekTrends['resolved_last'] ?? 0);
        $slaThisPct = $rThis > 0 ? (int)round(($weekTrends['sla_ok_this'] ?? 0) / $rThis * 100) : 0;
        $slaLastPct = $rLast > 0 ? (int)round(($weekTrends['sla_ok_last'] ?? 0) / $rLast * 100) : 0;
        ?>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="text-muted" style="font-size:.72rem;font-weight:600;text-transform:uppercase">New Tickets (7d)</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <span style="font-size:1.3rem;font-weight:700"><?= $tThis ?></span>
                        <?= trendChip($tThis, $tLast, false) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="text-muted" style="font-size:.72rem;font-weight:600;text-transform:uppercase">Resolved (7d)</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <span style="font-size:1.3rem;font-weight:700"><?= $rThis ?></span>
                        <?= trendChip($rThis, $rLast, true) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body py-2">
                    <div class="text-muted" style="font-size:.72rem;font-weight:600;text-transform:uppercase">SLA Compliance (7d)</div>
                    <div class="d-flex align-items-baseline gap-2">
                        <span style="font-size:1.3rem;font-weight:700"><?= $slaThisPct ?>%</span>
                        <?= trendChip($slaThisPct, $slaLastPct, true) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Live Feed + Volume Heatmap -->
    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-broadcast me-2 text-danger"></i>Live Ticket Feed</span>
                    <small class="text-muted" style="font-size:.68rem">
                        <span class="status-dot me-1"></span>auto-refresh 30s
                    </small>
                </div>
                <div class="card-body p-0" id="liveFeed" style="max-height:300px;overflow-y:auto">
                    <div class="text-center text-muted py-4" style="font-size:.8rem">
                        <span class="spinner-border spinner-border-sm me-1"></span>Loading feed...
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header">
                    <i class="bi bi-calendar3 me-2 text-primary"></i>Ticket Volume — Last 3 Months
                </div>
                <div class="card-body">
                    <div style="display:flex;flex-wrap:wrap;gap:3px" title="Daily ticket volume">
                        <?php
                        $maxHeat = max(1, $heatmap ? max($heatmap) : 1);
                        for ($d = 89; $d >= 0; $d--):
                            $day   = date('Y-m-d', strtotime("-{$d} days"));
                            $cnt   = $heatmap[$day] ?? 0;
                            $alpha = $cnt > 0 ? 0.25 + 0.75 * ($cnt / $maxHeat) : 0;
                            $bg    = $cnt > 0 ? "rgba(79,70,229,{$alpha})" : 'var(--border-light, #f1f5f9)';
                        ?>
                        <div title="<?= date('M d, Y', strtotime($day)) ?>: <?= $cnt ?> ticket<?= $cnt != 1 ? 's' : '' ?>"
                             style="width:14px;height:14px;border-radius:3px;background:<?= $bg ?>"></div>
                        <?php endfor; ?>
                    </div>
                    <div class="d-flex justify-content-between mt-2 text-muted" style="font-size:.68rem">
                        <span><?= date('M d', strtotime('-89 days')) ?></span>
                        <span class="d-flex align-items-center gap-1">
                            Less
                            <span style="width:10px;height:10px;border-radius:2px;background:var(--border-light,#f1f5f9)"></span>
                            <span style="width:10px;height:10px;border-radius:2px;background:rgba(79,70,229,.35)"></span>
                            <span style="width:10px;height:10px;border-radius:2px;background:rgba(79,70,229,.65)"></span>
                            <span style="width:10px;height:10px;border-radius:2px;background:rgba(79,70,229,1)"></span>
                            More
                        </span>
                        <span>Today</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Department Performance + Staff Leaderboard -->
    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-building me-2 text-primary"></i>Department Performance</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0" style="font-size:.8rem">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th class="text-center">Open</th>
                                    <th class="text-center">Avg. Resolution</th>
                                    <th class="text-center">SLA %</th>
                                    <th class="text-center">Staff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deptPerf as $dp): ?>
                                <tr>
                                    <td class="fw-500"><?= e($dp['name']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?= (int)$dp['open_count'] ?></span>
                                    </td>
                                    <td class="text-center"><?= $dp['avg_res_hours'] !== null ? $dp['avg_res_hours'] . 'h' : '—' ?></td>
                                    <td class="text-center">
                                        <?php if ($dp['sla_pct'] !== null): ?>
                                        <span style="font-weight:700;color:<?= $dp['sla_pct'] >= 80 ? '#10b981' : ($dp['sla_pct'] >= 50 ? '#f59e0b' : '#ef4444') ?>">
                                            <?= (int)$dp['sla_pct'] ?>%
                                        </span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td class="text-center"><?= (int)$dp['staff_count'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><i class="bi bi-trophy me-2 text-warning"></i>Staff Leaderboard</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($leaderboard as $i => $lb): ?>
                        <li class="list-group-item d-flex align-items-center gap-2 py-2" style="font-size:.8rem">
                            <span style="font-weight:800;width:20px;color:<?= $i === 0 ? '#f59e0b' : 'var(--text-muted)' ?>">
                                <?= $i + 1 ?>
                            </span>
                            <?= renderAvatar($lb['avatar'] ?? null, $lb['name']) ?>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-600 text-truncate"><?= e($lb['name']) ?></div>
                                <div class="text-muted" style="font-size:.68rem">
                                    <?= (int)$lb['resolved_count'] ?> resolved
                                    <?php if ($lb['avg_first_reply_mins'] !== null): ?>
                                        &bull; <?= (int)$lb['avg_first_reply_mins'] < 60
                                            ? (int)$lb['avg_first_reply_mins'] . 'm'
                                            : round($lb['avg_first_reply_mins'] / 60, 1) . 'h' ?> avg response
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($lb['avg_csat']): ?>
                            <span class="badge bg-warning text-dark" style="font-size:.68rem">
                                <i class="bi bi-star-fill"></i> <?= $lb['avg_csat'] ?>
                            </span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Staff Workload — full width below charts -->
<?php if (isStaff() && !empty($staffStats)): ?>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-people me-2 text-primary"></i>Staff Workload
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Staff Member</th>
                                    <th class="text-center">Total Assigned</th>
                                    <th class="text-center">Open</th>
                                    <th>Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staffStats as $s): ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?= renderAvatar($s['avatar'] ?? null, $s['name']) ?>
                                                <span class="fw-500"><?= e($s['name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="text-center fw-600"><?= $s['total'] ?></td>
                                        <td class="text-center">
                                            <span class="badge bg-info"><?= $s['open_count'] ?></span>
                                        </td>
                                        <td style="min-width:160px">
                                            <?php
                                            $pct = $s['total'] > 0
                                                ? round((($s['total'] - $s['open_count']) / $s['total']) * 100)
                                                : 0;
                                            ?>
                                            <div class="progress" style="height:6px;border-radius:3px">
                                                <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
                                            </div>
                                            <small class="text-muted"><?= $pct ?>% resolved</small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (isStaff() && $csatSummary && (int)$csatSummary['total_rated'] > 0): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-star-half me-2 text-warning"></i>Customer Satisfaction (CSAT)</span>
        <a href="<?= APP_URL ?>/views/admin/csat.php" class="btn btn-sm btn-outline-primary">
            View All <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="card-body">
        <div class="d-flex align-items-center gap-4 mb-3 flex-wrap">
            <div class="text-center">
                <div style="font-size:2rem;font-weight:700;color:var(--text-primary);line-height:1">
                    <?= $csatSummary['avg_rating'] ?? '—' ?>
                </div>
                <div class="d-flex gap-1 justify-content-center mt-1">
                    <?php $avg = round((float)($csatSummary['avg_rating'] ?? 0)); ?>
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star<?= $i <= $avg ? '-fill' : '' ?>" style="color:#f59e0b;font-size:.85rem"></i>
                    <?php endfor; ?>
                </div>
                <div class="text-muted" style="font-size:.72rem;margin-top:.25rem">out of 5</div>
            </div>
            <div style="width:1px;height:50px;background:var(--border-light)"></div>
            <div class="text-center">
                <div style="font-size:1.4rem;font-weight:700;color:var(--text-primary)"><?= number_format($csatSummary['total_rated']) ?></div>
                <div class="text-muted" style="font-size:.72rem">Total Ratings</div>
            </div>
            <div style="width:1px;height:50px;background:var(--border-light)"></div>
            <div class="text-center">
                <?php $posPct = $csatSummary['total_rated'] > 0 ? round($csatSummary['positive'] / $csatSummary['total_rated'] * 100) : 0; ?>
                <div style="font-size:1.4rem;font-weight:700;color:#10b981"><?= $posPct ?>%</div>
                <div class="text-muted" style="font-size:.72rem">Positive (4-5 stars)</div>
            </div>
        </div>

        <?php if (!empty($csatRecent)): ?>
        <div style="border-top:1px solid var(--border-light);padding-top:.75rem">
            <div class="text-muted mb-2" style="font-size:.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Recent Ratings</div>
            <?php foreach ($csatRecent as $r): ?>
            <div class="d-flex align-items-start gap-3 py-2" style="border-bottom:1px solid var(--border-light)">
                <div>
                    <div class="d-flex gap-1">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="bi bi-star<?= $i <= $r['csat_rating'] ? '-fill' : '' ?>" style="color:<?= $i <= $r['csat_rating'] ? '#f59e0b' : '#d1d5db' ?>;font-size:.8rem"></i>
                        <?php endfor; ?>
                    </div>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div style="font-size:.8rem">
                        <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $r['id'] ?>" class="fw-600 text-decoration-none"><?= e($r['ticket_code']) ?></a>
                        <span class="text-muted ms-1">by <?= e($r['requester_name'] ?? 'Unknown') ?></span>
                    </div>
                    <?php if ($r['csat_comment']): ?>
                    <div style="font-size:.78rem;color:var(--text-secondary);font-style:italic;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:400px">
                        "<?= e($r['csat_comment']) ?>"
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent Tickets Table -->
<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2 text-primary"></i>Recent Tickets</span>
        <a href="<?= APP_URL ?>/views/tickets/index.php" class="btn btn-sm btn-outline-primary">
            View All <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Ticket</th>
                    <th>Subject</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <?php if (isStaff()): ?>
                        <th>Requester</th>
                        <th>Assigned To</th><?php endif; ?>
                    <th>Department</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentTickets)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">No tickets found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentTickets as $t): ?>
                        <tr class="clickable-row" data-href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $t['id'] ?>">
                            <td>
                                <code class="text-primary" style="font-size:.75rem"><?= e($t['ticket_code']) ?></code>
                            </td>
                            <td class="ticket-subject">
                                <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $t['id'] ?>">
                                    <?= e(mb_strimwidth($t['subject'], 0, 55, '...')) ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                $pc = [
                                    'low' => 'priority-low',
                                    'medium' => 'priority-medium',
                                    'high' => 'priority-high',
                                    'critical' => 'priority-critical'
                                ];
                                $cls = $pc[$t['priority']] ?? 'priority-medium';
                                ?>
                                <span class="priority-badge <?= $cls ?>"><?= ucfirst($t['priority']) ?></span>
                            </td>
                            <td>
                                <?php
                                $sc = [
                                    'open' => 'status-open',
                                    'in_progress' => 'status-in_progress',
                                    'pending' => 'status-pending',
                                    'resolved' => 'status-resolved',
                                    'closed' => 'status-closed'
                                ];
                                $scls = $sc[$t['status']] ?? 'status-open';
                                ?>
                                <span
                                    class="status-badge <?= $scls ?>"><?= ucwords(str_replace('_', ' ', $t['status'])) ?></span>
                            </td>
                            <?php if (isStaff()): ?>
                                <td><?= e($t['requester_name'] ?? 'N/A') ?></td>
                                <td><?= e($t['assignee_name'] ?? 'Unassigned') ?></td>
                            <?php endif; ?>
                            <td><?= e($t['dept_name'] ?? 'N/A') ?></td>
                            <td><span title="<?= e($t['created_at']) ?>"><?= timeAgo($t['created_at']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$chartLabelsJson = json_encode($chartLabels);
$chartCountsJson = json_encode($chartCounts);
$statusLabelsJson = json_encode($statusLabels);
$statusCountsJson = json_encode($statusCounts);
$priorityLabelsJson = json_encode($priorityLabels);
$priorityCountsJson = json_encode($priorityCounts);

$extraScripts = <<<JS
<script>
// Activity Line Chart
new Chart(document.getElementById('activityChart'), {
    type: 'line',
    data: {
        labels: {$chartLabelsJson},
        datasets: [{
            label: 'Tickets Created',
            data:  {$chartCountsJson},
            borderColor: '#4f46e5',
            backgroundColor: 'rgba(79,70,229,.08)',
            borderWidth: 2.5,
            pointBackgroundColor: '#4f46e5',
            pointRadius: 4,
            tension: .4,
            fill: true,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false } }
        }
    }
});

// Status Doughnut Chart
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: {$statusLabelsJson},
        datasets: [{
            data: {$statusCountsJson},
            backgroundColor: ['#06b6d4','#4f46e5','#f59e0b','#10b981','#64748b'],
            borderWidth: 0,
            hoverOffset: 6,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: { legend: { position: 'bottom' } }
    }
});

// Priority Bar Chart
new Chart(document.getElementById('priorityChart'), {
    type: 'bar',
    data: {
        labels: {$priorityLabelsJson},
        datasets: [{
            label: 'Tickets',
            data: {$priorityCountsJson},
            backgroundColor: ['#ef4444','#f59e0b','#4f46e5','#64748b'],
            borderRadius: 6,
            borderSkipped: false,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
JS;

// ---- Live ticket feed (staff only, refreshes every 30s) ----
if (isStaff()) {
    $extraScripts .= <<<'JS'
<script>
function loadLiveFeed() {
    $.get(APP_URL + '/api/tickets.php', { action: 'live_feed' }, function(r) {
        if (!r.success || !r.feed) return;
        if (!r.feed.length) {
            $('#liveFeed').html('<div class="text-center text-muted py-4" style="font-size:.8rem">No recent activity.</div>');
            return;
        }
        const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const prioColors = { critical:'#ef4444', high:'#f59e0b', medium:'#4f46e5', low:'#64748b' };
        let html = '';
        r.feed.forEach(function(t) {
            html += `<a href="${APP_URL}/views/tickets/view.php?id=${t.id}"
                        class="d-flex align-items-center gap-2 text-decoration-none px-3 py-2"
                        style="border-bottom:1px solid var(--border-light,#f1f5f9);font-size:.78rem">
                <span style="width:8px;height:8px;border-radius:50%;flex-shrink:0;
                      background:${prioColors[t.priority] || '#64748b'}"></span>
                <div class="flex-grow-1 min-w-0">
                    <div class="text-truncate" style="color:var(--text-primary,#0f172a);font-weight:600">
                        ${t.is_new ? '<span class="badge bg-danger me-1" style="font-size:.55rem">NEW</span>' : ''}
                        ${esc(t.subject)}
                    </div>
                    <div class="text-muted" style="font-size:.68rem">
                        <code>${esc(t.code)}</code> &bull; ${esc(t.requester)} &bull; ${esc(t.status.replace('_',' '))}
                    </div>
                </div>
                <small class="text-muted text-nowrap" style="font-size:.66rem">${esc(t.time_ago)}</small>
            </a>`;
        });
        $('#liveFeed').html(html);
    }, 'json');
}
loadLiveFeed();
setInterval(loadLiveFeed, 30000);

// Run background automations once per dashboard load
$.get(APP_URL + '/api/sla_escalation.php');
$.get(APP_URL + '/api/auto_close.php');
</script>
JS;
}

include __DIR__ . '/includes/footer.php';
