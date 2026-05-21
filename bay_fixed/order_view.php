<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: lagin.html');
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    die('Invalid order.');
}

$conn = get_auth_database_connection();

$orderStmt = $conn->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult ? $orderResult->fetch_assoc() : null;
$orderStmt->close();

if (!$order) {
    $conn->close();
    die('Order not found.');
}

$items = [];
$itemStmt = $conn->prepare('SELECT product_name, unit_price, quantity, line_total FROM order_items WHERE order_id = ? ORDER BY id ASC');
$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();
while ($itemResult && $row = $itemResult->fetch_assoc()) {
    $items[] = $row;
}
$itemStmt->close();

$logs = [];
$logStmt = $conn->prepare('SELECT status, note, created_at FROM order_status_logs WHERE order_id = ? ORDER BY id ASC');
$logStmt->bind_param('i', $orderId);
$logStmt->execute();
$logResult = $logStmt->get_result();
while ($logResult && $row = $logResult->fetch_assoc()) {
    $logs[] = $row;
}
$logStmt->close();

$conn->close();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Order View</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-4" style="max-width:880px;">
    <div class="d-flex justify-content-between align-items-center mb-3"><h3 class="mb-0">Order #<?php echo (int)$order['id']; ?></h3><a href="admin_dashboard.php?tab=orders" class="btn btn-outline-secondary btn-sm">Back</a></div>
    <div class="card mb-3"><div class="card-body">
        <div><strong>Customer:</strong> <?php echo htmlspecialchars((string)$order['customer_name']); ?></div>
        <div><strong>Phone:</strong> <?php echo htmlspecialchars((string)($order['customer_phone'] ?? '-')); ?></div>
        <div><strong>Order Type:</strong> <?php echo htmlspecialchars((string)($order['order_type'] ?? 'pickup')); ?></div>
        <div><strong>Payment:</strong> <?php echo htmlspecialchars((string)($order['payment_method'] ?? 'cod')); ?></div>
        <div><strong>Status:</strong> <?php echo htmlspecialchars((string)$order['status']); ?></div>
        <div><strong>Address:</strong> <?php echo htmlspecialchars((string)($order['delivery_address'] ?? '-')); ?></div>
        <div><strong>Total:</strong> PHP <?php echo number_format((float)$order['total'], 2); ?></div>
    </div></div>

    <div class="card mb-3"><div class="card-header">Items</div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Item</th><th>Price</th><th>Qty</th><th>Line Total</th></tr></thead><tbody>
        <?php foreach ($items as $item): ?><tr><td><?php echo htmlspecialchars((string)$item['product_name']); ?></td><td>PHP <?php echo number_format((float)$item['unit_price'],2); ?></td><td><?php echo (int)$item['quantity']; ?></td><td>PHP <?php echo number_format((float)$item['line_total'],2); ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>

    <div class="card"><div class="card-header">Status History</div><ul class="list-group list-group-flush">
        <?php if (count($logs) === 0): ?><li class="list-group-item">No history.</li><?php else: foreach ($logs as $log): ?><li class="list-group-item"><strong><?php echo htmlspecialchars((string)$log['status']); ?></strong> - <?php echo htmlspecialchars((string)$log['created_at']); ?> <span class="text-muted"><?php echo htmlspecialchars((string)($log['note'] ?? '')); ?></span></li><?php endforeach; endif; ?>
    </ul></div>
</div></body></html>
