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

$orderId = (int)($_GET['order_id'] ?? 0);
$copyType = trim($_GET['copy'] ?? 'customer');

if ($orderId <= 0) {
    die('<html><head><style>body{font-family:Arial;padding:20px;color:#c00;}</style></head><body><h2>Invalid Order ID</h2><p>No order could be found. Please check the order number and try again.</p></body></html>');
}

if (!in_array($copyType, ['customer', 'kitchen'], true)) {
    $copyType = 'customer';
}

$conn = get_auth_database_connection();

$orderStmt = $conn->prepare('SELECT id, customer_name, customer_phone, note, subtotal, tax, total, status, created_at FROM orders WHERE id = ?');
if (!$orderStmt) {
    die('<html><head><style>body{font-family:Arial;padding:20px;color:#c00;}</style></head><body><h2>Database Error</h2><p>Query preparation failed.</p></body></html>');
}

$orderStmt->bind_param('i', $orderId);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();

if (!$orderResult || $orderResult->num_rows === 0) {
    $orderStmt->close();
    $conn->close();
    die('<html><head><style>body{font-family:Arial;padding:20px;color:#c00;}</style></head><body><h2>Order Not Found</h2><p>Order #' . htmlspecialchars($orderId) . ' does not exist. Please check the order number.</p></body></html>');
}

$order = $orderResult->fetch_assoc();
$orderStmt->close();

// Retrieve order items
$itemStmt = $conn->prepare('SELECT product_name, unit_price, quantity, line_total FROM order_items WHERE order_id = ? ORDER BY id ASC');
if (!$itemStmt) {
    die('<html><head><style>body{font-family:Arial;padding:20px;color:#c00;}</style></head><body><h2>Database Error</h2><p>Item query preparation failed.</p></body></html>');
}

$itemStmt->bind_param('i', $orderId);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();

$items = [];
if ($itemResult) {
    while ($line = $itemResult->fetch_assoc()) {
        $items[] = $line;
    }
}

$itemStmt->close();
$conn->close();

