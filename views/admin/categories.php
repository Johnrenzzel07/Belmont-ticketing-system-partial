<?php
/**
 * Category Management (Admin only)
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['name'] ?? '');
    $deptId = (int)($_POST['department_id'] ?? 0) ?: null;

    if ($name) {
        if ($id) {
            $pdo->prepare("UPDATE categories SET name=?, department_id=? WHERE id=?")->execute([$name, $deptId, $id]);
            flashMessage('success', 'Category updated.');
        } else {
            $pdo->prepare("INSERT INTO categories (name, department_id) VALUES (?,?)")->execute([$name, $deptId]);
            flashMessage('success', 'Category created.');
        }
    }
    header('Location: ' . APP_URL . '/views/admin/categories.php');
    exit;
}

if (isset($_GET['toggle'])) {
    $pdo->prepare("UPDATE categories SET is_active = NOT is_active WHERE id=?")->execute([(int)$_GET['toggle']]);
    header('Location: ' . APP_URL . '/views/admin/categories.php');
    exit;
}

$cats  = $pdo->query("SELECT c.*, d.name AS dept_name FROM categories c LEFT JOIN departments d ON d.id = c.department_id ORDER BY c.name")->fetchAll();
$depts = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();

$editCat = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM categories WHERE id=?");
    $s->execute([(int)$_GET['edit']]); $editCat = $s->fetch();
}

$pageTitle = 'Categories';
include __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <h1 class="page-title">Categories</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#catModal">
        <i class="bi bi-tags me-1"></i>Add Category
    </button>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Name</th><th>Department</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($cats as $c): ?>
            <tr>
                <td class="fw-600"><?= e($c['name']) ?></td>
                <td class="text-muted"><?= e($c['dept_name'] ?? 'All') ?></td>
                <td><?= $c['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                <td>
                    <a href="?edit=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                    <a href="?toggle=<?= $c['id'] ?>" class="btn btn-sm btn-outline-<?= $c['is_active']?'warning':'success' ?>"><i class="bi bi-<?= $c['is_active']?'pause':'play' ?>"></i></a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="catModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="id" value="<?= $editCat['id'] ?? 0 ?>">
            <div class="modal-header">
                <h5 class="modal-title"><?= $editCat ? 'Edit Category' : 'New Category' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label required">Name</label>
                    <input type="text" name="name" class="form-control" required value="<?= e($editCat['name']??'') ?>">
                </div>
                <div class="mb-0">
                    <label class="form-label">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($editCat['department_id']??'')==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= $editCat ? 'Save' : 'Create' ?></button>
            </div>
        </form>
    </div></div>
</div>

<?php if ($editCat): ?>
<script>document.addEventListener('DOMContentLoaded',function(){ new bootstrap.Modal(document.getElementById('catModal')).show(); });</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
