<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$id  = getInt('id');
if ($id <= 0) {
    flash('warning', 'Sales order id missing.');
    redirect('/admin/sales_orders.php');
}

$stmt = $pdo->prepare(
    "SELECT so.*, c.accounting_system, c.company_name
     FROM sales_orders so
     JOIN companies c ON c.company_id = so.company_id
     WHERE so.id = :id"
);
$stmt->execute([':id' => $id]);
$order = $stmt->fetch();
if (!$order) {
    flash('danger', 'Sales order not found.');
    redirect('/admin/sales_orders.php');
}
if ($order['local_status'] === 'pushed') {
    flash('warning', 'Pushed orders cannot be edited. Create a new one to amend.');
    redirect('/admin/sales_order_detail.php?id=' . $id);
}

$itemStmt = $pdo->prepare(
    "SELECT * FROM sales_order_items WHERE sales_order_id = :id ORDER BY id ASC"
);
$itemStmt->execute([':id' => $id]);
$existingItems = $itemStmt->fetchAll();

$companies = fetch_companies_active();

$form = [
    'company_id'      => (int)$order['company_id'],
    'customer_code'   => (string)$order['customer_code'],
    'customer_name'   => (string)$order['customer_name'],
    'customer_phone'  => (string)($order['customer_phone'] ?? ''),
    'customer_email'  => (string)($order['customer_email'] ?? ''),
    'doc_date'        => (string)$order['doc_date'],
    'required_date'   => (string)($order['required_date'] ?? ''),
    'reference_no'    => (string)($order['reference_no']  ?? ''),
    'remark'          => (string)($order['remark']        ?? ''),
];

$itemsForm = array_map(static function (array $line): array {
    return [
        'item_code'        => (string)$line['item_code'],
        'item_description' => (string)($line['item_description'] ?? ''),
        'qty'              => (float)$line['qty'],
        'unit_price'       => (float)$line['unit_price'],
        'discount'         => (float)$line['discount'],
        'tax_code'         => (string)($line['tax_code'] ?? ''),
    ];
}, $existingItems);

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    $form['company_id']     = (int)($_POST['company_id'] ?? 0);
    $form['customer_code']  = postStr('customer_code');
    $form['customer_name']  = postStr('customer_name');
    $form['customer_phone'] = postStr('customer_phone');
    $form['customer_email'] = postStr('customer_email');
    $form['doc_date']       = postStr('doc_date', date('Y-m-d'));
    $form['required_date']  = postStr('required_date');
    $form['reference_no']   = postStr('reference_no');
    $form['remark']         = postStr('remark');

    $itemsPost = $_POST['items'] ?? [];
    if (!is_array($itemsPost)) {
        $itemsPost = [];
    }
    $itemsForm = [];
    foreach ($itemsPost as $line) {
        if (!is_array($line)) {
            continue;
        }
        $itemsForm[] = [
            'item_code'        => trim((string)($line['item_code']        ?? '')),
            'item_description' => trim((string)($line['item_description'] ?? '')),
            'qty'              => (float)($line['qty']        ?? 0),
            'unit_price'       => (float)($line['unit_price'] ?? 0),
            'discount'         => (float)($line['discount']   ?? 0),
            'tax_code'         => trim((string)($line['tax_code'] ?? '')),
        ];
    }

    if ($form['company_id'] <= 0)      { $errors[] = 'Please select a company.'; }
    if ($form['customer_code'] === '') { $errors[] = 'Customer code is required.'; }
    if ($form['customer_name'] === '') { $errors[] = 'Customer name is required.'; }
    if ($form['doc_date'] === '')      { $errors[] = 'Document date is required.'; }
    if ($form['customer_email'] !== '' &&
        !filter_var($form['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Customer email is not valid.';
    }

    $cleanItems = [];
    foreach ($itemsForm as $i => $line) {
        if ($line['item_code'] === '' && $line['qty'] == 0 && $line['unit_price'] == 0) {
            continue;
        }
        $row = $i + 1;
        if ($line['item_code'] === '') { $errors[] = "Line {$row}: item code is required."; }
        if ($line['qty'] <= 0)         { $errors[] = "Line {$row}: qty must be > 0."; }
        if ($line['unit_price'] < 0)   { $errors[] = "Line {$row}: unit price must be >= 0."; }
        $line['amount'] = round(($line['qty'] * $line['unit_price']) - $line['discount'], 4);
        $cleanItems[] = $line;
    }
    if (empty($cleanItems)) {
        $errors[] = 'At least one item is required.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $headerSql = "UPDATE sales_orders SET
                            company_id = :company_id,
                            customer_code = :customer_code,
                            customer_name = :customer_name,
                            customer_phone = :customer_phone,
                            customer_email = :customer_email,
                            doc_date = :doc_date,
                            required_date = :required_date,
                            reference_no = :reference_no,
                            remark = :remark,
                            local_status = 'draft',
                            accounting_status = 'pending',
                            accounting_doc_no = NULL,
                            accounting_response = NULL
                          WHERE id = :id";
            $pdo->prepare($headerSql)->execute([
                ':company_id'      => $form['company_id'],
                ':customer_code'   => $form['customer_code'],
                ':customer_name'   => $form['customer_name'],
                ':customer_phone'  => $form['customer_phone'] ?: null,
                ':customer_email'  => $form['customer_email'] ?: null,
                ':doc_date'        => $form['doc_date'],
                ':required_date'   => $form['required_date'] ?: null,
                ':reference_no'    => $form['reference_no']  ?: null,
                ':remark'          => $form['remark']        ?: null,
                ':id'              => $id,
            ]);

            $pdo->prepare("DELETE FROM sales_order_items WHERE sales_order_id = :id")
                ->execute([':id' => $id]);

            $itemSql = "INSERT INTO sales_order_items
                (sales_order_id, item_code, item_description, qty, unit_price, discount, tax_code, amount)
                VALUES
                (:sales_order_id, :item_code, :item_description, :qty, :unit_price, :discount, :tax_code, :amount)";
            $itemStmtIns = $pdo->prepare($itemSql);
            foreach ($cleanItems as $line) {
                $itemStmtIns->execute([
                    ':sales_order_id'   => $id,
                    ':item_code'        => $line['item_code'],
                    ':item_description' => $line['item_description'] ?: null,
                    ':qty'              => $line['qty'],
                    ':unit_price'       => $line['unit_price'],
                    ':discount'         => $line['discount'],
                    ':tax_code'         => $line['tax_code'] ?: null,
                    ':amount'           => $line['amount'],
                ]);
            }

            // Any open sync_queue rows for this SO are stale — mark them cancelled.
            $pdo->prepare(
                "UPDATE sync_queue SET status = 'failed', last_error = 'Cancelled: SO was edited'
                 WHERE record_id = :rid AND action = 'sales_order_push'
                   AND status IN ('pending','processing')"
            )->execute([':rid' => $id]);

            $pdo->commit();
            flash('success', 'Sales order updated. Status reset to draft.');
            redirect('/admin/sales_order_detail.php?id=' . $id);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ACCBOS] SO edit failed: ' . $e->getMessage());
            $errors[] = 'Failed to save changes. Please try again.';
        }
    }
}

