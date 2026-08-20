<?php
/**
 * My Profile
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pdo    = db();
$user   = currentUser();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    $action = $_POST['action'] ?? 'profile';

    // ---- Avatar Upload ----
    if ($action === 'avatar') {
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $file     = $_FILES['avatar'];
            $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxSize  = 2 * 1024 * 1024; // 2 MB

            if (!in_array($file['type'], $allowed)) {
                $errors[] = 'Only JPG, PNG, GIF, or WebP images are allowed.';
            } elseif ($file['size'] > $maxSize) {
                $errors[] = 'Avatar must be smaller than 2 MB.';
            } else {
                // Delete old avatar file if exists
                $oldStmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
                $oldStmt->execute([$user['id']]);
                $oldAvatar = $oldStmt->fetchColumn();
                if ($oldAvatar) {
                    $oldPath = __DIR__ . '/../../uploads/avatars/' . basename($oldAvatar);
                    if (file_exists($oldPath)) @unlink($oldPath);
                }

                $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'avatar_' . $user['id'] . '_' . time() . '.' . strtolower($ext);
                $dir      = __DIR__ . '/../../uploads/avatars/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $dest     = $dir . $filename;

                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $avatarPath = 'uploads/avatars/' . $filename;
                    $pdo->prepare("UPDATE users SET avatar = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$avatarPath, $user['id']]);
                    $_SESSION['user_avatar'] = $avatarPath;
                    flashMessage('success', 'Profile picture updated successfully.');
                } else {
                    $errors[] = 'Failed to save the uploaded file. Check folder permissions.';
                }
            }
        } elseif (isset($_POST['remove_avatar'])) {
            // Remove avatar
            $oldStmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $oldStmt->execute([$user['id']]);
            $oldAvatar = $oldStmt->fetchColumn();
            if ($oldAvatar) {
                $oldPath = __DIR__ . '/../../uploads/avatars/' . basename($oldAvatar);
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $pdo->prepare("UPDATE users SET avatar = NULL, updated_at = NOW() WHERE id = ?")
                ->execute([$user['id']]);
            $_SESSION['user_avatar'] = null;
            flashMessage('success', 'Profile picture removed.');
        }

        if (empty($errors)) {
            header('Location: ' . APP_URL . '/views/profile/index.php');
            exit;
        }
    }

    // ---- Notification Preferences ----
    if ($action === 'notifications') {
        $notifyInapp = !empty($_POST['notify_inapp']) ? 1 : 0;
        $notifyEmail = !empty($_POST['notify_email']) ? 1 : 0;
        $pdo->prepare("UPDATE users SET notify_inapp=?, notify_email=?, updated_at=NOW() WHERE id=?")
            ->execute([$notifyInapp, $notifyEmail, $user['id']]);
        flashMessage('success', 'Notification preferences saved.');
        header('Location: ' . APP_URL . '/views/profile/index.php');
        exit;
    }

    // ---- Profile & Password ----
    if ($action === 'profile') {
        $name   = trim($_POST['name']             ?? '');
        $phone  = trim($_POST['phone']            ?? '');
        $curPwd = $_POST['current_password']      ?? '';
        $newPwd = $_POST['new_password']          ?? '';
        $cfmPwd = $_POST['confirm_password']      ?? '';

        if (empty($name)) $errors[] = 'Name is required.';

        if (!empty($newPwd)) {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id=?");
            $stmt->execute([$user['id']]); $dbUser = $stmt->fetch();
            if (!password_verify($curPwd, $dbUser['password'])) {
                $errors[] = 'Current password is incorrect.';
            } elseif ($newPwd !== $cfmPwd) {
                $errors[] = 'New passwords do not match.';
            } elseif (strlen($newPwd) < 6) {
                $errors[] = 'New password must be at least 6 characters.';
            }
        }

        if (empty($errors)) {
            if (!empty($newPwd)) {
                $pdo->prepare("UPDATE users SET name=?, phone=?, password=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $phone, password_hash($newPwd, PASSWORD_BCRYPT), $user['id']]);
            } else {
                $pdo->prepare("UPDATE users SET name=?, phone=?, updated_at=NOW() WHERE id=?")
                    ->execute([$name, $phone, $user['id']]);
            }
            $_SESSION['user_name'] = $name;
            flashMessage('success', 'Profile updated successfully.');
            header('Location: ' . APP_URL . '/views/profile/index.php');
            exit;
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT u.*, d.name AS dept_name, d.shared_email AS dept_shared_email
     FROM users u LEFT JOIN departments d ON d.id=u.department_id WHERE u.id=?"
);
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

// Ticket stats
$myStats = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM tickets WHERE user_id=? GROUP BY status");
$myStats->execute([$user['id']]);
$myStats = $myStats->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'My Profile';
include __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">My Profile</h1>
        <p class="page-subtitle">Manage your personal information and account settings</p>
    </div>
</div>

<div class="row g-3">
    <!-- Left Column: Avatar + Stats -->
    <div class="col-lg-4">

        <!-- Avatar Card -->
        <div class="card mb-3">
            <div class="card-body py-4 text-center">

                <!-- Clickable Avatar with camera overlay -->
                <div class="avatar-upload-wrap mx-auto mb-3" id="avatarWrap"
                     title="Click to change profile picture">
                    <?php if ($profile['avatar']): ?>
                        <img src="<?= e(resolveAvatarUrl($profile['avatar'])) ?>" alt="avatar" class="avatar-upload-img" id="avatarPreview">
                    <?php else: ?>
                        <div class="avatar-upload-initials" id="avatarInitials">
                            <?= strtoupper(substr($profile['name'], 0, 2)) ?>
                        </div>
                        <img src="" alt="avatar" class="avatar-upload-img d-none" id="avatarPreview">
                    <?php endif; ?>
                    <div class="avatar-upload-overlay">
                        <i class="bi bi-camera"></i>
                        <span>Change</span>
                    </div>
                </div>

                <!-- Hidden file input triggered by clicking the avatar -->
                <form method="POST" enctype="multipart/form-data" id="avatarForm">
                    <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="avatar">
                    <input type="file" name="avatar" id="avatarFileInput"
                           accept="image/jpeg,image/png,image/gif,image/webp"
                           class="d-none">
                </form>

                <h5 class="fw-700 mb-1"><?= e($profile['name']) ?></h5>
                <p class="text-muted mb-1" style="font-size:.83rem"><?= e($profile['email']) ?></p>
                <span class="badge bg-<?= ['admin'=>'danger','dept_admin'=>'warning','staff'=>'primary','user'=>'secondary'][$profile['role']] ?? 'secondary' ?>">
                    <?= ucwords(str_replace('_',' ',$profile['role'])) ?>
                </span>
                <?php if ($profile['dept_name']): ?>
                <p class="text-muted mt-2 mb-0" style="font-size:.8rem">
                    <i class="bi bi-building me-1"></i><?= e($profile['dept_name']) ?>
                </p>
                <?php endif; ?>

                <?php if ($profile['avatar']): ?>
                <div class="mt-3">
                    <form method="POST" id="removeAvatarForm">
                        <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="action" value="avatar">
                        <input type="hidden" name="remove_avatar" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                onclick="return confirm('Remove your profile picture?')">
                            <i class="bi bi-trash me-1"></i>Remove Photo
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger mt-3 text-start" style="font-size:.8rem">
                    <ul class="mb-0">
                        <?php foreach($errors as $err): ?>
                        <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <p class="text-muted mt-3 mb-0" style="font-size:.72rem">
                    JPG, PNG, GIF or WebP &mdash; max 2 MB
                </p>
            </div>
        </div>

        <!-- Ticket Stats -->
        <div class="card">
            <div class="card-header">My Ticket Summary</div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php
                    $statusList = ['open','in_progress','pending','resolved','closed'];
                    foreach ($statusList as $s):
                        $cnt = $myStats[$s] ?? 0;
                    ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3" style="font-size:.83rem">
                        <span><?= ucwords(str_replace('_',' ',$s)) ?></span>
                        <span class="badge bg-<?= ['open'=>'info','in_progress'=>'primary','pending'=>'warning','resolved'=>'success','closed'=>'secondary'][$s] ?? 'secondary' ?>">
                            <?= $cnt ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-3 fw-600" style="font-size:.83rem">
                        <span>Total</span>
                        <span class="badge bg-dark"><?= array_sum($myStats) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Edit Profile Form -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Edit Profile</div>
            <div class="card-body">
                <?php if (!empty($errors) && ($_POST['action'] ?? '') === 'profile'): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Full Name</label>
                            <input type="text" name="name" class="form-control" value="<?= e($profile['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email (read-only)</label>
                            <input type="email" class="form-control" value="<?= e($profile['email']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= e($profile['phone']??'') ?>">
                        </div>
                        <?php if (!empty($profile['dept_shared_email'])): ?>
                        <div class="col-md-6">
                            <label class="form-label">Department Email (read-only)</label>
                            <input type="email" class="form-control" value="<?= e($profile['dept_shared_email']) ?>" disabled>
                            <div class="form-text">
                                Your department's shared inbox &mdash; all colleagues in your department
                                receive the same notifications.
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <hr>
                    <p class="fw-600 mb-2">Change Password <small class="text-muted fw-400">(leave blank to keep current)</small></p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Current Password</label>
                            <input type="password" name="current_password" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">New Password</label>
                            <input type="password" name="new_password" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Notification Preferences -->
        <div class="card mt-3">
            <div class="card-header"><i class="bi bi-bell me-2 text-primary"></i>Notification Preferences</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="_csrf" value="<?= getCsrfToken() ?>">
                    <input type="hidden" name="action" value="notifications">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="notifyInapp"
                               name="notify_inapp" value="1" <?= !empty($profile['notify_inapp']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="notifyInapp">
                            <strong>In-app notifications</strong>
                            <div class="text-muted" style="font-size:.76rem">
                                Show alerts in the notification bell for ticket updates, replies, and assignments.
                            </div>
                        </label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="notifyEmail"
                               name="notify_email" value="1" <?= !empty($profile['notify_email']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="notifyEmail">
                            <strong>Email notifications</strong>
                            <div class="text-muted" style="font-size:.76rem">
                                Receive emails for tickets routed to you and your department's shared inbox.
                            </div>
                        </label>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-check-lg me-1"></i>Save Preferences
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const wrap  = document.getElementById('avatarWrap');
    const input = document.getElementById('avatarFileInput');
    const form  = document.getElementById('avatarForm');
    const preview = document.getElementById('avatarPreview');
    const initials = document.getElementById('avatarInitials');

    // Open file picker when avatar is clicked
    wrap.addEventListener('click', function () {
        input.click();
    });

    // Preview then auto-submit
    input.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;

        // Client-side size check (2 MB)
        if (file.size > 2 * 1024 * 1024) {
            alert('File is too large. Maximum size is 2 MB.');
            this.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
            preview.classList.remove('d-none');
            if (initials) initials.classList.add('d-none');
        };
        reader.readAsDataURL(file);

        // Submit form after a brief preview
        setTimeout(function () { form.submit(); }, 400);
    });
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
