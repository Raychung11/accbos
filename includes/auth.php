<?php
declare(strict_types=1);

/**
 * Authentication / session bootstrap for ACCBOS admin pages.
 * Include this from the top of every admin page that requires login.
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/**
 * True when an admin is logged in and the session has not timed out.
 */
function auth_check(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    $lastSeen = (int)($_SESSION['last_seen'] ?? 0);
    if ($lastSeen > 0 && (time() - $lastSeen) > SESSION_IDLE_TIMEOUT) {
        auth_logout();
        return false;
    }
    $_SESSION['last_seen'] = time();
    return true;
}

/**
 * Guard: redirect to login if not authenticated.
 */
function require_login(): void
{
    if (!auth_check()) {
        flash('warning', 'Please sign in to continue.');
        redirect('/admin/login.php');
    }
}

/**
 * Attempt to authenticate by username/password. Returns true on success.
 */
function auth_attempt(string $username, string $password): bool
{
    $stmt = db()->prepare("SELECT id, username, full_name, password_hash, role, status
                           FROM admins WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $admin = $stmt->fetch();
    if (!$admin || $admin['status'] !== 'active') {
        return false;
    }
    if (!password_verify($password, $admin['password_hash'])) {
        return false;
    }

    // Refresh ID to prevent session fixation
    session_regenerate_id(true);

    $_SESSION['admin_id']        = (int)$admin['id'];
    $_SESSION['admin_username']  = $admin['username'];
    $_SESSION['admin_full_name'] = $admin['full_name'];
    $_SESSION['admin_role']      = $admin['role'];
    $_SESSION['last_seen']       = time();

    // Update last login timestamp
    $upd = db()->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = :id");
    $upd->execute([':id' => (int)$admin['id']]);

    return true;
}

/**
 * Tear down the current session.
 */
function auth_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Convenience for templates.
 */
function current_admin(): array
{
    return [
        'id'        => (int)($_SESSION['admin_id'] ?? 0),
        'username'  => (string)($_SESSION['admin_username'] ?? ''),
        'full_name' => (string)($_SESSION['admin_full_name'] ?? ''),
        'role'      => (string)($_SESSION['admin_role'] ?? ''),
    ];
}
