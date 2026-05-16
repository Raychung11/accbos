<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../connectors/sql_account/SqlAccountClient.php';
require_once __DIR__ . '/../connectors/sql_account/SqlAccountSalesOrder.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/admin/sync_queue.php');
}
csrf_verify();

$pdo = db();
$id  = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('warning', 'Missing queue id.');
    redirect('/admin/sync_queue.php');
}

$stmt = $pdo->prepare("SELECT * FROM sync_queue WHERE id = :id");
$stmt->execute([':id' => $id]);
$queue = $stmt->fetch();
if (!$queue) {
    flash('danger', 'Queue item not found.');
    redirect('/admin/sync_queue.php');
}

if ($queue['action'] !== 'sales_order_push' || $queue['module'] !== 'sql_account') {
    flash('warning', 'Only SQL Account sales-order pushes are retriable in Phase 1.');
    redirect('/admin/sync_queue.php');
}

$pdo->prepare("UPDATE sync_queue SET status='processing', attempt_count = attempt_count + 1
               WHERE id = :id")->execute([':id' => $id]);

$soStmt = $pdo->prepare(
    "SELECT so.*, c.api_base_url, c.api_access_key, c.api_secret_key,
            c.api_region, c.api_service, c.accounting_system
     FROM sales_orders so
     JOIN companies c ON c.company_id = so.company_id
     WHERE so.id = :id"
);
$soStmt->execute([':id' => (int)$queue['record_id']]);
$order = $soStmt->fetch();

if (!$order) {
    $pdo->prepare("UPDATE sync_queue SET status='failed', last_error='SO not found' WHERE id=:id")
        ->execute([':id' => $id]);
    flash('danger', 'Sales order not found.');
    redirect('/admin/sync_queue.php');
}

$itemStmt = $pdo->prepare("SELECT * FROM sales_order_items WHERE sales_order_id=:id ORDER BY id ASC");
$itemStmt->execute([':id' => (int)$queue['record_id']]);
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

try {
    $client = new SqlAccountClient([
        'company_id'     => (int)$order['company_id'],
        'api_base_url'   => (string)$order['api_base_url'],
        'api_access_key' => (string)$order['api_access_key'],
        'api_secret_key' => (string)$order['api_secret_key'],
        'api_region'     => (string)($order['api_region']  ?? 'ap-southeast-1'),
        'api_service'    => (string)($order['api_service'] ?? 'execute-api'),
    ]);
    $result = SqlAccountSalesOrder::push($client, $internal);
} catch (Throwable $e) {
    $result = [
        'success'     => false,
        'http_status' => null,
        'body'        => null,
        'raw'         => '',
        'error'       => $e->getMessage(),
        'doc_no'      => null,
    ];
}

if ($result['success']) {
    $pdo->prepare(
        "UPDATE sales_orders SET local_status='pushed', accounting_status='success',
                accounting_doc_no=:doc_no, accounting_response=:resp WHERE id=:id"
    )->execute([
        ':doc_no' => $result['doc_no'],
        ':resp'   => $result['raw'],
        ':id'     => (int)$queue['record_id'],
    ]);
    $pdo->prepare("UPDATE sync_queue SET status='success', last_error=NULL WHERE id=:id")
        ->execute([':id' => $id]);
    flash('success', 'Retry succeeded.');
} else {
    $pdo->prepare(
        "UPDATE sales_orders SET local_status='failed', accounting_status='failed',
                accounting_response=:resp WHERE id=:id"
    )->execute([
        ':resp' => $result['raw'] !== '' ? $result['raw'] : (string)$result['error'],
        ':id'   => (int)$queue['record_id'],
    ]);
    $pdo->prepare("UPDATE sync_queue SET status='failed', last_error=:err WHERE id=:id")
        ->execute([':err' => (string)$result['error'], ':id' => $id]);
    flash('danger', 'Retry failed: ' . ((string)$result['error'] ?: 'unknown error'));
}

redirect('/admin/sync_queue.php');
