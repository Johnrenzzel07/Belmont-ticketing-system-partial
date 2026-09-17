<?php
/**
 * Forgot Password — Step 1: Email Input
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$sent = false;
$error = '';
$devLink = ''; // shown locally when MAIL_ENABLED is false

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $pdo = db();
        $user = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $user->execute([$email]);
        $user = $user->fetch();

        if ($user) {
            // Invalidate old tokens for this user
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")
                ->execute([$user['id']]);

            // Generate token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)")
                ->execute([$user['id'], $token, $expires]);

            $resetUrl = APP_URL . '/reset-password.php?token=' . $token;

            // Send email
            $html = mailTemplate(
                'Reset Your Password',
                "<p>Hi <strong>{$user['name']}</strong>,</p>
                 <p>We received a request to reset the password for your Belmont Helpdesk account.</p>
                 <p>Click the button below to create a new password. This link expires in <strong>1 hour</strong>.</p>
                 <p><a href=\"{$resetUrl}\" class=\"btn\">Reset My Password</a></p>
                 <hr class=\"divider\">
                 <p style=\"font-size:.78rem;color:#94a3b8\">If you did not request this, you can safely ignore this email. Your password will not change.</p>"
            );

            sendMail(['email' => $user['email'], 'name' => $user['name']], 'Reset Your Password — ' . APP_NAME, $html);

            // Always show success to prevent email enumeration
            $sent = true;

            // Dev mode: show link on screen
            if (!MAIL_ENABLED) {
                $devLink = $resetUrl;
            }
        } else {
            // Don't leak whether email exists
            $sent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/main.css">
</head>

<body class="login-page">

    <div style="position:absolute;inset:0;overflow:hidden;pointer-events:none;">
        <div
            style="position:absolute;width:300px;height:300px;border-radius:50%;background:rgba(99,102,241,.08);top:-80px;right:-60px;filter:blur(40px)">
        </div>
        <div
            style="position:absolute;width:400px;height:400px;border-radius:50%;background:rgba(139,92,246,.06);bottom:-100px;left:-80px;filter:blur(60px)">
        </div>
    </div>

    <div class="login-card">
        <div class="login-brand">
            <div class="login-logo">
                <img src="<?= APP_URL ?>/public/images/logo.png" alt="<?= APP_NAME ?>" class="login-logo-img">
            </div>
            <h1 class="login-title">Forgot Password</h1>
            <p class="login-subtitle">Enter your email and we'll send you a reset link</p>
        </div>

        <?php if ($sent): ?>
            <div class="alert alert-success py-2 px-3 mb-3" style="font-size:.83rem;border-radius:8px">
                <i class="bi bi-envelope-check me-1"></i>
                If that email is registered, you'll receive a reset link shortly. Check your inbox (and spam folder).
            </div>
            <?php if ($devLink): ?>
                <div class="alert alert-warning py-2 px-3 mb-3" style="font-size:.78rem;border-radius:8px">
                    <strong>Dev mode</strong> — Email not sent (MAIL_ENABLED = false).<br>
                    <a href="<?= e($devLink) ?>" style="word-break:break-all"><?= e($devLink) ?></a>
                </div>
            <?php endif; ?>
        <?php elseif ($error): ?>
            <div class="alert alert-danger py-2 px-3 mb-3" style="font-size:.83rem;border-radius:8px">
                <i class="bi bi-exclamation-circle me-1"></i><?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!$sent): ?>
            <form method="POST" novalidate>
                <div class="mb-3">
                    <label for="email" class="form-label required">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i
                                class="bi bi-envelope text-muted"></i></span>
                        <input type="email" id="email" name="email" class="form-control border-start-0"
                            value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@belmont.ph" required autofocus>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 btn-lg" style="border-radius:8px;font-weight:600">
                    <i class="bi bi-send me-1"></i>Send Reset Link
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