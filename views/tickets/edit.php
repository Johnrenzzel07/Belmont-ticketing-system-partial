<?php
/**
 * Edit Ticket
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ai_helpers.php';
requireLogin();

$pdo    = db();
$user   = currentUser();
$id     = (int)($_GET['id'] ?? 0);

$ticket = $pdo->prepare("SELECT * FROM tickets WHERE id = ?")->execute([$id])
    ? (function() use ($pdo, $id) {
        $s = $pdo->prepare("SELECT * FROM tickets WHERE id = ?");
        $s->execute([$id]); return $s->fetch();
    })() : null;

if (!$ticket) { header('Location: ' . APP_URL . '/views/tickets/index.php'); exit; }
if (!isStaff() && $ticket['user_id'] != $user['id']) {
    flashMessage('danger', 'Access denied.'); header('Location: ' . APP_URL . '/views/tickets/index.php'); exit;
}

$departments = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();
$categories  = $pdo->query("SELECT id, name, department_id FROM categories WHERE is_active=1 ORDER BY name")->fetchAll();
$staffList   = isStaff()
    ? $pdo->query("SELECT id, name FROM users WHERE role IN ('staff','admin') AND is_active=1 ORDER BY name")->fetchAll()
    : [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'subject'       => trim($_POST['subject'] ?? ''),
        'description'   => trim($_POST['description'] ?? ''),
        'priority'      => $_POST['priority'] ?? 'medium',
        'status'        => $_POST['status'] ?? 'open',
        'department_id' => (int)($_POST['department_id'] ?? 0) ?: null,
        'category_id'   => (int)($_POST['category_id'] ?? 0) ?: null,
        'assigned_to'   => (int)($_POST['assigned_to'] ?? 0) ?: null,
        'due_date'      => $_POST['due_date'] ?? null,
    ];

    if (empty($data['subject']))     $errors[] = 'Subject is required.';
    if (empty($data['description'])) $errors[] = 'Description is required.';

    if (empty($errors)) {
        $closedAt = ($data['status'] === 'closed' && $ticket['status'] !== 'closed')
            ? date('Y-m-d H:i:s') : ($ticket['closed_at'] ?? null);

        $pdo->prepare(
            "UPDATE tickets SET subject=?, description=?, priority=?, status=?,
             department_id=?, category_id=?, assigned_to=?, due_date=?, closed_at=?, updated_at=NOW()
             WHERE id=?"
        )->execute([
            $data['subject'], $data['description'], $data['priority'], $data['status'],
            $data['department_id'], $data['category_id'], $data['assigned_to'],
            $data['due_date'] ?: null, $closedAt, $id
        ]);

        // Notify if newly assigned
        if ($data['assigned_to'] && $data['assigned_to'] != $ticket['assigned_to']) {
            createNotification($data['assigned_to'], $id, 'assigned',
                "Ticket {$ticket['ticket_code']} assigned to you.");
        }

        logActivity($user['id'], $id, 'ticket_edited', "Edited ticket {$ticket['ticket_code']}", getClientIp());

        if (in_array($data['status'], ['resolved', 'closed'], true)
            && $data['status'] !== $ticket['status']) {
            triggerTicketKbLearning($pdo, $id, (int)$user['id']);
        }

        flashMessage('success', 'Ticket updated successfully.');
        header('Location: ' . APP_URL . '/views/tickets/view.php?id=' . $id);
        exit;
    }

    $ticket = array_merge($ticket, $data);
}

$pageTitle = 'Edit Ticket';
include __DIR__ . '/../../includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-2">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/index.php">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/tickets/index.php">Tickets</a></li>
        <li class="breadcrumb-item"><a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $id ?>"><?= e($ticket['ticket_code']) ?></a></li>
        <li class="breadcrumb-item active">Edit</li>
    </ol>
</nav>

<h1 class="page-title mb-3">Edit Ticket</h1>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
            <div class="card mb-3">
                <div class="card-header">Ticket Information</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label required">Subject</label>
                        <input type="text" name="subject" class="form-control" value="<?= e($ticket['subject']) ?>" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label required">Description</label>
                        <textarea name="description" rows="8" class="form-control" required><?= e($ticket['description']) ?></textarea>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= APP_URL ?>/views/tickets/view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Options</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="subject" value="<?= e($ticket['subject']) ?>">
                    <input type="hidden" name="description" value="<?= e($ticket['description']) ?>">

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach(['open','in_progress','pending','resolved','closed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $ticket['status']===$s?'selected':'' ?>>
                                <?= ucwords(str_replace('_',' ',$s)) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <?php foreach(['low','medium','high','critical'] as $p): ?>
                            <option value="<?= $p ?>" <?= $ticket['priority']===$p?'selected':'' ?>>
                                <?= ucfirst($p) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $ticket['department_id']==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category</label>
                        <select name="category_id" class="form-select">
                            <option value="">-- None --</option>
                            <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $ticket['category_id']==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (isStaff()): ?>
                    <div class="mb-3">
                        <label class="form-label">Assigned To</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">Unassigned</option>
                            <?php foreach ($staffList as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $ticket['assigned_to']==$s['id']?'selected':'' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-control" value="<?= e($ticket['due_date']??'') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Update Options</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
