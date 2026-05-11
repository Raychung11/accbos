<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$search = getStr('q');

if ($search !== '') {
    $stmt = $pdo->prepare(
        "SELECT company_id, company_name, registration_no, accounting_system, status, updated_at
         FROM companies
         WHERE company_name LIKE :s OR registration_no LIKE :s OR contact_person LIKE :s
         ORDER BY company_name ASC LIMIT 200"
    );
    $stmt->execute([':s' => '%' . $search . '%']);
} else {
    $stmt = $pdo->query(
        "SELECT company_id, company_name, registration_no, accounting_system, status, updated_at
         FROM companies ORDER BY company_name ASC LIMIT 200"
    );
}
$rows = $stmt->fetchAll();

$pageTitle = 'Companies';
$activeNav = 'companies';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="m-0">Companies</h4>
    <a class="btn btn-primary accbos-btn-primary"
       href="<?= e(url('/admin/company_form.php')) ?>">+ Add Company</a>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" class="form-control" name="q" value="<?= e($search) ?>"
               placeholder="Search by name, reg. no, or contact">
    </div>
    <div class="col-auto">
        <button class="btn btn-outline-secondary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn-link" href="<?= e(url('/admin/companies.php')) ?>">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Company</th>
                    <th>Registration</th>
                    <th>Accounting</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">
                        No companies found. Click "Add Company" to create your first one.
                    </td></tr>
                <?php else: foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e((string)$row['company_id']) ?></td>
                        <td><?= e($row['company_name']) ?></td>
                        <td><?= e($row['registration_no'] ?? '') ?></td>
                        <td><span class="badge bg-secondary"><?= e($row['accounting_system']) ?></span></td>
                        <td><?= status_badge((string)$row['status']) ?></td>
                        <td class="small text-muted"><?= e((string)($row['updated_at'] ?? '')) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="<?= e(url('/admin/company_detail.php?id=' . (int)$row['company_id'])) ?>">View</a>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(url('/admin/company_form.php?id=' . (int)$row['company_id'])) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
