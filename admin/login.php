<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (auth_check()) {
    redirect('/admin/dashboard.php');
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $username = postStr('username');
    $password = postStr('password');

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (auth_attempt($username, $password)) {
        flash('success', 'Welcome back, ' . current_admin()['full_name'] . '.');
        redirect('/admin/dashboard.php');
    } else {
        $error = 'Invalid credentials. Please try again.';
    }
}

$pageTitle = 'Sign in';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e(url('/assets/css/style.css')) ?>">
</head>
<body class="accbos-login-bg">
<div class="d-flex align-items-center justify-content-center min-vh-100 py-5">
    <div class="card accbos-login-card shadow-lg border-0">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <h2 class="fw-bold mb-1"><?= e(APP_NAME) ?></h2>
                <p class="text-muted small mb-0"><?= e(APP_FULL_NAME) ?></p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off" novalidate>
                <?= csrf_input() ?>
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="<?= e($_POST['username'] ?? '') ?>" required autofocus>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100 accbos-btn-primary">Sign in</button>
            </form>

            <p class="text-center small text-muted mt-4 mb-0">
                v<?= e(APP_VERSION) ?>
            </p>
        </div>
    </div>
</div>
</body>
</html>
