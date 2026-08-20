<?php
/**
 * Login Page
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = db()->prepare(
            'SELECT id, name, email, password, role, department_id, avatar, is_active
             FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['is_active'] && password_verify($password, $user['password'])) {
            loginUser($user);
            $redirect = $_SESSION['redirect_after_login'] ?? (APP_URL . '/index.php');
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Invalid email or password. Please try again.';
            logActivity(0, null, 'login_failed', 'Failed login attempt for: ' . $email, getClientIp());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
</head>

<body class="login-page">

    <!-- Background particles -->
    <div style="position:absolute;inset:0;overflow:hidden;pointer-events:none;">
        <div
            style="position:absolute;width:300px;height:300px;border-radius:50%;background:rgba(99,102,241,.08);top:-80px;right:-60px;filter:blur(40px)">
        </div>
        <div
            style="position:absolute;width:400px;height:400px;border-radius:50%;background:rgba(139,92,246,.06);bottom:-100px;left:-80px;filter:blur(60px)">
        </div>
    </div>

    <div class="login-card">
        <!-- Brand -->
        <div class="login-brand">
            <div class="login-logo">
                <img src="<?= APP_URL ?>/public/images/logo.png" alt="<?= APP_NAME ?>" class="login-logo-img">
            </div>
            <h1 class="login-title"><?= APP_NAME ?></h1>
            <p class="login-subtitle"><?= COMPANY ?> &mdash; Internal Support Portal</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.83rem;border-radius:8px">
                <i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" novalidate>
            <div class="mb-3">
                <label for="email" class="form-label required">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0">
                        <i class="bi bi-envelope text-muted"></i>
                    </span>
                    <input type="email" id="email" name="email" class="form-control border-start-0"
                        value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@belmont.ph" required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label for="password" class="form-label required">Password</label>
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0">
                        <i class="bi bi-lock text-muted"></i>
                    </span>
                    <input type="password" id="password" name="password"
                        class="form-control border-start-0 border-end-0" placeholder="Enter your password" required>
                    <button class="input-group-text bg-light border-start-0 toggle-pwd" type="button" id="togglePwd"
                        style="cursor:pointer">
                        <i class="bi bi-eye text-muted" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-lg" style="border-radius:8px;font-weight:600">
                <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
            </button>
            <div class="text-center mt-3">
                <a href="<?= APP_URL ?>/forgot-password.php" style="font-size:.82rem;color:var(--text-muted);text-decoration:none">
                    Forgot your password?
                </a>
            </div>
        </form>

        <hr class="my-4">

        <!-- Demo Credentials -->
        <div style="background:#f8fafc;border-radius:8px;padding:.875rem;font-size:.75rem;">
            <p class="fw-600 mb-1" style="font-size:.78rem">Demo Credentials</p>
            <div class="row g-1">
                <div class="col-12">
                    <span class="badge bg-danger me-1">Admin</span>
                    admin@belmont.ph &nbsp;/ &nbsp;<code>password</code>
                </div>
                <div class="col-12 mt-1">
                    <span class="badge bg-primary me-1">Staff</span>
                    it@belmont.ph &nbsp;/ &nbsp;<code>password</code>
                </div>
                <div class="col-12 mt-1">
                    <span class="badge bg-secondary me-1">User</span>
                    accounting@belmont.ph &nbsp;/ &nbsp;<code>password</code>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('togglePwd').addEventListener('click', function () {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('bi-eye', 'bi-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('bi-eye-slash', 'bi-eye');
            }
        });
    </script>
</body>

</html>