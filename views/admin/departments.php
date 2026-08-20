<?php
/**
 * Department Management (Admin only)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$pdo  = db();
$user = currentUser();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    // Inline shared-email save
    if (($_POST['action'] ?? '') === 'save_shared_email') {
        $id    = (int)($_POST['id'] ?? 0);
        $email = trim($_POST['shared_email'] ?? '');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flashMessage('danger', 'Invalid shared email address.');
        } elseif ($id) {
            $pdo->prepare("UPDATE departments SET shared_email=?, updated_at=NOW() WHERE id=?")
                ->execute([$email ?: null, $id]);
            logActivity($user['id'], null, 'dept_email_updated', "Department #$id shared email -> " . ($email ?: '(none)'), getClientIp());
            flashMessage('success', 'Shared email updated.');
        }
        header('Location: ' . APP_URL . '/views/admin/departments.php');
        exit;
    }

    $id    = (int)($_POST['id'] ?? 0);
    $name  = trim($_POST['name'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $email = trim($_POST['shared_email'] ?? '');

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flashMessage('danger', 'Invalid shared email address.');
    } elseif ($name) {
        if ($id) {
            $pdo->prepare("UPDATE departments SET name=?, description=?, shared_email=?, updated_at=NOW() WHERE id=?")
                ->execute([$name, $desc, $email ?: null, $id]);
            flashMessage('success', 'Department updated.');
        } else {
            $pdo->prepare("INSERT INTO departments (name, description, shared_email) VALUES (?,?,?)")
                ->execute([$name, $desc, $email ?: null]);
            flashMessage('success', 'Department created.');
        }
    }
    header('Location: ' . APP_URL . '/views/admin/departments.php');
    exit;
}

if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE departments SET is_active = NOT is_active WHERE id=?")->execute([(int)$_GET['toggle']]);
    header('Location: ' . APP_URL . '/views/admin/departments.php');
    exit;
}

$depts = $pdo->query(
    "SELECT d.*, COUNT(t.id) AS ticket_count,
            SUM(t.status NOT IN ('resolved','closed')) AS open_count
     FROM departments d
     LEFT JOIN tickets t ON t.department_id = d.id
     GROUP BY d.id ORDER BY d.name"
)->fetchAll();

// All active members grouped by department
$membersByDept = [];
$memberRows = $pdo->query(
    "SELECT id, department_id, name, role, last_login, avatar
     FROM users WHERE is_active = 1 AND department_id IS NOT NULL
     ORDER BY FIELD(role,'admin','staff','user'), name"
)->fetchAll();
foreach ($memberRows as $m) {
    $membersByDept[(int)$m['department_id']][] = $m;
}

$editDept = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM departments WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editDept = $s->fetch();
}

$pageTitle = 'Departments';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <h1 class="page-title">Departments</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal">
        <i class="bi bi-building-add me-1"></i>Add Department
    </button>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr>
                <th style="width:32px"></th>
                <th>Name</th>
                <th>Shared Email</th>
                <th class="text-center">Members</th>
                <th class="text-center">Open</th>
                <th class="text-center">Tickets</th>
                <th>Status</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($depts as $d): ?>
            <?php $members = $membersByDept[(int)$d['id']] ?? []; ?>
            <tr>
                <td>
                    <button class="btn btn-sm btn-link p-0" data-bs-toggle="collapse"
                            data-bs-target="#deptRow<?= $d['id'] ?>" title="View members">
                        <i class="bi bi-chevron-down"></i>
                    </button>
                </td>
                <td class="fw-600">
                    <?= e($d['name']) ?>
                    <div class="text-muted fw-400" style="font-size:.72rem"><?= e($d['description'] ?? '') ?></div>
                </td>
                <td>
                    <!-- Inline shared email edit -->
                    <form method="POST" class="d-flex align-items-center gap-1">
                        <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="action" value="save_shared_email">
                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                        <input type="email" name="shared_email" class="form-control form-control-sm"
                               style="font-size:.74rem;max-width:210px"
                               placeholder="dept@belmont.ph"
                               value="<?= e($d['shared_email'] ?? '') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Save shared email">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                </td>
                <td class="text-center"><span class="badge bg-primary"><?= count($members) ?></span></td>
                <td class="text-center"><span class="badge bg-info"><?= (int)($d['open_count'] ?? 0) ?></span></td>
                <td class="text-center"><span class="badge bg-secondary"><?= $d['ticket_count'] ?></span></td>
                <td>
                    <?php if ($d['is_active']): ?>
                    <span class="badge bg-success">Active</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="?edit=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="bi bi-pencil"></i></a>
                        <a href="?toggle=<?= $d['id'] ?>" class="btn btn-sm btn-outline-<?= $d['is_active']?'warning':'success' ?>"
                           title="<?= $d['is_active'] ? 'Deactivate' : 'Activate' ?>">
                            <i class="bi bi-<?= $d['is_active']?'pause':'play' ?>"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-info dept-test-btn" data-dept-id="<?= $d['id'] ?>"
                                title="Send test notification to all members">
                            <i class="bi bi-megaphone"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <!-- Expandable: department members -->
            <tr class="collapse" id="deptRow<?= $d['id'] ?>">
                <td colspan="8" style="background:var(--bg-subtle,#f8fafc);padding:.75rem 1.25rem">
                    <?php if (empty($members)): ?>
                        <span class="text-muted" style="font-size:.8rem">No active members in this department.</span>
                    <?php else: ?>
                        <div class="text-muted mb-2" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em">
                            Members — all receive notifications sent to
                            <code><?= e($d['shared_email'] ?: 'this department') ?></code>
                        </div>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($members as $m): ?>
                            <div class="d-flex align-items-center gap-2 p-2"
                                 style="background:#fff;border:1px solid var(--border-light,#e2e8f0);border-radius:8px;font-size:.78rem">
                                <?= renderAvatar($m['avatar'] ?? null, $m['name']) ?>
                                <div>
                                    <div class="fw-600"><?= e($m['name']) ?>
                                        <span class="badge bg-<?= ['admin'=>'danger','staff'=>'primary','user'=>'secondary'][$m['role']] ?? 'secondary' ?>"
                                              style="font-size:.58rem"><?= ucfirst($m['role']) ?></span>
                                    </div>
                                    <div class="text-muted" style="font-size:.68rem">
                                        Last login: <?= $m['last_login'] ? timeAgo($m['last_login']) : 'Never' ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="deptModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="id"   value="<?= $editDept['id'] ?? 0 ?>">
            <div class="modal-header">
                <h5 class="modal-title"><?= $editDept ? 'Edit Department' : 'New Department' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Name</label>
                    <input type="text" name="name" class="form-control" required value="<?= e($editDept['name']??'') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <textarea name="description" rows="3" class="form-control"><?= e($editDept['description']??'') ?></textarea>
                </div>
                <div class="mb-0">
                    <label class="form-label">Shared Email (department inbox)</label>
                    <input type="email" name="shared_email" class="form-control"
                           placeholder="e.g. it@belmont.ph"
                           value="<?= e($editDept['shared_email']??'') ?>">
                    <div class="form-text">
                        All members of this department receive notifications addressed to this inbox.
                        Replies to outgoing emails will go back to this address.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= $editDept ? 'Save' : 'Create' ?></button>
            </div>
        </form>
    </div></div>
</div>

<?php if ($editDept): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ new bootstrap.Modal(document.getElementById('deptModal')).show(); });</script>
<?php endif; ?>

<?php ob_start(); ?>
<script>
// Send a test broadcast notification to all members of a department
$(document).on('click', '.dept-test-btn', function() {
    const $btn = $(this);
    const deptId = $btn.data('dept-id');
    if (!confirm('Send a test notification to ALL active members of this department?')) return;
    $btn.prop('disabled', true);
    $.post(APP_URL + '/api/tickets.php',
        { action: 'dept_test_notification', dept_id: deptId, _csrf: CSRF_TOKEN },
        function(r) {
            showToast(r.success ? 'success' : 'danger', r.message);
        }, 'json'
    ).fail(function() {
        showToast('danger', 'Request failed.');
    }).always(function() {
        $btn.prop('disabled', false);
    });
});
</script>
<?php $extraScripts = ob_get_clean(); ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
