<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['id'])) {
    header('Location: lagin.html');
    exit;
}

$conn = get_auth_database_connection();

// Support older databases created before user_id tracking.
$userIdColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'user_id'");
if ($userIdColumn && $userIdColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN user_id INT NULL AFTER id");
}

// Support older databases created before stock tracking.
$stockColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
if ($stockColumn && $stockColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 20 AFTER price");
}

$conn->query("UPDATE orders SET status = 'received' WHERE status = 'pending'");
$conn->query("UPDATE orders SET status = 'processing' WHERE status = 'preparing'");
$conn->query("UPDATE orders SET status = 'out_for_delivery' WHERE status = 'ready'");

$conn->query("CREATE TABLE IF NOT EXISTS product_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    order_id INT NOT NULL,
    product_id INT NULL,
    rating TINYINT NOT NULL,
    review_text TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_order (user_id, order_id),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");

$reviewProductColumn = $conn->query("SHOW COLUMNS FROM product_reviews LIKE 'product_id'");
if ($reviewProductColumn && $reviewProductColumn->num_rows === 0) {
    $conn->query("ALTER TABLE product_reviews ADD COLUMN product_id INT NULL AFTER order_id");
}

$userId = (int)$_SESSION['id'];
$displayName = htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'User');

$reviewableStatuses = ['received', 'processing', 'out_for_delivery', 'completed'];

$getPendingReviewCount = static function (mysqli $db, int $uid): int {
    $sql = "SELECT COUNT(*) AS total
        FROM orders o
        LEFT JOIN product_reviews pr ON pr.order_id = o.id AND pr.user_id = o.user_id
        WHERE o.user_id = ? AND o.status = 'completed' AND pr.id IS NULL";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['total' => 0];
    $stmt->close();
    return (int)($row['total'] ?? 0);
};

