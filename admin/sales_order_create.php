<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$companies = fetch_companies_active();

$form = [
    'company_id'      => (int)($_POST['company_id'] ?? 0),
    'customer_code'   => postStr('customer_code'),
    'customer_name'   => postStr('customer_name'),
    'customer_phone'  => postStr('customer_phone'),
    'customer_email'  => postStr('customer_email'),
    'doc_date'        => postStr('doc_date', date('Y-m-d')),
    'required_date'   => postStr('required_date'),
    'reference_no'    => postStr('reference_no'),
    'remark'          => postStr('remark'),
];

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

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();

    if ($form['company_id'] <= 0)                 { $errors[] = 'Please select a company.'; }
    if ($form['customer_code'] === '')            { $errors[] = 'Customer code is required.'; }
    if ($form['customer_name'] === '')            { $errors[] = 'Customer name is required.'; }
    if ($form['doc_date'] === '')                 { $errors[] = 'Document date is required.'; }
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
        if ($line['item_code'] === '') {
            $errors[] = "Line {$row}: item code is required.";
        }
        if ($line['qty'] <= 0) {
            $errors[] = "Line {$row}: qty must be > 0.";
        }
        if ($line['unit_price'] < 0) {
            $errors[] = "Line {$row}: unit price must be >= 0.";
        }
        $amount = round(($line['qty'] * $line['unit_price']) - $line['discount'], 4);
        $line['amount'] = $amount;
        $cleanItems[] = $line;
    }
    if (empty($cleanItems)) {
        $errors[] = 'At least one item is required.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $headerSql = "INSERT INTO sales_orders
                (company_id, customer_code, customer_name, customer_phone, customer_email,
                 doc_date, required_date, reference_no, remark, local_status, accounting_status)
                VALUES
                (:company_id, :customer_code, :customer_name, :customer_phone, :customer_email,
                 :doc_date, :required_date, :reference_no, :remark, 'draft', 'pending')";
            $pdo->prepare($headerSql)->execute([
                ':company_id'      => $form['company_id'],
                ':customer_code'   => $form['customer_code'],
                ':customer_name'   => $form['customer_name'],
                ':customer_phone'  => $form['customer_phone'] ?: null,
                ':customer_email'  => $form['customer_email'] ?: null,
                ':doc_date'        => $form['doc_date'],
                ':required_date'   => $form['required_date'] ?: null,
                ':reference_no'    => $form['reference_no'] ?: null,
                ':remark'          => $form['remark']        ?: null,
            ]);
            $soId = (int)$pdo->lastInsertId();

            $itemSql = "INSERT INTO sales_order_items
                (sales_order_id, item_code, item_description, qty, unit_price, discount, tax_code, amount)
                VALUES
                (:sales_order_id, :item_code, :item_description, :qty, :unit_price, :discount, :tax_code, :amount)";
            $itemStmt = $pdo->prepare($itemSql);
            foreach ($cleanItems as $line) {
                $itemStmt->execute([
                    ':sales_order_id'   => $soId,
                    ':item_code'        => $line['item_code'],
                    ':item_description' => $line['item_description'] ?: null,
                    ':qty'              => $line['qty'],
                    ':unit_price'       => $line['unit_price'],
                    ':discount'         => $line['discount'],
                    ':tax_code'         => $line['tax_code'] ?: null,
                    ':amount'           => $line['amount'],
                ]);
            }

            $pdo->commit();
            flash('success', 'Sales order created as draft.');
            redirect('/admin/sales_order_detail.php?id=' . $soId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[ACCBOS] SO create failed: ' . $e->getMessage());
            $errors[] = 'Failed to save sales order. Please try again.';
        }
    }
}

if (empty($itemsForm)) {
    $itemsForm = [['item_code'=>'','item_description'=>'','qty'=>1,'unit_price'=>0,'discount'=>0,'tax_code'=>'SST']];
}

$pageTitle = 'New Sales Order';
$activeNav = 'sales_orders';
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
                       value="<?= e($form['reference_no']) ?>" placeholder="BOS-ORDER-0001">
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
        <button type="submit" class="btn btn-primary accbos-btn-primary">Save Draft</button>
        <a class="btn btn-link" href="<?= e(url('/admin/sales_orders.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
