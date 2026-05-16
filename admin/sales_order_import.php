<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();

const IMPORT_COLUMNS = [
    'company_id', 'reference_no', 'customer_code', 'customer_name',
    'customer_phone', 'customer_email', 'doc_date', 'required_date', 'remark',
    'item_code', 'item_description', 'qty', 'unit_price', 'discount', 'tax_code',
];
const IMPORT_MAX_ROWS   = 1000;
const IMPORT_MAX_ORDERS = 500;

// ---- Downloadable CSV template ----------------------------------------------
if (getStr('template') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="accbos_so_import_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, IMPORT_COLUMNS);
    fputcsv($out, [
        '1', 'BOS-0001', 'CUST001', 'ABC Trading Sdn Bhd', '0123456789',
        'abc@example.com', '2026-05-16', '2026-05-20', 'Imported from BOS',
        'ITEM001', 'Product A', '2', '100.00', '0', 'SST',
    ]);
    fputcsv($out, [
        '1', 'BOS-0001', 'CUST001', 'ABC Trading Sdn Bhd', '0123456789',
        'abc@example.com', '2026-05-16', '2026-05-20', 'Imported from BOS',
        'ITEM002', 'Product B', '1', '50.00', '5', 'SST',
    ]);
    fclose($out);
    exit;
}

/**
 * Parse + validate uploaded CSV into grouped orders.
 *
 * @return array{orders: array<string,array<string,mixed>>, fatal: ?string}
 */