$redirectWith = function ($params = []) {
    $base = 'my_orders.php';
    $query = http_build_query($params);
    header('Location: ' . $base . ($query !== '' ? ('?' . $query) : ''));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId <= 0) {
        $redirectWith(['status' => 'invalid_order']);
    }

    if ($action === 'cancel_order') {
        $stmt = $conn->prepare('UPDATE orders SET status = ? WHERE id = ? AND user_id = ? AND status = ?');
        $cancelled = 'cancelled';
        $received = 'received';
        $stmt->bind_param('siis', $cancelled, $orderId, $userId, $received);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();

        $redirectWith(['status' => $ok ? 'cancelled' : 'cannot_cancel']);
    }

    if ($action === 'reorder') {
        $pendingReviewCount = $getPendingReviewCount($conn, $userId);
        if ($pendingReviewCount > 0) {
            $redirectWith(['status' => 'review_required']);
        }

        $orderStmt = $conn->prepare('SELECT customer_name, customer_phone, note FROM orders WHERE id = ? AND user_id = ?');
        $orderStmt->bind_param('ii', $orderId, $userId);
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $originalOrder = $orderResult ? $orderResult->fetch_assoc() : null;
        $orderStmt->close();

        if (!$originalOrder) {
            $redirectWith(['status' => 'invalid_order']);
        }

        $itemsStmt = $conn->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ? ORDER BY id ASC');
        $itemsStmt->bind_param('i', $orderId);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();

        $requested = [];
        while ($itemsResult && $row = $itemsResult->fetch_assoc()) {
            $pid = (int)$row['product_id'];
            $qty = (int)$row['quantity'];
            if ($pid > 0 && $qty > 0) {
                if (!isset($requested[$pid])) {
                    $requested[$pid] = 0;
                }
                $requested[$pid] += $qty;
            }
        }
        $itemsStmt->close();

        if (count($requested) === 0) {
            $redirectWith(['status' => 'reorder_empty']);
        }

        $ids = implode(',', array_map('intval', array_keys($requested)));
        $productResult = $conn->query("SELECT id, name, price, stock_quantity, is_available FROM products WHERE id IN ($ids)");

        $productMap = [];
        if ($productResult) {
            while ($p = $productResult->fetch_assoc()) {
                $productMap[(int)$p['id']] = $p;
            }
            $productResult->close();
        }

        $orderLines = [];
        $subtotal = 0.0;
        foreach ($requested as $pid => $qty) {
            if (!isset($productMap[$pid])) {
                continue;
            }

            $prod = $productMap[$pid];
            if ((int)$prod['is_available'] !== 1) {
                continue;
            }

            $available = max(0, (int)$prod['stock_quantity']);
            if ($available <= 0) {
                continue;
            }

            $useQty = min($qty, $available);
            if ($useQty <= 0) {
                continue;
            }

            $unit = (float)$prod['price'];
            $lineTotal = $unit * $useQty;
            $subtotal += $lineTotal;

            $orderLines[] = [
                'product_id' => (int)$prod['id'],
                'product_name' => $prod['name'],
                'unit_price' => $unit,
                'quantity' => $useQty,
                'line_total' => $lineTotal
            ];
        }

        if (count($orderLines) === 0) {
            $redirectWith(['status' => 'reorder_unavailable']);
        }

        $subtotal = round($subtotal, 2);
        $tax = round($subtotal * 0.10, 2);
        $total = round($subtotal + $tax, 2);

        $conn->begin_transaction();
        try {
            $insertOrder = $conn->prepare('INSERT INTO orders (user_id, customer_name, customer_phone, note, subtotal, tax, total, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $statusNew = 'received';
            $reorderNote = trim(($originalOrder['note'] ?? '') . ' | Reorder from #' . $orderId);
            $insertOrder->bind_param('isssddds', $userId, $originalOrder['customer_name'], $originalOrder['customer_phone'], $reorderNote, $subtotal, $tax, $total, $statusNew);
            if (!$insertOrder->execute()) {
                throw new Exception('Could not create reorder');
            }

            $newOrderId = (int)$insertOrder->insert_id;
            $insertOrder->close();

            $insertItem = $conn->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total) VALUES (?, ?, ?, ?, ?, ?)');
            $updateStock = $conn->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');

            foreach ($orderLines as $line) {
                $insertItem->bind_param('iisdid', $newOrderId, $line['product_id'], $line['product_name'], $line['unit_price'], $line['quantity'], $line['line_total']);
                if (!$insertItem->execute()) {
                    throw new Exception('Could not save reorder items');
                }

                $updateStock->bind_param('iii', $line['quantity'], $line['product_id'], $line['quantity']);
                $updateStock->execute();
                if ($updateStock->affected_rows <= 0) {
                    throw new Exception('Insufficient stock during reorder');
                }
            }

            $insertItem->close();
            $updateStock->close();
            $conn->commit();

            $redirectWith(['status' => 'reorder_success', 'new_order' => $newOrderId]);
        } catch (Exception $e) {
            $conn->rollback();
            $redirectWith(['status' => 'reorder_error']);
        }
    }

    if ($action === 'save_review') {
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
        $reviewText = trim($_POST['review_text'] ?? '');
        $productId = max(0, (int)($_POST['product_id'] ?? 0));

        $reviewableStmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ? AND status IN ('received','processing','out_for_delivery','completed') LIMIT 1");
        $reviewableStmt->bind_param('ii', $orderId, $userId);
        $reviewableStmt->execute();
        $reviewableResult = $reviewableStmt->get_result();
        $canReview = $reviewableResult && $reviewableResult->num_rows > 0;
        $reviewableStmt->close();

        if (!$canReview || $rating < 1 || $rating > 5 || $reviewText === '') {
            $redirectWith(['status' => 'review_invalid']);
        }

        $existsStmt = $conn->prepare('SELECT id FROM product_reviews WHERE user_id = ? AND order_id = ? LIMIT 1');
        $existsStmt->bind_param('ii', $userId, $orderId);
        $existsStmt->execute();
        $existsResult = $existsStmt->get_result();
        $existing = $existsResult ? $existsResult->fetch_assoc() : null;
        $existsStmt->close();

        if ($existing) {
            $updateStmt = $conn->prepare('UPDATE product_reviews SET product_id = ?, rating = ?, review_text = ? WHERE id = ?');
            $reviewId = (int)$existing['id'];
            $updateStmt->bind_param('iisi', $productId, $rating, $reviewText, $reviewId);
            $ok = $updateStmt->execute();
            $updateStmt->close();
            $redirectWith(['status' => $ok ? 'review_updated' : 'review_error']);
        }

        $insertStmt = $conn->prepare('INSERT INTO product_reviews (user_id, order_id, product_id, rating, review_text) VALUES (?, ?, ?, ?, ?)');
        $insertStmt->bind_param('iiiis', $userId, $orderId, $productId, $rating, $reviewText);
        $ok = $insertStmt->execute();
        $insertStmt->close();
        $redirectWith(['status' => $ok ? 'review_saved' : 'review_error']);
    }

    if ($action === 'delete_review') {
        $deleteStmt = $conn->prepare('DELETE FROM product_reviews WHERE user_id = ? AND order_id = ?');
        $deleteStmt->bind_param('ii', $userId, $orderId);
        $ok = $deleteStmt->execute();
        $deleteStmt->close();
        $redirectWith(['status' => $ok ? 'review_deleted' : 'review_error']);
    }
}

