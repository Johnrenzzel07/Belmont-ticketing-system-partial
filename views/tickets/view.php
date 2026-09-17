<?php
/**
 * View Single Ticket
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_helpers.php';
requireLogin();

$pdo = db();
$user = currentUser();
$id = (int) ($_GET['id'] ?? 0);

if (!$id) {
    header('Location: ' . APP_URL . '/views/tickets/index.php');
    exit;
}

// Fetch ticket
$stmt = $pdo->prepare(
    "SELECT t.*, u.name AS requester_name, u.email AS requester_email,
            u.avatar AS requester_avatar,
            a.name AS assignee_name,
            d.name AS dept_name, c.name AS cat_name
     FROM tickets t
     LEFT JOIN users u ON u.id = t.user_id
     LEFT JOIN users a ON a.id = t.assigned_to
     LEFT JOIN departments d ON d.id = t.department_id
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.id = ?"
);
$stmt->execute([$id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    header('HTTP/1.0 404 Not Found');
    echo 'Ticket not found.';
    exit;
}

// Access control:
// - Staff/admin can view everything
// - Requesters can view their own tickets
// - Department members can view tickets routed to their department (shared inbox)
$sameDept = !empty($user['dept_id']) && !empty($ticket['department_id'])
    && (int)$user['dept_id'] === (int)$ticket['department_id'];
if (!isStaff() && $ticket['user_id'] != $user['id'] && !$sameDept) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access denied.';
    exit;
}
// Department Admins may only open tickets of their own department (or their own)
if (isDeptAdmin() && $ticket['user_id'] != $user['id'] && !$sameDept) {
    header('HTTP/1.0 403 Forbidden');
    echo 'Access denied.';
    exit;
}

// Replies
$replies = $pdo->prepare(
    "SELECT r.*, u.name AS author_name, u.role AS author_role, u.avatar AS author_avatar
     FROM ticket_replies r
     JOIN users u ON u.id = r.user_id
     WHERE r.ticket_id = ?
     " . (!isStaff() ? ' AND r.is_internal = 0' : '') . "
     ORDER BY r.created_at ASC"
);
$replies->execute([$id]);
$replies = $replies->fetchAll();

// Activity log
$logs = $pdo->prepare(
    "SELECT l.*, u.name AS actor_name
     FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.ticket_id = ?
     ORDER BY l.created_at ASC"
);
$logs->execute([$id]);
$logs = $logs->fetchAll();

// Staff for assign
$staffList = [];
if (isStaff()) {
    $staffList = $pdo->query("SELECT id, name, role FROM users WHERE role IN ('staff','admin') AND is_active=1 ORDER BY name")->fetchAll();
}

// ---- Watchers ----
$watchers   = [];
$isWatching = false;
try {
    $wStmt = $pdo->prepare(
        "SELECT w.user_id, u.name, u.email, u.avatar
         FROM ticket_watchers w
         JOIN users u ON u.id = w.user_id
         WHERE w.ticket_id = ?
         ORDER BY u.name"
    );
    $wStmt->execute([$id]);
    $watchers = $wStmt->fetchAll();
    foreach ($watchers as $w) {
        if ((int)$w['user_id'] === (int)$user['id']) { $isWatching = true; break; }
    }
} catch (Exception $e) { /* table may not exist yet */ }

// Mark this ticket as read for the current user
$pdo->prepare(
    "INSERT INTO ticket_reads (user_id, ticket_id, last_read_at)
     VALUES (?, ?, NOW())
     ON DUPLICATE KEY UPDATE last_read_at = NOW()"
)->execute([$user['id'], $id]);

// Attachments
$attachments = $pdo->prepare("SELECT * FROM attachments WHERE ticket_id = ?");
$attachments->execute([$id]);
$attachments = $attachments->fetchAll();

// Index reply attachments by reply_id for easy lookup
$replyAttachments = [];
$ticketAttachments = []; // original ticket-level attachments (no reply)
foreach ($attachments as $att) {
    if ($att['reply_id']) {
        $replyAttachments[$att['reply_id']][] = $att;
    } else {
        $ticketAttachments[] = $att;
    }
}

$pageTitle = $ticket['ticket_code'] . ' - ' . mb_strimwidth($ticket['subject'], 0, 50, '...');

$priorityClasses = [
    'low' => 'priority-low',
    'medium' => 'priority-medium',
    'high' => 'priority-high',
    'critical' => 'priority-critical'
];
$statusClasses = [
    'open' => 'status-open',
    'in_progress' => 'status-in_progress',
    'pending' => 'status-pending',
    'resolved' => 'status-resolved',
    'closed' => 'status-closed'
];

// ---- SLA / Resolution computed values ----
$slaHours = (int) ($ticket['sla_hours'] ?? 24);
$isResolved = in_array($ticket['status'], ['resolved', 'closed']);
$resolutionHours = null;
$firstReplyMins = null;
if ($ticket['resolved_at'] && $ticket['created_at']) {
    $resolutionHours = (strtotime($ticket['resolved_at']) - strtotime($ticket['created_at'])) / 3600;
}
if ($ticket['first_reply_at'] && $ticket['created_at']) {
    $firstReplyMins = (strtotime($ticket['first_reply_at']) - strtotime($ticket['created_at'])) / 60;
}
$slaBreached  = (bool)($ticket['sla_breached'] ?? false);
$slaRemaining = null;
if (!$isResolved && $ticket['created_at']) {
    if (!empty($ticket['due_date'])) {
        // Due-date based: remaining hours until end of due date
        $dueTs        = strtotime($ticket['due_date'] . ' 23:59:59');
        $slaRemaining = ($dueTs - time()) / 3600;
    } elseif ($slaHours) {
        // Hours-based fallback
        $elapsed      = (time() - strtotime($ticket['created_at'])) / 3600;
        $slaRemaining = $slaHours - $elapsed;
    }
}
$fmtDuration = function (float $totalMins): string {
    $totalMins = round($totalMins);
    if ($totalMins < 60)
        return "{$totalMins}m";
    $h = (int) floor($totalMins / 60);
    $m = (int) ($totalMins % 60);
    return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
};

