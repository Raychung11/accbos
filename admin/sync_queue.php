<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$statusFilter = getStr('status');

$conds  = [];
$params = [];
if ($statusFilter !== '') {
    $conds[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$sql = "SELECT * FROM sync_queue {$where} ORDER BY id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Sync Queue';
$activeNav = 'sync_queue';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="m-0">Sync Queue</h4>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">All statuses</option>
            <?php foreach (['pending','processing','success','failed'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary">Filter</button>
        <?php if ($statusFilter !== ''): ?>
            <a class="btn btn-link" href="<?= e(url('/admin/sync_queue.php')) ?>">Clear</a>
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
                    <th>Record</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Last Error</th>
                    <th>Updated</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Queue is empty.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?= e((string)$row['id']) ?></td>
                        <td><?= e($row['module']) ?></td>
                        <td><?= e($row['action']) ?></td>
                        <td>
                            <?php if ($row['action'] === 'sales_order_push'): ?>
                                <a href="<?= e(url('/admin/sales_order_detail.php?id=' . (int)$row['record_id'])) ?>">
                                    SO #<?= e((string)$row['record_id']) ?>
                                </a>
                            <?php else: ?>
                                <?= e((string)$row['record_id']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= status_badge((string)$row['status']) ?></td>
                        <td><?= e((string)$row['attempt_count']) ?></td>
                        <td class="text-truncate small text-muted" style="max-width:300px;">
                            <?= e($row['last_error'] ?? '') ?>
                        </td>
                        <td class="small text-muted"><?= e((string)($row['updated_at'] ?? '')) ?></td>
                        <td class="text-end">
                            <?php if (in_array($row['status'], ['pending','failed'], true)): ?>
                                <form method="post" action="<?= e(url('/admin/sync_retry.php')) ?>"
                                      class="d-inline">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                    <button class="btn btn-sm btn-outline-primary"
                                            onclick="return confirm('Retry this queue item?');">
                                        Retry
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
