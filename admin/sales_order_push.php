<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/so_push.php';

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

$result = accbos_push_sales_order($pdo, $id);

switch ($result['outcome']) {
    case 'not_found':
        flash('danger', $result['message']);
        redirect('/admin/sales_orders.php');
        // no break — redirect() exits

    case 'wrong_system':
    case 'not_pushable':
        flash('warning', $result['message']);
        break;

    case 'validation_failed':
    case 'connector_error':
        flash('danger', $result['message']);
        break;

    case 'push_failed':
        accbos_queue_retry($pdo, (int)$result['company_id'], $id, (string)$result['error']);
        flash('danger', $result['message']);
        break;

    case 'success':
        flash('success', $result['message']);
        break;
}

redirect('/admin/sales_order_detail.php?id=' . $id);
