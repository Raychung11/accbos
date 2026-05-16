<?php
declare(strict_types=1);

require_once __DIR__ . '/../connectors/sql_account/SqlAccountClient.php';
require_once __DIR__ . '/../connectors/sql_account/SqlAccountSalesOrder.php';

/**
 * Push a single sales order to its company's accounting system and persist
 * the outcome on the sales_orders row.
 *
 * This helper deliberately does NOT touch sync_queue — callers manage queue
 * rows because the lifecycle differs (manual/bulk insert a new row on
 * failure; the retry/cron workers update an existing row).
 *
 * @return array{
 *   outcome: string,   // success|push_failed|validation_failed|connector_error|not_found|wrong_system|not_pushable
 *   message: string,
 *   doc_no: ?string,
 *   error: ?string,
 *   raw: string,
 *   company_id: ?int
 * }
 */
function accbos_push_sales_order(PDO $pdo, int $soId): array
{
    $base = ['doc_no' => null, 'error' => null, 'raw' => '', 'company_id' => null];

    $stmt = $pdo->prepare(
        "SELECT so.*, c.company_id AS join_company_id, c.accounting_system,
                c.api_base_url, c.api_access_key, c.api_secret_key,
                c.api_region, c.api_service
         FROM sales_orders so
         JOIN companies c ON c.company_id = so.company_id
         WHERE so.id = :id"
    );
    $stmt->execute([':id' => $soId]);
    $order = $stmt->fetch();

    if (!$order) {
        return $base + ['outcome' => 'not_found', 'message' => 'Sales order not found.'];
    }

    $base['company_id'] = (int)$order['company_id'];

    if ($order['accounting_system'] !== 'sql_account') {
        return $base + ['outcome' => 'wrong_system',
            'message' => 'Push is only available for SQL Account in Phase 1.'];
    }
    if (in_array($order['local_status'], ['pushed', 'cancelled'], true)) {
        return $base + ['outcome' => 'not_pushable',
            'message' => 'Sales order is ' . $order['local_status'] . ' and cannot be pushed.'];
    }

    $itemStmt = $pdo->prepare(
        "SELECT * FROM sales_order_items WHERE sales_order_id = :id ORDER BY id ASC"
    );
    $itemStmt->execute([':id' => $soId]);
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
        $msg = implode("\n", $validationErrors);
        $pdo->prepare("UPDATE sales_orders SET local_status='failed', accounting_status='failed',
                           accounting_response=:resp WHERE id=:id")
            ->execute([':resp' => $msg, ':id' => $soId]);
        return $base + ['outcome' => 'validation_failed',
            'message' => 'Validation failed: ' . implode(' ', $validationErrors),
            'error'   => $msg];
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
            ->execute([':resp' => 'Connector setup error: ' . $e->getMessage(), ':id' => $soId]);
        return $base + ['outcome' => 'connector_error',
            'message' => 'Connector setup error: ' . $e->getMessage(),
            'error'   => $e->getMessage()];
    }

    $result = SqlAccountSalesOrder::push($client, $internal);

    if ($result['success']) {
        $pdo->prepare(
            "UPDATE sales_orders SET local_status='pushed', accounting_status='success',
                    accounting_doc_no=:doc_no, accounting_response=:resp WHERE id=:id"
        )->execute([
            ':doc_no' => $result['doc_no'],
            ':resp'   => $result['raw'],
            ':id'     => $soId,
        ]);
        return $base + [
            'outcome' => 'success',
            'message' => 'Pushed to SQL Account' .
                ($result['doc_no'] !== null ? ' as ' . $result['doc_no'] : '') . '.',
            'doc_no'  => $result['doc_no'],
            'raw'     => $result['raw'],
        ];
    }

    $pdo->prepare(
        "UPDATE sales_orders SET local_status='failed', accounting_status='failed',
                accounting_response=:resp WHERE id=:id"
    )->execute([
        ':resp' => $result['raw'] !== '' ? $result['raw'] : (string)$result['error'],
        ':id'   => $soId,
    ]);

    return $base + [
        'outcome' => 'push_failed',
        'message' => 'Push failed: ' . ((string)$result['error'] ?: 'unknown error'),
        'error'   => (string)$result['error'],
        'raw'     => $result['raw'],
    ];
}

/**
 * Insert a fresh retry row in sync_queue after a manual/bulk push failure.
 */
function accbos_queue_retry(PDO $pdo, int $companyId, int $soId, string $error): void
{
    $pdo->prepare(
        "INSERT INTO sync_queue (company_id, module, record_id, action, status, attempt_count, last_error)
         VALUES (:company_id, 'sql_account', :record_id, 'sales_order_push', 'pending', 1, :err)"
    )->execute([
        ':company_id' => $companyId,
        ':record_id'  => $soId,
        ':err'        => $error,
    ]);
}
