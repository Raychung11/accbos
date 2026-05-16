<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/so_push.php';

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/admin/sales_orders.php');
}
csrf_verify();

$pdo  = db();
$ids  = $_POST['ids'] ?? [];
$back = str_replace(["\r", "\n"], '', postStr('return_to'));
$backUrl = ($back !== '' && str_starts_with($back, '/') && !str_starts_with($back, '//'))
    ? $back
    : '/admin/sales_orders.php';

if (!is_array($ids) || empty($ids)) {
    flash('warning', 'No sales orders selected.');
    redirect($backUrl);
}

// Normalise to a unique list of positive integers, capped to a sane batch.
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
$ids = array_slice($ids, 0, 100);

$pushed  = 0;
$failed  = 0;
$skipped = 0;

foreach ($ids as $soId) {
    $result = accbos_push_sales_order($pdo, $soId);
    switch ($result['outcome']) {
        case 'success':
            $pushed++;
            break;
        case 'push_failed':
            accbos_queue_retry($pdo, (int)$result['company_id'], $soId, (string)$result['error']);
            $failed++;
            break;
        case 'validation_failed':
        case 'connector_error':
            $failed++;
            break;
        default: // not_found, wrong_system, not_pushable
            $skipped++;
            break;
    }
}

$summary = sprintf(
    'Bulk push complete: %d pushed, %d failed, %d skipped (of %d selected).',
    $pushed, $failed, $skipped, count($ids)
);
if ($failed > 0) {
    flash('warning', $summary . ' Failed orders were queued for retry.');
} elseif ($pushed > 0) {
    flash('success', $summary);
} else {
    flash('info', $summary);
}

redirect($backUrl);
