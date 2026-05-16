<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$id  = getInt('id');
$isEdit = $id > 0;

$company = [
    'company_id'        => 0,
    'company_name'      => '',
    'registration_no'   => '',
    'contact_person'    => '',
    'phone'             => '',
    'email'             => '',
    'accounting_system' => 'sql_account',
    'api_base_url'      => '',
    'api_access_key'    => '',
    'api_secret_key'    => '',
    'api_region'        => '',
    'api_service'       => '',
    'status'            => 'active',
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE company_id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Company not found.');
        redirect('/admin/companies.php');
    }
    $company = array_merge($company, $row);
}

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $company['company_name']      = postStr('company_name');
    $company['registration_no']   = postStr('registration_no');
    $company['contact_person']    = postStr('contact_person');
    $company['phone']             = postStr('phone');
    $company['email']             = postStr('email');
    $company['accounting_system'] = postStr('accounting_system', 'sql_account');
    $company['api_base_url']      = postStr('api_base_url');
    $company['api_access_key']    = postStr('api_access_key');
    $newSecret                    = postStr('api_secret_key');
    $company['api_region']        = postStr('api_region');
    $company['api_service']       = postStr('api_service');
    $company['status']            = postStr('status', 'active');

    if ($company['company_name'] === '') {
        $errors[] = 'Company name is required.';
    }
    $allowedSystems = ['sql_account','autocount','ubs','bukku','manual_csv'];
    if (!in_array($company['accounting_system'], $allowedSystems, true)) {
        $errors[] = 'Invalid accounting system.';
    }
    if (!in_array($company['status'], ['active','inactive'], true)) {
        $errors[] = 'Invalid status.';
    }
    if ($company['email'] !== '' && !filter_var($company['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email is not valid.';
    }
    if ($company['api_base_url'] !== '' && !filter_var($company['api_base_url'], FILTER_VALIDATE_URL)) {
        $errors[] = 'API base URL must be a valid URL.';
    }

    if (empty($errors)) {
        if ($isEdit) {
            $sql = "UPDATE companies SET
                        company_name = :company_name,
                        registration_no = :registration_no,
                        contact_person = :contact_person,
                        phone = :phone,
                        email = :email,
                        accounting_system = :accounting_system,
                        api_base_url = :api_base_url,
                        api_access_key = :api_access_key,
                        " . ($newSecret !== '' ? "api_secret_key = :api_secret_key," : "") . "
                        api_region = :api_region,
                        api_service = :api_service,
                        status = :status
                    WHERE company_id = :company_id";
            $params = [
                ':company_name'      => $company['company_name'],
                ':registration_no'   => $company['registration_no'] ?: null,
                ':contact_person'    => $company['contact_person'] ?: null,
                ':phone'             => $company['phone'] ?: null,
                ':email'             => $company['email'] ?: null,
                ':accounting_system' => $company['accounting_system'],
                ':api_base_url'      => $company['api_base_url'] ?: null,
                ':api_access_key'    => $company['api_access_key'] ?: null,
                ':api_region'        => $company['api_region'] ?: null,
                ':api_service'       => $company['api_service'] ?: null,
                ':status'            => $company['status'],
                ':company_id'        => $id,
            ];
            if ($newSecret !== '') {
                $params[':api_secret_key'] = $newSecret;
            }
            $pdo->prepare($sql)->execute($params);
            flash('success', 'Company updated.');
            redirect('/admin/company_detail.php?id=' . $id);
        } else {
            $sql = "INSERT INTO companies
                    (company_name, registration_no, contact_person, phone, email,
                     accounting_system, api_base_url, api_access_key, api_secret_key,
                     api_region, api_service, status)
                    VALUES
                    (:company_name, :registration_no, :contact_person, :phone, :email,
                     :accounting_system, :api_base_url, :api_access_key, :api_secret_key,
                     :api_region, :api_service, :status)";
            $pdo->prepare($sql)->execute([
                ':company_name'      => $company['company_name'],
                ':registration_no'   => $company['registration_no'] ?: null,
                ':contact_person'    => $company['contact_person'] ?: null,
                ':phone'             => $company['phone'] ?: null,
                ':email'             => $company['email'] ?: null,
                ':accounting_system' => $company['accounting_system'],
                ':api_base_url'      => $company['api_base_url'] ?: null,
                ':api_access_key'    => $company['api_access_key'] ?: null,
                ':api_secret_key'    => $newSecret ?: null,
                ':api_region'        => $company['api_region'] ?: null,
                ':api_service'       => $company['api_service'] ?: null,
                ':status'            => $company['status'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            flash('success', 'Company created.');
            redirect('/admin/company_detail.php?id=' . $newId);
        }
    }
}

$pageTitle = $isEdit ? 'Edit Company' : 'Add Company';
$activeNav = 'companies';
require __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-3"><?= e($pageTitle) ?></h4>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" autocomplete="off">
    <?= csrf_input() ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Company Profile</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label">Company Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="company_name"
                       value="<?= e($company['company_name']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Registration No.</label>
                <input type="text" class="form-control" name="registration_no"
                       value="<?= e($company['registration_no'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Contact Person</label>
                <input type="text" class="form-control" name="contact_person"
                       value="<?= e($company['contact_person'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" name="phone"
                       value="<?= e($company['phone'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email"
                       value="<?= e($company['email'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active"   <?= $company['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $company['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Accounting Connector</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">Accounting System</label>
                <select name="accounting_system" class="form-select">
                    <option value="sql_account" <?= $company['accounting_system'] === 'sql_account' ? 'selected' : '' ?>>SQL Account</option>
                    <option value="autocount"   <?= $company['accounting_system'] === 'autocount'   ? 'selected' : '' ?>>AutoCount</option>
                    <option value="ubs"         <?= $company['accounting_system'] === 'ubs'         ? 'selected' : '' ?>>UBS</option>
                    <option value="bukku"       <?= $company['accounting_system'] === 'bukku'       ? 'selected' : '' ?>>Bukku</option>
                    <option value="manual_csv"  <?= $company['accounting_system'] === 'manual_csv'  ? 'selected' : '' ?>>Manual CSV</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">API Base URL</label>
                <input type="url" class="form-control" name="api_base_url"
                       value="<?= e($company['api_base_url'] ?? '') ?>"
                       placeholder="https://api.sql.com.my">
            </div>
            <div class="col-md-6">
                <label class="form-label">API Access Key</label>
                <input type="text" class="form-control" name="api_access_key"
                       value="<?= e($company['api_access_key'] ?? '') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">
                    API Secret Key
                    <?php if ($isEdit && !empty($company['api_secret_key'])): ?>
                        <small class="text-muted">(stored: <?= e(mask_secret($company['api_secret_key'])) ?>)</small>
                    <?php endif; ?>
                </label>
                <input type="password" class="form-control" name="api_secret_key"
                       value=""
                       placeholder="<?= $isEdit ? 'Leave blank to keep current' : '' ?>"
                       autocomplete="new-password">
                <div class="form-text">Stored encrypted. Never displayed in plain text after save.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">API Region</label>
                <input type="text" class="form-control" name="api_region"
                       value="<?= e($company['api_region'] ?? '') ?>"
                       placeholder="ap-southeast-1">
            </div>
            <div class="col-md-6">
                <label class="form-label">API Service</label>
                <input type="text" class="form-control" name="api_service"
                       value="<?= e($company['api_service'] ?? '') ?>"
                       placeholder="execute-api">
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary accbos-btn-primary">
            <?= $isEdit ? 'Save Changes' : 'Create Company' ?>
        </button>
        <a class="btn btn-link" href="<?= e(url('/admin/companies.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
