<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();

$statusFilter = getStr('status');
$moduleFilter = getStr('module');
$logId        = getInt('id');

if ($logId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM api_logs WHERE id = :id");
    $stmt->execute([':id' => $logId]);
    $log = $stmt->fetch();
    if (!$log) {
        flash('warning', 'Log not found.');
        redirect('/admin/api_logs.php');
    }

    $pageTitle = 'API Log #' . (int)$log['id'];
    $activeNav = 'api_logs';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="m-0"><?= e($pageTitle) ?></h4>
        <a class="btn btn-outline-secondary" href="<?= e(url('/admin/api_logs.php')) ?>">Back</a>
    </div>
    <div class="row g-3">
        <div class="col-12 col-lg-5">
            <div class="card h-100">
                <div class="card-header"><strong>Summary</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">When</dt>
                        <dd class="col-sm-8"><?= e((string)$log['created_at']) ?></dd>

                        <dt class="col-sm-4">Module</dt>
                        <dd class="col-sm-8"><?= e($log['module'] ?? '') ?></dd>

                        <dt class="col-sm-4">Action</dt>
                        <dd class="col-sm-8"><?= e($log['action'] ?? '') ?></dd>

                        <dt class="col-sm-4">Endpoint</dt>
                        <dd class="col-sm-8 text-break"><?= e($log['endpoint'] ?? '') ?></dd>

                        <dt class="col-sm-4">HTTP Status</dt>
                        <dd class="col-sm-8"><?= e((string)($log['http_status'] ?? '')) ?></dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8"><?= status_badge((string)$log['status']) ?></dd>

                        <dt class="col-sm-4">Error</dt>
                        <dd class="col-sm-8"><?= e($log['error_message'] ?? '-') ?></dd>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><strong>Request</strong></div>
                <div class="card-body">
                    <pre class="mb-0 small text-wrap"><?= e($log['request_payload'] ?? '') ?></pre>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><strong>Response</strong></div>
                <div class="card-body">
                    <pre class="mb-0 small text-wrap"><?= e($log['response_payload'] ?? '') ?></pre>
                </div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$conds  = [];
$params = [];
if ($statusFilter !== '') {
    $conds[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
if ($moduleFilter !== '') {
    $conds[] = 'module = :module';
    $params[':module'] = $moduleFilter;
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$sql = "SELECT id, company_id, module, action, endpoint, http_status, status, created_at
        FROM api_logs {$where} ORDER BY id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'API Logs';
$activeNav = 'api_logs';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="m-0">API Logs</h4>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">All statuses</option>
            <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>success</option>
            <option value="failed"  <?= $statusFilter === 'failed'  ? 'selected' : '' ?>>failed</option>
        </select>
    </div>
    <div class="col-md-3">
        <input type="text" class="form-control" name="module" value="<?= e($moduleFilter) ?>"
               placeholder="Module (e.g. sql_account)">
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary">Filter</button>
        <?php if ($statusFilter !== '' || $moduleFilter !== ''): ?>
            <a class="btn btn-link" href="<?= e(url('/admin/api_logs.php')) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Module</th>
                    <th>Action</th>
                    <th>Endpoint</th>
                    <th>HTTP</th>
                    <th>Status</th>
                    <th>When</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No API logs yet.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e((string)$row['id']) ?></td>
                        <td><?= e($row['module'] ?? '') ?></td>
                        <td><?= e($row['action'] ?? '') ?></td>
                        <td class="text-truncate" style="max-width:300px;"><?= e($row['endpoint'] ?? '') ?></td>
                        <td><?= e((string)($row['http_status'] ?? '')) ?></td>
                        <td><?= status_badge((string)$row['status']) ?></td>
                        <td class="small text-muted"><?= e((string)$row['created_at']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= e(url('/admin/api_logs.php?id=' . (int)$row['id'])) ?>">View</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
