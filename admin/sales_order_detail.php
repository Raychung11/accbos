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
    "SELECT so.*, c.company_name, c.accounting_system
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

$itemStmt = $pdo->prepare(
    "SELECT * FROM sales_order_items WHERE sales_order_id = :id ORDER BY id ASC"
);
$itemStmt->execute([':id' => $id]);
$items = $itemStmt->fetchAll();

$total = 0.0;
foreach ($items as $line) {
    $total += (float)$line['amount'];
}

$pageTitle = 'Sales Order #' . (int)$order['id'];
$activeNav = 'sales_orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="m-0">Sales Order #<?= e((string)$order['id']) ?></h4>
        <small class="text-muted">
            <?= e($order['company_name']) ?> · <?= e($order['accounting_system']) ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary"
           href="<?= e(url('/admin/sales_orders.php')) ?>">Back</a>
        <?php if (in_array($order['local_status'], ['draft','ready_to_push','failed'], true)): ?>
            <form method="post" action="<?= e(url('/admin/sales_order_push.php')) ?>" class="d-inline">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                <button type="submit" class="btn btn-primary accbos-btn-primary"
                        onclick="return confirm('Push this sales order to <?= e($order['accounting_system']) ?>?');">
                    Push to <?= e($order['accounting_system']) ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>Header</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Reference</dt>
                    <dd class="col-sm-8"><?= e($order['reference_no'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Doc Date</dt>
                    <dd class="col-sm-8"><?= e((string)$order['doc_date']) ?></dd>

                    <dt class="col-sm-4">Required Date</dt>
                    <dd class="col-sm-8"><?= e($order['required_date'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Local Status</dt>
                    <dd class="col-sm-8"><?= status_badge((string)$order['local_status']) ?></dd>

                    <dt class="col-sm-4">Accounting</dt>
                    <dd class="col-sm-8"><?= status_badge((string)$order['accounting_status']) ?></dd>

                    <dt class="col-sm-4">Accounting Doc No</dt>
                    <dd class="col-sm-8"><?= e($order['accounting_doc_no'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Remark</dt>
                    <dd class="col-sm-8"><?= e($order['remark'] ?? '-') ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="card h-100">
            <div class="card-header"><strong>Customer</strong></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Code</dt>
                    <dd class="col-sm-8"><?= e($order['customer_code']) ?></dd>

                    <dt class="col-sm-4">Name</dt>
                    <dd class="col-sm-8"><?= e($order['customer_name']) ?></dd>

                    <dt class="col-sm-4">Phone</dt>
                    <dd class="col-sm-8"><?= e($order['customer_phone'] ?? '-') ?></dd>

                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8"><?= e($order['customer_email'] ?? '-') ?></dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><strong>Items</strong></div>
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Item</th>
                    <th>Description</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Unit Price</th>
                    <th class="text-end">Discount</th>
                    <th>Tax</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No items.</td></tr>
                <?php else: foreach ($items as $line): ?>
                    <tr>
                        <td><?= e($line['item_code']) ?></td>
                        <td><?= e($line['item_description'] ?? '') ?></td>
                        <td class="text-end"><?= e(money((float)$line['qty'])) ?></td>
                        <td class="text-end"><?= e(money((float)$line['unit_price'])) ?></td>
                        <td class="text-end"><?= e(money((float)$line['discount'])) ?></td>
                        <td><?= e($line['tax_code'] ?? '') ?></td>
                        <td class="text-end"><?= e(money((float)$line['amount'])) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="6" class="text-end">Total</th>
                    <th class="text-end"><?= e(money($total)) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if (!empty($order['accounting_response'])): ?>
    <div class="card mt-3">
        <div class="card-header"><strong>Accounting Response</strong></div>
        <div class="card-body">
            <pre class="mb-0 small"><?= e((string)$order['accounting_response']) ?></pre>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
