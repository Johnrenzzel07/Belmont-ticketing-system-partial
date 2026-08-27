<?php
/**
 * Legacy Ticket Migration (Admin / Encoder)
 * Import tickets from the old system using only fields that exist in Belmont Helpdesk.
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$migrationHelpers = __DIR__ . '/../../includes/migration_helpers.php';
if (!is_readable($migrationHelpers)) {
    http_response_code(500);
    die('Migration module is missing on this server. Upload includes/migration_helpers.php to fix this page.');
}
require_once $migrationHelpers;
requireRole('admin');

$pdo  = db();
$user = currentUser();
$errors = [];
$schemaReady = ensureLegacyMigrationSchema($pdo);

$departments = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$categories  = loadCategoriesWithLabels($pdo);
$users       = $pdo->query(
    "SELECT id, name, email, department_id FROM users WHERE is_active=1 ORDER BY name"
)->fetchAll();
$staffList   = $pdo->query(
    "SELECT id, name, role FROM users WHERE role IN ('staff','admin','dept_admin') AND is_active=1 ORDER BY name"
)->fetchAll();

$formData = [
    'legacy_ticket_number' => '',
    'user_id'              => '',
    'department_id'        => '',
    'category_id'          => '',
    'category_name'        => '',
    'priority'             => 'medium',
    'status'               => 'closed',
    'assigned_to'          => '',
    'created_at'           => '',
    'closed_at'            => '',
    'subject'              => '',
    'description'          => '',
    'thread_messages'      => [],
    'thread_paste'         => '',
    'ticket_files'         => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_csrf'] ?? '';
    if (empty($token) || $token !== ($_SESSION['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } elseif (!$schemaReady) {
        $errors[] = 'Migration database columns are not ready. Run database/008_legacy_migration.sql first.';
    } else {
        foreach (array_keys($formData) as $key) {
            if ($key === 'thread_messages' || $key === 'ticket_files') {
                continue;
            }
            $formData[$key] = trim((string)($_POST[$key] ?? ''));
        }

        $formData['ticket_files'] = extractUploadedFileList($_FILES['ticket_files'] ?? null);

        $formData['thread_messages'] = [];
        if (!empty($_POST['thread_messages']) && is_array($_POST['thread_messages'])) {
            foreach ($_POST['thread_messages'] as $idx => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowFiles = extractUploadedFileList($_FILES['thread_files'] ?? null, $idx);
                $formData['thread_messages'][] = [
                    'user_id'     => trim((string)($row['user_id'] ?? '')),
                    'created_at'  => trim((string)($row['created_at'] ?? '')),
                    'message'     => trim((string)($row['message'] ?? '')),
                    'is_internal' => !empty($row['is_internal']),
                    'files'       => $rowFiles,
                ];
            }
        }
        $formData['thread_paste'] = trim((string)($_POST['thread_paste'] ?? ''));

        if ($formData['legacy_ticket_number'] === '') {
            $errors[] = 'Old ticket number is required (e.g. 002469).';
        }
        if (!$formData['user_id']) {
            $errors[] = 'Requester is required.';
        }
        if ($formData['subject'] === '') {
            $errors[] = 'Subject is required.';
        }
        if ($formData['description'] === '') {
            $errors[] = 'Description is required.';
        }
        if ($formData['created_at'] === '') {
            $errors[] = 'Created date is required.';
        }

        if (empty($errors)) {
            try {
                $result = importLegacyTicket($pdo, $formData, (int)$user['id']);
                flashMessage(
                    'success',
                    'Ticket migrated successfully as ' . $result['ticket_code']
                    . ' (old #' . str_pad(normalizeLegacyTicketNumber($formData['legacy_ticket_number']), 6, '0', STR_PAD_LEFT) . ').'
                );
                header('Location: ' . APP_URL . '/views/tickets/view.php?id=' . $result['ticket_id']);
                exit;
            } catch (InvalidArgumentException $e) {
                $errors[] = $e->getMessage();
            } catch (Exception $e) {
                $errors[] = 'Migration failed: ' . $e->getMessage();
            }
        }
    }
}

$search = trim($_GET['q'] ?? '');
$migrated = $schemaReady ? getMigratedTickets($pdo, 30, $search ?: null) : [];
$migratedCount = 0;
if ($schemaReady) {
    $migratedCount = (int)$pdo->query('SELECT COUNT(*) FROM tickets WHERE is_legacy = 1')->fetchColumn();
}

// Pre-fill category name when re-posting with a selected id
if ($formData['category_name'] === '' && $formData['category_id'] !== '') {
    $formData['category_name'] = categoryLabelById($categories, (int)$formData['category_id']);
}

$categoriesJson = categoriesToClientJson($categories);
$threadMessages = $formData['thread_messages'];
if (empty($threadMessages) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $threadMessages = [
        ['user_id' => '', 'created_at' => '', 'message' => '', 'is_internal' => false],
        ['user_id' => '', 'created_at' => '', 'message' => '', 'is_internal' => false],
    ];
}

$pageTitle = 'Migrate Legacy Tickets';
$extraHead = '<style>
.migrate-section-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#64748b);margin:1.25rem 0 .75rem;padding-bottom:.35rem;border-bottom:1px solid var(--border-light,#e2e8f0)}
.migrate-hint{font-size:.75rem;color:var(--text-muted,#64748b)}
.legacy-badge{font-size:.68rem;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;padding:.1rem .45rem;border-radius:4px}
.thread-row{border:1px solid var(--border-light,#e2e8f0);border-radius:10px;padding:.85rem;background:#fafbfd}
</style>';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title mb-1">Migrate Legacy Tickets</h1>
        <p class="text-muted mb-0" style="font-size:.85rem">
            Import old tickets using the same fields as the new ticketing system.
        </p>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="legacy-badge"><i class="bi bi-archive me-1"></i><?= number_format($migratedCount) ?> migrated</span>
        <a href="<?= APP_URL ?>/views/tickets/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-ticket-detailed me-1"></i>All Tickets
        </a>
    </div>
</div>

<?php if (!$schemaReady): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Migration columns are missing. Run <code>database/008_legacy_migration.sql</code> in phpMyAdmin, or reload this page to auto-apply.
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white fw-600">
                <i class="bi bi-box-arrow-in-down-right me-1 text-primary"></i> Migration Form
            </div>
            <div class="card-body">
                <form method="POST" id="migrateForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">

                    <div class="migrate-section-title">Ticket Info</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Old Ticket # <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">#</span>
                                <input type="text" name="legacy_ticket_number" id="legacyTicketNumber"
                                       class="form-control" required placeholder="002469"
                                       value="<?= e($formData['legacy_ticket_number']) ?>">
                            </div>
                            <div class="migrate-hint mt-1">Reference only — prevents duplicate imports</div>
                            <div id="dupWarning" class="text-danger small mt-1" style="display:none"></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" class="form-control" required
                                   placeholder="Ticket title"
                                   value="<?= e($formData['subject']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="4" required
                                      placeholder="Full details of the request"><?= e($formData['description']) ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label d-flex align-items-center justify-content-between mb-1">
                                <span><i class="bi bi-paperclip me-1 text-primary"></i> Ticket Attachments (optional)</span>
                                <span class="migrate-hint">Excel, PDF, Word, images, etc. (max 10MB each)</span>
                            </label>
                            <input type="file" name="ticket_files[]" class="form-control form-control-sm" multiple
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.gif,.webp,.zip">
                            <div class="migrate-hint mt-1">Attach original files or screenshots from the old ticket request</div>
                        </div>
                    </div>

                    <div class="migrate-section-title">People &amp; Routing</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Requester <span class="text-danger">*</span></label>
                            <select name="user_id" id="requesterSelect" class="form-select" required>
                                <option value="">— Select requester —</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"
                                        data-dept="<?= (int)$u['department_id'] ?>"
                                        <?= $formData['user_id'] == $u['id'] ? 'selected' : '' ?>>
                                    <?= e($u['name']) ?> (<?= e($u['email']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assigned To</label>
                            <select name="assigned_to" class="form-select">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($staffList as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $formData['assigned_to'] == $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department_id" id="departmentSelect" class="form-select">
                                <option value="">— Select —</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= $formData['department_id'] == $d['id'] ? 'selected' : '' ?>>
                                    <?= e($d['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="migrate-hint mt-1">Auto-fills from the requester's department</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Category</label>
                            <input type="text" name="category_name" id="categoryInput" class="form-control"
                                   list="categoryDatalist" autocomplete="off"
                                   placeholder="Select existing or type a new category"
                                   value="<?= e($formData['category_name']) ?>">
                            <input type="hidden" name="category_id" id="categoryId"
                                   value="<?= e($formData['category_id']) ?>">
                            <datalist id="categoryDatalist"></datalist>
                            <div class="migrate-hint mt-1">Pick from the list or type a new category name</div>
                        </div>
                    </div>

                    <div class="migrate-section-title">Classification</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Priority</label>
                            <select name="priority" class="form-select">
                                <?php foreach (['low','medium','high','critical'] as $p): ?>
                                <option value="<?= $p ?>" <?= $formData['priority'] === $p ? 'selected' : '' ?>>
                                    <?= ucfirst($p) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['open','in_progress','pending','resolved','closed'] as $s): ?>
                                <option value="<?= $s ?>" <?= $formData['status'] === $s ? 'selected' : '' ?>>
                                    <?= ucwords(str_replace('_', ' ', $s)) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="migrate-section-title">Dates</div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Created <span class="text-danger">*</span></label>
                            <input type="datetime-local" name="created_at" class="form-control" required
                                   value="<?= e($formData['created_at']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Closed</label>
                            <input type="datetime-local" name="closed_at" class="form-control"
                                   value="<?= e($formData['closed_at']) ?>">
                        </div>
                    </div>

                    <div class="migrate-section-title">Conversation Thread (optional)</div>
                    <p class="migrate-hint mb-2">
                        Add as many messages as needed. For long old tickets, use <strong>Quick Paste</strong> below
                        or click <strong>Add Message</strong> for each reply.
                    </p>

                    <div id="threadRows" class="d-flex flex-column gap-3 mb-3">
                        <?php foreach ($threadMessages as $i => $row): ?>
                        <div class="thread-row" data-thread-row>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label">Author</label>
                                    <select name="thread_messages[<?= $i ?>][user_id]" class="form-select form-select-sm thread-author">
                                        <option value="">— Select user —</option>
                                        <?php foreach ($users as $u): ?>
                                        <option value="<?= $u['id'] ?>" <?= (string)($row['user_id'] ?? '') === (string)$u['id'] ? 'selected' : '' ?>>
                                            <?= e($u['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Date</label>
                                    <input type="datetime-local" name="thread_messages[<?= $i ?>][created_at]"
                                           class="form-control form-control-sm"
                                           value="<?= e($row['created_at'] ?? '') ?>">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger w-100 remove-thread-row">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Message</label>
                                    <textarea name="thread_messages[<?= $i ?>][message]" class="form-control form-control-sm" rows="3"
                                              placeholder="Message text from the old ticket thread"><?= e($row['message'] ?? '') ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label d-flex align-items-center justify-content-between mb-1" style="font-size:.8rem;font-weight:600">
                                        <span><i class="bi bi-paperclip me-1 text-primary"></i> Attachment (optional)</span>
                                        <span class="migrate-hint">Excel, PDF, Word, image, etc.</span>
                                    </label>
                                    <input type="file" name="thread_files[<?= $i ?>][]" class="form-control form-control-sm thread-files-input" multiple
                                           accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.gif,.webp,.zip">
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input"
                                               name="thread_messages[<?= $i ?>][is_internal]" value="1"
                                               id="threadInternal<?= $i ?>"
                                               <?= !empty($row['is_internal']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="threadInternal<?= $i ?>" style="font-size:.8rem">
                                            Internal note (staff only)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addThreadRow">
                            <i class="bi bi-plus-lg me-1"></i> Add Message
                        </button>
                    </div>

                    <div class="border rounded p-3" style="background:#f8fafc">
                        <label class="form-label mb-1">Quick Paste (optional)</label>
                        <textarea name="thread_paste" id="threadPaste" class="form-control form-control-sm" rows="5"
                                  placeholder="Paste the full old thread here. One block per message:

[2024-05-24 10:37] Merchandising
Original request text here...

[2024-06-01 09:15] IT Department
First staff reply here...

[2024-06-11 10:25] IT Department
Final reply here..."><?= e($formData['thread_paste'] ?? '') ?></textarea>
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <div class="migrate-hint">Use the old ticket date, author, then message on the next lines.</div>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="parseThreadPaste">
                                Parse into messages
                            </button>
                        </div>
                    </div>

                    <template id="threadRowTemplate">
                        <div class="thread-row" data-thread-row>
                            <div class="row g-2">
                                <div class="col-md-5">
                                    <label class="form-label">Author</label>
                                    <select name="thread_messages[__INDEX__][user_id]" class="form-select form-select-sm thread-author">
                                        <option value="">— Select user —</option>
                                        <?php foreach ($users as $u): ?>
                                        <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label">Date</label>
                                    <input type="datetime-local" name="thread_messages[__INDEX__][created_at]" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger w-100 remove-thread-row">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Message</label>
                                    <textarea name="thread_messages[__INDEX__][message]" class="form-control form-control-sm" rows="3"
                                              placeholder="Message text from the old ticket thread"></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label d-flex align-items-center justify-content-between mb-1" style="font-size:.8rem;font-weight:600">
                                        <span><i class="bi bi-paperclip me-1 text-primary"></i> Attachment (optional)</span>
                                        <span class="migrate-hint">Excel, PDF, Word, image, etc.</span>
                                    </label>
                                    <input type="file" name="thread_files[__INDEX__][]" class="form-control form-control-sm thread-files-input" multiple
                                           accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png,.gif,.webp,.zip">
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input"
                                               name="thread_messages[__INDEX__][is_internal]" value="1">
                                        <label class="form-check-label" style="font-size:.8rem">Internal note (staff only)</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div class="d-flex gap-2 pt-3">
                        <button type="submit" class="btn btn-primary" <?= $schemaReady ? '' : 'disabled' ?>>
                            <i class="bi bi-box-arrow-in-down-right me-1"></i> Import Ticket
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">Clear Form</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header bg-white fw-600">
                <i class="bi bi-info-circle me-1 text-info"></i> Fields in This Form
            </div>
            <div class="card-body" style="font-size:.82rem">
                <p class="text-muted mb-2">Every field below maps directly to the new system:</p>
                <ul class="mb-0 ps-3">
                    <li>Subject, Description</li>
                    <li>Requester, Assigned To</li>
                    <li>Department, Category (select or type new)</li>
                    <li>Priority, Status</li>
                    <li>Created / Closed dates</li>
                    <li>Ticket &amp; conversation thread file attachments (Excel, PDF, images, Word, etc.)</li>
                    <li>Full conversation thread (multiple messages)</li>
                </ul>
                <p class="text-muted mt-3 mb-0" style="font-size:.78rem">
                    Old ticket # is kept only as a reference to avoid importing the same ticket twice.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-600"><i class="bi bi-clock-history me-1"></i> Recently Migrated</span>
                <form method="GET" class="d-flex gap-1">
                    <input type="text" name="q" class="form-control form-control-sm" style="width:130px"
                           placeholder="Search..." value="<?= e($search) ?>">
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="list-group list-group-flush" style="max-height:420px;overflow-y:auto">
                <?php if (empty($migrated)): ?>
                <div class="list-group-item text-muted text-center py-4 small">No migrated tickets yet.</div>
                <?php else: ?>
                <?php foreach ($migrated as $t): ?>
                <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $t['id'] ?>"
                   class="list-group-item list-group-item-action py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="legacy-badge me-1">#<?= e(str_pad($t['legacy_ticket_number'], 6, '0', STR_PAD_LEFT)) ?></span>
                            <span class="fw-600" style="font-size:.82rem"><?= e($t['ticket_code']) ?></span>
                            <div class="text-muted" style="font-size:.75rem"><?= e(mb_strimwidth($t['subject'], 0, 50, '...')) ?></div>
                            <div class="text-muted" style="font-size:.7rem"><?= e($t['requester_name'] ?? '') ?></div>
                        </div>
                        <div class="text-end">
                            <?= getStatusBadge($t['status']) ?>
                            <div class="text-muted" style="font-size:.68rem"><?= timeAgo($t['migrated_at'] ?? $t['created_at']) ?></div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const requester = document.getElementById('requesterSelect');
    const deptSelect = document.getElementById('departmentSelect');
    const categoryInput = document.getElementById('categoryInput');
    const categoryId = document.getElementById('categoryId');
    const categoryDatalist = document.getElementById('categoryDatalist');
    const legacyInput = document.getElementById('legacyTicketNumber');
    const dupWarning = document.getElementById('dupWarning');
    const allCategories = <?= $categoriesJson ?>;

    function applyRequesterDepartment() {
        const opt = requester?.options[requester.selectedIndex];
        if (!opt || !deptSelect) return;

        const deptId = opt.dataset.dept || '';
        if (deptId && deptId !== '0') {
            deptSelect.value = deptId;
            refreshCategoryDatalist();
        }
    }

    function refreshCategoryDatalist() {
        if (!categoryDatalist) return;
        const deptId = deptSelect?.value || '';
        categoryDatalist.innerHTML = '';
        allCategories.forEach(function (cat) {
            if (deptId && cat.department_id && String(cat.department_id) !== deptId) {
                return;
            }
            const opt = document.createElement('option');
            opt.value = cat.label || cat.name;
            if (cat.department_name && (cat.label || cat.name) !== cat.name) {
                opt.label = cat.department_name;
            }
            categoryDatalist.appendChild(opt);
        });
        syncCategoryId();
    }

    function syncCategoryId() {
        if (!categoryInput || !categoryId) return;
        const raw = categoryInput.value.trim().toLowerCase();
        const deptId = deptSelect?.value || '';
        const match = allCategories.find(function (cat) {
            const label = (cat.label || cat.name).toLowerCase();
            const name = cat.name.toLowerCase();
            if (raw === label) return true;
            if (raw === name) {
                if (!deptId) return true;
                return String(cat.department_id) === deptId;
            }
            return false;
        });
        categoryId.value = match ? String(match.id) : '';
    }

    requester?.addEventListener('change', applyRequesterDepartment);
    deptSelect?.addEventListener('change', refreshCategoryDatalist);
    categoryInput?.addEventListener('input', syncCategoryId);
    categoryInput?.addEventListener('change', syncCategoryId);

    refreshCategoryDatalist();
    applyRequesterDepartment();

    const threadRows = document.getElementById('threadRows');
    const threadTemplate = document.getElementById('threadRowTemplate');
    const requesterSelect = document.getElementById('requesterSelect');

    function reindexThreadRows() {
        threadRows?.querySelectorAll('[data-thread-row]').forEach(function (row, index) {
            row.querySelectorAll('[name^="thread_messages"]').forEach(function (el) {
                el.name = el.name.replace(/thread_messages\[\d+\]/, 'thread_messages[' + index + ']');
            });
            row.querySelectorAll('[name^="thread_files"]').forEach(function (el) {
                el.name = el.name.replace(/thread_files\[\d+\]/, 'thread_files[' + index + ']');
            });
            const checkbox = row.querySelector('input[type="checkbox"]');
            const label = row.querySelector('.form-check-label');
            if (checkbox) {
                checkbox.id = 'threadInternal' + index;
            }
            if (label && checkbox) {
                label.setAttribute('for', checkbox.id);
            }
        });
    }

    function addThreadRow(prefill) {
        if (!threadRows || !threadTemplate) return;
        const index = threadRows.querySelectorAll('[data-thread-row]').length;
        const html = threadTemplate.innerHTML.replace(/__INDEX__/g, String(index));
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        const row = wrapper.firstElementChild;
        threadRows.appendChild(row);

        if (prefill) {
            if (prefill.user_id) {
                const author = row.querySelector('.thread-author');
                if (author) author.value = String(prefill.user_id);
            }
            if (prefill.created_at) {
                const date = row.querySelector('input[type="datetime-local"]');
                if (date) date.value = prefill.created_at;
            }
            if (prefill.message) {
                const message = row.querySelector('textarea');
                if (message) message.value = prefill.message;
            }
            if (prefill.is_internal) {
                const internal = row.querySelector('input[type="checkbox"]');
                if (internal) internal.checked = true;
            }
        } else if (requesterSelect?.value) {
            const author = row.querySelector('.thread-author');
            if (author && !author.value) author.value = requesterSelect.value;
        }
    }

    document.getElementById('addThreadRow')?.addEventListener('click', function () {
        addThreadRow();
    });

    threadRows?.addEventListener('click', function (event) {
        const btn = event.target.closest('.remove-thread-row');
        if (!btn) return;
        const row = btn.closest('[data-thread-row]');
        if (!row) return;
        row.remove();
        reindexThreadRows();
    });

    function normalizePasteDate(value) {
        const trimmed = String(value || '').trim();
        if (!trimmed) return '';
        if (/^\d{4}-\d{2}-\d{2}T/.test(trimmed)) return trimmed.slice(0, 16);
        const parsed = new Date(trimmed.replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return '';
        const pad = n => String(n).padStart(2, '0');
        return parsed.getFullYear() + '-' + pad(parsed.getMonth() + 1) + '-' + pad(parsed.getDate())
            + 'T' + pad(parsed.getHours()) + ':' + pad(parsed.getMinutes());
    }

    function guessUserId(authorName) {
        const needle = String(authorName || '').trim().toLowerCase();
        if (!needle) return '';
        const requesterOpt = requesterSelect?.options[requesterSelect.selectedIndex];
        if (requesterOpt && requesterOpt.text.toLowerCase().includes(needle)) {
            return requesterSelect.value;
        }
        const authorSelects = Array.from(document.querySelectorAll('.thread-author option'));
        const match = authorSelects.find(function (opt) {
            return opt.value && opt.text.toLowerCase().includes(needle);
        });
        return match ? match.value : '';
    }

    document.getElementById('parseThreadPaste')?.addEventListener('click', function () {
        const text = document.getElementById('threadPaste')?.value || '';
        const blocks = text.trim().split(/\n(?=\[[^\]]+\])/);
        let added = 0;
        blocks.forEach(function (block) {
            const trimmed = block.trim();
            if (!trimmed) return;
            const match = trimmed.match(/^\[(.+?)\]\s*(.*?)(?:\n([\s\S]*))?$/);
            if (!match) return;
            const createdAt = normalizePasteDate(match[1]);
            const author = match[2].trim();
            const message = (match[3] || '').trim();
            if (!message) return;
            addThreadRow({
                user_id: guessUserId(author),
                created_at: createdAt,
                message: author ? author + ':\n' + message : message,
            });
            added++;
        });
        if (!added) {
            alert('No messages found. Use blocks like:\n[2024-05-24 10:37] Author Name\nMessage text');
            return;
        }
        reindexThreadRows();
    });

    legacyInput?.addEventListener('blur', async function () {
        const num = this.value.replace(/\D/g, '');
        if (!num || num.length < 3) {
            dupWarning.style.display = 'none';
            return;
        }
        try {
            const res = await fetch('<?= APP_URL ?>/api/migrate_check.php?legacy=' + encodeURIComponent(num));
            const data = await res.json();
            if (data.exists) {
                dupWarning.textContent = 'Already migrated as ' + data.ticket_code;
                dupWarning.style.display = 'block';
            } else {
                dupWarning.style.display = 'none';
            }
        } catch (e) {
            dupWarning.style.display = 'none';
        }
    });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