function parse_import(string $tmpPath, PDO $pdo): array
{
    $fh = @fopen($tmpPath, 'r');
    if ($fh === false) {
        return ['orders' => [], 'fatal' => 'Could not read the uploaded file.'];
    }

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        return ['orders' => [], 'fatal' => 'The CSV appears to be empty.'];
    }
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
    $missing = array_diff(IMPORT_COLUMNS, $header);
    if (!empty($missing)) {
        fclose($fh);
        return ['orders' => [], 'fatal' => 'Missing column(s): ' . implode(', ', $missing)];
    }
    $idx = array_flip($header);

    // Cache active companies for validation.
    $companies = [];
    foreach ($pdo->query("SELECT company_id, company_name, status FROM companies")->fetchAll() as $c) {
        $companies[(int)$c['company_id']] = $c;
    }

    $orders  = [];
    $rowNo   = 1;
    while (($cells = fgetcsv($fh)) !== false) {
        $rowNo++;
        if ($rowNo > IMPORT_MAX_ROWS + 1) {
            break;
        }
        // Skip fully blank lines.
        if (count(array_filter($cells, static fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }

        $get = static function (string $col) use ($cells, $idx): string {
            $i = $idx[$col] ?? null;
            return $i !== null && isset($cells[$i]) ? trim((string)$cells[$i]) : '';
        };

        $companyId = (int)$get('company_id');
        $refNo     = $get('reference_no');
        $groupKey  = $companyId . '|' . ($refNo !== '' ? $refNo : 'ROW' . $rowNo);

        if (!isset($orders[$groupKey])) {
            $orders[$groupKey] = [
                'company_id'     => $companyId,
                'company_name'   => $companies[$companyId]['company_name'] ?? '(unknown)',
                'reference_no'   => $refNo,
                'customer_code'  => $get('customer_code'),
                'customer_name'  => $get('customer_name'),
                'customer_phone' => $get('customer_phone'),
                'customer_email' => $get('customer_email'),
                'doc_date'       => $get('doc_date'),
                'required_date'  => $get('required_date'),
                'remark'         => $get('remark'),
                'items'          => [],
                'errors'         => [],
            ];

            $o = &$orders[$groupKey];
            if ($companyId <= 0 || !isset($companies[$companyId])) {
                $o['errors'][] = "Row {$rowNo}: company_id {$companyId} not found.";
            } elseif (($companies[$companyId]['status'] ?? '') !== 'active') {
                $o['errors'][] = "Row {$rowNo}: company {$companyId} is not active.";
            }
            if ($o['customer_code'] === '') {
                $o['errors'][] = "Row {$rowNo}: customer_code is required.";
            }
            if ($o['customer_name'] === '') {
                $o['errors'][] = "Row {$rowNo}: customer_name is required.";
            }
            $d = DateTime::createFromFormat('Y-m-d', $o['doc_date']);
            if (!$d || $d->format('Y-m-d') !== $o['doc_date']) {
                $o['errors'][] = "Row {$rowNo}: doc_date must be YYYY-MM-DD.";
            }
            if ($o['required_date'] !== '') {
                $rd = DateTime::createFromFormat('Y-m-d', $o['required_date']);
                if (!$rd || $rd->format('Y-m-d') !== $o['required_date']) {
                    $o['errors'][] = "Row {$rowNo}: required_date must be YYYY-MM-DD.";
                }
            }
            if ($o['customer_email'] !== '' &&
                !filter_var($o['customer_email'], FILTER_VALIDATE_EMAIL)) {
                $o['errors'][] = "Row {$rowNo}: customer_email is invalid.";
            }
            unset($o);
        }

        $itemCode = $get('item_code');
        $qty      = (float)$get('qty');
        $price    = (float)$get('unit_price');
        $discount = (float)$get('discount');
        $lineErr  = [];
        if ($itemCode === '') {
            $lineErr[] = "Row {$rowNo}: item_code is required.";
        }
        if ($qty <= 0) {
            $lineErr[] = "Row {$rowNo}: qty must be > 0.";
        }
        if ($price < 0) {
            $lineErr[] = "Row {$rowNo}: unit_price must be >= 0.";
        }
        $orders[$groupKey]['errors'] = array_merge($orders[$groupKey]['errors'], $lineErr);
        $orders[$groupKey]['items'][] = [
            'item_code'        => $itemCode,
            'item_description' => $get('item_description'),
            'qty'              => $qty,
            'unit_price'       => $price,
            'discount'         => $discount,
            'tax_code'         => $get('tax_code'),
            'amount'           => round(($qty * $price) - $discount, 4),
        ];

        if (count($orders) > IMPORT_MAX_ORDERS) {
            fclose($fh);
            return ['orders' => $orders,
                    'fatal'  => 'Too many orders in one file (max ' . IMPORT_MAX_ORDERS . ').'];
        }
    }
    fclose($fh);

    foreach ($orders as &$o) {
        if (empty($o['items'])) {
            $o['errors'][] = 'Order has no item lines.';
        }
    }
    unset($o);

    return ['orders' => $orders, 'fatal' => null];
}

$step    = 'upload';
$preview = [];
$fatal   = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = postStr('action');

    if ($action === 'upload') {
        if (!isset($_FILES['csv']) || !is_array($_FILES['csv']) ||
            ($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
            !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            $fatal = 'Please choose a CSV file to upload.';
        } elseif (($_FILES['csv']['size'] ?? 0) > 2 * 1024 * 1024) {
            $fatal = 'File too large (max 2 MB).';
        } else {
            $parsed = parse_import((string)$_FILES['csv']['tmp_name'], $pdo);
            if ($parsed['fatal'] !== null) {
                $fatal = $parsed['fatal'];
            }
            $preview = $parsed['orders'];
            if (!empty($preview)) {
                $_SESSION['so_import'] = $preview;
                $step = 'preview';
            }
        }
    } elseif ($action === 'confirm') {
        $preview = $_SESSION['so_import'] ?? [];
        if (empty($preview)) {
            $fatal = 'Nothing to import. Please upload again.';
        } else {
            $created = 0;
            $skipped = 0;
            foreach ($preview as $o) {
                if (!empty($o['errors'])) {
                    $skipped++;
                    continue;
                }
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare(
                        "INSERT INTO sales_orders
                         (company_id, customer_code, customer_name, customer_phone, customer_email,
                          doc_date, required_date, reference_no, remark, local_status, accounting_status)
                         VALUES
                         (:company_id, :customer_code, :customer_name, :customer_phone, :customer_email,
                          :doc_date, :required_date, :reference_no, :remark, 'draft', 'pending')"
                    )->execute([
                        ':company_id'     => (int)$o['company_id'],
                        ':customer_code'  => $o['customer_code'],
                        ':customer_name'  => $o['customer_name'],
                        ':customer_phone' => $o['customer_phone'] ?: null,
                        ':customer_email' => $o['customer_email'] ?: null,
                        ':doc_date'       => $o['doc_date'],
                        ':required_date'  => $o['required_date'] ?: null,
                        ':reference_no'   => $o['reference_no'] ?: null,
                        ':remark'         => $o['remark'] ?: null,
                    ]);
                    $soId = (int)$pdo->lastInsertId();
                    $itemStmt = $pdo->prepare(
                        "INSERT INTO sales_order_items
                         (sales_order_id, item_code, item_description, qty, unit_price, discount, tax_code, amount)
                         VALUES
                         (:sid, :code, :desc, :qty, :price, :disc, :tax, :amt)"
                    );
                    foreach ($o['items'] as $line) {
                        $itemStmt->execute([
                            ':sid'   => $soId,
                            ':code'  => $line['item_code'],
                            ':desc'  => $line['item_description'] ?: null,
                            ':qty'   => $line['qty'],
                            ':price' => $line['unit_price'],
                            ':disc'  => $line['discount'],
                            ':tax'   => $line['tax_code'] ?: null,
                            ':amt'   => $line['amount'],
                        ]);
                    }
                    $pdo->commit();
                    $created++;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[ACCBOS] CSV import row failed: ' . $e->getMessage());
                    $skipped++;
                }
            }
            unset($_SESSION['so_import']);
            flash('success', "Import complete: {$created} order(s) created as draft, {$skipped} skipped.");
            redirect('/admin/sales_orders.php');
        }
    }
}

