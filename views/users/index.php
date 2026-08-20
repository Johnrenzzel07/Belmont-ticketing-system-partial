<?php
/**
 * User Management (Admin only)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$pdo    = db();
$user   = currentUser();
$errors = [];

// Handle actions
$action = $_GET['action'] ?? '';
if ($action === 'toggle' && isset($_GET['id'])) {
    $uid = (int)$_GET['id'];
    $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?")->execute([$uid]);
    flashMessage('success', 'User status updated.');
    header('Location: ' . APP_URL . '/views/users/index.php');
    exit;
}
if ($action === 'delete' && isset($_GET['id'])) {
    $uid = (int)$_GET['id'];
    if ($uid !== (int)$user['id']) {
        $pdo->prepare("UPDATE users SET is_active=0 WHERE id=?")->execute([$uid]);
        flashMessage('success', 'User deactivated.');
    }
    header('Location: ' . APP_URL . '/views/users/index.php');
    exit;
}

// Create / Edit User
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'id'            => (int)($_POST['id'] ?? 0),
        'name'          => trim($_POST['name'] ?? ''),
        'email'         => trim($_POST['email'] ?? ''),
        'role'          => $_POST['role'] ?? 'user',
        'department_id' => (int)($_POST['department_id'] ?? 0) ?: null,
        'phone'         => trim($_POST['phone'] ?? ''),
        'password'      => $_POST['password'] ?? '',
    ];

    if (empty($data['name']))  $errors[] = 'Name is required.';
    if (empty($data['email'])) $errors[] = 'Email is required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';
    if (!in_array($data['role'], ['admin','dept_admin','staff','user'])) $errors[] = 'Invalid role.';

    // Check duplicate email
    $exists = $pdo->prepare("SELECT id FROM users WHERE email=? AND id != ?");
    $exists->execute([$data['email'], $data['id']]);
    if ($exists->fetch()) $errors[] = 'Email already in use.';

    if (empty($errors)) {
        if ($data['id']) {
            // Update
            $setParts = ['name=?','email=?','role=?','department_id=?','phone=?'];
            $params   = [$data['name'],$data['email'],$data['role'],$data['department_id'],$data['phone']];
            if (!empty($data['password'])) {
                $setParts[] = 'password=?';
                $params[]   = password_hash($data['password'], PASSWORD_BCRYPT);
            }
            $params[] = $data['id'];
            $pdo->prepare("UPDATE users SET " . implode(',',$setParts) . " WHERE id=?")->execute($params);
            flashMessage('success', 'User updated successfully.');
        } else {
            // Create
            if (empty($data['password'])) { $errors[] = 'Password is required for new users.'; }
            if (empty($errors)) {
                $pdo->prepare(
                    "INSERT INTO users (name, email, password, role, department_id, phone) VALUES (?,?,?,?,?,?)"
                )->execute([
                    $data['name'],$data['email'],
                    password_hash($data['password'], PASSWORD_BCRYPT),
                    $data['role'],$data['department_id'],$data['phone']
                ]);
                flashMessage('success', 'User created successfully.');
            }
        }
        if (empty($errors)) {
            header('Location: ' . APP_URL . '/views/users/index.php');
            exit;
        }
    }
}

// Filters & Pagination
$q        = trim($_GET['q']    ?? '');
$role     = $_GET['role']       ?? '';
$page     = max(1,(int)($_GET['page'] ?? 1));
$perPage  = 20;

$where  = ['1=1'];
$params = [];
if ($q)    { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($role) { $where[] = 'u.role = ?'; $params[] = $role; }
$whereStr = implode(' AND ', $where);

$total = (int)$pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereStr")
    ->execute($params) ? (function() use ($pdo, $whereStr, $params) {
        $s = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereStr");
        $s->execute($params); return (int)$s->fetchColumn();
    })() : 0;

$pagInfo = paginate($total, $perPage, $page);

$users = $pdo->prepare(
    "SELECT u.*, d.name AS dept_name,
     (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id) AS ticket_count
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE $whereStr
     ORDER BY u.name ASC LIMIT ? OFFSET ?"
);
$users->execute(array_merge($params, [$perPage, $pagInfo['offset']]));
$users = $users->fetchAll();

$depts     = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$editUser  = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editUser = $s->fetch();
}

$pageTitle = 'User Management';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">User Management</h1>
        <p class="page-subtitle"><?= number_format($total) ?> users in the system</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal">
        <i class="bi bi-person-plus me-1"></i>Add User
    </button>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-lg-4">
                <div class="table-search position-relative">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" name="q" class="form-control" placeholder="Search users..." value="<?= e($q) ?>">
                </div>
            </div>
            <div class="col-lg-2">
                <select name="role" class="form-select">
                    <option value="">All Roles</option>
                    <option value="admin"      <?= $role==='admin'?'selected':'' ?>>Admin</option>
                    <option value="dept_admin" <?= $role==='dept_admin'?'selected':'' ?>>Dept Admin</option>
                    <option value="staff"      <?= $role==='staff'?'selected':'' ?>>Staff</option>
                    <option value="user"       <?= $role==='user'?'selected':'' ?>>User</option>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="<?= APP_URL ?>/views/users/index.php" class="btn btn-outline-secondary ms-1">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card table-card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th class="text-center">Tickets</th>
                    <th>Last Login</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="8" class="text-center py-4 text-muted">No users found.</td></tr>
            <?php else: ?>
            <?php foreach ($users as $u): ?>
            <tr>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <?= renderAvatar(
                            $u['avatar'] ?? null,
                            $u['name'],
                            'avatar-sm',
                            'background:' . (['admin'=>'var(--primary)','staff'=>'#0ea5e9','user'=>'var(--secondary)'][$u['role']] ?? 'var(--secondary)') . ';color:#fff'
                        ) ?>
                        <span class="fw-600"><?= e($u['name']) ?></span>
                    </div>
                </td>
                <td><?= e($u['email']) ?></td>
                <td>
                    <?php
                    $roleCls = ['admin'=>'danger','dept_admin'=>'warning','staff'=>'primary','user'=>'secondary'][$u['role']] ?? 'secondary';
                    ?>
                    <span class="badge bg-<?= $roleCls ?>"><?= ucwords(str_replace('_',' ',$u['role'])) ?></span>
                </td>
                <td><?= e($u['dept_name'] ?? 'N/A') ?></td>
                <td class="text-center">
                    <a href="<?= APP_URL ?>/views/tickets/index.php?user=<?= $u['id'] ?>" class="badge bg-secondary">
                        <?= $u['ticket_count'] ?>
                    </a>
                </td>
                <td>
                    <small class="text-muted">
                        <?= $u['last_login'] ? timeAgo($u['last_login']) : 'Never' ?>
                    </small>
                </td>
                <td>
                    <?php if ($u['is_active']): ?>
                    <span class="badge bg-success">Active</span>
                    <?php else: ?>
                    <span class="badge bg-secondary">Inactive</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="?edit=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <a href="?action=toggle&id=<?= $u['id'] ?>"
                           class="btn btn-sm btn-outline-<?= $u['is_active'] ? 'warning' : 'success' ?>">
                            <i class="bi bi-<?= $u['is_active'] ? 'pause' : 'play' ?>"></i>
                        </a>
                        <?php if ($u['id'] !== (int)$user['id']): ?>
                        <a href="?action=delete&id=<?= $u['id'] ?>"
                           class="btn btn-sm btn-outline-danger confirm-delete"
                           data-message="Deactivate this user?">
                            <i class="bi bi-person-x"></i>
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
    <?php if ($pagInfo['total_pages'] > 1): ?>
    <div class="card-footer d-flex justify-content-end">
        <nav><ul class="pagination mb-0 pagination-sm">
            <?php for ($p = 1; $p <= $pagInfo['total_pages']; $p++): ?>
            <li class="page-item <?= $p===$pagInfo['current']?'active':'' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a>
            </li>
            <?php endfor; ?>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                <input type="hidden" name="id" value="<?= $editUser ? $editUser['id'] : 0 ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $editUser ? 'Edit User' : 'Add New User' ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger p-2">
                        <?php foreach($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Full Name</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= e($editUser['name'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label required">Email</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= e($editUser['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required">Role</label>
                            <select name="role" class="form-select">
                                <?php foreach(['admin','dept_admin','staff','user'] as $r): ?>
                                <option value="<?= $r ?>" <?= ($editUser['role'] ?? 'user')===$r?'selected':'' ?>>
                                    <?= ucwords(str_replace('_',' ',$r)) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">-- None --</option>
                                <?php foreach ($depts as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= ($editUser['department_id']??'')==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?= e($editUser['phone'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label <?= $editUser ? '' : 'required' ?>">
                                Password <?= $editUser ? '(leave blank to keep current)' : '' ?>
                            </label>
                            <input type="password" name="password" class="form-control"
                                   <?= $editUser ? '' : 'required' ?>>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <?= $editUser ? 'Save Changes' : 'Create User' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editUser || !empty($errors)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('userModal')).show();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
