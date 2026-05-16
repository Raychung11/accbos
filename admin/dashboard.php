<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();

$totalCompanies = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$totalSO        = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders")->fetchColumn();
$pushedSO       = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders
                                    WHERE accounting_status = 'success'")->fetchColumn();
$failedSO       = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders
                                    WHERE accounting_status = 'failed'")->fetchColumn();
$pendingQueue   = (int)$pdo->query("SELECT COUNT(*) FROM sync_queue
                                    WHERE status IN ('pending','processing')")->fetchColumn();

$latestLogs = $pdo->query(
    "SELECT id, company_id, module, action, endpoint, status, http_status, created_at
     FROM api_logs ORDER BY id DESC LIMIT 10"
)->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card accbos-stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Companies</div>
                <div class="fs-3 fw-bold"><?= e((string)$totalCompanies) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card accbos-stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">Total SO</div>
                <div class="fs-3 fw-bold"><?= e((string)$totalSO) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card accbos-stat-card h-100 border-success">
            <div class="card-body">
                <div class="text-muted small">SO Pushed</div>
                <div class="fs-3 fw-bold text-success"><?= e((string)$pushedSO) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card accbos-stat-card h-100 border-danger">
            <div class="card-body">
                <div class="text-muted small">Failed SO</div>
                <div class="fs-3 fw-bold text-danger"><?= e((string)$failedSO) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card accbos-stat-card h-100 border-warning">
            <div class="card-body">
                <div class="text-muted small">Pending Sync</div>
                <div class="fs-3 fw-bold text-warning"><?= e((string)$pendingQueue) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card accbos-stat-card h-100">
            <div class="card-body">
                <div class="text-muted small">App Version</div>
                <div class="fs-5 fw-bold"><?= e(APP_VERSION) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Latest API Logs</strong>
                <a class="btn btn-sm btn-outline-primary"
                   href="<?= e(url('/admin/api_logs.php')) ?>">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Module</th>
                            <th>Action</th>
                            <th>Endpoint</th>
                            <th>HTTP</th>
                            <th>Status</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($latestLogs)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No API calls yet.</td></tr>
                    <?php else: foreach ($latestLogs as $log): ?>
                        <tr>
                            <td><?= e((string)$log['id']) ?></td>
                            <td><?= e($log['module'] ?? '') ?></td>
                            <td><?= e($log['action'] ?? '') ?></td>
                            <td class="text-truncate" style="max-width: 220px;"><?= e($log['endpoint'] ?? '') ?></td>
                            <td><?= e((string)($log['http_status'] ?? '')) ?></td>
                            <td><?= status_badge((string)$log['status']) ?></td>
                            <td class="small text-muted"><?= e((string)$log['created_at']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-5">
        <div class="card h-100">
            <div class="card-header"><strong>Quick Actions</strong></div>
            <div class="card-body d-flex flex-column gap-2">
                <a class="btn btn-primary accbos-btn-primary"
                   href="<?= e(url('/admin/sales_order_create.php')) ?>">+ New Sales Order</a>
                <a class="btn btn-outline-primary"
                   href="<?= e(url('/admin/company_form.php')) ?>">+ Add Company</a>
                <a class="btn btn-outline-secondary"
                   href="<?= e(url('/admin/sync_queue.php')) ?>">Open Sync Queue</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
