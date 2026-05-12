<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('/admin/sales_orders.php');
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('warning', 'Missing sales order id.');
    redirect('/admin/sales_orders.php');
}

$pdo = db();
$stmt = $pdo->prepare("SELECT id, local_status, accounting_doc_no FROM sales_orders WHERE id = :id");
$stmt->execute([':id' => $id]);
$order = $stmt->fetch();
if (!$order) {
    flash('danger', 'Sales order not found.');
    redirect('/admin/sales_orders.php');
}

if ($order['local_status'] === 'cancelled') {
    flash('info', 'This sales order is already cancelled.');
    redirect('/admin/sales_order_detail.php?id=' . $id);
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE sales_orders SET local_status = 'cancelled' WHERE id = :id")
        ->execute([':id' => $id]);

    // Stop any retries that are still waiting on the queue.
    $pdo->prepare(
        "UPDATE sync_queue
         SET status = 'failed', last_error = 'Cancelled: SO was cancelled'
         WHERE record_id = :rid AND action = 'sales_order_push'
           AND status IN ('pending','processing')"
    )->execute([':rid' => $id]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[ACCBOS] SO cancel failed: ' . $e->getMessage());
    flash('danger', 'Failed to cancel sales order.');
    redirect('/admin/sales_order_detail.php?id=' . $id);
}

if (!empty($order['accounting_doc_no'])) {
    flash('warning', 'Sales order cancelled locally. The accounting document ' .
        $order['accounting_doc_no'] . ' was not voided in the accounting system — handle that separately.');
} else {
    flash('success', 'Sales order cancelled.');
}
redirect('/admin/sales_order_detail.php?id=' . $id);