$status = trim($_GET['status'] ?? '');
$statusFilter = trim($_GET['status_filter'] ?? '');
$search = trim($_GET['q'] ?? '');
$allowedStatuses = ['received', 'processing', 'out_for_delivery', 'completed', 'cancelled'];

$summary = [
    'total_orders' => 0,
    'pending_orders' => 0,
    'completed_orders' => 0,
    'cancelled_orders' => 0
];

$summarySql = "SELECT COUNT(*) AS total_orders,
    SUM(CASE WHEN status IN ('received', 'processing', 'out_for_delivery') THEN 1 ELSE 0 END) AS pending_orders,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders
    FROM orders WHERE user_id = ?";
$summaryStmt = $conn->prepare($summarySql);
$summaryStmt->bind_param('i', $userId);
$summaryStmt->execute();
$summaryResult = $summaryStmt->get_result();
if ($summaryResult && $summaryResult->num_rows > 0) {
    $summary = array_merge($summary, $summaryResult->fetch_assoc());
}
$summaryStmt->close();

$sql = 'SELECT id, customer_name, subtotal, tax, total, status, created_at FROM orders WHERE user_id = ?';
$types = 'i';
$params = [$userId];

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= ' AND status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $sql .= ' AND (CAST(id AS CHAR) LIKE ? OR customer_name LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}

$sql .= ' ORDER BY id DESC LIMIT 50';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$orders = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
}

$reviewableOrderIds = array_map(static function ($o) {
    return (int)$o['id'];
}, array_filter($orders, static function ($o) {
    return in_array((string)($o['status'] ?? ''), ['received', 'processing', 'out_for_delivery', 'completed'], true);
}));

$reviewableOrderProducts = [];
$reviewByOrder = [];
if (count($reviewableOrderIds) > 0) {
    $reviewableIds = implode(',', array_map('intval', $reviewableOrderIds));

    $orderProductsResult = $conn->query("SELECT order_id, product_id, product_name FROM order_items WHERE order_id IN ($reviewableIds) ORDER BY id ASC");
    if ($orderProductsResult) {
        while ($line = $orderProductsResult->fetch_assoc()) {
            $oid = (int)$line['order_id'];
            if (!isset($reviewableOrderProducts[$oid])) {
                $reviewableOrderProducts[$oid] = [];
            }
            $reviewableOrderProducts[$oid][] = [
                'product_id' => (int)($line['product_id'] ?? 0),
                'product_name' => (string)($line['product_name'] ?? 'Item')
            ];
        }
        $orderProductsResult->close();
    }

    $reviewResult = $conn->query("SELECT order_id, product_id, rating, review_text, updated_at FROM product_reviews WHERE user_id = $userId AND order_id IN ($reviewableIds)");
    if ($reviewResult) {
        while ($review = $reviewResult->fetch_assoc()) {
            $reviewByOrder[(int)$review['order_id']] = $review;
        }
        $reviewResult->close();
    }
}

