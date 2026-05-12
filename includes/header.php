<?php
declare(strict_types=1);

/**
 * Shared layout header for ACCBOS admin pages.
 * Expects $pageTitle to be set before inclusion (optional).
 */

if (!defined('ACCBOS_APP')) {
    require_once __DIR__ . '/../config/app_config.php';
}
require_once __DIR__ . '/functions.php';

$pageTitle  = isset($pageTitle) ? (string)$pageTitle : 'Dashboard';
$activeNav  = isset($activeNav) ? (string)$activeNav : '';
$admin      = function_exists('current_admin') ? current_admin() : ['username' => '', 'full_name' => ''];
$flash      = function_exists('flash_pull') ? flash_pull() : [];
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
<body class="bg-light">
<nav class="navbar navbar-expand-lg accbos-navbar shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold text-white" href="<?= e(url('/admin/dashboard.php')) ?>">
            <?= e(APP_NAME) ?>
            <small class="text-white-50 fw-normal ms-1">/ <?= e(APP_FULL_NAME) ?></small>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>"
                       href="<?= e(url('/admin/dashboard.php')) ?>">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeNav === 'companies' ? 'active' : '' ?>"
                       href="<?= e(url('/admin/companies.php')) ?>">Companies</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeNav === 'sales_orders' ? 'active' : '' ?>"
                       href="<?= e(url('/admin/sales_orders.php')) ?>">Sales Orders</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeNav === 'sync_queue' ? 'active' : '' ?>"
                       href="<?= e(url('/admin/sync_queue.php')) ?>">Sync Queue</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeNav === 'api_logs' ? 'active' : '' ?>"
                       href="<?= e(url('/admin/api_logs.php')) ?>">API Logs</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeNav === 'mock_inbox' ? 'active' : '' ?>"
                       href="<?= e(url('/mock/sql_account/inbox.php')) ?>"
                       title="Mock SQL Account inbox (dev tool)">Mock</a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" id="userMenu"
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?= e($admin['full_name'] ?: $admin['username'] ?: 'Guest') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                        <li><span class="dropdown-item-text small text-muted">
                            Role: <?= e($admin['username'] !== '' ? ($_SESSION['admin_role'] ?? 'admin') : '-') ?>
                        </span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(url('/admin/change_password.php')) ?>">Change password</a></li>
                        <li><a class="dropdown-item" href="<?= e(url('/admin/logout.php')) ?>">Sign out</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="container-fluid py-4">
    <?php if (!empty($flash)): ?>
        <?php foreach ($flash as $msg): ?>
            <div class="alert alert-<?= e($msg['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($msg['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