// Format SLA hours: show "1 day", "2 days" etc. when it's a whole-day multiple
$fmtSla = function (int $hours): string {
    if ($hours >= 24 && $hours % 24 === 0) {
        $days = $hours / 24;
        return $days === 1 ? '1 day' : "{$days} days";
    }
    return "{$hours}h";
};

include __DIR__ . '/../../includes/header.php';
?>

<!-- Print-only header (hidden on screen) -->
<div class="print-header ticket-print-header">
    <strong><?= e(APP_NAME) ?></strong> — <?= e(COMPANY) ?><br>
    <span><?= e($ticket['ticket_code']) ?> — <?= e($ticket['subject']) ?></span><br>
    <small>Printed: <?= date('M d, Y H:i') ?></small>
</div>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-2 no-print">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/tickets/index.php">Tickets</a></li>
        <li class="breadcrumb-item active"><?= e($ticket['ticket_code']) ?></li>
    </ol>
</nav>

<!-- Page Header Row -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h1 class="page-title"><?= e($ticket['subject']) ?></h1>
        <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
            <code class="text-primary copy-code" data-code="<?= e($ticket['ticket_code']) ?>"
                style="cursor:pointer;font-size:.82rem" title="Click to copy">
                <?= e($ticket['ticket_code']) ?>
            </code>
            <?php if (!empty($ticket['is_legacy']) && !empty($ticket['legacy_ticket_number'])): ?>
            <span class="badge bg-warning text-dark" style="font-size:.68rem"
                  title="Imported from old osTicket system">
                Legacy #<?= e(str_pad($ticket['legacy_ticket_number'], 6, '0', STR_PAD_LEFT)) ?>
            </span>
            <?php endif; ?>
            <span class="priority-badge <?= $priorityClasses[$ticket['priority']] ?? '' ?>">
                <?= ucfirst($ticket['priority']) ?>
            </span>
            <span class="status-badge <?= $statusClasses[$ticket['status']] ?? '' ?>" id="statusBadgeMain">
                <?= ucwords(str_replace('_', ' ', $ticket['status'])) ?>
            </span>
            <?php if ($slaBreached): ?>
                <span class="sla-badge sla-breached">SLA Breached</span>
            <?php elseif ($isResolved && !$slaBreached): ?>
                <span class="sla-badge sla-met">SLA Met</span>
            <?php elseif (!$isResolved && $slaRemaining !== null): ?>
                <!-- Live SLA countdown (ticks in real-time) -->
                <span class="sla-badge <?= $slaRemaining <= 2 ? 'sla-warning' : 'sla-met' ?>" id="slaCountdown"
                      data-deadline="<?= !empty($ticket['due_date'])
                          ? strtotime($ticket['due_date'] . ' 23:59:59') * 1000
                          : (strtotime($ticket['created_at']) + $slaHours * 3600) * 1000 ?>">
                    SLA: calculating...
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php if (isStaff()): ?>
            <button class="btn btn-sm <?= $isWatching ? 'btn-warning' : 'btn-outline-warning' ?>" id="watchBtn"
                    data-watching="<?= $isWatching ? '1' : '0' ?>" title="Get notified on every update">
                <i class="bi <?= $isWatching ? 'bi-eye-fill' : 'bi-eye' ?>"></i>
                <span id="watchLabel"><?= $isWatching ? 'Watching' : 'Watch' ?></span>
            </button>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer"></i> Print
        </button>
        <?php if (isStaff() || $ticket['user_id'] == $user['id']): ?>
            <a href="<?= APP_URL ?>/views/tickets/edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil"></i> Edit
            </a>
        <?php endif; ?>
        <?php if (isAdmin()): ?>
            <a href="<?= APP_URL ?>/views/tickets/delete.php?id=<?= $id ?>"
                class="btn btn-outline-danger btn-sm confirm-delete" data-message="Delete this ticket permanently?">
                <i class="bi bi-trash"></i> Delete
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isResolved && $resolutionHours !== null): ?>
    <div class="resolution-banner mb-3 no-print">
        <div class="res-item">
            <span class="res-label">Resolved in</span>
            <span class="res-value"><?= $fmtDuration($resolutionHours * 60) ?></span>
        </div>
        <?php if ($firstReplyMins !== null): ?>
            <div class="res-divider"></div>
            <div class="res-item">
                <span class="res-label">First response</span>
                <span class="res-value"><?= $fmtDuration($firstReplyMins) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($ticket['csat_rating']): ?>
            <div class="res-divider"></div>
            <div class="res-item">
                <span class="res-label">CSAT</span>
                <span class="res-value"><?= $ticket['csat_rating'] ?>/5</span>
            </div>
        <?php endif; ?>
        <div class="res-divider"></div>
        <div class="res-item">
            <span class="res-label">SLA</span>
            <span class="res-value" style="color:<?= $slaBreached ? '#dc2626' : '#16a34a' ?>">
                <?= $slaBreached ? 'Breached' : 'Met' ?> (<?= $fmtSla($slaHours) ?> target)
            </span>
        </div>
    </div>