$pendingReviewCount = $getPendingReviewCount($conn, $userId);
$stmt->close();
$conn->close();

$statusClass = function ($status) {
    switch ($status) {
        case 'received':
            return 'bg-warning text-dark';
        case 'processing':
            return 'bg-info text-dark';
        case 'out_for_delivery':
            return 'bg-primary';
        case 'completed':
            return 'bg-success';
        case 'cancelled':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
};

$statusMap = [
    'cancelled' => ['success', 'Order cancelled successfully.'],
    'cannot_cancel' => ['warning', 'Only received orders can be cancelled.'],
    'invalid_order' => ['danger', 'Invalid order request.'],
    'reorder_success' => ['success', 'Reorder placed successfully.'],
    'reorder_error' => ['danger', 'Unable to place reorder right now.'],
    'reorder_unavailable' => ['warning', 'All items from that order are currently unavailable/out of stock.'],
    'reorder_empty' => ['warning', 'No valid items were found to reorder.'],
    'review_saved' => ['success', 'Review submitted successfully.'],
    'review_updated' => ['success', 'Review updated successfully.'],
    'review_deleted' => ['success', 'Review deleted successfully.'],
    'review_invalid' => ['warning', 'Orders in progress/completed require both rating and review text.'],
    'review_required' => ['warning', 'Please submit your pending review(s) before placing a new order.'],
    'review_error' => ['danger', 'Unable to process review action.']
];

$alert = $statusMap[$status] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Orders - Klint's Cafe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #0d1b2a;
            color: #f5f1ed;
            min-height: 100vh;
        }

        .page-wrap {
            max-width: 980px;
            margin: 40px auto;
            padding: 0 16px;
        }

        .card-shell {
            background: #17293d;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 14px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.25);
        }

        .table {
            color: #f5f1ed;
        }

        .table thead th {
            border-color: rgba(255,255,255,0.14);
            color: #d6c6ad;
            font-weight: 600;
        }

        .table td {
            border-color: rgba(255,255,255,0.08);
            vertical-align: middle;
        }

        .muted-note {
            color: #b8afa3;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-card {
            background: #102033;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 12px;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #b8afa3;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .stat-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: #f5f1ed;
        }
    </style>
