<?php
/**
 * Reports Page
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin', 'staff', 'dept_admin');

$pdo  = db();
$user = currentUser();

// Department Admins only see their own department's data
$scopeDept = deptScopeId();
$deptCond  = $scopeDept !== null ? " AND department_id = {$scopeDept} "   : '';
$deptCondT = $scopeDept !== null ? " AND t.department_id = {$scopeDept} " : '';

// Date range
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$export   = $_GET['export']    ?? '';

// ---- Summary Stats ----
$summary = $pdo->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(status='open') AS open,
        SUM(status='in_progress') AS in_progress,
        SUM(status='pending') AS pending,
        SUM(status='resolved') AS resolved,
        SUM(status='closed') AS closed,
        SUM(priority='critical') AS critical,
        SUM(priority='high') AS high,
        AVG(TIMESTAMPDIFF(HOUR, created_at,
            CASE WHEN closed_at IS NOT NULL THEN closed_at ELSE NOW() END
        )) AS avg_resolution_hours
     FROM tickets
     WHERE DATE(created_at) BETWEEN ? AND ? {$deptCond}"
);
$summary->execute([$dateFrom, $dateTo]);
$summary = $summary->fetch();

// ---- Extra metrics ----
$extraMetrics = $pdo->prepare(
    "SELECT
        AVG(TIMESTAMPDIFF(MINUTE, created_at, first_reply_at)) AS avg_first_reply_mins,
        AVG(csat_rating)              AS avg_csat,
        SUM(sla_breached = 1)         AS sla_breached_count,
        SUM(status IN ('resolved','closed')) AS resolved_total
     FROM tickets
     WHERE DATE(created_at) BETWEEN ? AND ? {$deptCond}"
);
$extraMetrics->execute([$dateFrom, $dateTo]);
$extraMetrics = $extraMetrics->fetch();


// ---- Daily Trend ----
$daily = $pdo->prepare(
    "SELECT DATE(created_at) AS day,
            COUNT(*) AS total,
            SUM(status IN ('resolved','closed')) AS resolved_count
     FROM tickets
     WHERE DATE(created_at) BETWEEN ? AND ? {$deptCond}
     GROUP BY day ORDER BY day ASC"
);
$daily->execute([$dateFrom, $dateTo]);
$daily = $daily->fetchAll();

// ---- By Department ----
$byDept = $pdo->prepare(
    "SELECT d.name AS dept, COUNT(t.id) AS total,
            SUM(t.status IN ('resolved','closed')) AS resolved
     FROM tickets t
     LEFT JOIN departments d ON d.id = t.department_id
     WHERE DATE(t.created_at) BETWEEN ? AND ? {$deptCondT}
     GROUP BY t.department_id ORDER BY total DESC"
);
$byDept->execute([$dateFrom, $dateTo]);
$byDept = $byDept->fetchAll();

// ---- Staff Performance ----
$staffPerf = $pdo->prepare(
    "SELECT u.name, u.avatar,
            COUNT(t.id) AS assigned,
            SUM(t.status IN ('resolved','closed')) AS resolved,
            AVG(CASE WHEN t.resolved_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, t.created_at, t.resolved_at) END) AS avg_hours,
            AVG(CASE WHEN t.first_reply_at IS NOT NULL
                THEN TIMESTAMPDIFF(MINUTE, t.created_at, t.first_reply_at) END) AS avg_first_reply_mins,
            AVG(t.csat_rating) AS avg_csat
     FROM users u
     LEFT JOIN tickets t ON t.assigned_to = u.id AND DATE(t.created_at) BETWEEN ? AND ?
     WHERE u.is_active = 1
       AND (u.role IN ('staff','admin')
            OR EXISTS (SELECT 1 FROM tickets t2 WHERE t2.assigned_to = u.id))"
     . ($scopeDept !== null ? " AND u.department_id = {$scopeDept}" : '') . "
     GROUP BY u.id
     ORDER BY resolved DESC, avg_hours ASC"
);
$staffPerf->execute([$dateFrom, $dateTo]);
$staffPerf = $staffPerf->fetchAll();


// ---- Recent Tickets in Range ----
$tickets = $pdo->prepare(
    "SELECT t.*, u.name AS requester_name, a.name AS assignee_name, d.name AS dept_name
     FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     LEFT JOIN users a ON a.id = t.assigned_to
     LEFT JOIN departments d ON d.id = t.department_id
     WHERE DATE(t.created_at) BETWEEN ? AND ? {$deptCondT}
     ORDER BY t.created_at DESC LIMIT 100"
);
$tickets->execute([$dateFrom, $dateTo]);
$tickets = $tickets->fetchAll();

// ---- Aging Tickets (open / in_progress / pending) ----
$agingTickets = $pdo->query(
    "SELECT t.id, t.ticket_code, t.subject, t.priority, t.status,
            u.name AS requester_name, a.name AS assignee_name,
            DATEDIFF(NOW(), t.created_at) AS age_days
     FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     LEFT JOIN users a ON a.id = t.assigned_to
     WHERE t.status NOT IN ('resolved','closed') {$deptCondT}
     ORDER BY age_days DESC
     LIMIT 200"
)->fetchAll();

// Bucket counts
$agingBuckets = ['fresh' => 0, 'aging' => 0, 'old' => 0, 'overdue' => 0];
foreach ($agingTickets as $at) {
    $d = (int)$at['age_days'];
    if ($d < 7)       $agingBuckets['fresh']++;
    elseif ($d < 14)  $agingBuckets['aging']++;
    elseif ($d < 30)  $agingBuckets['old']++;
    else              $agingBuckets['overdue']++;
}

// ---- CSV Export ----
if ($export === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="belmont_report_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ticket#','Subject','Priority','Status','Requester','Assigned To',
                   'Department','Created At','Resolved At','Resolution (hrs)','First Reply (mins)','CSAT']);
    foreach ($tickets as $t) {
        $resHrs  = ($t['resolved_at'] && $t['created_at'])
            ? round((strtotime($t['resolved_at']) - strtotime($t['created_at'])) / 3600, 1) : '';
        $repMins = ($t['first_reply_at'] && $t['created_at'])
            ? round((strtotime($t['first_reply_at']) - strtotime($t['created_at'])) / 60) : '';
        fputcsv($out, [
            $t['ticket_code'], $t['subject'], $t['priority'], $t['status'],
            $t['requester_name'], $t['assignee_name'] ?? 'Unassigned',
            $t['dept_name'] ?? 'N/A', $t['created_at'],
            $t['resolved_at'] ?? '', $resHrs, $repMins, $t['csat_rating'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Chart data
$chartDays      = array_column($daily, 'day');
$chartTotal     = array_column($daily, 'total');
$chartResolved  = array_column($daily, 'resolved_count');
$chartDays      = array_map(fn($d) => date('M d', strtotime($d)), $chartDays);

// Staff chart data (avg resolution hours per person)
$staffChartNames = array_column($staffPerf, 'name');
$staffChartHrs   = array_map(fn($s) => $s['avg_hours'] ? round($s['avg_hours'], 1) : 0, $staffPerf);

$deptNames  = array_map(fn($r) => ($r['dept'] ?? 'N/A'), $byDept);
$deptCounts = array_column($byDept, 'total');

$pageTitle = 'Reports';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Reports &amp; Analytics</h1>
        <p class="page-subtitle"><?= COMPANY ?> Helpdesk Performance Report</p>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="?date_from=<?= e($dateFrom) ?>&date_to=<?= e($dateTo) ?>&export=csv"
           class="btn btn-outline-success">
            <i class="bi bi-download me-1"></i>Export CSV
        </a>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Print / PDF
        </button>
    </div>
</div>

<!-- Print-only header (hidden on screen) -->
<div class="print-header">
    <strong><?= e(APP_NAME) ?></strong> — <?= e(COMPANY) ?><br>
    <span>Performance Report: <?= date('M d, Y', strtotime($dateFrom)) ?> to <?= date('M d, Y', strtotime($dateTo)) ?></span><br>
    <small>Generated: <?= date('M d, Y H:i') ?></small>
</div>

<!-- Date Range Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-1">From</label>
                <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-1">To</label>
                <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>">
            </div>
            <div class="col-auto">
                <button class="btn btn-primary" type="submit"><i class="bi bi-bar-chart-line me-1"></i>Generate</button>
                <!-- Quick presets -->
                <a href="?date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary ms-1">This Month</a>
                <a href="?date_from=<?= date('Y-m-d', strtotime('-7 days')) ?>&date_to=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary ms-1">Last 7 Days</a>
            </div>
        </form>
    </div>
</div>

<!-- Summary Stats -->
<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Total Tickets</span>
            <div class="stat-icon icon-primary"><i class="bi bi-ticket-detailed"></i></div>
        </div>
        <div class="stat-value"><?= (int)$summary['total'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Resolved / Closed</span>
            <div class="stat-icon icon-success"><i class="bi bi-check-circle"></i></div>
        </div>
        <div class="stat-value"><?= (int)$summary['resolved'] + (int)$summary['closed'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Still Open</span>
            <div class="stat-icon icon-info"><i class="bi bi-folder2-open"></i></div>
        </div>
        <div class="stat-value"><?= (int)$summary['open'] ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Avg Resolution Time</span>
            <div class="stat-icon icon-warning"><i class="bi bi-clock-history"></i></div>
        </div>
        <div class="stat-value"><?= $summary['avg_resolution_hours'] ? round($summary['avg_resolution_hours'],1) . 'h' : 'N/A' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Avg First Response</span>
            <div class="stat-icon icon-primary"><i class="bi bi-reply"></i></div>
        </div>
        <?php
            $frMins = $extraMetrics['avg_first_reply_mins'];
            $frFmt  = $frMins ? ($frMins < 60 ? round($frMins).'m' : round($frMins/60,1).'h') : 'N/A';
        ?>
        <div class="stat-value"><?= $frFmt ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">SLA Breach Rate</span>
            <div class="stat-icon icon-danger"><i class="bi bi-shield-exclamation"></i></div>
        </div>
        <?php
            $breachRate = ($extraMetrics['resolved_total'] > 0)
                ? round(($extraMetrics['sla_breached_count'] / $extraMetrics['resolved_total']) * 100, 1)
                : 0;
        ?>
        <div class="stat-value"><?= $breachRate ?>%</div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Avg CSAT Score</span>
            <div class="stat-icon icon-success"><i class="bi bi-star-half"></i></div>
        </div>
        <div class="stat-value"><?= $extraMetrics['avg_csat'] ? round($extraMetrics['avg_csat'],1) . '/5' : 'N/A' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-top">
            <span class="stat-label">Critical</span>
            <div class="stat-icon icon-danger"><i class="bi bi-exclamation-octagon"></i></div>
        </div>
        <div class="stat-value"><?= (int)$summary['critical'] ?></div>
    </div>
</div>


<!-- Charts Row -->
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="chart-card card h-100">
            <div class="card-header"><i class="bi bi-graph-up me-2 text-primary"></i>Daily Ticket Volume</div>
            <div class="card-body">
                <canvas id="dailyChart" style="width:100%;height:260px"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="chart-card card h-100">
            <div class="card-header"><i class="bi bi-building me-2 text-primary"></i>Tickets by Department</div>
            <div class="card-body">
                <canvas id="deptChart" style="width:100%;height:260px"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Staff Leaderboard -->
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-trophy me-2 text-warning"></i>Staff Leaderboard
            <span class="text-muted" style="font-size:.78rem;font-weight:400">
                (<?= date('M d', strtotime($dateFrom)) ?> – <?= date('M d, Y', strtotime($dateTo)) ?>)
            </span>
        </span>
        <small class="text-muted">Ranked by tickets resolved</small>
    </div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th style="width:40px">Rank</th>
                    <th>Staff Member</th>
                    <th class="text-center">Assigned</th>
                    <th class="text-center">Resolved</th>
                    <th class="text-center">Rate</th>
                    <th class="text-center">Avg Resolution</th>
                    <th class="text-center">Avg 1st Reply</th>
                    <th class="text-center">CSAT</th>
                    <th style="min-width:120px">Performance</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($staffPerf)): ?>
                <tr><td colspan="9" class="text-center text-muted py-3">No data for selected period.</td></tr>
            <?php else: ?>
            <?php foreach ($staffPerf as $rank => $s): ?>
            <?php
                $rate    = $s['assigned'] > 0 ? round(($s['resolved']/$s['assigned'])*100) : 0;
                $barCls  = $rate >= 80 ? 'bg-success' : ($rate >= 50 ? 'bg-warning' : 'bg-danger');
                $rankNum = $rank + 1;
                $rankBadge = match($rankNum) {
                    1 => '<span class="leaderboard-rank rank-gold">1</span>',
                    2 => '<span class="leaderboard-rank rank-silver">2</span>',
                    3 => '<span class="leaderboard-rank rank-bronze">3</span>',
                    default => "<span class=\"leaderboard-rank\">{$rankNum}</span>",
                };
                $frMins = $s['avg_first_reply_mins'];
                $frFmt  = $frMins ? ($frMins < 60 ? round($frMins).'m' : round($frMins/60,1).'h') : '—';
                $avgHrFmt = $s['avg_hours'] ? round($s['avg_hours'],1) . 'h' : '—';
                $csatFmt  = $s['avg_csat']  ? round($s['avg_csat'],1) . '/5' : '—';
                $rowCls   = $rankNum === 1 ? 'style="background:#fffbeb"' : '';
            ?>
            <tr <?= $rowCls ?>>
                <td><?= $rankBadge ?></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <?= renderAvatar($s['avatar'] ?? null, $s['name'], 'avatar-sm') ?>
                        <span style="font-weight:<?= $rankNum===1?'700':'500' ?>"><?= e($s['name']) ?></span>
                        <?php if ($rankNum === 1 && $s['resolved'] > 0): ?>
                            <span class="badge" style="background:#f59e0b;font-size:.6rem">Top Performer</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="text-center"><?= $s['assigned'] ?></td>
                <td class="text-center"><span class="badge bg-success"><?= $s['resolved'] ?></span></td>
                <td class="text-center fw-600"><?= $rate ?>%</td>
                <td class="text-center text-muted"><?= $avgHrFmt ?></td>
                <td class="text-center text-muted"><?= $frFmt ?></td>
                <td class="text-center">
                    <?php if ($s['avg_csat']): ?>
                    <span style="color:#f59e0b;font-weight:600"><?= $csatFmt ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td>
                    <div class="progress" style="height:7px;border-radius:4px">
                        <div class="progress-bar <?= $barCls ?>" style="width:<?= $rate ?>%"></div>
                    </div>
                    <small class="text-muted" style="font-size:.68rem"><?= $rate ?>% resolved</small>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Avg Resolution Time by Staff (bar chart) -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-bar-chart-steps me-2 text-primary"></i>Avg Resolution Time by Staff</div>
    <div class="card-body">
        <canvas id="staffChart" style="width:100%;height:220px"></canvas>
    </div>
</div>
<!-- Ticket Aging Report -->
<div class="card mb-3">
    <div class="card-header"><i class="bi bi-hourglass-split me-2 text-warning"></i>Ticket Aging — Currently Open</div>
    <div class="card-body">
        <!-- Aging Bucket Stats -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="aging-bucket bucket-fresh">
                    <div class="bucket-count"><?= $agingBuckets['fresh'] ?></div>
                    <div class="bucket-label">Fresh (0–6 days)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="aging-bucket bucket-aging">
                    <div class="bucket-count"><?= $agingBuckets['aging'] ?></div>
                    <div class="bucket-label">Aging (7–13 days)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="aging-bucket bucket-old">
                    <div class="bucket-count"><?= $agingBuckets['old'] ?></div>
                    <div class="bucket-label">Old (14–29 days)</div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="aging-bucket bucket-overdue">
                    <div class="bucket-count"><?= $agingBuckets['overdue'] ?></div>
                    <div class="bucket-label">Overdue (30+ days)</div>
                </div>
            </div>
        </div>
        <!-- Aging Table -->
        <?php if (empty($agingTickets)): ?>
            <p class="text-muted text-center py-2">No open tickets.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table mb-0" style="font-size:.83rem">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Subject</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Requester</th>
                        <th>Assigned To</th>
                        <th class="text-center">Age</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($agingTickets as $at):
                    $d = (int)$at['age_days'];
                    if ($d < 7)      { $ageCls = 'aging-fresh';   $ageLabel = $d . 'd'; }
                    elseif ($d < 14) { $ageCls = 'aging-warn';    $ageLabel = $d . 'd'; }
                    elseif ($d < 30) { $ageCls = 'aging-old';     $ageLabel = $d . 'd'; }
                    else             { $ageCls = 'aging-overdue'; $ageLabel = $d . 'd'; }
                    $priColors = ['low'=>'secondary','medium'=>'primary','high'=>'warning','critical'=>'danger'];
                ?>
                <tr>
                    <td>
                        <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $at['id'] ?>"
                           style="font-weight:600;font-size:.8rem">
                            <?= e($at['ticket_code']) ?>
                        </a>
                    </td>
                    <td style="max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <?= e(mb_strimwidth($at['subject'],0,50,'...')) ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $priColors[$at['priority']] ?? 'secondary' ?>">
                            <?= ucfirst($at['priority']) ?>
                        </span>
                    </td>
                    <td><?= ucwords(str_replace('_',' ',$at['status'])) ?></td>
                    <td><?= e($at['requester_name'] ?? 'N/A') ?></td>
                    <td><?= e($at['assignee_name'] ?? 'Unassigned') ?></td>
                    <td class="text-center">
                        <span class="age-badge <?= $ageCls ?>"><?= $ageLabel ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Ticket List -->
<div class="card table-card">
    <div class="card-header d-flex justify-content-between">
        <span><i class="bi bi-list-ul me-2 text-primary"></i>Ticket Details</span>
        <small class="text-muted"><?= count($tickets) ?> tickets shown</small>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:.8rem">
            <thead>
                <tr>
                    <th>Ticket #</th><th>Subject</th><th>Priority</th>
                    <th>Status</th><th>Requester</th><th>Assigned</th>
                    <th>Department</th><th>Created</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $t): ?>
            <tr>
                <td><code class="text-primary"><?= e($t['ticket_code']) ?></code></td>
                <td><?= e(mb_strimwidth($t['subject'],0,45,'...')) ?></td>
                <td><span class="badge bg-<?= ['low'=>'secondary','medium'=>'primary','high'=>'warning','critical'=>'danger'][$t['priority']] ?? 'secondary' ?>"><?= ucfirst($t['priority']) ?></span></td>
                <td><?= ucwords(str_replace('_',' ',$t['status'])) ?></td>
                <td><?= e($t['requester_name']??'N/A') ?></td>
                <td><?= e($t['assignee_name']??'Unassigned') ?></td>
                <td><?= e($t['dept_name']??'N/A') ?></td>
                <td><?= formatDate($t['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$jsChartDays      = json_encode(array_values($chartDays));
$jsChartTotal     = json_encode(array_values($chartTotal));
$jsChartResolved  = json_encode(array_values($chartResolved));
$jsDeptNames      = json_encode(array_values($deptNames));
$jsDeptCounts     = json_encode(array_values($deptCounts));
$jsStaffNames     = json_encode(array_values($staffChartNames));
$jsStaffAvgHrs    = json_encode(array_values($staffChartHrs));

$extraScripts = <<<JS
<script>
new Chart(document.getElementById('dailyChart'), {
    type: 'line',
    data: {
        labels: {$jsChartDays},
        datasets: [
            {
                label: 'Total Created',
                data: {$jsChartTotal},
                borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.08)',
                borderWidth: 2.5, fill: true, tension: .4, pointRadius: 3,
            },
            {
                label: 'Resolved',
                data: {$jsChartResolved},
                borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.06)',
                borderWidth: 2, fill: true, tension: .4, pointRadius: 3,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
            x: { grid: { display: false } }
        }
    }
});

new Chart(document.getElementById('deptChart'), {
    type: 'doughnut',
    data: {
        labels: {$jsDeptNames},
        datasets: [{
            data: {$jsDeptCounts},
            backgroundColor: ['#4f46e5','#06b6d4','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899'],
            borderWidth: 0, hoverOffset: 6,
        }]
    },
    options: { responsive: true, maintainAspectRatio: false, cutout: '65%' }
});

if (document.getElementById('staffChart')) {
    new Chart(document.getElementById('staffChart'), {
        type: 'bar',
        data: {
            labels: {$jsStaffNames},
            datasets: [{
                label: 'Avg Resolution (hrs)',
                data: {$jsStaffAvgHrs},
                backgroundColor: 'rgba(79,70,229,.75)',
                borderRadius: 5,
                borderSkipped: false,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, title: { display: true, text: 'Hours' }, grid: { color: '#f1f5f9' } },
                y: { grid: { display: false } }
            }
        }
    });
}
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
