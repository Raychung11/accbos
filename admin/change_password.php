<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$errors = [];
$pdo    = db();
$me     = current_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $current = postStr('current_password');
    $new     = postStr('new_password');
    $confirm = postStr('confirm_password');

    if ($current === '' || $new === '' || $confirm === '') {
        $errors[] = 'All fields are required.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New password and confirmation do not match.';
    }
    if ($new !== '' && $new === $current) {
        $errors[] = 'New password must be different from the current one.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $me['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($current, $row['password_hash'])) {
            $errors[] = 'Current password is incorrect.';
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $upd = $pdo->prepare("UPDATE admins SET password_hash = :h WHERE id = :id");
            $upd->execute([':h' => $newHash, ':id' => $me['id']]);

            // Force re-login for safety: rotate session and clear it.
            auth_logout();
            session_start();
            flash('success', 'Password changed. Please sign in with your new password.');
            redirect('/admin/login.php');
        }
    }
}

$pageTitle = 'Change Password';
$activeNav = '';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-12 col-md-8 col-lg-6">
        <h4 class="mb-3"><?= e($pageTitle) ?></h4>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off" novalidate>
                    <?= csrf_input() ?>

                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password"
                               class="form-control" required autofocus
                               autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password"
                               class="form-control" required minlength="8"
                               autocomplete="new-password">
                        <div class="form-text">At least 8 characters.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password"
                               class="form-control" required minlength="8"
                               autocomplete="new-password">
                    </div>

                    <button type="submit" class="btn btn-primary accbos-btn-primary">
                        Update Password
                    </button>
                    <a class="btn btn-link" href="<?= e(url('/admin/dashboard.php')) ?>">Cancel</a>
                </form>
            </div>
        </div>

        <p class="text-muted small mt-3 mb-0">
            You'll be signed out after a successful change and asked to sign in again.
        </p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