</head>
<body>
    <div class="page-wrap">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="mb-1">My Orders</h2>
                <div class="muted-note">Hello, <?php echo $displayName; ?>. Track your recent orders here.</div>
            </div>
            <a href="cafe.php" class="btn btn-outline-light">Back to Menu</a>
        </div>

        <?php if ($alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert[0]); ?> mb-3" role="alert">
                <?php echo htmlspecialchars($alert[1]); ?>
                <?php if ($status === 'reorder_success' && isset($_GET['new_order'])): ?>
                    <a class="alert-link" href="order_receipt.php?order_id=<?php echo (int)$_GET['new_order']; ?>&copy=customer">View receipt</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($pendingReviewCount > 0): ?>
            <div class="alert alert-warning mb-3" role="alert">
                You have <?php echo $pendingReviewCount; ?> completed order(s) without a review. Please submit rating and review below before placing a new order.
            </div>
        <?php endif; ?>

        <div class="card-shell p-3 p-md-4">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Orders</div>
                    <div class="stat-value"><?php echo (int)($summary['total_orders'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Orders</div>
                    <div class="stat-value"><?php echo (int)($summary['pending_orders'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?php echo (int)($summary['completed_orders'] ?? 0); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Cancelled</div>
                    <div class="stat-value"><?php echo (int)($summary['cancelled_orders'] ?? 0); ?></div>
                </div>
            </div>

            <form method="GET" action="my_orders.php" class="row g-2 mb-3">
                <div class="col-md-4">
                    <select class="form-select" name="status_filter">
                        <option value="">All Statuses</option>
                        <?php foreach ($allowedStatuses as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $s))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by order number or name">
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-outline-light" type="submit">Apply</button>
                </div>
            </form>

            <?php if (count($orders) === 0): ?>
                <div class="text-center py-4">
                    <h5 class="mb-2">No orders yet</h5>
                    <p class="muted-note mb-3">Your new orders will appear here with live status.</p>
                    <a href="cafe.php" class="btn btn-warning">Start Ordering</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th class="text-end">Total</th>
                                <th class="text-center">Receipt</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td>#<?php echo (int)$order['id']; ?></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($order['created_at']))); ?></td>
                                    <td>
                                        <span class="badge <?php echo $statusClass($order['status']); ?> text-uppercase">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$order['status']))); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">₱<?php echo number_format((float)$order['total'], 2); ?></td>
                                    <td class="text-center">
                                        <a href="order_receipt.php?order_id=<?php echo (int)$order['id']; ?>&copy=customer" class="btn btn-sm btn-outline-light">View</a>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                                            <form method="POST" action="my_orders.php">
                                                <input type="hidden" name="action" value="reorder">
                                                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-warning">Reorder</button>
                                            </form>
                                            <?php if ($order['status'] === 'received'): ?>
                                                <form method="POST" action="my_orders.php" onsubmit="return confirm('Cancel this order?');">
                                                    <input type="hidden" name="action" value="cancel_order">
                                                    <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <hr class="my-4" style="border-color: rgba(255,255,255,0.14);">
            <h5 class="mb-3">Order Reviews</h5>
            <?php
                $reviewableOrders = array_values(array_filter($orders, static function ($o) {
                    return in_array((string)($o['status'] ?? ''), ['received', 'processing', 'out_for_delivery', 'completed'], true);
                }));
            ?>
            <?php if (count($reviewableOrders) === 0): ?>
                <p class="muted-note mb-0">Place an order first to leave your rating and review.</p>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($reviewableOrders as $reviewableOrder): ?>
                        <?php
                            $oid = (int)$reviewableOrder['id'];
                            $existingReview = $reviewByOrder[$oid] ?? null;
                            $productOptions = $reviewableOrderProducts[$oid] ?? [];
                            $currentStatus = (string)($reviewableOrder['status'] ?? 'received');
                        ?>
                        <div class="col-md-6">
                            <div class="p-3 rounded" style="border:1px solid rgba(255,255,255,0.12); background:#102033;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong>Order #<?php echo $oid; ?></strong>
                                    <span class="badge <?php echo $statusClass($currentStatus); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $currentStatus))); ?></span>
                                </div>

                                <form method="POST" action="my_orders.php" class="d-grid gap-2">
                                    <input type="hidden" name="action" value="save_review">
                                    <input type="hidden" name="order_id" value="<?php echo $oid; ?>">

                                    <select name="product_id" class="form-select form-select-sm">
                                        <?php foreach ($productOptions as $option): ?>
                                            <option value="<?php echo (int)$option['product_id']; ?>" <?php echo ((int)($existingReview['product_id'] ?? 0) === (int)$option['product_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars((string)$option['product_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <select name="rating" class="form-select form-select-sm" required>
                                        <option value="">Select Rating</option>
                                        <?php for ($r = 5; $r >= 1; $r--): ?>
                                            <option value="<?php echo $r; ?>" <?php echo ((int)($existingReview['rating'] ?? 0) === $r) ? 'selected' : ''; ?>><?php echo $r; ?> Star<?php echo $r > 1 ? 's' : ''; ?></option>
                                        <?php endfor; ?>
                                    </select>

                                    <textarea name="review_text" class="form-control form-control-sm" rows="2" required placeholder="Share your experience..."><?php echo htmlspecialchars((string)($existingReview['review_text'] ?? '')); ?></textarea>

                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="submit" class="btn btn-sm btn-warning"><?php echo $existingReview ? 'Update Review' : 'Submit Review'; ?></button>
                                    </div>
                                </form>
                                <?php if ($existingReview): ?>
                                    <form method="POST" action="my_orders.php" class="mt-2" onsubmit="return confirm('Delete this review?');">
                                        <input type="hidden" name="action" value="delete_review">
                                        <input type="hidden" name="order_id" value="<?php echo $oid; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