if (empty($itemsForm)) {
    $itemsForm = [['item_code'=>'','item_description'=>'','qty'=>1,'unit_price'=>0,'discount'=>0,'tax_code'=>'SST']];
}

$pageTitle = 'Edit Sales Order #' . (int)$order['id'];
$activeNav = 'sales_orders';
require __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-1"><?= e($pageTitle) ?></h4>
<p class="text-muted small mb-3">
    Saving resets <code>local_status</code> to <code>draft</code> and clears the
    previous accounting response so the order can be pushed cleanly.
</p>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" autocomplete="off" id="soForm">
    <?= csrf_input() ?>

    <div class="card mb-3">
        <div class="card-header"><strong>Header</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label">Company <span class="text-danger">*</span></label>
                <select name="company_id" class="form-select" required>
                    <option value="0">— select —</option>
                    <?php foreach ($companies as $c): ?>
                        <option value="<?= (int)$c['company_id'] ?>"
                            <?= $form['company_id'] === (int)$c['company_id'] ? 'selected' : '' ?>>
                            <?= e($c['company_name']) ?> (<?= e($c['accounting_system']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Reference No.</label>
                <input type="text" class="form-control" name="reference_no"
                       value="<?= e($form['reference_no']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Doc Date <span class="text-danger">*</span></label>
                <input type="date" class="form-control" name="doc_date"
                       value="<?= e($form['doc_date']) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Required Date</label>
                <input type="date" class="form-control" name="required_date"
                       value="<?= e($form['required_date']) ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Customer Code <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="customer_code"
                       value="<?= e($form['customer_code']) ?>" required>
            </div>
            <div class="col-md-5">
                <label class="form-label">Customer Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="customer_name"
                       value="<?= e($form['customer_name']) ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Phone</label>
                <input type="text" class="form-control" name="customer_phone"
                       value="<?= e($form['customer_phone']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="customer_email"
                       value="<?= e($form['customer_email']) ?>">
            </div>

            <div class="col-12">
                <label class="form-label">Remark</label>
                <input type="text" class="form-control" name="remark"
                       value="<?= e($form['remark']) ?>">
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Items</strong>
            <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn">+ Add Line</button>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle" id="itemsTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 14%;">Item Code</th>
                        <th>Description</th>
                        <th style="width: 10%;">Qty</th>
                        <th style="width: 12%;">Unit Price</th>
                        <th style="width: 10%;">Discount</th>
                        <th style="width: 8%;">Tax</th>
                        <th style="width: 12%;">Amount</th>
                        <th style="width: 40px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($itemsForm as $i => $line): ?>
                    <tr class="item-row">
                        <td><input type="text" name="items[<?= $i ?>][item_code]"
                                   class="form-control form-control-sm item-code"
                                   value="<?= e($line['item_code']) ?>"></td>
                        <td><input type="text" name="items[<?= $i ?>][item_description]"
                                   class="form-control form-control-sm"
                                   value="<?= e($line['item_description']) ?>"></td>
                        <td><input type="number" step="0.0001" min="0"
                                   name="items[<?= $i ?>][qty]"
                                   class="form-control form-control-sm item-qty"
                                   value="<?= e((string)$line['qty']) ?>"></td>
                        <td><input type="number" step="0.0001" min="0"
                                   name="items[<?= $i ?>][unit_price]"
                                   class="form-control form-control-sm item-price"
                                   value="<?= e((string)$line['unit_price']) ?>"></td>
                        <td><input type="number" step="0.0001" min="0"
                                   name="items[<?= $i ?>][discount]"
                                   class="form-control form-control-sm item-discount"
                                   value="<?= e((string)$line['discount']) ?>"></td>
                        <td><input type="text" name="items[<?= $i ?>][tax_code]"
                                   class="form-control form-control-sm"
                                   value="<?= e($line['tax_code']) ?>"></td>
                        <td><input type="text" class="form-control form-control-sm item-amount"
                                   value="0.00" readonly></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-link text-danger remove-item">×</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="6" class="text-end">Total</th>
                        <th><span id="grandTotal">0.00</span></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary accbos-btn-primary">Save Changes</button>
        <a class="btn btn-link"
           href="<?= e(url('/admin/sales_order_detail.php?id=' . $id)) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