$isKitchen = ($copyType === 'kitchen');
$reviewLink = 'my_orders.php';
$title = $isKitchen ? 'Kitchen Receipt' : 'Customer Receipt';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - Order #<?php echo (int)$order['id']; ?></title>
    <style>
        :root {
            --dark-bg: #0d1b2a;
            --accent-gold: #c5a572;
            --text-light: #f5f1ed;
            --text-muted: #b8afa3;
            --border-color: rgba(197, 165, 114, 0.2);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            background: white;
            color: #222;
        }

        body {
            font-family: "Montserrat", Arial, sans-serif;
            padding: 20px;
        }

        .receipt {
            max-width: 420px;
            margin: 0 auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 24px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .header {
            text-align: center;
            margin-bottom: 16px;
            border-bottom: 2px solid var(--accent-gold);
            padding-bottom: 12px;
        }

        .header h1 {
            font-family: "Cormorant Garamond", serif;
            font-size: 1.8rem;
            color: var(--accent-gold);
            margin-bottom: 6px;
        }

        .header p {
            margin: 4px 0;
            color: #666;
            font-size: 0.9rem;
        }

        .order-info {
            background: rgba(197, 165, 114, 0.05);
            padding: 12px;
            border-radius: 6px;
            margin: 12px 0;
            font-size: 0.9rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 6px 0;
        }

        .info-label {
            font-weight: 600;
            color: #333;
        }

        .info-value {
            color: #666;
            text-align: right;
        }

        .items-section {
            border-top: 2px solid var(--accent-gold);
            border-bottom: 2px solid var(--accent-gold);
            padding: 12px 0;
            margin: 14px 0;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin: 8px 0;
            font-size: 0.9rem;
        }

        .item-name {
            font-weight: 600;
            color: #222;
            flex: 1;
        }

        .item-qty {
            color: #888;
            font-size: 0.85rem;
            margin: 0 8px;
        }

        .item-price {
            text-align: right;
            color: var(--accent-gold);
            font-weight: 600;
            min-width: 60px;
        }

        .no-items {
            padding: 12px;
            background: #f5f5f5;
            border-radius: 6px;
            color: #888;
            text-align: center;
            font-size: 0.9rem;
        }

        .summary {
            margin: 12px 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 6px 0;
            font-size: 0.9rem;
        }

        .summary-row.total {
            border-top: 1px solid #ddd;
            padding-top: 8px;
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--accent-gold);
        }

        .footer {
            text-align: center;
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px dashed #ddd;
            color: #666;
            font-size: 0.85rem;
        }

        .kitchen-note {
            background: #fef3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 10px;
            border-radius: 6px;
            font-size: 0.85rem;
            margin-top: 10px;
        }

        .print-bar {
            max-width: 420px;
            margin: 0 auto 16px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }

        .print-bar button,
        .print-bar a {
            flex: 1;
            border: 1px solid var(--accent-gold);
            background: white;
            color: var(--accent-gold);
            border-radius: 6px;
            padding: 10px;
            text-decoration: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .print-bar button:hover,
        .print-bar a:hover {
            background: var(--accent-gold);
            color: white;
        }

        .print-bar a:first-child {
            background: var(--accent-gold);
            color: white;
        }

        .print-bar a:first-child:hover {
            background: #b89461;
        }

        .post-order-actions {
            max-width: 420px;
            margin: 12px auto 0;
            padding: 12px;
            border: 1px solid #ead7bc;
            border-radius: 18px;
            background: #fbf6ee;
        }

        .quick-action-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .quick-action-btn,
        .order-more-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            border: 1px solid #dcc9ab;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            padding: 12px 10px;
            text-align: center;
            transition: all 0.2s ease;
        }

        .quick-action-btn:hover,
        .order-more-btn:hover {
            transform: translateY(-1px);
        }

        .quick-action-btn.track {
            background: #cc8840;
            color: #fff;
            border-color: #cc8840;
        }

        .quick-action-btn.receipt {
            background: #fff;
            color: #7a6a52;
        }

        .quick-action-btn.review {
            background: #f2a418;
            color: #2f210d;
            border-color: #f2a418;
        }

        .order-more-btn {
            width: 100%;
            margin-top: 12px;
            background: #fff;
            color: #6d5c47;
        }

        @media (max-width: 460px) {
            .quick-action-grid {
                grid-template-columns: 1fr;
            }
        }

        @media print {
            .print-bar {
                display: none;
            }

            .post-order-actions {
                display: none;
            }

            body {
                padding: 0;
                background: white;
            }

            .receipt {
                box-shadow: none;
                border: none;
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="print-bar">
        <a href="javascript:window.print()">🖨️ Print Receipt</a>
        <button onclick="window.location.href='cafe.php'">Continue Ordering</button>
    </div>

    <?php if (!$isKitchen): ?>
        <div class="post-order-actions">
            <div class="quick-action-grid">
                <a class="quick-action-btn track" href="track_order.php?search=1&order_id=<?php echo (int)$order['id']; ?>&identity=<?php echo urlencode((string)($order['customer_phone'] ?: $order['customer_name'])); ?>">Track Order →</a>
                <a class="quick-action-btn receipt" href="order_receipt.php?order_id=<?php echo (int)$order['id']; ?>&copy=customer">View Receipt</a>
                <a class="quick-action-btn review" href="<?php echo htmlspecialchars($reviewLink); ?>">Leave a Review</a>
            </div>
            <a class="order-more-btn" href="cafe.php">Order More</a>
        </div>
    <?php endif; ?>

    <div class="receipt">
        <div class="header">
            <h1>Klint's Cafe</h1>
            <p><?php echo htmlspecialchars($title); ?></p>
            <p>Order #<?php echo (int)$order['id']; ?> | <?php echo date('M d, Y H:i', strtotime($order['created_at'])); ?></p>
        </div>

        <div class="order-info">
            <div class="info-row">
                <span class="info-label">Customer:</span>
                <span class="info-value"><?php echo htmlspecialchars($order['customer_name']); ?></span>
            </div>
            <?php if (!empty($order['customer_phone'])): ?>
                <div class="info-row">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['customer_phone']); ?></span>
                </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-label">Status:</span>
                <span class="info-value" style="color: var(--accent-gold); font-weight: 600; text-transform: uppercase;"><?php echo htmlspecialchars($order['status']); ?></span>
            </div>
            <?php if (!$isKitchen && !empty($order['note'])): ?>
                <div class="info-row">
                    <span class="info-label">Notes:</span>
                    <span class="info-value"><?php echo htmlspecialchars($order['note']); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="items-section">
            <?php if (count($items) === 0): ?>
                <div class="no-items">⚠️ No items found for this order</div>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <div class="item-row">
                        <span class="item-name"><?php echo htmlspecialchars($item['product_name']); ?></span>
                        <span class="item-qty">×<?php echo (int)$item['quantity']; ?></span>
                        <?php if (!$isKitchen): ?>
                            <span class="item-price">₱<?php echo number_format((float)$item['line_total'], 2); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!$isKitchen): ?>
            <div class="summary">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span>₱<?php echo number_format((float)$order['subtotal'], 2); ?></span>
                </div>
                <div class="summary-row">
                    <span>Tax (10%):</span>
                    <span>₱<?php echo number_format((float)$order['tax'], 2); ?></span>
                </div>
                <div class="summary-row total">
                    <span>TOTAL:</span>
                    <span>₱<?php echo number_format((float)$order['total'], 2); ?></span>
                </div>
            </div>
        <?php else: ?>
            <div class="kitchen-note">
                <strong>Kitchen Copy Only</strong><br>
                Pricing not shown. Please prepare the items listed above.
            </div>
        <?php endif; ?>

        <div class="footer">
            Thank you for choosing Klint's Cafe!<br>
            <small>Order placed: <?php echo date('M d, Y \a\t h:i A', strtotime($order['created_at'])); ?></small>
        </div>
    </div>
</body>
</html>
