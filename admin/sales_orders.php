<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$companyFilter    = getInt('company_id');
$statusFilter     = getStr('local_status');
$acctStatusFilter = getStr('accounting_status');
$search           = getStr('q');

$conds  = [];
$params = [];
if ($companyFilter > 0) {
    $conds[] = 'so.company_id = :company_id';
    $params[':company_id'] = $companyFilter;
}
if ($statusFilter !== '') {
    $conds[] = 'so.local_status = :local_status';
    $params[':local_status'] = $statusFilter;
}
if ($acctStatusFilter !== '') {
    $conds[] = 'so.accounting_status = :accounting_status';
    $params[':accounting_status'] = $acctStatusFilter;
}
if ($search !== '') {
    $conds[] = '(so.reference_no LIKE :s OR so.customer_name LIKE :s OR so.customer_code LIKE :s OR so.accounting_doc_no LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$sql = "SELECT so.id, so.reference_no, so.customer_name, so.customer_code, so.doc_date,
               so.local_status, so.accounting_status, so.accounting_doc_no,
               c.company_name
        FROM sales_orders so
        JOIN companies c ON c.company_id = so.company_id
        {$where}
        ORDER BY so.id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$companies   = fetch_companies_active();
$hasFilters  = $companyFilter > 0 || $statusFilter !== '' || $acctStatusFilter !== '' || $search !== '';
$returnTo    = str_replace(["\r", "\n"], '', (string)($_SERVER['REQUEST_URI'] ?? '/admin/sales_orders.php'));
$pushable    = static fn(string $s): bool => in_array($s, ['draft', 'ready_to_push', 'failed'], true);
$pushableCount = 0;
foreach ($rows as $r) {
    if ($pushable((string)$r['local_status'])) {
        $pushableCount++;
    }
}

$pageTitle = 'Sales Orders';
$activeNav = 'sales_orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="m-0">Sales Orders</h4>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary"
           href="<?= e(url('/admin/sales_order_import.php')) ?>">Import CSV</a>
        <a class="btn btn-primary accbos-btn-primary"
           href="<?= e(url('/admin/sales_order_create.php')) ?>">+ New Sales Order</a>
    </div>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <input type="text" class="form-control" name="q" value="<?= e($search) ?>"
               placeholder="Search ref / customer / doc no">
    </div>
    <div class="col-md-3">
        <select name="company_id" class="form-select">
            <option value="0">All companies</option>
            <?php foreach ($companies as $c): ?>
                <option value="<?= (int)$c['company_id'] ?>"
                    <?= $companyFilter === (int)$c['company_id'] ? 'selected' : '' ?>>
                    <?= e($c['company_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="local_status" class="form-select">
            <option value="">Local: all</option>
            <?php foreach (['draft','ready_to_push','pushed','failed','cancelled'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="accounting_status" class="form-select">
            <option value="">Acct: all</option>
            <?php foreach (['pending','success','failed'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $acctStatusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary">Filter</button>
        <?php if ($hasFilters): ?>
            <a class="btn btn-link" href="<?= e(url('/admin/sales_orders.php')) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<form method="post" action="<?= e(url('/admin/sales_order_bulk_push.php')) ?>" id="bulkForm"
      onsubmit="return confirm('Push all selected sales orders now?');">
    <?= csrf_input() ?>
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span class="small text-muted">
                <?= e((string)count($rows)) ?> result<?= count($rows) === 1 ? '' : 's' ?>
                <?= count($rows) === 200 ? ' (showing first 200)' : '' ?>
                <span id="selCount" class="ms-2"></span>
            </span>
            <button type="submit" class="btn btn-sm btn-primary accbos-btn-primary"
                    id="bulkPushBtn" disabled>
                Push selected
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:36px;">
                            <input type="checkbox" class="form-check-input" id="selectAll"
                                   <?= $pushableCount === 0 ? 'disabled' : '' ?>
                                   title="Select all pushable rows">
                        </th>
                        <th>#</th>
                        <th>Reference</th>
                        <th>Company</th>
                        <th>Customer</th>
                        <th>Doc Date</th>
                        <th>Local</th>
                        <th>Accounting</th>
                        <th>Doc No</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">
                            No sales orders<?= $hasFilters ? ' match these filters.' : ' yet.' ?>
                        </td></tr>
                    <?php else: foreach ($rows as $row): ?>
                        <tr class="<?= in_array($row['local_status'], ['cancelled'], true) ? 'text-muted' : '' ?>">
                            <td>
                                <?php if ($pushable((string)$row['local_status'])): ?>
                                    <input type="checkbox" class="form-check-input bulk-cb"
                                           name="ids[]" value="<?= (int)$row['id'] ?>">
                                <?php endif; ?>
                            </td>
                            <td>#<?= e((string)$row['id']) ?></td>
                            <td><?= e($row['reference_no'] ?? '') ?></td>
                            <td><?= e($row['company_name']) ?></td>
                            <td><?= e($row['customer_name']) ?>
                                <span class="text-muted small">(<?= e($row['customer_code']) ?>)</span></td>
                            <td><?= e((string)$row['doc_date']) ?></td>
                            <td><?= status_badge((string)$row['local_status']) ?></td>
                            <td><?= status_badge((string)$row['accounting_status']) ?></td>
                            <td><?= e($row['accounting_doc_no'] ?? '') ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?= e(url('/admin/sales_order_detail.php?id=' . (int)$row['id'])) ?>">View</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
