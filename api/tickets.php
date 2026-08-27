<?php
/**
 * Tickets AJAX API
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai_helpers.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin();

header('Content-Type: application/json');

$pdo    = db();
$user   = currentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ---- SLA hours by priority ----
function slaHours(string $priority): int {
    return ['critical' => 4, 'high' => 8, 'medium' => 24, 'low' => 48][$priority] ?? 24;
}

// ---- Format hours as human string ----
function fmtHours(float $hours): string {
    if ($hours < 1)   return round($hours * 60) . 'm';
    if ($hours < 24)  return round($hours, 1)   . 'h';
    $d = floor($hours / 24);
    $h = round(fmod($hours, 24));
    return $h > 0 ? "{$d}d {$h}h" : "{$d}d";
}

// ---- Department Admin guard: may only manage tickets of their own dept ----
function assertDeptScope(PDO $pdo, int $ticketId): void {
    $scope = deptScopeId();
    if ($scope === null || !$ticketId) return;
    $st = $pdo->prepare("SELECT department_id FROM tickets WHERE id = ?");
    $st->execute([$ticketId]);
    if ((int)$st->fetchColumn() !== $scope) {
        jsonResponse(false, 'Permission denied: ticket belongs to another department.', [], 403);
    }
}

switch ($action) {

    // ---- Update Status ----
    case 'update_status':
        if (!isStaff()) { jsonResponse(false, 'Permission denied.', [], 403); }
        validateCsrf();
        $ticketId  = (int)($_POST['ticket_id'] ?? 0);
        assertDeptScope($pdo, $ticketId);
        $status    = $_POST['status'] ?? '';
        $validStatuses = ['open','in_progress','pending','resolved','closed'];
        if (!$ticketId || !in_array($status, $validStatuses)) {
            jsonResponse(false, 'Invalid parameters.');
        }
        $ticket = $pdo->prepare("SELECT ticket_code, status, user_id, assigned_to, created_at, sla_hours FROM tickets WHERE id=?");
        $ticket->execute([$ticketId]);
        $ticket = $ticket->fetch();
        if (!$ticket) jsonResponse(false, 'Ticket not found.', [], 404);

        $now        = date('Y-m-d H:i:s');
        $resolvedAt = ($status === 'resolved') ? $now : null;
        $closedAt   = ($status === 'closed')   ? $now : null;

        // Determine SLA breach at resolve/close time
        $slaBreach = 0;
        if (in_array($status, ['resolved','closed']) && $ticket['sla_hours']) {
            $diffHours = (time() - strtotime($ticket['created_at'])) / 3600;
            $slaBreach = $diffHours > $ticket['sla_hours'] ? 1 : 0;
        }

        $pdo->prepare(
            "UPDATE tickets
             SET status=?, closed_at=COALESCE(?, closed_at),
                 resolved_at=COALESCE(?, resolved_at),
                 sla_breached=IF(? IN ('resolved','closed'), ?, sla_breached),
                 updated_at=NOW()
             WHERE id=?"
        )->execute([$status, $closedAt, $resolvedAt, $status, $slaBreach, $ticketId]);

        $statusLabelTxt = ucwords(str_replace('_',' ',$status));

        // Notify requester
        createNotification($ticket['user_id'], $ticketId, 'status_changed',
            "Ticket {$ticket['ticket_code']} status changed to " . $statusLabelTxt);

        // Notify watchers
        notifyWatchers($pdo, $ticketId, (int)$user['id'], 'watching',
            "Watched ticket {$ticket['ticket_code']} status changed to " . $statusLabelTxt . ".");

        // Department-wide broadcast + group email (shared inbox behavior)
        $deptIdRow = $pdo->prepare("SELECT department_id FROM tickets WHERE id = ?");
        $deptIdRow->execute([$ticketId]);
        $tDeptId = (int)$deptIdRow->fetchColumn();
        if ($tDeptId) {
            notifyDepartment($pdo, $tDeptId, $ticketId, 'status_changed',
                "Ticket {$ticket['ticket_code']} status changed to {$statusLabelTxt}.", (int)$user['id']);
        }

        // Email the requester + all department members
        $statusMailHtml = mailTemplate(
            "Ticket {$ticket['ticket_code']} — Status Update",
            '<p>The status of ticket <strong>' . e($ticket['ticket_code']) . '</strong> was changed to '
            . '<strong>' . $statusLabelTxt . '</strong> by ' . e($user['name']) . '.</p>'
            . '<a class="btn" href="' . APP_URL . '/views/tickets/view.php?id=' . $ticketId . '">View Ticket</a>'
        );
        $reqRow = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
        $reqRow->execute([$ticket['user_id']]);
        if ($req = $reqRow->fetch()) {
            sendMail(['email' => $req['email'], 'name' => $req['name']],
                "[{$ticket['ticket_code']}] Status changed to {$statusLabelTxt}", $statusMailHtml);
        }
        sendDeptMailForTicket($pdo, $ticketId,
            "[{$ticket['ticket_code']}] Status changed to {$statusLabelTxt}", $statusMailHtml, (int)$user['id']);
        emailWatchersOutsideDept($pdo, $ticketId,
            "[{$ticket['ticket_code']}] Status changed to {$statusLabelTxt}", $statusMailHtml, (int)$user['id']);

        // ---- AI learning: resolved/closed ticket -> knowledge base article ----
        if (in_array($status, ['resolved', 'closed'], true)) {
            triggerTicketKbLearning($pdo, $ticketId, (int)$user['id']);
        }

        logActivity($user['id'], $ticketId, 'status_updated',
            "Status: {$ticket['status']} -> $status", getClientIp());

        $statusClasses = [
            'open'=>'status-open','in_progress'=>'status-in_progress',
            'pending'=>'status-pending','resolved'=>'status-resolved','closed'=>'status-closed'
        ];
        $cls      = $statusClasses[$status] ?? 'status-open';
        $label    = ucwords(str_replace('_',' ',$status));
        $badgeHtml = "<span class=\"status-badge $cls\" id=\"statusBadgeMain\">$label</span>";

        jsonResponse(true, 'Status updated to ' . $label, ['badge_html' => $badgeHtml]);

    // ---- Update Priority ----
    case 'update_priority':
        if (!isStaff()) { jsonResponse(false, 'Permission denied.', [], 403); }
        validateCsrf();
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        assertDeptScope($pdo, $ticketId);
        $priority = $_POST['priority'] ?? '';
        if (!in_array($priority, ['low','medium','high','critical'])) {
            jsonResponse(false, 'Invalid priority.');
        }
        // Update priority AND recalculate sla_hours
        $newSla = slaHours($priority);
        $pdo->prepare("UPDATE tickets SET priority=?, sla_hours=?, updated_at=NOW() WHERE id=?")
            ->execute([$priority, $newSla, $ticketId]);
        logActivity($user['id'], $ticketId, 'priority_updated', "Priority -> $priority", getClientIp());
        jsonResponse(true, 'Priority updated to ' . ucfirst($priority));

    // ---- Assign Ticket ----
    case 'assign':
        if (!isStaff()) { jsonResponse(false, 'Permission denied.', [], 403); }
        validateCsrf();
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        assertDeptScope($pdo, $ticketId);
        $staffId  = (int)($_POST['staff_id']  ?? 0) ?: null;

        $tk = $pdo->prepare("SELECT ticket_code, assigned_to FROM tickets WHERE id=?");
        $tk->execute([$ticketId]); $tk = $tk->fetch();
        if (!$tk) jsonResponse(false, 'Ticket not found.');

        $pdo->prepare("UPDATE tickets SET assigned_to=?, updated_at=NOW() WHERE id=?")->execute([$staffId, $ticketId]);

        if ($staffId && $staffId != $tk['assigned_to']) {
            createNotification($staffId, $ticketId, 'assigned',
                "Ticket {$tk['ticket_code']} has been assigned to you by {$user['name']}.");
        }
        logActivity($user['id'], $ticketId, 'ticket_assigned',
            "Assigned to user ID $staffId", getClientIp());

        $assigneeName = 'Unassigned';
        if ($staffId) {
            $s = $pdo->prepare("SELECT name FROM users WHERE id=?");
            $s->execute([$staffId]); $s = $s->fetch();
            $assigneeName = $s['name'] ?? 'Unknown';
        }
        jsonResponse(true, "Ticket assigned to $assigneeName.", ['assignee' => $assigneeName]);

    // ---- Add Reply ----
    case 'add_reply':
        validateCsrf();
        $ticketId      = (int)($_POST['ticket_id'] ?? 0);
        $message       = trim($_POST['message'] ?? '');
        $isInternal    = (isStaff() && !empty($_POST['is_internal'])) ? 1 : 0;
        $resolveOnReply = !empty($_POST['resolve_on_reply']);

        if (!$ticketId || empty($message)) {
            jsonResponse(false, 'Message cannot be empty.');
        }

        $tk = $pdo->prepare("SELECT ticket_code, user_id, status, assigned_to, created_at, sla_hours, first_reply_at FROM tickets WHERE id=?");
        $tk->execute([$ticketId]); $tk = $tk->fetch();
        if (!$tk) jsonResponse(false, 'Ticket not found.');
        if (!isStaff() && $tk['user_id'] != $user['id']) jsonResponse(false, 'Access denied.', [], 403);

        // Insert reply
        $pdo->prepare(
            "INSERT INTO ticket_replies (ticket_id, user_id, message, is_internal) VALUES (?,?,?,?)"
        )->execute([$ticketId, $user['id'], $message, $isInternal]);
        $replyId = (int)$pdo->lastInsertId();

        // Track first staff reply time
        if (isStaff() && !$isInternal && empty($tk['first_reply_at'])) {
            $pdo->prepare("UPDATE tickets SET first_reply_at=NOW() WHERE id=?")
                ->execute([$ticketId]);
        }

        // Handle attachment
        if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['attachment'];
            if ($file['size'] <= UPLOAD_MAX_SIZE && in_array($file['type'], UPLOAD_ALLOWED)) {
                $ext    = pathinfo($file['name'], PATHINFO_EXTENSION);
                $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $stored)) {
                    $pdo->prepare(
                        "INSERT INTO attachments (ticket_id, reply_id, user_id, filename, stored_name, mime_type, file_size)
                         VALUES (?,?,?,?,?,?,?)"
                    )->execute([$ticketId, $replyId, $user['id'], $file['name'], $stored, $file['type'], $file['size']]);
                }
            }
        }

        // Status update / resolve
        $newStatus  = $resolveOnReply ? 'resolved' : null;
        $slaBreach  = 0;
        if ($newStatus === 'resolved' && $tk['sla_hours']) {
            $diffHours = (time() - strtotime($tk['created_at'])) / 3600;
            $slaBreach = $diffHours > $tk['sla_hours'] ? 1 : 0;
        }
        if ($newStatus) {
            $pdo->prepare(
                "UPDATE tickets SET status='resolved', resolved_at=NOW(), sla_breached=?, updated_at=NOW() WHERE id=?"
            )->execute([$slaBreach, $ticketId]);
            triggerTicketKbLearning($pdo, $ticketId, (int)$user['id']);
        } else {
            $pdo->prepare("UPDATE tickets SET updated_at=NOW() WHERE id=?")->execute([$ticketId]);
        }

        // Notifications
        $isStaffReply = isStaff();
        if ($isStaffReply && !$isInternal) {
            createNotification($tk['user_id'], $ticketId, 'replied',
                "{$user['name']} replied to ticket {$tk['ticket_code']}.");
        } elseif (!$isStaffReply && $tk['assigned_to']) {
            createNotification($tk['assigned_to'], $ticketId, 'replied',
                "New reply on ticket {$tk['ticket_code']} by {$user['name']}.");
        }

        // Notify watchers (skip internal notes)
        if (!$isInternal) {
            notifyWatchers($pdo, $ticketId, (int)$user['id'], 'watching',
                "New reply on watched ticket {$tk['ticket_code']} by {$user['name']}.");

            // Department-wide broadcast + group email (shared inbox behavior)
            $deptIdRow = $pdo->prepare("SELECT department_id FROM tickets WHERE id = ?");
            $deptIdRow->execute([$ticketId]);
            $tDeptId = (int)$deptIdRow->fetchColumn();
            if ($tDeptId) {
                notifyDepartment($pdo, $tDeptId, $ticketId, 'replied',
                    "New reply on ticket {$tk['ticket_code']} by {$user['name']}.", (int)$user['id']);

                $replyMailHtml = mailTemplate(
                    "New Reply on {$tk['ticket_code']}",
                    '<p><strong>' . e($user['name']) . '</strong> replied to ticket '
                    . '<strong>' . e($tk['ticket_code']) . '</strong>:</p>'
                    . '<p>' . nl2br(e(mb_strimwidth($message, 0, 400, '...'))) . '</p>'
                    . '<a class="btn" href="' . APP_URL . '/views/tickets/view.php?id=' . $ticketId . '">View Conversation</a>'
                );
                sendDeptMailForTicket($pdo, $ticketId,
                    "[{$tk['ticket_code']}] New reply from {$user['name']}",
                    $replyMailHtml,
                    (int)$user['id']);
                emailWatchersOutsideDept($pdo, $ticketId,
                    "[{$tk['ticket_code']}] New reply from {$user['name']}",
                    $replyMailHtml,
                    (int)$user['id']);
            }
        }

        logActivity($user['id'], $ticketId, 'reply_added', '', getClientIp());

        // Build HTML for the new reply — include avatar if set
        $avatarHtml  = '';
        $avatarUrl   = resolveAvatarUrl($user['avatar'] ?? null);
        $initials    = strtoupper(substr($user['name'], 0, 2));
        $avatarClass = $user['role'] === 'admin' ? 'admin' : ($isStaffReply ? 'staff' : '');
        if ($avatarUrl) {
            $avatarHtml = '<img src="' . htmlspecialchars($avatarUrl, ENT_QUOTES) . '" alt=""'
                        . ' style="width:100%;height:100%;object-fit:cover;border-radius:50%;">';
        } else {
            $avatarHtml = $initials;
        }

        $internalCls = $isInternal ? 'reply-internal' : '';
        $msgSafe     = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

        $html = <<<HTML
<div class="reply-bubble mine-reply {$internalCls}" style="animation:fadeSlideIn .3s ease">
    <div class="reply-avatar {$avatarClass}">{$avatarHtml}</div>
    <div class="reply-content">
        <div class="reply-meta">
            <div class="d-flex align-items-center gap-2">
                <span class="reply-author">{$user['name']}</span>
                <span class="reply-you-badge">You</span>
HTML;
        if ($isInternal) $html .= '<span class="internal-label">Internal Note</span>';
        $html .= <<<HTML
            </div>
            <span class="reply-time">Just now</span>
        </div>
        <div class="reply-body">{$msgSafe}</div>
    </div>
</div>
HTML;

        jsonResponse(true, 'Reply added.', ['html' => $html, 'reply_id' => $replyId]);

    // ---- Submit CSAT Rating ----
    case 'submit_csat':
        validateCsrf();
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        $rating   = (int)($_POST['rating']    ?? 0);
        $comment  = trim($_POST['comment']    ?? '');

        if (!$ticketId || $rating < 1 || $rating > 5) {
            jsonResponse(false, 'Invalid rating. Please choose 1–5 stars.');
        }

        $tk = $pdo->prepare("SELECT user_id, status, csat_rating FROM tickets WHERE id=?");
        $tk->execute([$ticketId]); $tk = $tk->fetch();
        if (!$tk) jsonResponse(false, 'Ticket not found.');

        // Only the requester can rate, and only once
        if ($tk['user_id'] != $user['id']) {
            jsonResponse(false, 'Only the ticket requester can rate this ticket.', [], 403);
        }
        if (!in_array($tk['status'], ['resolved','closed'])) {
            jsonResponse(false, 'CSAT can only be submitted for resolved or closed tickets.');
        }
        if ($tk['csat_rating'] !== null) {
            jsonResponse(false, 'You have already rated this ticket.');
        }

        $pdo->prepare("UPDATE tickets SET csat_rating=?, csat_comment=? WHERE id=?")
            ->execute([$rating, $comment ?: null, $ticketId]);

        logActivity($user['id'], $ticketId, 'csat_submitted', "Rating: {$rating}/5", getClientIp());
        jsonResponse(true, 'Thank you for your feedback!', ['rating' => $rating]);

    // ---- Toggle Watch (staff follow a ticket) ----
    case 'toggle_watch':
        if (!isStaff()) jsonResponse(false, 'Permission denied.', [], 403);
        validateCsrf();
        $ticketId = (int)($_POST['ticket_id'] ?? 0);
        if (!$ticketId) jsonResponse(false, 'Invalid ticket.');

        $exists = $pdo->prepare("SELECT id FROM ticket_watchers WHERE ticket_id = ? AND user_id = ?");
        $exists->execute([$ticketId, $user['id']]);
        if ($exists->fetch()) {
            $pdo->prepare("DELETE FROM ticket_watchers WHERE ticket_id = ? AND user_id = ?")
                ->execute([$ticketId, $user['id']]);
            jsonResponse(true, 'You are no longer watching this ticket.', ['watching' => false]);
        }
        $pdo->prepare("INSERT INTO ticket_watchers (ticket_id, user_id, added_by) VALUES (?,?,?)")
            ->execute([$ticketId, $user['id'], $user['id']]);
        jsonResponse(true, 'You are now watching this ticket.', ['watching' => true]);

    // ---- Department Test Notification (admin) ----
    case 'dept_test_notification':
        if (!isAdmin()) jsonResponse(false, 'Permission denied.', [], 403);
        validateCsrf();
        $deptId = (int)($_POST['dept_id'] ?? 0);
        if (!$deptId) jsonResponse(false, 'Invalid department.');

        $d = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $d->execute([$deptId]);
        $deptName = $d->fetchColumn();
        if (!$deptName) jsonResponse(false, 'Department not found.');

        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM users WHERE department_id = ? AND is_active = 1"
        );
        $count->execute([$deptId]);
        $memberCount = (int)$count->fetchColumn();
        if ($memberCount === 0) jsonResponse(false, "No active members in {$deptName}.");

        notifyDepartment($pdo, $deptId, null, 'updated',
            "[TEST] This is a test broadcast to the {$deptName} department sent by {$user['name']}.");

        logActivity($user['id'], null, 'dept_test_notification',
            "Test notification sent to {$deptName}", getClientIp());
        jsonResponse(true, "Test notification sent to {$memberCount} member(s) of {$deptName}.");

    // ---- Live Feed (dashboard auto-refresh) ----
    case 'live_feed':
        if (!isStaff()) jsonResponse(false, 'Permission denied.', [], 403);
        $feedScope = deptScopeId();
        $rows = $pdo->query(
            "SELECT t.id, t.ticket_code, t.subject, t.status, t.priority, t.updated_at, t.created_at,
                    u.name AS requester_name
             FROM tickets t
             LEFT JOIN users u ON u.id = t.user_id"
             . ($feedScope !== null ? " WHERE t.department_id = {$feedScope}" : '') . "
             ORDER BY t.updated_at DESC
             LIMIT 8"
        )->fetchAll();
        $feed = array_map(function ($r) {
            $createdTs = strtotime($r['created_at']);
            $updatedTs = strtotime($r['updated_at']);
            // Show "updated" only when the ticket was actually changed after creation
            $wasUpdated = $updatedTs > $createdTs + 60;
            return [
                'id'        => (int)$r['id'],
                'code'      => $r['ticket_code'],
                'subject'   => mb_strimwidth($r['subject'], 0, 50, '...'),
                'status'    => $r['status'],
                'priority'  => $r['priority'],
                'requester' => $r['requester_name'] ?? 'Unknown',
                'time_ago'  => ($wasUpdated ? 'Updated ' : '') . timeAgo($wasUpdated ? $r['updated_at'] : $r['created_at']),
                'is_new'    => ($createdTs >= time() - 3600),
            ];
        }, $rows);
        jsonResponse(true, 'OK', ['feed' => $feed, 'server_time' => date('H:i:s')]);

    // ---- CC / Notify Others autocomplete (registered users only) ----
    case 'cc_search':
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 2) {
            jsonResponse(true, 'OK', ['users' => []]);
        }
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare(
            "SELECT id, name, email
             FROM users
             WHERE is_active = 1
               AND id != ?
               AND (name LIKE ? OR email LIKE ?)
             ORDER BY name
             LIMIT 8"
        );
        $stmt->execute([(int)$user['id'], $like, $like]);
        jsonResponse(true, 'OK', ['users' => $stmt->fetchAll()]);

    default:
        jsonResponse(false, 'Unknown action.', [], 400);
}
