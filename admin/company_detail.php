<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$id  = getInt('id');
if ($id <= 0) {
    flash('warning', 'Company id missing.');
    redirect('/admin/companies.php');
}

$stmt = $pdo->prepare("SELECT * FROM companies WHERE company_id = :id");
$stmt->execute([':id' => $id]);
$company = $stmt->fetch();
if (!$company) {
    flash('danger', 'Company not found.');
    redirect('/admin/companies.php');
}

$soStmt = $pdo->prepare(
    "SELECT id, reference_no, customer_name, doc_date, local_status, accounting_status, accounting_doc_no
     FROM sales_orders WHERE company_id = :id
     ORDER BY id DESC LIMIT 25"
);
$soStmt->execute([':id' => $id]);
$recentOrders = $soStmt->fetchAll();

$pageTitle = 'Company · ' . $company['company_name'];
$activeNav = 'companies';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="m-0"><?= e($company['company_name']) ?></h4>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary"
           href="<?= e(url('/admin/companies.php')) ?>">Back</a>
        <a class="btn btn-primary accbos-btn-primary"
           href="<?= e(url('/admin/company_form.php?id=' . (int)$company['company_id'])) ?>">Edit</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>Profile</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">ID</dt>
                    <dd class="col-sm-8"><?= e((string)$company['company_id']) ?></dd>

                    <dt class="col-sm-4">Registration</dt>
                    <dd class="col-sm-8"><?= e($company['registration_no'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Contact</dt>
                    <dd class="col-sm-8"><?= e($company['contact_person'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Phone</dt>
                    <dd class="col-sm-8"><?= e($company['phone'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8"><?= e($company['email'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8"><?= status_badge((string)$company['status']) ?></dd>

                    <dt class="col-sm-4">Created</dt>
                    <dd class="col-sm-8 small text-muted"><?= e((string)$company['created_at']) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>Accounting Connector</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">System</dt>
                    <dd class="col-sm-8"><span class="badge bg-secondary"><?= e($company['accounting_system']) ?></span></dd>

                    <dt class="col-sm-4">Base URL</dt>
                    <dd class="col-sm-8 text-break"><?= e($company['api_base_url'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Access Key</dt>
                    <dd class="col-sm-8"><?= e(mask_secret($company['api_access_key'] ?? null)) ?: '-' ?></dd>

                    <dt class="col-sm-4">Secret Key</dt>
                    <dd class="col-sm-8">
                        <?= !empty($company['api_secret_key']) ? '<em>configured</em> (' .
                            e(mask_secret($company['api_secret_key'])) . ')' : '-' ?>
                    </dd>

                    <dt class="col-sm-4">Region</dt>
                    <dd class="col-sm-8"><?= e($company['api_region'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Service</dt>
                    <dd class="col-sm-8"><?= e($company['api_service'] ?? '-') ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong>Recent Sales Orders</strong>
        <a class="btn btn-sm btn-outline-primary"
           href="<?= e(url('/admin/sales_orders.php?company_id=' . (int)$company['company_id'])) ?>">View all</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Reference</th>
                    <th>Customer</th>
                    <th>Doc Date</th>
                    <th>Local</th>
                    <th>Accounting</th>
                    <th>Doc No</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentOrders)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No sales orders yet.</td></tr>
                <?php else: foreach ($recentOrders as $so): ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/sales_order_detail.php?id=' . (int)$so['id'])) ?>">
                            #<?= e((string)$so['id']) ?></a></td>
                        <td><?= e($so['reference_no'] ?? '') ?></td>
                        <td><?= e($so['customer_name']) ?></td>
                        <td><?= e((string)$so['doc_date']) ?></td>
                        <td><?= status_badge((string)$so['local_status']) ?></td>
                        <td><?= status_badge((string)$so['accounting_status']) ?></td>
                        <td><?= e($so['accounting_doc_no'] ?? '') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
