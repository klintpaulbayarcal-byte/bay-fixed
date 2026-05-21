<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

try {
    $conn = get_auth_database_connection();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo '<!doctype html><html><body style="font-family:Arial;padding:24px;"><h2>Database unavailable</h2><p>' . htmlspecialchars($exception->getMessage()) . '</p></body></html>';
    exit;
}
$dbError = '';

$order = null;
$orderItems = [];
$statusLogs = [];
$formError = '';
$searched = false;

$orderIdInput = trim($_GET['order_id'] ?? '');
$identityInput = trim($_GET['identity'] ?? '');

if ($dbError === '') {
    $conn->query("CREATE TABLE IF NOT EXISTS order_status_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        status VARCHAR(30) NOT NULL,
        note VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    )");
    $conn->query("UPDATE orders SET status = 'received' WHERE status = 'pending'");
    $conn->query("UPDATE orders SET status = 'processing' WHERE status = 'preparing'");
    $conn->query("UPDATE orders SET status = 'out_for_delivery' WHERE status = 'ready'");
}

if ($dbError === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cancelOrderId = (int)($_POST['order_id'] ?? 0);
    $cancelIdentity = trim($_POST['identity'] ?? '');

    if ($cancelOrderId > 0 && $cancelIdentity !== '') {
        $cancelStmt = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND status = 'received' AND (customer_name = ? OR customer_phone = ?)");
        $cancelStmt->bind_param('iss', $cancelOrderId, $cancelIdentity, $cancelIdentity);
        $cancelStmt->execute();
        $cancelOk = $cancelStmt->affected_rows > 0;
        $cancelStmt->close();

        if ($cancelOk) {
            $logStmt = $conn->prepare('INSERT INTO order_status_logs (order_id, status, note) VALUES (?, ?, ?)');
            $cancelledStatus = 'cancelled';
            $cancelNote = 'Cancelled by customer from tracking page';
            $logStmt->bind_param('iss', $cancelOrderId, $cancelledStatus, $cancelNote);
            $logStmt->execute();
            $logStmt->close();
        }
    }

    header('Location: track_order.php?search=1&order_id=' . urlencode((string)$cancelOrderId) . '&identity=' . urlencode($cancelIdentity));
    exit;
}

if ($dbError === '' && isset($_GET['search'])) {
    $searched = true;
    $orderId = (int)$orderIdInput;

    if ($orderId <= 0 || $identityInput === '') {
        $formError = 'Enter a valid order number and customer name or phone.';
    } else {
        $orderStmt = $conn->prepare('SELECT id, customer_name, customer_phone, order_type, payment_method, delivery_address, note, subtotal, tax, delivery_fee, service_fee, total, estimated_minutes, status, created_at FROM orders WHERE id = ? AND (customer_name = ? OR customer_phone = ?) LIMIT 1');
        $orderStmt->bind_param('iss', $orderId, $identityInput, $identityInput);
        $orderStmt->execute();
        $result = $orderStmt->get_result();
        $order = $result ? $result->fetch_assoc() : null;
        $orderStmt->close();

        if (!$order) {
            $formError = 'No matching order found. Check your order number and customer name/phone.';
        } else {
            $itemsStmt = $conn->prepare('SELECT product_name, unit_price, quantity, line_total FROM order_items WHERE order_id = ? ORDER BY id ASC');
            $itemsStmt->bind_param('i', $orderId);
            $itemsStmt->execute();
            $itemsResult = $itemsStmt->get_result();
            while ($itemsResult && $row = $itemsResult->fetch_assoc()) {
                $orderItems[] = $row;
            }
            $itemsStmt->close();

            $logStmt = $conn->prepare('SELECT status, note, created_at FROM order_status_logs WHERE order_id = ? ORDER BY id ASC');
            $logStmt->bind_param('i', $orderId);
            $logStmt->execute();
            $logResult = $logStmt->get_result();
            while ($logResult && $log = $logResult->fetch_assoc()) {
                $statusLogs[] = $log;
            }
            $logStmt->close();

            if (count($statusLogs) === 0) {
                $statusLogs[] = [
                    'status' => (string)$order['status'],
                    'note' => 'Current order status',
                    'created_at' => (string)$order['created_at']
                ];
            }
        }
    }
}

