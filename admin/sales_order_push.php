<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../connectors/sql_account/SqlAccountClient.php';
require_once __DIR__ . '/../connectors/sql_account/SqlAccountSalesOrder.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    flash('warning', 'Push must be triggered via the SO detail page.');
    redirect('/admin/sales_orders.php');
}
csrf_verify();

$pdo = db();
$id  = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('warning', 'Missing sales order id.');
    redirect('/admin/sales_orders.php');
}

$stmt = $pdo->prepare(
    "SELECT so.*, c.company_id, c.accounting_system, c.api_base_url, c.api_access_key,
            c.api_secret_key, c.api_region, c.api_service
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

if ($order['accounting_system'] !== 'sql_account') {
    flash('warning', 'Push is only available for SQL Account in Phase 1.');
    redirect('/admin/sales_order_detail.php?id=' . $id);
}
if (in_array($order['local_status'], ['pushed','cancelled'], true)) {
    flash('warning', 'This sales order cannot be pushed in its current state.');
    redirect('/admin/sales_order_detail.php?id=' . $id);
}

$itemStmt = $pdo->prepare(
    "SELECT * FROM sales_order_items WHERE sales_order_id = :id ORDER BY id ASC"
);
$itemStmt->execute([':id' => $id]);
$items = $itemStmt->fetchAll();

$internal = [
    'company_id' => (int)$order['company_id'],
    'customer'   => [
        'code'  => $order['customer_code'],
        'name'  => $order['customer_name'],
        'phone' => $order['customer_phone'] ?? '',
        'email' => $order['customer_email'] ?? '',
    ],
    'sales_order' => [
        'doc_date'      => $order['doc_date'],
        'required_date' => $order['required_date'] ?? '',
        'reference_no'  => $order['reference_no']  ?? '',
        'remark'        => $order['remark']        ?? '',
    ],
    'items' => array_map(static function (array $line): array {
        return [
            'item_code'   => $line['item_code'],
            'description' => $line['item_description'] ?? '',
            'qty'         => (float)$line['qty'],
            'unit_price'  => (float)$line['unit_price'],
            'discount'    => (float)$line['discount'],
            'tax_code'    => $line['tax_code'] ?? '',
            'amount'      => (float)$line['amount'],
        ];
    }, $items),
];

$validationErrors = SqlAccountSalesOrder::validateInternal($internal);
if (!empty($validationErrors)) {
    $pdo->prepare("UPDATE sales_orders SET local_status='failed', accounting_status='failed',
                       accounting_response=:resp WHERE id=:id")
        ->execute([':resp' => implode("\n", $validationErrors), ':id' => $id]);
    flash('danger', 'Validation failed: ' . implode(' ', $validationErrors));
    redirect('/admin/sales_order_detail.php?id=' . $id);
}

try {
    $client = new SqlAccountClient([
        'company_id'     => (int)$order['company_id'],
        'api_base_url'   => (string)$order['api_base_url'],
        'api_access_key' => (string)$order['api_access_key'],
        'api_secret_key' => (string)$order['api_secret_key'],
        'api_region'     => (string)($order['api_region']  ?? 'ap-southeast-1'),
        'api_service'    => (string)($order['api_service'] ?? 'execute-api'),
    ]);
} catch (Throwable $e) {
    $pdo->prepare("UPDATE sales_orders SET local_status='failed', accounting_status='failed',
                       accounting_response=:resp WHERE id=:id")
        ->execute([':resp' => 'Connector setup error: ' . $e->getMessage(), ':id' => $id]);
    flash('danger', 'Connector setup error: ' . $e->getMessage());
    redirect('/admin/sales_order_detail.php?id=' . $id);
}

$result = SqlAccountSalesOrder::push($client, $internal);

if ($result['success']) {
    $pdo->prepare(
        "UPDATE sales_orders SET local_status='pushed', accounting_status='success',
                accounting_doc_no=:doc_no, accounting_response=:resp WHERE id=:id"
    )->execute([
        ':doc_no' => $result['doc_no'],
        ':resp'   => $result['raw'],
        ':id'     => $id,
    ]);
    flash('success', 'Pushed to SQL Account' .
        ($result['doc_no'] !== null ? ' as ' . $result['doc_no'] : '') . '.');
} else {
    $pdo->prepare(
        "UPDATE sales_orders SET local_status='failed', accounting_status='failed',
                accounting_response=:resp WHERE id=:id"
    )->execute([
        ':resp' => $result['raw'] !== '' ? $result['raw'] : (string)$result['error'],
        ':id'   => $id,
    ]);

    // Queue a retry record
    $pdo->prepare(
        "INSERT INTO sync_queue (company_id, module, record_id, action, status, attempt_count, last_error)
         VALUES (:company_id, 'sql_account', :record_id, 'sales_order_push', 'pending', 1, :err)"
    )->execute([
        ':company_id' => (int)$order['company_id'],
        ':record_id'  => $id,
        ':err'        => (string)$result['error'],
    ]);

    flash('danger', 'Push failed: ' . ((string)$result['error'] ?: 'unknown error'));
}

redirect('/admin/sales_order_detail.php?id=' . $id);
