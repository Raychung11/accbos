<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$companyFilter = getInt('company_id');
$statusFilter  = getStr('local_status');

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
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$sql = "SELECT so.id, so.reference_no, so.customer_name, so.doc_date,
               so.local_status, so.accounting_status, so.accounting_doc_no,
               c.company_name
        FROM sales_orders so
        JOIN companies c ON c.company_id = so.company_id
        {$where}
        ORDER BY so.id DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$companies = fetch_companies_active();

$pageTitle = 'Sales Orders';
$activeNav = 'sales_orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="m-0">Sales Orders</h4>
    <a class="btn btn-primary accbos-btn-primary"
       href="<?= e(url('/admin/sales_order_create.php')) ?>">+ New Sales Order</a>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
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
    <div class="col-md-3">
        <select name="local_status" class="form-select">
            <option value="">All statuses</option>
            <?php foreach (['draft','ready_to_push','pushed','failed','cancelled'] as $s): ?>
                <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary">Filter</button>
        <?php if ($companyFilter > 0 || $statusFilter !== ''): ?>
            <a class="btn btn-link" href="<?= e(url('/admin/sales_orders.php')) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
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
                    <tr><td colspan="9" class="text-center text-muted py-4">No sales orders.</td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?= e((string)$row['id']) ?></td>
                        <td><?= e($row['reference_no'] ?? '') ?></td>
                        <td><?= e($row['company_name']) ?></td>
                        <td><?= e($row['customer_name']) ?></td>
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
