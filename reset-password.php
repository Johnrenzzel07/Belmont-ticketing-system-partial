<?php
/**
 * Reset Password — Step 2: Token Validation + New Password
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$pdo    = db();
$token  = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error  = '';
$done   = false;

// Validate token
$tokenRow = null;
if ($token) {
    $stmt = $pdo->prepare(
        "SELECT pr.*, u.email, u.name
         FROM password_resets pr
         JOIN users u ON u.id = pr.user_id
         WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->execute([$token]);
    $tokenRow = $stmt->fetch();
}

if (!$token || !$tokenRow) {
    $error = 'This reset link is invalid or has expired. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow && !$done) {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([$hash, $tokenRow['user_id']]);
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")
            ->execute([$tokenRow['id']]);
        logActivity($tokenRow['user_id'], null, 'password_reset', 'Password reset via token', getClientIp());
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
</head>
<body class="login-page">

    <div style="position:absolute;inset:0;overflow:hidden;pointer-events:none;">
        <div style="position:absolute;width:300px;height:300px;border-radius:50%;background:rgba(99,102,241,.08);top:-80px;right:-60px;filter:blur(40px)"></div>
        <div style="position:absolute;width:400px;height:400px;border-radius:50%;background:rgba(139,92,246,.06);bottom:-100px;left:-80px;filter:blur(60px)"></div>
    </div>

    <div class="login-card">
        <div class="login-brand">
            <div class="login-logo">
                <img src="<?= APP_URL ?>/public/images/logo.png" alt="<?= APP_NAME ?>" class="login-logo-img">
            </div>
            <h1 class="login-title">Set New Password</h1>
            <p class="login-subtitle">
                <?php if ($tokenRow): ?>
                    Resetting password for <strong><?= e($tokenRow['email']) ?></strong>
                <?php else: ?>
                    Password Reset
                <?php endif; ?>
            </p>
        </div>

        <?php if ($done): ?>
            <div class="alert alert-success py-2 px-3 mb-3" style="font-size:.83rem;border-radius:8px">
                <i class="bi bi-check-circle me-1"></i> Your password has been updated successfully.
            </div>
            <a href="<?= APP_URL ?>/login.php" class="btn btn-primary w-100 btn-lg" style="border-radius:8px;font-weight:600">
                <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
            </a>

        <?php elseif ($error && !$tokenRow): ?>
            <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.83rem;border-radius:8px">
                <i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?>
            </div>
            <div class="text-center">
                <a href="<?= APP_URL ?>/forgot-password.php" class="btn btn-outline-primary">Request New Link</a>
            </div>

        <?php else: ?>
            <?php if ($error): ?>
            <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.83rem;border-radius:8px">
                <i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="mb-3">
                    <label for="password" class="form-label required">New Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock text-muted"></i></span>
                        <input type="password" id="password" name="password"
                               class="form-control border-start-0 border-end-0"
                               placeholder="At least 8 characters" required autofocus minlength="8">
                        <button class="input-group-text bg-light border-start-0 toggle-pwd" type="button"
                                onclick="this.previousElementSibling.type = this.previousElementSibling.type === 'password' ? 'text' : 'password'">
                            <i class="bi bi-eye text-muted"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="confirm" class="form-label required">Confirm Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="bi bi-lock-fill text-muted"></i></span>
                        <input type="password" id="confirm" name="confirm"
                               class="form-control border-start-0"
                               placeholder="Repeat your new password" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 btn-lg" style="border-radius:8px;font-weight:600">
                    <i class="bi bi-shield-check me-1"></i>Update Password
                </button>
            </form>
        <?php endif; ?>

        <div class="text-center mt-3">
            <a href="<?= APP_URL ?>/login.php" style="font-size:.82rem;color:var(--text-muted);text-decoration:none">
                <i class="bi bi-arrow-left me-1"></i>Back to Sign In
            </a>
        </div>
    </div>
</body>
</html>
