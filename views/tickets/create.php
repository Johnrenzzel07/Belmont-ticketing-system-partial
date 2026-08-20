<?php
/**
 * Create Ticket
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireLogin();

$pdo    = db();
$user   = currentUser();
$errors = [];
$formData = [];

// Load dropdowns
$departments = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$categories  = $pdo->query("SELECT id, name, department_id FROM categories WHERE is_active=1 ORDER BY name")->fetchAll();
// Staff and regular-user accounts only — admins are excluded from the
// Assign To dropdown (they manage the system but don't take ticket queues).
$staffList   = isStaff()
    ? $pdo->query("SELECT id, name, role, department_id FROM users WHERE role IN ('staff','user') AND is_active=1 ORDER BY name")->fetchAll()
    : [];

// Default form data — pre-fill user's own department on fresh load
$formData = [
    'subject'       => '',
    'description'   => '',
    'priority'      => 'medium',
    'department_id' => (int)($user['dept_id'] ?? 0),
    'category_id'   => 0,
    'assigned_to'   => null,
    'due_date'      => '',
    'source'        => 'web',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate
    $formData = [
        'subject'       => trim($_POST['subject']       ?? ''),
        'description'   => trim($_POST['description']   ?? ''),
        'priority'      => $_POST['priority']           ?? 'medium',
        'department_id' => (int)($_POST['department_id']?? 0),
        'category_id'   => (int)($_POST['category_id'] ?? 0),
        'assigned_to'   => (int)($_POST['assigned_to']  ?? 0) ?: null,
        'due_date'      => $_POST['due_date']            ?? '',
        'source'        => 'web',
    ];

    if (empty($formData['subject']))     $errors[] = 'Subject is required.';
    if (empty($formData['description'])) $errors[] = 'Description is required.';
    if (!in_array($formData['priority'], ['low','medium','high','critical'])) $errors[] = 'Invalid priority.';

    if (empty($errors)) {
        $ticketCode = generateTicketCode();

        // ---- AI Auto-Routing: classify department when none was chosen ----
        $aiServerRouted = false;
        $aiConfidence   = (float)($_POST['ai_confidence'] ?? 0);
        $aiSuggestedDept = (int)($_POST['ai_suggested_dept'] ?? 0) ?: null;
        if (!$formData['department_id']) {
            $cls = aiClassifyDepartment($pdo, $formData['subject'] . ' ' . $formData['description']);
            if ($cls['department_id'] && $cls['confidence'] >= 0.45) {
                $formData['department_id'] = $cls['department_id'];
                $aiServerRouted = true;
                $aiConfidence   = $cls['confidence'];
                $aiSuggestedDept = $cls['department_id'];
            }
        }

        $slaHoursMap = ['critical' => 4, 'high' => 8, 'medium' => 24, 'low' => 48];
        $slaHours    = $slaHoursMap[$formData['priority']] ?? 24;

        $stmt = $pdo->prepare(
            "INSERT INTO tickets
             (ticket_code, user_id, department_id, category_id, assigned_to,
              subject, description, priority, source, due_date, sla_hours, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')"
        );
        $stmt->execute([
            $ticketCode,
            $user['id'],
            $formData['department_id'] ?: null,
            $formData['category_id']   ?: null,
            $formData['assigned_to'],
            $formData['subject'],
            $formData['description'],
            $formData['priority'],
            $formData['source'],
            $formData['due_date'] ?: null,
            $slaHours,
        ]);

        $ticketId = (int)$pdo->lastInsertId();

        // ---- Log AI routing decision (for admin review) ----
        if ($aiSuggestedDept) {
            $accepted = ((int)$formData['department_id'] === $aiSuggestedDept) ? 1 : 0;
            $aiSuggestedPriority = in_array($_POST['ai_suggested_priority'] ?? '', ['low','medium','high','critical'])
                ? $_POST['ai_suggested_priority'] : null;
            try {
                $pdo->prepare(
                    "INSERT INTO auto_routing_logs
                     (ticket_id, suggested_department_id, suggested_priority, confidence, accepted, method)
                     VALUES (?,?,?,?,?,?)"
                )->execute([
                    $ticketId, $aiSuggestedDept, $aiSuggestedPriority,
                    round($aiConfidence * 100, 2), $accepted,
                    $aiServerRouted ? 'keyword_server' : 'keyword_client',
                ]);
            } catch (Exception $e) { /* non-fatal */ }
        }

        // ---- Mark duplicate check as "proceeded anyway" ----
        $dupCheckId = (int)($_POST['dup_check_id'] ?? 0);
        if ($dupCheckId) {
            try {
                $pdo->prepare("UPDATE duplicate_checks SET proceeded = 1 WHERE id = ? AND user_id = ?")
                    ->execute([$dupCheckId, $user['id']]);
            } catch (Exception $e) { /* non-fatal */ }
        }

        // AI auto-assign: pick the least-loaded active member of the handling
        // department (any role — the requester is excluded so people don't get
        // assigned their own tickets). Staff roles are preferred on ties.
        if (!$formData['assigned_to'] && $formData['department_id']) {
            $autoAssignee = aiPickAssignee($pdo, (int)$formData['department_id'], (int)$user['id']);
            if ($autoAssignee) {
                $pdo->prepare("UPDATE tickets SET assigned_to = ?, status = 'open' WHERE id = ?")
                    ->execute([$autoAssignee, $ticketId]);
                $formData['assigned_to'] = $autoAssignee;
            }
        }

        // Handle attachment
        if (!empty($_FILES['attachment']['name'])) {
            $file = $_FILES['attachment'];
            if ($file['error'] === UPLOAD_ERR_OK && $file['size'] <= UPLOAD_MAX_SIZE) {
                if (in_array($file['type'], UPLOAD_ALLOWED)) {
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
                    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $stored)) {
                        $pdo->prepare(
                            "INSERT INTO attachments (ticket_id, user_id, filename, stored_name, mime_type, file_size)
                             VALUES (?,?,?,?,?,?)"
                        )->execute([$ticketId, $user['id'], $file['name'], $stored, $file['type'], $file['size']]);
                    }
                }
            }
        }

        // Notifications
        if ($formData['assigned_to']) {
            createNotification(
                $formData['assigned_to'], $ticketId, 'assigned',
                "Ticket $ticketCode has been assigned to you."
            );
        }

        // ---- Department-wide broadcast (shared inbox behavior) ----
        // Every active member of the handling department gets notified,
        // mirroring the company's one-shared-email-per-department setup.
        if ($formData['department_id']) {
            notifyDepartment(
                $pdo, (int)$formData['department_id'], $ticketId, 'new_ticket',
                "New ticket $ticketCode for your department: " . mb_strimwidth($formData['subject'], 0, 60, '...'),
                (int)$user['id']
            );

            // Group email to all department members (Reply-To = shared inbox)
            sendDeptMailForTicket(
                $pdo, $ticketId,
                "[{$ticketCode}] New Ticket: " . mb_strimwidth($formData['subject'], 0, 60, '...'),
                mailTemplate(
                    "New Ticket {$ticketCode}",
                    '<p><strong>' . e($user['name']) . '</strong> submitted a new ticket for your department.</p>'
                    . '<p><strong>Subject:</strong> ' . e($formData['subject']) . '</p>'
                    . '<p><strong>Priority:</strong> ' . ucfirst($formData['priority']) . '</p>'
                    . '<p>' . nl2br(e(mb_strimwidth($formData['description'], 0, 300, '...'))) . '</p>'
                    . '<a class="btn" href="' . APP_URL . '/views/tickets/view.php?id=' . $ticketId . '">Open Ticket</a>'
                ),
                (int)$user['id']
            );

            // Non-staff requester: auto-add the department's staff as watchers so the
            // whole team keeps receiving updates on this ticket (group inbox behavior)
            if (!isStaff()) {
                try {
                    $deptStaff = $pdo->prepare(
                        "SELECT id FROM users
                         WHERE department_id = ? AND role IN ('staff','admin') AND is_active = 1"
                    );
                    $deptStaff->execute([(int)$formData['department_id']]);
                    $watchIns = $pdo->prepare(
                        "INSERT IGNORE INTO ticket_watchers (ticket_id, user_id, added_by) VALUES (?,?,?)"
                    );
                    foreach ($deptStaff->fetchAll() as $ds) {
                        if ((int)$ds['id'] !== (int)$user['id']) {
                            $watchIns->execute([$ticketId, $ds['id'], $user['id']]);
                        }
                    }
                } catch (Exception $e) { /* non-fatal */ }
            }
        }

        // ---- AI learning: remember this ticket as a suggestion template ----
        // New, sufficiently-unique tickets become entries in the smart subject
        // dropdown so future requesters get them as ready-made templates.
        aiLearnTemplate($pdo, $formData['subject'], $formData['description'],
            (int)$formData['department_id'] ?: null);

        // Notify admins outside the handling department (they didn't get the dept broadcast)
        if ($user['role'] !== 'admin') {
            $admins = $pdo->prepare(
                "SELECT id FROM users WHERE role='admin' AND is_active=1
                 AND (department_id IS NULL OR department_id != ?)"
            );
            $admins->execute([(int)$formData['department_id'] ?: 0]);
            foreach ($admins->fetchAll() as $admin) {
                createNotification($admin['id'], $ticketId, 'new_ticket',
                    "New ticket $ticketCode submitted by {$user['name']}.");
            }
        }

        // ---- CC additional users as watchers ----
        $ccRaw = trim($_POST['cc_users'] ?? '');
        if ($ccRaw !== '') {
            $tokens = array_filter(array_map('trim', preg_split('/[,;]+/', $ccRaw)));
            $resolver = $pdo->prepare(
                "SELECT id, name, email FROM users WHERE is_active = 1 AND (email = ? OR name LIKE ?) LIMIT 1"
            );
            $ccMailHtml = mailTemplate(
                "You were CC'd on ticket {$ticketCode}",
                '<p><strong>' . e($user['name']) . '</strong> added you to ticket '
                . '<strong>' . e($ticketCode) . '</strong> so you stay informed of updates.</p>'
                . '<p><strong>Subject:</strong> ' . e($formData['subject']) . '</p>'
                . '<p><strong>Priority:</strong> ' . ucfirst($formData['priority']) . '</p>'
                . '<a class="btn" href="' . APP_URL . '/views/tickets/view.php?id=' . $ticketId . '">Open Ticket</a>'
            );
            $ccMailed = 0;
            $ccMailFailed = 0;
            foreach (array_slice($tokens, 0, 10) as $token) {
                $resolver->execute([$token, '%' . $token . '%']);
                $cc = $resolver->fetch();
                if ($cc && (int)$cc['id'] !== (int)$user['id']) {
                    try {
                        $pdo->prepare(
                            "INSERT IGNORE INTO ticket_watchers (ticket_id, user_id, added_by) VALUES (?,?,?)"
                        )->execute([$ticketId, $cc['id'], $user['id']]);
                        createNotification((int)$cc['id'], $ticketId, 'watching',
                            "{$user['name']} added you as a watcher on ticket $ticketCode.");
                        if (sendMail(
                            ['email' => $cc['email'], 'name' => $cc['name']],
                            "[{$ticketCode}] You were CC'd on a ticket",
                            $ccMailHtml
                        )) {
                            $ccMailed++;
                        } else {
                            $ccMailFailed++;
                        }
                    } catch (Exception $e) { /* non-fatal */ }
                }
            }
        }

        logActivity($user['id'], $ticketId, 'ticket_created', "Created ticket $ticketCode", getClientIp());
        $okMsg = "Ticket $ticketCode created successfully!";
        if (!empty($ccMailFailed)) {
            $okMsg .= ' CC email could not be sent (' . mailLastError() . '). Check Settings → Outgoing Email.';
        } elseif (!empty($ccMailed)) {
            $okMsg .= ' CC notification email was sent.';
        }
        flashMessage(!empty($ccMailFailed) ? 'warning' : 'success', $okMsg);
        header('Location: ' . APP_URL . '/views/tickets/view.php?id=' . $ticketId);
        exit;
    }
}