if ($dbError === '') {
    $conn->close();
}

$statusClass = function ($status) {
    switch ($status) {
        case 'received':
            return 'pending';
        case 'processing':
            return 'preparing';
        case 'out_for_delivery':
            return 'ready';
        case 'completed':
            return 'completed';
        case 'cancelled':
            return 'cancelled';
        default:
            return 'unknown';
    }
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Order - Klint's Cafe</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --dark-bg: #0d1b2a;
            --darker-bg: #0a1218;
            --card-bg: #1a2a3a;
            --accent-gold: #c5a572;
            --accent-light: #e8dcc8;
            --text-light: #f5f1ed;
            --text-muted: #b8afa3;
            --border-color: rgba(197, 165, 114, 0.15);
            --error: #ff9d96;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Montserrat", "Segoe UI", sans-serif;
            color: var(--text-light);
            background: radial-gradient(circle at 10% 20%, rgba(197, 165, 114, 0.1), transparent 40%), linear-gradient(135deg, var(--dark-bg), var(--darker-bg));
            min-height: 100vh;
        }

        .container {
            max-width: 980px;
            margin: 0 auto;
            padding: 28px 16px 40px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }

        .brand {
            font-family: "Cormorant Garamond", serif;
            color: var(--accent-gold);
            text-decoration: none;
            font-size: 1.8rem;
            font-weight: 700;
        }

        .back-link {
            border: 1px solid var(--border-color);
            color: var(--accent-gold);
            text-decoration: none;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.85rem;
        }

        .panel {
            border: 1px solid var(--border-color);
            background: linear-gradient(145deg, rgba(26, 42, 58, 0.78) 0%, rgba(26, 42, 58, 0.52) 100%);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
        }

        h1 {
            margin: 0 0 8px;
            font-family: "Cormorant Garamond", serif;
            color: var(--accent-gold);
            font-size: 2.1rem;
        }

        .sub {
            margin: 0;
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .search-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 10px;
            margin-top: 14px;
        }

        input {
            width: 100%;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.92rem;
            background: rgba(13, 27, 42, 0.7);
            color: var(--text-light);
        }

        input::placeholder {
            color: var(--text-muted);
        }

        button {
            border: none;
            border-radius: 8px;
            background: var(--accent-gold);
            color: var(--dark-bg);
            padding: 10px 16px;
            font-weight: 700;
            cursor: pointer;
        }

        .error {
            color: var(--error);
            font-size: 0.9rem;
            margin-top: 10px;
        }

        .status-pill {
            display: inline-block;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.45px;
            font-weight: 700;
        }

        .status-pill.pending { background: rgba(255, 193, 7, 0.22); color: #ffd367; }
        .status-pill.preparing { background: rgba(112, 193, 255, 0.22); color: #9ddcff; }
        .status-pill.ready { background: rgba(118, 143, 255, 0.25); color: #b7c7ff; }
        .status-pill.completed { background: rgba(93, 175, 120, 0.24); color: #98e3b0; }
        .status-pill.cancelled { background: rgba(220, 106, 100, 0.24); color: #ff9d96; }
        .status-pill.unknown { background: rgba(255, 255, 255, 0.16); color: #ddd; }

        .timeline {
            margin-top: 14px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 12px;
            background: rgba(13, 27, 42, 0.45);
        }

        .timeline-step {
            position: relative;
            padding: 8px 0 8px 24px;
            border-left: 1px dashed rgba(197, 165, 114, 0.34);
            margin-left: 6px;
        }

        .timeline-step::before {
            content: "";
            position: absolute;
            left: -6px;
            top: 13px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #d0b48b;
        }

        .timeline-step:last-child {
            border-left-color: transparent;
            padding-bottom: 0;
        }

        .timeline-step strong {
            display: block;
            color: var(--text-light);
            text-transform: capitalize;
            font-size: 0.9rem;
        }

        .timeline-step span {
            color: var(--text-muted);
            font-size: 0.82rem;
        }

        .meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .meta-box {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: rgba(13, 27, 42, 0.5);
            padding: 10px;
        }

        .meta-box strong {
            display: block;
            color: var(--accent-gold);
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
        }

        th {
            color: var(--text-muted);
            font-weight: 600;
        }

        .totals {
            margin-top: 12px;
            display: grid;
            gap: 6px;
            justify-content: end;
        }

        .totals div {
            min-width: 220px;
            display: flex;
            justify-content: space-between;
            color: var(--text-muted);
        }

        .totals .grand {
            color: var(--accent-gold);
            font-weight: 700;
            border-top: 1px solid var(--border-color);
            padding-top: 6px;
        }

        @media (max-width: 760px) {
            .search-grid {
                grid-template-columns: 1fr;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            table, thead, tbody, th, td, tr {
                display: block;
            }

            thead {
                display: none;
            }

            td {
                border-bottom: none;
                padding: 4px 0;
            }

            tr {
                border-bottom: 1px solid var(--border-color);
                padding: 10px 0;
            }
        }
    </style>
</head>
<body>
    <main class="container">
        <div class="topbar">
            <a href="cafe.php" class="brand">Klint's Cafe</a>
            <a href="cafe.php" class="back-link">Back to Menu</a>
        </div>

        <section class="panel">
            <h1>Track Your Order</h1>
            <p class="sub">Enter your order number and the same name or phone you used at checkout.</p>
            <form method="GET" action="track_order.php" class="search-grid">
                <input type="number" min="1" name="order_id" placeholder="Order ID (example: 24)" value="<?php echo htmlspecialchars($orderIdInput); ?>" required>
                <input type="text" name="identity" placeholder="Customer name or phone" value="<?php echo htmlspecialchars($identityInput); ?>" required>
                <button type="submit" name="search" value="1">Track</button>
            </form>
            <?php if ($dbError !== ''): ?>
                <p class="error"><?php echo htmlspecialchars($dbError); ?></p>
            <?php elseif ($formError !== ''): ?>
                <p class="error"><?php echo htmlspecialchars($formError); ?></p>
            <?php endif; ?>
        </section>

        <?php if ($order): ?>
            <section class="panel">
                <span class="status-pill <?php echo $statusClass($order['status']); ?>"><?php echo htmlspecialchars($order['status']); ?></span>
                <div class="meta">
                    <div class="meta-box"><strong>Order No.</strong>#<?php echo (int)$order['id']; ?></div>
                    <div class="meta-box"><strong>Customer</strong><?php echo htmlspecialchars($order['customer_name']); ?></div>
                    <div class="meta-box"><strong>Phone</strong><?php echo htmlspecialchars((string)($order['customer_phone'] ?: '-')); ?></div>
                    <div class="meta-box"><strong>Placed At</strong><?php echo htmlspecialchars((string)$order['created_at']); ?></div>
                    <div class="meta-box"><strong>Order Type</strong><?php echo htmlspecialchars((string)($order['order_type'] ?? 'pickup')); ?></div>
                    <div class="meta-box"><strong>Payment</strong><?php echo htmlspecialchars((string)($order['payment_method'] ?? 'cod')); ?></div>
                    <div class="meta-box"><strong>ETA</strong><?php echo (int)($order['estimated_minutes'] ?? 0); ?> mins</div>
                    <div class="meta-box"><strong>Delivery Address</strong><?php echo htmlspecialchars((string)($order['delivery_address'] ?: '-')); ?></div>
                </div>
                <?php if (!empty($order['note'])): ?>
                    <p class="sub" style="margin-top: 10px;">Note: <?php echo htmlspecialchars((string)$order['note']); ?></p>
                <?php endif; ?>

                <div class="timeline">
                    <?php foreach ($statusLogs as $log): ?>
                        <div class="timeline-step">
                            <strong><?php echo htmlspecialchars((string)$log['status']); ?></strong>
                            <span><?php echo htmlspecialchars((string)$log['created_at']); ?><?php echo !empty($log['note']) ? (' - ' . htmlspecialchars((string)$log['note'])) : ''; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (($order['status'] ?? '') === 'received'): ?>
                    <form method="POST" action="track_order.php" style="margin-top:12px;" onsubmit="return confirm('Cancel this order?');">
                        <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                        <input type="hidden" name="identity" value="<?php echo htmlspecialchars($identityInput); ?>">
                        <button type="submit" style="background:#d96767;color:#fff;">Cancel Order</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h1 style="font-size: 1.6rem; margin-bottom: 8px;">Items</h1>
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Price</th>
                            <th>Qty</th>
                            <th>Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItems as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$item['product_name']); ?></td>
                                <td>PHP <?php echo number_format((float)$item['unit_price'], 2); ?></td>
                                <td><?php echo (int)$item['quantity']; ?></td>
                                <td>PHP <?php echo number_format((float)$item['line_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="totals">
                    <div><span>Subtotal</span><span>PHP <?php echo number_format((float)$order['subtotal'], 2); ?></span></div>
                    <div><span>Tax</span><span>PHP <?php echo number_format((float)$order['tax'], 2); ?></span></div>
                    <div><span>Delivery Fee</span><span>PHP <?php echo number_format((float)($order['delivery_fee'] ?? 0), 2); ?></span></div>
                    <div><span>Service Fee</span><span>PHP <?php echo number_format((float)($order['service_fee'] ?? 0), 2); ?></span></div>
                    <div class="grand"><span>Total</span><span>PHP <?php echo number_format((float)$order['total'], 2); ?></span></div>
                </div>
            </section>
        <?php elseif ($searched && $formError === '' && $dbError === ''): ?>
            <section class="panel">
                <p class="sub">No order details found for your search.</p>
            </section>
        <?php endif; ?>
    </main>
    <?php if ($order): ?>
    <script>
        const trackedOrderId = <?php echo (int)$order['id']; ?>;
        const trackedIdentity = <?php echo json_encode($identityInput); ?>;

        function prettyStatus(value) {
            return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (m) => m.toUpperCase());
        }

        async function refreshTracking() {
            try {
                const response = await fetch(`order_status_api.php?mode=single&order_id=${encodeURIComponent(trackedOrderId)}&identity=${encodeURIComponent(trackedIdentity)}`);
                const data = await response.json();
                if (!data.success || !data.order) {
                    return;
                }

                const statusPill = document.querySelector('.status-pill');
                if (statusPill) {
                    statusPill.textContent = prettyStatus(data.order.status);
                    statusPill.className = 'status-pill ' + (data.order.status === 'received' ? 'pending' : data.order.status === 'processing' ? 'preparing' : data.order.status === 'out_for_delivery' ? 'ready' : data.order.status === 'completed' ? 'completed' : data.order.status === 'cancelled' ? 'cancelled' : 'unknown');
                }

                const timeline = document.querySelector('.timeline');
                if (timeline && Array.isArray(data.timeline)) {
                    timeline.innerHTML = data.timeline.map((step) => `
                        <div class="timeline-step">
                            <strong>${prettyStatus(step.status)}</strong>
                            <span>${step.created_at || ''}${step.note ? (' - ' + step.note) : ''}</span>
                        </div>
                    `).join('');
                }
            } catch (error) {
                // Keep the last visible state if refresh fails.
            }
        }

        setInterval(refreshTracking, 10000);
    </script>
    <?php endif; ?>
</body>
</html>