<?php endif; ?>

<?php if ($isResolved && $ticket['user_id'] == $user['id'] && $ticket['csat_rating'] === null): ?>
    <div class="csat-widget mb-3 no-print" id="csatWidget">
        <div class="csat-question">Was your issue resolved satisfactorily?</div>
        <div class="csat-stars" id="csatStars">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" class="csat-star" data-rating="<?= $i ?>" aria-label="<?= $i ?> star">
                    <?= str_repeat('&#9733;', $i) . str_repeat('&#9734;', 5 - $i) ?>
                </button>
            <?php endfor; ?>
        </div>
        <div id="csatComment" style="display:none;margin-top:.75rem">
            <textarea class="form-control" id="csatCommentText" rows="2" placeholder="Optional comment..."
                style="font-size:.82rem"></textarea>
            <button class="btn btn-primary btn-sm mt-2" id="csatSubmit">Submit Rating</button>
        </div>
        <div id="csatThanks" style="display:none;color:#16a34a;font-weight:600;font-size:.88rem">
            Thank you for your feedback!
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <!-- Main Column: Description + Thread -->
    <div class="col-lg-8">

        <!-- Original Message (printable ticket body) -->
        <div class="card mb-3 ticket-print-section">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <?= renderAvatar($ticket['requester_avatar'] ?? null, $ticket['requester_name'] ?? '?', 'avatar-sm', 'flex-shrink:0') ?>
                    <div>
                        <div style="font-weight:600;font-size:.84rem;line-height:1.2">
                            <?= e($ticket['requester_name'] ?? 'Unknown') ?></div>
                        <?php
                            $sourceLabels = [
                                'web'     => 'reported via Portal',
                                'email'   => 'reported via Email',
                                'phone'   => 'reported via Phone',
                                'walk-in' => 'reported via Walk-in',
                            ];
                            $sourceLabel = $sourceLabels[$ticket['source'] ?? 'web'] ?? 'reported via ' . ucfirst($ticket['source'] ?? 'Portal');
                        ?>
                        <div style="font-size:.7rem;color:var(--text-muted)"><?= $sourceLabel ?></div>
                    </div>
                </div>
                <small class="text-muted"><?= formatDateTime($ticket['created_at']) ?></small>
            </div>
            <div class="card-body">
                <div class="ticket-description"><?= e(formatTicketDescription((string)$ticket['description'])) ?></div>
                <div class="ticket-cc">
                    <div class="ticket-cc-label"><i class="bi bi-people me-1"></i>CC</div>
                    <?php if (!empty($watchers)): ?>
                        <div class="ticket-cc-list">
                            <?php foreach ($watchers as $w): ?>
                                <span class="ticket-cc-chip" title="<?= e($w['email'] ?? '') ?>">
                                    <?= renderAvatar($w['avatar'] ?? null, $w['name'], 'avatar-sm') ?>
                                    <span>
                                        <strong><?= e($w['name']) ?></strong>
                                        <?php if (!empty($w['email'])): ?>
                                            <small><?= e($w['email']) ?></small>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted" style="font-size:.82rem">No one was CC'd on this ticket.</div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ticketAttachments)): ?>
                    <div class="reply-attachments mt-3 pt-3" style="border-top:1px solid var(--border-light)">
                        <div
                            style="font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin-bottom:.5rem">
                            Attachments (<?= count($ticketAttachments) ?>)
                        </div>
                        <?php foreach ($ticketAttachments as $att):
                            $isImage = strpos($att['mime_type'], 'image/') === 0;
                            $fileUrl = APP_URL . '/uploads/' . e($att['stored_name']);
                            $ext = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION));
                            $iconClass = match ($ext) {
                                'pdf' => 'bi-file-earmark-pdf text-danger',
                                'doc', 'docx' => 'bi-file-earmark-word text-primary',
                                'xls', 'xlsx' => 'bi-file-earmark-excel text-success',
                                'jpg', 'jpeg', 'png', 'gif', 'webp' => 'bi-file-earmark-image text-info',
                                default => 'bi-file-earmark text-muted',
                            };
                            ?>
                            <?php if ($isImage): ?>
                                <?= attachmentImagePreviewHtml($att['stored_name'], $att['filename']) ?>
                            <?php else: ?>
                                <a href="<?= $fileUrl ?>" target="_blank" class="reply-attachment-file">
                                    <i class="bi <?= $iconClass ?>"></i>
                                    <span><?= e($att['filename']) ?></span>
                                    <small class="text-muted"><?= round($att['file_size'] / 1024, 1) ?>KB</small>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Reply Thread -->
        <div class="card mb-3 no-print">
            <div class="card-header">
                <i class="bi bi-chat-dots me-2 text-primary"></i>
                Conversation Thread
                <span class="badge bg-secondary ms-1"><?= count($replies) ?></span>
            </div>
            <div class="card-body" style="max-height:480px;overflow-y:auto;padding-right:.85rem;">
                <?php if (empty($replies)): ?>
                    <div class="empty-state py-3" id="noRepliesState">
                        <div class="empty-state-icon fs-2"><i class="bi bi-chat"></i></div>
                        <p>No replies yet. Be the first to respond.</p>
                    </div>
                <?php endif; ?>
                    <div class="reply-thread" id="replyThread">
                        <?php foreach ($replies as $reply): ?>
                            <?php
                            $isMine = ($reply['user_id'] == $user['id']);
                            $isStaffAuthor = in_array($reply['author_role'], ['admin', 'staff', 'dept_admin'], true);
                            $bubbleClass = $isMine ? 'mine-reply' : ($isStaffAuthor ? 'staff-reply' : 'other-reply');
                            $initials = strtoupper(substr($reply['author_name'] ?? '?', 0, 2));
                            $avatarClass = $reply['author_role'] === 'admin' ? 'admin'
                                : ($isStaffAuthor ? 'staff' : '');
                            ?>
                            <div
                                class="reply-bubble <?= $bubbleClass ?> <?= $reply['is_internal'] ? 'reply-internal' : '' ?>">
                                <div class="reply-avatar <?= $avatarClass ?>">
                                    <?php if (!empty($reply['author_avatar'])): ?>
                                        <img src="<?= e(resolveAvatarUrl($reply['author_avatar'])) ?>" alt="<?= e($reply['author_name']) ?>"
                                            style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                                    <?php else: ?>
                                        <?= $initials ?>
                                    <?php endif; ?>
                                </div>
                                <div class="reply-content">
                                    <div class="reply-meta">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="reply-author"><?= e($reply['author_name'] ?? 'Unknown') ?></span>
                                            <?php if ($isMine): ?>
                                                <span class="reply-you-badge">You</span>
                                            <?php elseif ($isStaffAuthor): ?>
                                                <span class="reply-staff-badge">IT Support</span>
                                            <?php endif; ?>

                                            <?php if ($reply['is_internal']): ?>
                                                <span class="internal-label">Internal Note</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="reply-time" title="<?= e($reply['created_at']) ?>">
                                            <?= timeAgo($reply['created_at']) ?>
                                        </span>
                                    </div>
                                    <div class="reply-body"><?= nl2br(e($reply['message'])) ?></div>
                                    <?php if (!empty($replyAttachments[$reply['id']])): ?>
                                        <div class="reply-attachments">
                                            <?php foreach ($replyAttachments[$reply['id']] as $att): ?>
                                                <?php
                                                $isImage = strpos($att['mime_type'], 'image/') === 0;
                                                $fileUrl = APP_URL . '/uploads/' . e($att['stored_name']);
                                                $ext = strtolower(pathinfo($att['filename'], PATHINFO_EXTENSION));
                                                $iconClass = match ($ext) {
                                                    'pdf' => 'bi-file-earmark-pdf text-danger',
                                                    'doc', 'docx' => 'bi-file-earmark-word text-primary',
                                                    'xls', 'xlsx' => 'bi-file-earmark-excel text-success',
                                                    'jpg', 'jpeg', 'png', 'gif', 'webp' => 'bi-file-earmark-image text-info',
                                                    default => 'bi-file-earmark text-muted',
                                                };
                                                ?>
                                                <?php if ($isImage): ?>
                                                    <?= attachmentImagePreviewHtml($att['stored_name'], $att['filename']) ?>
                                                <?php else: ?>
                                                    <a href="<?= $fileUrl ?>" target="_blank" class="reply-attachment-file">
                                                        <i class="bi <?= $iconClass ?>"></i>
                                                        <span><?= e($att['filename']) ?></span>
                                                        <small class="text-muted"><?= round($att['file_size'] / 1024, 1) ?>KB</small>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
            </div>
        </div>

        <!-- Reply Form -->
        <?php if ($ticket['status'] !== 'closed' || isStaff()): ?>
            <div class="reply-form-card card no-print">
                <div class="card-header">
                    <i class="bi bi-reply me-2 text-primary"></i>Add Reply
                </div>
                <div class="card-body">
                    <form id="replyForm" enctype="multipart/form-data">
                        <input type="hidden" name="ticket_id" value="<?= $id ?>">

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label required mb-0">Message</label>
                                <?php if (isStaff()): ?>
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                            id="tplDropBtn" data-bs-toggle="dropdown" aria-expanded="false"
                                            onclick="loadTplDropdown()">
                                            Templates
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow" id="tplDropMenu"
                                            style="max-height:260px;overflow-y:auto;min-width:260px">
                                            <li><span class="dropdown-item text-muted">Loading...</span></li>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <textarea name="message" id="replyMessage" rows="5" class="form-control"
                                placeholder="Type your reply here… Paste screenshots with Ctrl+V to attach."
                                data-paste-target="replyFile" data-paste-preview="filePreview" required></textarea>
                        </div>

                        <?php if (isStaff()): ?>
                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input internal-check" id="internalNote"
                                    name="is_internal" value="1">
                                <label class="form-check-label" for="internalNote">
                                    Internal Note (not visible to requester)
                                </label>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Attachments</label>
                            <div class="upload-wrapper" data-paste-target="replyFile" data-paste-preview="filePreview">
                                <input type="file" name="attachments[]" id="replyFile" multiple
                                    accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx" class="d-none">
                                <div class="upload-area" onclick="document.getElementById('replyFile').click()">
                                    <i class="bi bi-cloud-upload"></i>
                                    <span>Click or drag to attach images/files (max 10MB each)</span>
                                </div>
                                <div id="filePreview" class="mt-1 small text-muted"></div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i>Send Reply
                            </button>
                            <?php if (isStaff() && $ticket['status'] !== 'resolved'): ?>
                                <button type="button" class="btn btn-outline-success" id="resolveBtn">
                                    <i class="bi bi-check-circle me-1"></i>Resolve & Reply
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-secondary no-print">
                <i class="bi bi-lock me-1"></i>This ticket is closed. Reopen it to add replies.
                <?php if (isStaff()): ?>
                    <button class="btn btn-sm btn-outline-primary ms-2 ajax-status-select-btn" data-ticket-id="<?= $id ?>"
                        data-status="open">Reopen Ticket</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Right Column: Ticket Details + Actions -->
    <div class="col-lg-4 no-print">

        <!-- Quick Actions (Staff) -->
        <?php if (isStaff()): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-sliders me-2 text-primary"></i>Quick Actions</div>
                <div class="card-body">
                    <!-- Status -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Status</label>
                        <select class="form-select form-select-sm ajax-status-select" data-ticket-id="<?= $id ?>">
                            <?php foreach (['open', 'in_progress', 'pending', 'resolved', 'closed'] as $s): ?>
                                <option value="<?= $s ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>>
                                    <?= ucwords(str_replace('_', ' ', $s)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Priority -->
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Priority</label>
                        <select class="form-select form-select-sm ajax-priority-select" data-ticket-id="<?= $id ?>">
                            <?php foreach (['critical', 'high', 'medium', 'low'] as $p): ?>
                                <option value="<?= $p ?>" <?= $ticket['priority'] === $p ? 'selected' : '' ?>>
                                    <?= ucfirst($p) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Assign -->
                    <div class="mb-0">
                        <label class="form-label small fw-semibold">Assigned To</label>
                        <select class="form-select form-select-sm ajax-assign-select" data-ticket-id="<?= $id ?>">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($staffList as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $ticket['assigned_to'] == $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isStaff() && $ticket['status'] !== 'closed'): ?>
            <!-- Smart Reply Suggestions (AI) -->
            <div class="card mb-3" id="smartReplyCard">
                <div class="card-header">
                    <i class="bi bi-magic me-2 text-primary"></i>Smart Reply Suggestions
                </div>
                <div class="card-body" id="smartReplyBody">
                    <div class="text-center text-muted py-2" style="font-size:.8rem">
                        <span class="spinner-border spinner-border-sm me-1"></span>Analyzing ticket...
                    </div>
                </div>
            </div>
        <?php endif; ?>

            <!-- CC / Watchers -->
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-people me-2 text-primary"></i>CC / Watchers
                    <span class="badge bg-secondary ms-1"><?= count($watchers) ?></span>
                </div>
                <div class="card-body d-flex flex-wrap gap-2">
                    <?php if (!empty($watchers)): ?>
                        <?php foreach ($watchers as $w): ?>
                            <div class="d-flex align-items-center gap-1" title="<?= e($w['email'] ?? $w['name']) ?>">
                                <?= renderAvatar($w['avatar'] ?? null, $w['name']) ?>
                                <small style="font-size:.74rem"><?= e($w['name']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-muted" style="font-size:.82rem">No one CC'd</span>
                    <?php endif; ?>
                </div>
            </div>

        <!-- Ticket Details -->
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-2 text-primary"></i>Details</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:.82rem;gap:.4rem 0">
                    <dt class="col-5 text-muted">Ticket #</dt>
                    <dd class="col-7 mb-0">
                        <code><?= e($ticket['ticket_code']) ?></code>
                    </dd>

                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7 mb-0">
                        <span class="status-badge <?= $statusClasses[$ticket['status']] ?? '' ?>">
                            <?= ucwords(str_replace('_', ' ', $ticket['status'])) ?>
                        </span>
                    </dd>

                    <dt class="col-5 text-muted">Priority</dt>
                    <dd class="col-7 mb-0">
                        <span class="priority-badge <?= $priorityClasses[$ticket['priority']] ?? '' ?>">
                            <?= ucfirst($ticket['priority']) ?>
                        </span>
                    </dd>

                    <dt class="col-5 text-muted">Department</dt>
                    <dd class="col-7 mb-0"><?= e($ticket['dept_name'] ?? 'N/A') ?></dd>

                    <dt class="col-5 text-muted">Category</dt>
                    <dd class="col-7 mb-0"><?= e($ticket['cat_name'] ?? 'N/A') ?></dd>

                    <dt class="col-5 text-muted">Requester</dt>
                    <dd class="col-7 mb-0"><?= e($ticket['requester_name'] ?? 'N/A') ?></dd>

                    <dt class="col-5 text-muted">Assigned To</dt>
                    <dd class="col-7 mb-0"><?= e($ticket['assignee_name'] ?? 'Unassigned') ?></dd>

                    <dt class="col-5 text-muted">CC</dt>
                    <dd class="col-7 mb-0">
                        <?php if (!empty($watchers)): ?>
                            <?= e(implode(', ', array_column($watchers, 'name'))) ?>
                        <?php else: ?>
                            <span class="text-muted">None</span>
                        <?php endif; ?>
                    </dd>

                    <?php if (!empty($ticket['is_legacy']) && !empty($ticket['legacy_ticket_number'])): ?>
                        <dt class="col-5 text-muted">Old Ticket #</dt>
                        <dd class="col-7 mb-0">
                            <span class="badge bg-warning text-dark">
                                #<?= e(str_pad($ticket['legacy_ticket_number'], 6, '0', STR_PAD_LEFT)) ?>
                            </span>
                        </dd>
                    <?php endif; ?>

                    <dt class="col-5 text-muted">Created</dt>
                    <dd class="col-7 mb-0"><?= formatDateTime($ticket['created_at']) ?></dd>

                    <dt class="col-5 text-muted">Updated</dt>
                    <dd class="col-7 mb-0"><?= formatDateTime($ticket['updated_at']) ?></dd>

                    <?php if ($ticket['due_date']): ?>
                        <dt class="col-5 text-muted">Due Date</dt>
                        <dd class="col-7 mb-0 <?= strtotime($ticket['due_date']) < time() ? 'text-danger fw-600' : '' ?>">
                            <?= formatDate($ticket['due_date']) ?>
                        </dd>
                    <?php endif; ?>

                    <?php if ($ticket['closed_at']): ?>
                        <dt class="col-5 text-muted">Closed At</dt>
                        <dd class="col-7 mb-0"><?= formatDateTime($ticket['closed_at']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-paperclip me-2 text-primary"></i>Attachments</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($attachments as $att):
                            $attIsImage = strpos($att['mime_type'], 'image/') === 0;
                            $attUrl = attachmentPublicUrl($att['stored_name']);
                            ?>
                            <li class="list-group-item d-flex align-items-center gap-2 py-2 px-3" style="font-size:.8rem">
                                <i class="bi <?= $attIsImage ? 'bi-file-earmark-image text-info' : 'bi-file-earmark text-muted' ?>"></i>
                                <?php if ($attIsImage): ?>
                                    <a href="#" role="button" class="text-truncate ticket-image-preview"
                                       data-image-src="<?= e($attUrl) ?>"
                                       data-image-title="<?= e($att['filename']) ?>">
                                        <?= e($att['filename']) ?>
                                    </a>
                                <?php else: ?>
                                    <a href="<?= e($attUrl) ?>" target="_blank" class="text-truncate">
                                        <?= e($att['filename']) ?>
                                    </a>
                                <?php endif; ?>
                                <small class="text-muted ms-auto text-nowrap">
                                    <?= round($att['file_size'] / 1024, 1) ?>KB
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <!-- Activity Log -->
        <?php if (isStaff() && !empty($logs)): ?>
            <div class="card">
                <div class="card-header"><i class="bi bi-journal-text me-2 text-primary"></i>Activity Log</div>
                <div class="card-body">
                    <div class="timeline">
                        <?php foreach ($logs as $log): ?>
                            <div class="timeline-item">
                                <div class="fw-500"><?= e(ucwords(str_replace('_', ' ', $log['action']))) ?></div>
                                <?php if ($log['description']): ?>
                                    <div class="timeline-desc text-muted" style="font-size:.75rem">
                                        <?= e($log['description']) ?>
                                    </div>
                                <?php endif; ?>
                                <div class="timeline-time">
                                    <?= e($log['actor_name'] ?? 'System') ?> &bull;
                                    <?= timeAgo($log['created_at']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Image preview modal -->
<div class="modal fade" id="ticketImageModal" tabindex="-1" aria-labelledby="ticketImageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl ticket-image-modal-dialog">
        <div class="modal-content ticket-image-modal-content">
            <div class="modal-header border-0 pb-2">
                <h5 class="modal-title text-truncate pe-3" id="ticketImageModalLabel">Image</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 px-3 pb-3 text-center">
                <img src="" alt="" id="ticketImageModalImg" class="ticket-image-modal-img">
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center gap-2">
                <a href="#" target="_blank" rel="noopener" id="ticketImageModalOpen" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open in new tab
                </a>
                <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">
                    <i class="bi bi-x-lg me-1"></i>Close
                </button>
            </div>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<script>
// ---- Ticket image preview modal ----
(function () {
    const modalEl = document.getElementById('ticketImageModal');
    if (!modalEl || typeof bootstrap === 'undefined') return;

    const modalImg = document.getElementById('ticketImageModalImg');
    const modalTitle = document.getElementById('ticketImageModalLabel');
    const modalOpen = document.getElementById('ticketImageModalOpen');
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

    function openTicketImagePreview(src, title) {
        if (!src || !modalImg) return;
        modalImg.src = src;
        modalImg.alt = title || 'Attachment';
        if (modalTitle) modalTitle.textContent = title || 'Image';
        if (modalOpen) modalOpen.href = src;
        bsModal.show();
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('.ticket-image-preview');
        if (!trigger) return;
        e.preventDefault();
        openTicketImagePreview(trigger.dataset.imageSrc, trigger.dataset.imageTitle || '');
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (modalImg) modalImg.src = '';
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalEl.classList.contains('show')) {
            bsModal.hide();
        }
    });
})();

// ---- Dynamic variables for canned responses / smart replies ----
const TICKET_VARS = {
    requester_name: <?= json_encode($ticket['requester_name'] ?? 'there') ?>,
    ticket_code:    <?= json_encode($ticket['ticket_code']) ?>,
    department:     <?= json_encode($ticket['dept_name'] ?? 'our team') ?>,
    staff_name:     <?= json_encode($user['name']) ?>,
    date:           <?= json_encode(date('M d, Y')) ?>
};
function fillVars(text) {
    return String(text).replace(/\{\{(\w+)\}\}/g, (m, key) => TICKET_VARS[key] ?? m);
}

// ---- Live SLA countdown timer ----
(function() {
    const el = document.getElementById('slaCountdown');
    if (!el) return;
    const deadline = parseInt(el.dataset.deadline);
    function tick() {
        const diff = deadline - Date.now();
        if (diff <= 0) {
            el.textContent = 'SLA: Overdue';
            el.classList.remove('sla-met');
            el.classList.add('sla-breached');
            return;
        }
        const h = Math.floor(diff / 3600000);
        const m = Math.floor((diff % 3600000) / 60000);
        const s = Math.floor((diff % 60000) / 1000);
        el.textContent = 'SLA: ' + (h > 0 ? h + 'h ' : '') + m + 'm ' + s + 's left';
        if (diff <= 2 * 3600000) {
            el.classList.remove('sla-met');
            el.classList.add('sla-warning');
        }
        setTimeout(tick, 1000);
    }
    tick();
})();

// ---- Template dropdown ----
let tplCache = null;
function loadTplDropdown() {
    if (tplCache) { renderTplDrop(tplCache); return; }
    $.get(APP_URL + '/api/templates.php?action=list', function(r) {
        if (!r.success) return;
        tplCache = r.templates;
        renderTplDrop(tplCache);
    }, 'json');
}
function renderTplDrop(items) {
    const $menu = $('#tplDropMenu');
    if (!items.length) {
        $menu.html('<li><span class="dropdown-item text-muted">No templates yet.</span></li>');
        return;
    }
    $menu.html(items.map(t =>
        `<li><button type="button" class="dropdown-item tpl-pick" data-body="${escAttr(t.body)}">${escHtml(t.title)}</button></li>`
    ).join(''));
}
$(document).on('click', '.tpl-pick', function() {
    document.getElementById('replyMessage').value = fillVars($(this).data('body'));
    document.getElementById('replyMessage').focus();
});
function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escAttr(s) { return String(s).replace(/"/g,'&quot;').replace(/\n/g,'&#10;'); }

// ---- File preview (multiple) ----
document.getElementById('replyFile')?.addEventListener('change', function() {
    const $prev = $('#filePreview');
    if (!this.files || !this.files.length) {
        $prev.html('');
        return;
    }
    $prev.html(Array.from(this.files).map(function(file) {
        return '<div><i class="bi bi-file-earmark me-1"></i>' + file.name +
            ' <span class="text-muted">(' + (file.size / 1024).toFixed(1) + ' KB)</span></div>';
    }).join(''));
});

// ---- AJAX Reply Form submit ----
let replySubmitting = false;
$('#replyForm').on('submit', function(e) {
    e.preventDefault();
    if (replySubmitting) return;
    replySubmitting = true;

    const $btn  = $(this).find('button[type=submit]');
    const $form = $(this);
    const fd    = new FormData(this);
    fd.append('action',    'add_reply');
    fd.append('ticket_id', '<?= $id ?>');
    fd.append('_csrf',     CSRF_TOKEN);

    $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Sending...');

    $.ajax({
        url:         APP_URL + '/api/tickets.php',
        type:        'POST',
        data:        fd,
        processData: false,
        contentType: false,
        dataType:    'json',
        success: function(r) {
            if (r.success) {
                // Hide the "No replies yet" empty state if present
                $('#noRepliesState').hide();
                // Append reply bubble (the thread container always exists now)
                $('#replyThread').append(r.html);
                // Scroll to bottom
                const el = document.getElementById('replyThread');
                if (el) el.lastElementChild?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                // Clear form
                $form[0].reset();
                $('#filePreview').html('');
                $form.find('input[name=resolve_on_reply]').remove();
                // If resolved, reload to update status badges/UI
                if (fd.get('resolve_on_reply')) {
                    showToast('success', 'Ticket resolved successfully.');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('success', 'Reply sent.');
                }
            } else {
                showToast('danger', r.message || 'Failed to send reply.');
            }
        },
        error: function() { showToast('danger', 'Network error. Please try again.'); },
        complete: function() { replySubmitting = false; $btn.prop('disabled', false).html('<i class="bi bi-send me-1"></i>Send Reply'); }
    });
});

// ---- Resolve & Reply ----
$('#resolveBtn')?.on('click', function() {
    const $form = $('#replyForm');
    if (!$form.find('input[name=resolve_on_reply]').length) {
        $form.append('<input type="hidden" name="resolve_on_reply" value="1">');
    }
    $form.trigger('submit');
});

// ---- Reopen button ----
$(document).on('click', '.ajax-status-select-btn', function() {
    const ticketId = $(this).data('ticket-id');
    const status   = $(this).data('status');
    $.post(APP_URL + '/api/tickets.php',
        { action:'update_status', ticket_id:ticketId, status:status, _csrf:CSRF_TOKEN },
        function(r) {
            if (r.success) { showToast('success', r.message); location.reload(); }
            else { showToast('danger', r.message); }
        }, 'json');
});

// ---- CSAT star rating ----
let csatSelected = 0;
$(document).on('click', '.csat-star', function() {
    csatSelected = parseInt($(this).data('rating'));
    $('.csat-star').each(function(i) {
        $(this).toggleClass('selected', i < csatSelected);
    });
    $('#csatComment').slideDown(200);
});
$('#csatSubmit').on('click', function() {
    if (!csatSelected) { showToast('warning', 'Please select a star rating first.'); return; }
    const $btn = $(this).prop('disabled', true).text('Submitting...');
    $.post(APP_URL + '/api/tickets.php', {
        action:    'submit_csat',
        ticket_id: <?= $id ?>,
        rating:    csatSelected,
        comment:   $('#csatCommentText').val(),
        _csrf:     CSRF_TOKEN
    }, function(r) {
        if (r.success) {
            $('#csatStars, #csatComment').hide();
            $('#csatThanks').fadeIn();
        } else {
            showToast('danger', r.message);
            $btn.prop('disabled', false).text('Submit Rating');
        }
    }, 'json');
});

// ---- Smart Reply Suggestions (AI) ----
<?php if (isStaff() && $ticket['status'] !== 'closed'): ?>
$.get(APP_URL + '/api/ai.php', { action: 'reply_suggestions', ticket_id: <?= $id ?> }, function(r) {
    const $body = $('#smartReplyBody');
    if (!r.success || !r.suggestions || !r.suggestions.length) {
        $body.html('<p class="text-muted mb-0" style="font-size:.78rem">No suggestions for this ticket.</p>');
        return;
    }
    let html = '';
    r.suggestions.forEach(function(s, i) {
        html += `<button type="button" class="smart-reply-pick d-block w-100 text-start mb-2 btn btn-light"
                     style="font-size:.78rem;border:1px solid var(--border-light,#e2e8f0);border-radius:8px;padding:.5rem .65rem"
                     data-idx="${i}">
            <div class="fw-600"><i class="bi bi-magic text-primary me-1"></i>${escHtml(s.title)}</div>
            <div class="text-muted" style="font-size:.7rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                ${escHtml(s.body.slice(0, 70))}...
            </div>
        </button>`;
    });
    $body.html(html);
    $body.data('suggestions', r.suggestions);
}, 'json');

$(document).on('click', '.smart-reply-pick', function() {
    const suggestions = $('#smartReplyBody').data('suggestions') || [];
    const s = suggestions[$(this).data('idx')];
    if (!s) return;
    document.getElementById('replyMessage').value = fillVars(s.body);
    document.getElementById('replyMessage').focus();
    showToast('info', 'Suggestion inserted — review and edit before sending.');
});
<?php endif; ?>

// ---- Watch / Unwatch ----
$('#watchBtn').on('click', function() {
    const $btn = $(this);
    $.post(APP_URL + '/api/tickets.php',
        { action: 'toggle_watch', ticket_id: <?= $id ?>, _csrf: CSRF_TOKEN },
        function(r) {
            if (!r.success) { showToast('danger', r.message); return; }
            const watching = r.watching;
            $btn.toggleClass('btn-warning', watching).toggleClass('btn-outline-warning', !watching);
            $btn.find('i').attr('class', watching ? 'bi bi-eye-fill' : 'bi bi-eye');
            $('#watchLabel').text(watching ? 'Watching' : 'Watch');
            showToast('success', r.message);
        }, 'json');
});

// ---- "::" Canned Response Picker in reply box ----
(function() {
    const $msg = document.getElementById('replyMessage');
    if (!$msg) return;
    let picker = null;

    function closePicker() {
        if (picker) { picker.remove(); picker = null; }
    }

    function openPicker() {
        closePicker();
        picker = document.createElement('div');
        picker.id = 'cannedPicker';
        picker.style.cssText = 'position:absolute;z-index:1060;background:#fff;border:1px solid #e2e8f0;' +
            'border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:240px;overflow-y:auto;' +
            'min-width:280px;font-size:.82rem';
        const rect = $msg.getBoundingClientRect();
        picker.style.left = (rect.left + window.scrollX) + 'px';
        picker.style.top  = (rect.top + window.scrollY - 8) + 'px';
        picker.innerHTML = '<div class="p-2 text-muted">Loading canned responses...</div>';
        document.body.appendChild(picker);

        $.get(APP_URL + '/api/templates.php?action=list', function(r) {
            if (!r.success || !r.templates.length) {
                picker.innerHTML = '<div class="p-2 text-muted">No canned responses available.</div>';
                return;
            }
            let html = '<div style="padding:.45rem .7rem;font-size:.66rem;font-weight:700;color:#94a3b8;' +
                'text-transform:uppercase;border-bottom:1px solid #f1f5f9">Canned Responses ' +
                '<span style="float:right;text-transform:none;font-weight:400">Esc to close</span></div>';
            r.templates.forEach(function(t, i) {
                html += `<button type="button" class="canned-pick" data-idx="${i}" style="display:block;width:100%;
                    text-align:left;background:none;border:none;border-bottom:1px solid #f8fafc;
                    padding:.5rem .7rem;cursor:pointer">
                    <span class="fw-600">${escHtml(t.title)}</span>
                    ${t.is_global == 1 ? '<span class="badge bg-secondary ms-1" style="font-size:.58rem">Global</span>'
                                       : '<span class="badge bg-info ms-1" style="font-size:.58rem">Personal</span>'}
                </button>`;
            });
            picker.innerHTML = html;
            picker.dataset.loaded = '1';
            window._cannedTemplates = r.templates;
        }, 'json');
    }

    $msg.addEventListener('input', function() {
        const pos = this.selectionStart;
        if (pos >= 2 && this.value.slice(pos - 2, pos) === '::') {
            openPicker();
        } else if (picker) {
            closePicker();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closePicker();
    });
    document.addEventListener('click', function(e) {
        if (picker && !e.target.closest('#cannedPicker') && e.target !== $msg) closePicker();
    });
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.canned-pick');
        if (!btn || !window._cannedTemplates) return;
        const t = window._cannedTemplates[parseInt(btn.dataset.idx)];
        if (!t) return;
        // Replace the trailing '::' trigger with the template body (variables filled)
        const pos = $msg.selectionStart;
        const before = $msg.value.slice(0, pos).replace(/::$/, '');
        const after  = $msg.value.slice(pos);
        $msg.value = before + fillVars(t.body) + after;
        closePicker();
        $msg.focus();
    });
})();

// ---- SLA check on page load ----
<?php if (isStaff()): ?>
    $.get(APP_URL + '/api/sla_check.php');
    $.get(APP_URL + '/api/sla_escalation.php');
    $.get(APP_URL + '/api/auto_close.php');
<?php endif; ?>
</script>
<?php $extraScripts = ob_get_clean(); ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>