<?php
declare(strict_types=1);

/**
 * ACCBOS sync queue worker.
 *
 * Processes pending sales-order pushes so transient SQL Account failures
 * recover without manual Retry clicks.
 *
 * Run from cron (recommended, CLI — no token needed):
 *   * /5 * * * * /usr/bin/php /home/USER/.../public_html/cron/run_queue.php >> /dev/null 2>&1
 *
 * Or via URL cron (token required):
 *   https://your-host/cron/run_queue.php?token=YOUR_CRON_TOKEN
 *
 * Set the token with the ACCBOS_CRON_TOKEN environment variable.
 */

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../connectors/sql_account/SqlAccountClient.php';
require_once __DIR__ . '/../connectors/sql_account/SqlAccountSalesOrder.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_GET['token'] ?? '';
    if (!is_string($token) || !hash_equals(SYNC_CRON_TOKEN, $token)) {
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }
}

/**
 * Emit a line to stdout (CLI) or collect for the JSON summary (HTTP).
 */
$logLines = [];
$say = static function (string $msg) use ($isCli, &$logLines): void {
    $stamped = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($isCli) {
        echo $stamped . "\n";
    }
    $logLines[] = $stamped;
};

// Prevent overlapping runs.
$lockPath = LOG_DIR . '/sync_queue.lock';
$lock     = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $say('Another worker run is in progress. Exiting.');
    if (!$isCli) {
        echo json_encode(['skipped' => true, 'reason' => 'locked', 'log' => $logLines]);
    }
    exit;
}

$pdo = db();

$stmt = $pdo->prepare(
    "SELECT * FROM sync_queue
     WHERE module = 'sql_account'
       AND action = 'sales_order_push'
       AND status = 'pending'
       AND attempt_count < :max
     ORDER BY id ASC
     LIMIT :batch"
);
$stmt->bindValue(':max', SYNC_MAX_ATTEMPTS, PDO::PARAM_INT);
$stmt->bindValue(':batch', SYNC_BATCH_SIZE, PDO::PARAM_INT);
$stmt->execute();
$queueRows = $stmt->fetchAll();

$processed = 0;
$succeeded = 0;
$failed    = 0;
$parked    = 0;

$say('Worker start. ' . count($queueRows) . ' queued item(s) to process.');

foreach ($queueRows as $queue) {
    $processed++;
    $queueId  = (int)$queue['id'];
    $recordId = (int)$queue['record_id'];
    $attempt  = (int)$queue['attempt_count'] + 1;

    $pdo->prepare("UPDATE sync_queue SET status = 'processing', attempt_count = :a WHERE id = :id")
        ->execute([':a' => $attempt, ':id' => $queueId]);

    $soStmt = $pdo->prepare(
        "SELECT so.*, c.company_id AS c_company_id, c.api_base_url, c.api_access_key,
                c.api_secret_key, c.api_region, c.api_service, c.accounting_system
         FROM sales_orders so
         JOIN companies c ON c.company_id = so.company_id
         WHERE so.id = :id"
    );
    $soStmt->execute([':id' => $recordId]);
    $order = $soStmt->fetch();

    if (!$order) {
        $pdo->prepare("UPDATE sync_queue SET status = 'failed', last_error = 'SO not found' WHERE id = :id")
            ->execute([':id' => $queueId]);
        $say("Queue #{$queueId}: SO #{$recordId} not found — parked.");
        $failed++;
        $parked++;
        continue;
    }

    if ($order['local_status'] === 'cancelled') {
        $pdo->prepare("UPDATE sync_queue SET status = 'failed', last_error = 'SO is cancelled' WHERE id = :id")
            ->execute([':id' => $queueId]);
        $say("Queue #{$queueId}: SO #{$recordId} is cancelled — skipped.");
        $failed++;
        continue;
    }

    $itemStmt = $pdo->prepare("SELECT * FROM sales_order_items WHERE sales_order_id = :id ORDER BY id ASC");
    $itemStmt->execute([':id' => $recordId]);
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
            'success' => false, 'http_status' => null, 'body' => null,
            'raw' => '', 'error' => $e->getMessage(), 'doc_no' => null,
        ];
    }

    if ($result['success']) {
        $pdo->prepare(
            "UPDATE sales_orders SET local_status='pushed', accounting_status='success',
                    accounting_doc_no=:doc_no, accounting_response=:resp WHERE id=:id"
        )->execute([
            ':doc_no' => $result['doc_no'],
            ':resp'   => $result['raw'],
            ':id'     => $recordId,
        ]);
        $pdo->prepare("UPDATE sync_queue SET status='success', last_error=NULL WHERE id=:id")
            ->execute([':id' => $queueId]);
        $say("Queue #{$queueId}: SO #{$recordId} pushed as " . ($result['doc_no'] ?? 'OK') . '.');
        $succeeded++;
    } else {
        $pdo->prepare(
            "UPDATE sales_orders SET local_status='failed', accounting_status='failed',
                    accounting_response=:resp WHERE id=:id"
        )->execute([
            ':resp' => $result['raw'] !== '' ? $result['raw'] : (string)$result['error'],
            ':id'   => $recordId,
        ]);

        // Park permanently once the attempt cap is reached; otherwise re-queue.
        $nextStatus = $attempt >= SYNC_MAX_ATTEMPTS ? 'failed' : 'pending';
        $pdo->prepare("UPDATE sync_queue SET status=:st, last_error=:err WHERE id=:id")
            ->execute([
                ':st'  => $nextStatus,
                ':err' => (string)$result['error'],
                ':id'  => $queueId,
            ]);
        if ($nextStatus === 'failed') {
            $say("Queue #{$queueId}: SO #{$recordId} failed after {$attempt} attempts — parked. ("
                 . (string)$result['error'] . ')');
            $parked++;
        } else {
            $say("Queue #{$queueId}: SO #{$recordId} failed (attempt {$attempt}/"
                 . SYNC_MAX_ATTEMPTS . "), will retry. (" . (string)$result['error'] . ')');
        }
        $failed++;
    }
}

$say("Worker done. processed={$processed} succeeded={$succeeded} failed={$failed} parked={$parked}");

flock($lock, LOCK_UN);
fclose($lock);

if (!$isCli) {
    echo json_encode([
        'processed' => $processed,
        'succeeded' => $succeeded,
        'failed'    => $failed,
        'parked'    => $parked,
        'log'       => $logLines,
    ], JSON_UNESCAPED_SLASHES);
}
