<?php
declare(strict_types=1);

/**
 * Mock SQL Account inbox.
 * Lists recent calls received by the mock endpoint. Requires ACCBOS admin login.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_login();

$logFile = __DIR__ . '/_state/log.jsonl';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    if (postStr('action') === 'clear') {
        @file_put_contents($logFile, '');
        @file_put_contents(__DIR__ . '/_state/counter.txt', '0');
        flash('success', 'Mock inbox cleared.');
        redirect('/mock/sql_account/inbox.php');
    }
}

$entries = [];
if (is_readable($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_reverse(array_slice($lines, -100)) as $line) {
        $row = json_decode($line, true);
        if (is_array($row)) {
            $entries[] = $row;
        }
    }
}

$pageTitle = 'Mock SQL Account Inbox';
$activeNav = '';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="m-0"><?= e($pageTitle) ?></h4>
        <small class="text-muted">
            Endpoint: <code>/mock/sql_account/api.php</code> · 100 most recent calls
        </small>
    </div>
    <form method="post" class="d-inline" onsubmit="return confirm('Clear all mock inbox entries?');">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="clear">
        <button class="btn btn-outline-danger btn-sm">Clear inbox</button>
    </form>
</div>

<div class="alert alert-info">
    Configure a company with <code>api_base_url</code> =
    <code><?= e(((($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-host')
        . '/mock/sql_account/api.php') ?></code>
    and any non-empty access/secret to start sending mock requests. Failure simulators:
    customer code <code>FAIL_CUSTOMER</code>, item code <code>FAIL_ITEM</code>,
    or any reference number containing <code>FAIL</code>.
</div>

<?php if (empty($entries)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        No requests yet. Push a sales order from ACCBOS to see it land here.
    </div></div>
<?php else: ?>
    <?php foreach ($entries as $i => $entry): ?>
        <div class="card mb-2">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-secondary me-1"><?= e((string)$entry['method']) ?></span>
                    <code><?= e((string)$entry['path']) ?></code>
                </div>
                <div class="small text-muted">
                    <span class="badge bg-<?= ((int)$entry['response_status'] >= 200 && (int)$entry['response_status'] < 300) ? 'success' : 'danger' ?>">
                        HTTP <?= e((string)$entry['response_status']) ?>
                    </span>
                    <span class="ms-2"><?= e((string)$entry['ts']) ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="row g-0">
                    <div class="col-md-6 border-end">
                        <div class="p-2 small bg-light fw-bold">Request</div>
                        <pre class="m-0 p-2 small" style="max-height:300px;"><?=
                            e(json_encode($entry['request_body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '')
                        ?></pre>
                        <details class="px-2 pb-2 small">
                            <summary class="text-muted">Headers</summary>
                            <pre class="m-0 small"><?=
                                e(json_encode($entry['headers'] ?? [], JSON_PRETTY_PRINT) ?: '')
                            ?></pre>
                        </details>
                    </div>
                    <div class="col-md-6">
                        <div class="p-2 small bg-light fw-bold">Response</div>
                        <pre class="m-0 p-2 small" style="max-height:300px;"><?=
                            e(json_encode($entry['response_body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '')
                        ?></pre>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