$pageTitle = 'New Ticket';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/tickets/index.php">Tickets</a></li>
                <li class="breadcrumb-item active">New Ticket</li>
            </ol>
        </nav>
        <h1 class="page-title">Submit New Ticket</h1>
        <p class="page-subtitle">Describe your issue and our team will respond shortly.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-3">
    <strong>Please fix the following errors:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">

            <!-- Subject & Priority -->
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-ticket me-2 text-primary"></i>Ticket Information</div>
                <div class="card-body">
                    <div class="mb-3 position-relative">
                        <label for="subject" class="form-label required">Subject</label>
                        <div class="input-group">
                            <input type="text" id="subject" name="subject" class="form-control"
                                   placeholder="Brief description of the issue"
                                   value="<?= e($formData['subject'] ?? '') ?>" required maxlength="255"
                                   autocomplete="off">
                            <button type="button" class="btn btn-outline-primary" id="aiWriteBtn"
                                    title="Let the AI turn your draft into a complete ticket">
                                <i class="bi bi-magic me-1"></i>Write with AI
                            </button>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-stars text-primary"></i>
                            Start typing — smart templates appear. Or type a rough draft and click
                            <strong>Write with AI</strong> to generate the full ticket.
                            <span id="aiModelBadge" class="badge bg-success-subtle text-success d-none"
                                  style="font-size:.62rem"></span>
                        </div>
                        <!-- AI Subject Suggestions -->
                        <div id="subjectSuggest" style="display:none;position:absolute;z-index:1050;left:0;right:0;
                             background:#fff;border:1px solid var(--border-light,#e2e8f0);border-radius:8px;
                             box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:280px;overflow-y:auto"></div>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label required">Description</label>
                        <textarea id="description" name="description" rows="7" class="form-control"
                                  placeholder="Provide detailed information about your issue..."
                                  required><?= e($formData['description'] ?? '') ?></textarea>
                        <div class="form-text">Include steps to reproduce, error messages, screenshots, etc.</div>
                    </div>
                    <div class="mb-0 cc-field">
                        <label for="ccUsers" class="form-label">CC / Notify Others <span class="text-muted">(optional)</span></label>
                        <input type="text" id="ccUsers" name="cc_users" class="form-control"
                               placeholder="Type a name or email to search users..."
                               autocomplete="off">
                        <div id="ccSuggest" class="cc-suggest" style="display:none"></div>
                        <div class="form-text">Registered users of this system (their work or Gmail address). They get an in-app alert and an email when email is enabled.</div>
                    </div>

                    <!-- AI Routing Suggestion Banner -->
                    <div id="aiRouteBanner" class="alert alert-info d-none align-items-center justify-content-between mt-3 mb-0"
                         style="font-size:.84rem">
                        <div>
                            <i class="bi bi-robot me-1"></i>
                            <span id="aiRouteText"></span>
                        </div>
                        <div class="d-flex gap-2 flex-shrink-0 ms-2">
                            <button type="button" class="btn btn-sm btn-primary" id="aiRouteAccept">Apply</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="aiRouteDismiss">Dismiss</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attachment -->
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-paperclip me-2 text-primary"></i>Attachment</div>
                <div class="card-body">
                    <div class="upload-wrapper">
                        <input type="file" name="attachment" id="attachFile"
                               accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx"
                               class="d-none">
                        <div class="upload-area">
                            <i class="bi bi-cloud-upload"></i>
                            <div>Click to browse or drag &amp; drop</div>
                            <small class="text-muted d-block mt-1">Supports: JPG, PNG, PDF, DOC, XLS (max 10MB)</small>
                        </div>
                        <div id="createFilePreview" class="mt-2 small text-muted"></div>
                    </div>
                </div>
            </div>

            <!-- Hidden AI metadata -->
            <input type="hidden" name="ai_suggested_dept" id="aiSuggestedDept" value="">
            <input type="hidden" name="ai_suggested_priority" id="aiSuggestedPriority" value="">
            <input type="hidden" name="ai_confidence" id="aiConfidence" value="">
            <input type="hidden" name="dup_check_id" id="dupCheckId" value="">

            <!-- Duplicate Warning Banner -->
            <div id="dupBanner" class="alert alert-warning mb-3" style="display:none;font-size:.86rem">
                <div class="d-flex align-items-start gap-2">
                    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                    <div class="flex-grow-1">
                        <strong>A similar ticket already exists:</strong>
                        <div id="dupDetails" class="mt-1"></div>
                        <div class="d-flex gap-2 mt-2">
                            <a href="#" id="dupViewLink" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye me-1"></i>View Existing Ticket
                            </a>
                            <button type="button" class="btn btn-sm btn-warning" id="dupContinue">
                                Continue Submitting Anyway
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-lg" id="submitTicketBtn">
                    <i class="bi bi-send me-1"></i>Submit Ticket
                </button>
                <a href="<?= APP_URL ?>/views/tickets/index.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Right Column: Options -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-sliders me-2 text-primary"></i>Ticket Options</div>
            <div class="card-body">
                <form id="optionsProxy">
                    <div class="mb-3">
                        <label class="form-label required">Priority</label>
                        <select name="priority" id="prioritySelect" class="form-select">
                            <option value="low"      <?= ($formData['priority']??'medium')==='low'?'selected':'' ?>>Low</option>
                            <option value="medium"   <?= ($formData['priority']??'medium')==='medium'?'selected':'' ?>>Medium</option>
                            <option value="high"     <?= ($formData['priority']??'')==='high'?'selected':'' ?>>High</option>
                            <option value="critical" <?= ($formData['priority']??'')==='critical'?'selected':'' ?>>Critical</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select name="department_id" id="deptSelect" class="form-select">
                            <option value="">-- Select Department --</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= ($formData['department_id']??0)==$d['id']?'selected':'' ?>>
                                <?= e($d['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="category_id" id="catSelect" class="form-select">
                            <option value="">-- Select Category --</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" data-dept="<?= $c['department_id'] ?>"
                                    <?= ($formData['category_id']??0)==$c['id']?'selected':'' ?>>
                                <?= e($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (isStaff()): ?>
                    <div class="mb-3">
                        <label class="form-label">Assign To</label>
                        <select name="assigned_to" id="assignSelect" class="form-select">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($staffList as $s): ?>
                            <option value="<?= $s['id'] ?>" data-dept="<?= $s['department_id'] ?? '' ?>"
                                    <?= ($formData['assigned_to']??0)==$s['id']?'selected':'' ?>>
                                <?= e($s['name']) ?><?= in_array($s['role'], ['admin','staff']) ? ' (' . ucfirst($s['role']) . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Filtered by the selected department.</div>
                    </div>

                    <?php endif; ?>
                    <div class="mb-0">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-control"
                               min="<?= date('Y-m-d') ?>"
                               value="<?= e($formData['due_date']??'') ?>">
                        <div class="form-text">Leave blank if no specific deadline.</div>
                    </div>
                </form>
            </div>
        </div>

        <!-- KB Self-Service Panel -->
        <div class="card" id="kbPanel" style="display:none">
            <div class="card-header">
                <i class="bi bi-lightbulb me-2 text-warning"></i>Before submitting, these may help you
            </div>
            <div class="card-body" id="kbPanelBody"></div>
            <div class="card-footer text-center" style="font-size:.8rem">
                <button type="button" class="btn btn-sm btn-outline-success" id="kbResolvedBtn">
                    <i class="bi bi-check-circle me-1"></i>My issue is resolved — cancel this ticket
                </button>
            </div>
        </div>
    </div>
</div>

<div id="ticketSubmitOverlay" class="ticket-submit-overlay" hidden aria-live="assertive" aria-busy="true">
    <div class="ticket-submit-card">
        <div class="ticket-submit-spinner" role="status" aria-label="Loading"></div>
        <h3>Submitting your ticket</h3>
        <p>Please wait. Do not close or refresh this page.</p>
    </div>
</div>

<script>
// Sync options from right panel to main form (update value on every submit attempt)
document.querySelector('[type="submit"]').closest('form').addEventListener('submit', function() {
    const form = this;
    ['priority','department_id','category_id','assigned_to','due_date'].forEach(function(name) {
        const src = document.querySelector('#optionsProxy [name="' + name + '"]');
        if (!src) return;
        let dst = form.querySelector('[name="' + name + '"]');
        if (!dst) {
            dst = document.createElement('input');
            dst.type = 'hidden';
            dst.name = name;
            form.appendChild(dst);
        }
        dst.value = src.value;
    });
});

// File preview
document.getElementById('attachFile')?.addEventListener('change', function() {
    const file = this.files[0];
    if (file) {
        document.getElementById('createFilePreview').innerHTML =
            '<i class="bi bi-file-earmark me-1"></i>' + file.name +
            ' <span class="text-muted">(' + (file.size/1024).toFixed(1) + ' KB)</span>';
    }
});

// Dynamic category filter by department
function filterCategoriesByDept(deptId) {
    const $cat = document.getElementById('catSelect');
    Array.from($cat.options).forEach(function(opt) {
        if (!opt.value) return; // keep "-- Select Category --"
        // Hide all if no department selected, otherwise filter by dept
        opt.hidden = !deptId || opt.dataset.dept != deptId;
    });
    // Reset category if the current selection belongs to a different dept
    const selected = $cat.options[$cat.selectedIndex];
    if (selected && selected.value && deptId && selected.dataset.dept != deptId) {
        $cat.value = '';
    }
}

// Dynamic assignee filter by department — every department's members are
// assignable; admins stay visible regardless of department.
function filterStaffByDept(deptId) {
    const $assign = document.getElementById('assignSelect');
    if (!$assign) return; // non-staff users don't have this field
    Array.from($assign.options).forEach(function(opt) {
        if (!opt.value) return; // keep "-- Unassigned --"
        opt.hidden = deptId ? opt.dataset.dept != deptId : false;
    });
    const selected = $assign.options[$assign.selectedIndex];
    if (selected && selected.value && selected.hidden) {
        $assign.value = '';
    }
}

document.getElementById('deptSelect')?.addEventListener('change', function() {
    filterCategoriesByDept(this.value);
    filterStaffByDept(this.value);
});

// Run on page load to apply filters if department is already pre-selected
(function() {
    const deptSel = document.getElementById('deptSelect');
    if (deptSel) {
        filterCategoriesByDept(deptSel.value);
        filterStaffByDept(deptSel.value);
    }
})();

/* ============================================================
   AI-POWERED SMART TICKET CREATION
   ============================================================ */
const escHtml = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const mainForm = document.getElementById('submitTicketBtn').closest('form');

/* ---- 1. Smart Subject Suggestions (department-aware) ---- */
let sugTimer = null;
let suggestions = [];

function fetchSubjectSuggestions(q) {
    const deptId = document.getElementById('deptSelect')?.value || '';
    fetch(APP_URL + '/api/ai.php?action=subject_suggest&q=' + encodeURIComponent(q) + '&dept_id=' + deptId)
        .then(r => r.json())
        .then(r => {
            const $box = document.getElementById('subjectSuggest');
            if (!r.success || !r.suggestions.length) { $box.style.display = 'none'; return; }
            suggestions = r.suggestions;
            let html = '<div style="padding:.5rem .75rem;font-size:.68rem;font-weight:700;text-transform:uppercase;'
                     + 'letter-spacing:.05em;color:#94a3b8;border-bottom:1px solid #f1f5f9">'
                     + '<i class="bi bi-stars me-1"></i>Suggested Templates</div>';
            r.suggestions.forEach((s, i) => {
                html += `<button type="button" class="subj-sug" data-idx="${i}" style="display:block;width:100%;
                         text-align:left;background:none;border:none;border-bottom:1px solid #f8fafc;
                         padding:.55rem .75rem;cursor:pointer;font-size:.84rem">
                    <i class="bi bi-lightning-charge text-primary me-1"></i>${escHtml(s.subject)}
                </button>`;
            });
            $box.innerHTML = html;
            $box.style.display = 'block';
        }).catch(() => {});
}

document.getElementById('subject')?.addEventListener('input', function() {
    clearTimeout(sugTimer);
    const q = this.value.trim();
    sugTimer = setTimeout(() => { fetchSubjectSuggestions(q); searchKb(q); }, 300);
});
document.getElementById('subject')?.addEventListener('focus', function() {
    if (this.value.trim().length === 0) fetchSubjectSuggestions('');
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('#subjectSuggest') && e.target.id !== 'subject') {
        document.getElementById('subjectSuggest').style.display = 'none';
    }
});
document.getElementById('subjectSuggest')?.addEventListener('click', function(e) {
    const btn = e.target.closest('.subj-sug');
    if (!btn) return;
    const s = suggestions[parseInt(btn.dataset.idx)];
    if (!s) return;
    document.getElementById('subject').value = s.subject;
    const $desc = document.getElementById('description');
    if (s.template && $desc.value.trim() === '') {
        $desc.value = s.template;
    }
    this.style.display = 'none';
    classifyTicket();
    $desc.focus();
});

/* ---- 1b. "Write with AI" — full template via free LLM (Groq) ---- */
// Show the model badge when the AI is configured.
// Deferred to DOMContentLoaded: APP_URL/CSRF_TOKEN are declared in the footer,
// which loads after this inline script.
document.addEventListener('DOMContentLoaded', function() {
    fetch(APP_URL + '/api/ai.php?action=ai_status')
        .then(r => r.json())
        .then(r => {
            if (r.success && r.llm) {
                const badge = document.getElementById('aiModelBadge');
                badge.textContent = 'AI online: ' + r.model;
                badge.classList.remove('d-none');
            }
        })
        .catch(() => {});

    // ---- Apply a ticket draft handed over by the floating AI assistant ----
    try {
        const raw = sessionStorage.getItem('aiTicketDraft');
        if (raw) {
            sessionStorage.removeItem('aiTicketDraft');
            const t = JSON.parse(raw);
            if (t && t.subject) {
                document.getElementById('subject').value = t.subject;
                document.getElementById('description').value = t.description || '';

                const deptSel = document.getElementById('deptSelect');
                if (t.department_id && deptSel) {
                    deptSel.value = t.department_id;
                    filterCategoriesByDept(String(t.department_id));
                }
                const catSel = document.getElementById('catSelect');
                if (t.category_id && catSel) catSel.value = t.category_id;

                const assignSel = document.getElementById('assignSelect');
                if (assignSel && t.department_id) filterStaffByDept(String(t.department_id));

                if (t.priority) document.getElementById('prioritySelect').value = t.priority;

                const dueInput = document.querySelector('#optionsProxy [name="due_date"]');
                if (t.due_date && dueInput) dueInput.value = t.due_date;

                if (t.department_id) document.getElementById('aiSuggestedDept').value = t.department_id;
                if (t.priority)      document.getElementById('aiSuggestedPriority').value = t.priority;
                document.getElementById('aiConfidence').value = '0.9';

                showToast('success', 'Draft from Belmont Assist applied. Review and fill in any [bracketed] parts before submitting.');
                document.getElementById('description').focus();
            }
        }
    } catch (e) { /* draft is optional */ }
});

let aiWriting = false;
document.getElementById('aiWriteBtn').addEventListener('click', function() {
    if (aiWriting) return;
    const $subject = document.getElementById('subject');
    const $desc    = document.getElementById('description');
    const draft    = ($subject.value.trim() + ' ' + $desc.value.trim()).trim();

    if (draft.length < 4) {
        showToast('warning', 'Type a few words about your issue first, then click Write with AI.');
        $subject.focus();
        return;
    }
    if ($desc.value.trim() !== '' &&
        !confirm('The AI will rewrite your description into a structured ticket. Continue?')) {
        return;
    }

    aiWriting = true;
    const btn  = this;
    const orig = btn.innerHTML;
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Writing...';
    document.getElementById('subjectSuggest').style.display = 'none';

    $.post(APP_URL + '/api/ai.php', {
        action: 'ai_template',
        draft: draft,
        dept_id: document.getElementById('deptSelect')?.value || '',
        _csrf: CSRF_TOKEN
    }, function(r) {
        if (r.success && r.template) {
            const t = r.template;
            $subject.value = t.subject;
            $desc.value    = t.description;

            const applied = [];
            if (r.source === 'llm') {
                // Department
                const deptSel = document.getElementById('deptSelect');
                if (t.department_id && deptSel) {
                    deptSel.value = t.department_id;
                    filterCategoriesByDept(String(t.department_id));
                    if (t.department_name) applied.push('Dept: ' + t.department_name);
                }
                // Category (after the dept filter so the option is visible)
                const catSel = document.getElementById('catSelect');
                if (t.category_id && catSel) {
                    catSel.value = t.category_id;
                    if (t.category_name) applied.push('Category: ' + t.category_name);
                }
                // Auto-assign (staff only — the field doesn't exist for requesters)
                const assignSel = document.getElementById('assignSelect');
                if (t.assignee_id && assignSel) {
                    if (t.department_id) filterStaffByDept(String(t.department_id));
                    assignSel.value = t.assignee_id;
                    if (t.assignee_name) applied.push('Assigned: ' + t.assignee_name);
                }
                // Priority
                if (t.priority) {
                    document.getElementById('prioritySelect').value = t.priority;
                    applied.push('Priority: ' + t.priority.charAt(0).toUpperCase() + t.priority.slice(1));
                }
                // Due date
                const dueInput = document.querySelector('#optionsProxy [name="due_date"]');
                if (t.due_date && dueInput) {
                    dueInput.value = t.due_date;
                    applied.push('Due: ' + t.due_date);
                }
                // Mirror into the AI metadata fields for routing logs
                if (t.department_id) document.getElementById('aiSuggestedDept').value = t.department_id;
                if (t.priority)      document.getElementById('aiSuggestedPriority').value = t.priority;
                document.getElementById('aiConfidence').value = '0.9';
            } else {
                classifyTicket();
            }

            showToast(r.source === 'llm' ? 'success' : 'info',
                r.source === 'llm'
                    ? 'Ticket written by AI' + (applied.length ? ' — ' + applied.join(' • ') : '')
                      + '. Review and fill in any [bracketed] parts.'
                    : r.message);
            $desc.focus();
        } else {
            showToast('danger', r.message || 'AI generation failed.');
        }
    }, 'json').fail(function() {
        showToast('danger', 'AI request failed. The free model may be busy — try again in a moment.');
    }).always(function() {
        aiWriting = false;
        btn.disabled  = false;
        btn.innerHTML = orig;
    });
});

/* ---- 2. AI Auto Department Routing + Priority Suggestion ---- */
let aiSuggestion = null;
let aiDismissed  = false;

function classifyTicket() {
    const subject = document.getElementById('subject').value.trim();
    const description = document.getElementById('description').value.trim();
    if (subject.length + description.length < 8 || aiDismissed) return;

    $.post(APP_URL + '/api/ai.php', { action: 'classify', subject: subject, description: description }, function(r) {
        if (!r.success) return;
        aiSuggestion = r;
        const parts = [];
        const deptSel = document.getElementById('deptSelect');
        const prioSel = document.getElementById('prioritySelect');

        if (r.department) {
            document.getElementById('aiSuggestedDept').value = r.department.id;
            document.getElementById('aiConfidence').value = r.department.confidence;
            // High confidence + nothing selected -> apply silently
            if (r.department.confidence >= 0.7 && !deptSel.value) {
                deptSel.value = r.department.id;
                deptSel.dispatchEvent(new Event('change'));
                showToast('info', 'Department auto-set to ' + r.department.name + ' (AI)');
            } else if (deptSel.value != r.department.id) {
                parts.push('route this to <strong>' + escHtml(r.department.name) + '</strong>'
                    + ' <span class="text-muted">(' + Math.round(r.department.confidence * 100) + '% match)</span>');
            }
        }
        if (r.priority) {
            document.getElementById('aiSuggestedPriority').value = r.priority;
            if (prioSel.value !== r.priority) {
                parts.push('set priority to <strong>' + escHtml(r.priority.charAt(0).toUpperCase() + r.priority.slice(1)) + '</strong>');
            }
        }

        const $banner = $('#aiRouteBanner');
        if (parts.length) {
            $('#aiRouteText').html('Based on your text, we suggest to ' + parts.join(' and ') + '.');
            $banner.removeClass('d-none').addClass('d-flex');
        } else {
            $banner.removeClass('d-flex').addClass('d-none');
        }
    }, 'json');
}

document.getElementById('description')?.addEventListener('blur', classifyTicket);
document.getElementById('subject')?.addEventListener('blur', function() { setTimeout(classifyTicket, 250); });

document.getElementById('aiRouteAccept')?.addEventListener('click', function() {
    if (!aiSuggestion) return;
    if (aiSuggestion.department) {
        const deptSel = document.getElementById('deptSelect');
        deptSel.value = aiSuggestion.department.id;
        deptSel.dispatchEvent(new Event('change'));
    }
    if (aiSuggestion.priority) {
        document.getElementById('prioritySelect').value = aiSuggestion.priority;
    }
    $('#aiRouteBanner').removeClass('d-flex').addClass('d-none');
    showToast('success', 'AI suggestion applied.');
});
document.getElementById('aiRouteDismiss')?.addEventListener('click', function() {
    aiDismissed = true;
    $('#aiRouteBanner').removeClass('d-flex').addClass('d-none');
});

/* ---- 6. Duplicate Ticket Detection (pre-submit) ---- */
let dupChecked = false;
let ticketSubmitting = false;

function showTicketSubmitLoading() {
    if (ticketSubmitting) return;
    ticketSubmitting = true;
    const overlay = document.getElementById('ticketSubmitOverlay');
    if (overlay) overlay.hidden = false;
    document.body.classList.add('ticket-submit-busy');
    const btn = document.getElementById('submitTicketBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting...';
    }
}

mainForm.addEventListener('submit', function(e) {
    if (ticketSubmitting) {
        e.preventDefault();
        return;
    }
    if (dupChecked) {
        showTicketSubmitLoading();
        return;
    }
    e.preventDefault();
    const subject = document.getElementById('subject').value.trim();
    const deptId  = document.getElementById('deptSelect')?.value || '';

    $.post(APP_URL + '/api/ai.php', { action: 'duplicate_check', subject: subject, department_id: deptId }, function(r) {
        if (r.success && r.duplicate) {
            const d = r.duplicate;
            document.getElementById('dupCheckId').value = d.check_id;
            document.getElementById('dupDetails').innerHTML =
                '<code>' + escHtml(d.ticket_code) + '</code> — ' + escHtml(d.subject)
                + ' <span class="badge bg-secondary">' + escHtml(d.status.replace('_',' ')) + '</span>'
                + ' <span class="text-muted">(' + Math.round(d.similarity) + '% similar)</span>';
            document.getElementById('dupViewLink').href = APP_URL + '/views/tickets/view.php?id=' + d.ticket_id;
            document.getElementById('dupBanner').style.display = 'block';
            document.getElementById('dupBanner').scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            dupChecked = true;
            mainForm.requestSubmit();
        }
    }, 'json').fail(function() {
        dupChecked = true;
        mainForm.requestSubmit();
    });
});

document.getElementById('dupContinue')?.addEventListener('click', function() {
    dupChecked = true;
    document.getElementById('dupBanner').style.display = 'none';
    mainForm.requestSubmit();
});

/* ---- 8. KB Self-Service Side Panel + Deflection ---- */
let kbBest = null;

function searchKb(q) {
    if (q.length < 4) { document.getElementById('kbPanel').style.display = 'none'; return; }
    fetch(APP_URL + '/api/kb.php?action=search&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(r => {
            const $panel = document.getElementById('kbPanel');
            const results = (r.results || []);
            if (!r.success || !results.length) { $panel.style.display = 'none'; kbBest = null; return; }
            kbBest = results[0];
            let html = '';
            results.forEach(a => {
                html += `<a href="${APP_URL}/views/kb/article.php?slug=${encodeURIComponent(a.slug)}"
                            target="_blank" class="d-block text-decoration-none mb-2 pb-2"
                            style="border-bottom:1px solid var(--border-light,#f1f5f9)">
                    <div style="font-weight:600;font-size:.83rem"><i class="bi bi-file-text me-1 text-primary"></i>${escHtml(a.title)}</div>
                    <div class="text-muted" style="font-size:.75rem">${escHtml((a.excerpt || '').slice(0, 90))}...</div>
                </a>`;
            });
            document.getElementById('kbPanelBody').innerHTML = html;
            $panel.style.display = 'block';
        }).catch(() => {});
}

document.getElementById('kbResolvedBtn')?.addEventListener('click', function() {
    $.post(APP_URL + '/api/ai.php', {
        action: 'kb_deflect',
        kb_article_id: kbBest ? kbBest.id : '',
        subject_typed: document.getElementById('subject').value.trim(),
        _csrf: CSRF_TOKEN
    }, function(r) {
        showToast('success', 'Great! Glad the knowledge base helped.');
        setTimeout(() => { window.location.href = APP_URL + '/index.php'; }, 800);
    }, 'json');
});

/* ---- 9. CC / Notify Others autocomplete ---- */
(function() {
    const $input = document.getElementById('ccUsers');
    const $box   = document.getElementById('ccSuggest');
    if (!$input || !$box) return;

    let ccTimer = null;

    function currentToken() {
        const parts = $input.value.split(/[,;]/);
        return (parts[parts.length - 1] || '').trim();
    }

    function insertCcEmail(email) {
        const parts = $input.value.split(/[,;]/).map(s => s.trim()).filter(Boolean);
        if (parts.length) parts.pop();
        if (!parts.some(p => p.toLowerCase() === email.toLowerCase())) {
            parts.push(email);
        }
        $input.value = parts.join(', ') + ', ';
        $box.style.display = 'none';
        $input.focus();
    }

    function searchCcUsers(q) {
        if (q.length < 2) { $box.style.display = 'none'; return; }
        fetch(APP_URL + '/api/tickets.php?action=cc_search&q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(r => {
                const users = r.users || [];
                if (!r.success || !users.length) { $box.style.display = 'none'; return; }
                $box.innerHTML = users.map(u => {
                    const initial = escHtml((u.name || '?').charAt(0).toUpperCase());
                    return `<button type="button" class="cc-sug-item" data-email="${escHtml(u.email)}">
                        <span class="cc-sug-avatar">${initial}</span>
                        <span>
                            <span class="cc-sug-name">${escHtml(u.name)}</span>
                            <span class="cc-sug-email">${escHtml(u.email)}</span>
                        </span>
                    </button>`;
                }).join('');
                $box.style.display = 'block';
            }).catch(() => { $box.style.display = 'none'; });
    }

    $input.addEventListener('input', function() {
        clearTimeout(ccTimer);
        ccTimer = setTimeout(() => searchCcUsers(currentToken()), 220);
    });
    $input.addEventListener('focus', function() {
        const q = currentToken();
        if (q.length >= 2) searchCcUsers(q);
    });
    $box.addEventListener('click', function(e) {
        const btn = e.target.closest('.cc-sug-item');
        if (btn) insertCcEmail(btn.dataset.email);
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.cc-field')) $box.style.display = 'none';
    });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