$pageTitle = 'Import Sales Orders (CSV)';
$activeNav = 'sales_orders';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="m-0">Import Sales Orders (CSV)</h4>
    <a class="btn btn-outline-secondary" href="<?= e(url('/admin/sales_orders.php')) ?>">Back</a>
</div>

<?php if ($fatal !== ''): ?>
    <div class="alert alert-danger"><?= e($fatal) ?></div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
    <div class="card">
        <div class="card-body">
            <p class="mb-2">Upload a CSV with these columns (header row required):</p>
            <p><code><?= e(implode(', ', IMPORT_COLUMNS)) ?></code></p>
            <ul class="small text-muted">
                <li>Rows sharing the same <code>company_id</code> + <code>reference_no</code>
                    are merged into one sales order; each row is one item line.</li>
                <li>Dates must be <code>YYYY-MM-DD</code>. Orders are created as
                    <strong>draft</strong> — nothing is pushed automatically.</li>
                <li>Limits: <?= IMPORT_MAX_ROWS ?> rows / <?= IMPORT_MAX_ORDERS ?> orders / 2&nbsp;MB per file.</li>
            </ul>
            <a class="btn btn-sm btn-link px-0"
               href="<?= e(url('/admin/sales_order_import.php?template=1')) ?>">
                Download CSV template
            </a>

            <form method="post" enctype="multipart/form-data" class="mt-3">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="upload">
                <div class="mb-3">
                    <input type="file" name="csv" accept=".csv,text/csv"
                           class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary accbos-btn-primary">
                    Upload &amp; Preview
                </button>
            </form>
        </div>
    </div>
<?php else: ?>
    <?php
    $validCount = 0;
    $errCount   = 0;
    foreach ($preview as $o) {
        if (empty($o['errors'])) {
            $validCount++;
        } else {
            $errCount++;
        }
    }
    ?>
    <div class="alert alert-info">
        <strong><?= e((string)$validCount) ?></strong> order(s) ready to import,
        <strong><?= e((string)$errCount) ?></strong> with errors (will be skipped).
    </div>

    <?php foreach ($preview as $o): ?>
        <div class="card mb-2 <?= empty($o['errors']) ? '' : 'border-danger' ?>">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <strong><?= e($o['reference_no'] !== '' ? $o['reference_no'] : '(no ref)') ?></strong>
                    · <?= e($o['company_name']) ?>
                    · <?= e($o['customer_name']) ?> (<?= e($o['customer_code']) ?>)
                </div>
                <?php if (empty($o['errors'])): ?>
                    <span class="badge bg-success">ready</span>
                <?php else: ?>
                    <span class="badge bg-danger"><?= e((string)count($o['errors'])) ?> error(s)</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($o['errors'])): ?>
                    <div class="p-2 small text-danger">
                        <?php foreach ($o['errors'] as $err): ?>
                            <div><?= e($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Item</th><th>Description</th><th class="text-end">Qty</th>
                            <th class="text-end">Price</th><th class="text-end">Disc</th>
                            <th>Tax</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($o['items'] as $line): ?>
                            <tr>
                                <td><?= e($line['item_code']) ?></td>
                                <td><?= e($line['item_description']) ?></td>
                                <td class="text-end"><?= e(money((float)$line['qty'])) ?></td>
                                <td class="text-end"><?= e(money((float)$line['unit_price'])) ?></td>
                                <td class="text-end"><?= e(money((float)$line['discount'])) ?></td>
                                <td><?= e($line['tax_code']) ?></td>
                                <td class="text-end"><?= e(money((float)$line['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <form method="post" class="d-flex gap-2 mt-3">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="confirm">
        <button type="submit" class="btn btn-primary accbos-btn-primary"
                <?= $validCount === 0 ? 'disabled' : '' ?>
                onclick="return confirm('Import <?= (int)$validCount ?> order(s) as draft?');">
            Import <?= e((string)$validCount) ?> order(s)
        </button>
        <a class="btn btn-link" href="<?= e(url('/admin/sales_order_import.php')) ?>">Start over</a>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
