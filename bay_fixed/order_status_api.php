<?php
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/cors_bootstrap.php';
apply_api_cors_headers();
handle_api_preflight_request();
start_app_session();
header('Content-Type: application/json');

require_once __DIR__ . '/auth_bootstrap.php';

try {
    $conn = get_auth_database_connection();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
    exit;
}

$conn->query("CREATE TABLE IF NOT EXISTS order_status_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status VARCHAR(30) NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");

$requiredOrderColumns = [
    "order_type VARCHAR(20) NOT NULL DEFAULT 'pickup' AFTER customer_phone",
    "payment_method VARCHAR(30) NOT NULL DEFAULT 'cod' AFTER order_type",
    "delivery_address VARCHAR(255) NULL AFTER payment_method",
    "delivery_lat DECIMAL(10,7) NULL AFTER delivery_address",
    "delivery_lng DECIMAL(10,7) NULL AFTER delivery_lat",
    "delivery_distance_km DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER delivery_lng",
    "delivery_zone VARCHAR(30) NOT NULL DEFAULT 'near' AFTER delivery_distance_km",
    "delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER tax",
    "service_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER delivery_fee",
    "estimated_minutes INT NOT NULL DEFAULT 20 AFTER total"
];

foreach ($requiredOrderColumns as $columnDef) {
    $columnName = explode(' ', trim($columnDef), 2)[0];
    $columnCheck = $conn->query("SHOW COLUMNS FROM orders LIKE '{$columnName}'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE orders ADD COLUMN {$columnDef}");
    }
}

$mode = trim($_GET['mode'] ?? 'single');

if ($mode === 'queue') {
    if (!isset($_SESSION['username']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        $conn->close();
        exit;
    }

    $activeStatuses = "'received','processing','out_for_delivery'";
    $queue = [];
    $result = $conn->query("SELECT id, customer_name, customer_phone, order_type, payment_method, total, status, created_at FROM orders WHERE status IN ($activeStatuses) ORDER BY id DESC LIMIT 50");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $queue[] = $row;
        }
        $result->close();
    }

    $conn->close();
    echo json_encode(['success' => true, 'queue' => $queue, 'count' => count($queue)]);
    exit;
}

if ($mode === 'staff_dashboard') {
    if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'staff') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        $conn->close();
        exit;
    }

    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $typeFilter = trim((string)($_GET['type'] ?? ''));
    $dateFilter = trim((string)($_GET['date'] ?? ''));
    $periodFilter = trim((string)($_GET['period'] ?? ''));
    $queryFilter = trim((string)($_GET['q'] ?? ''));

    $allowedStatuses = ['received', 'processing', 'out_for_delivery', 'completed', 'cancelled'];
    $allowedTypes = ['pickup', 'delivery'];

    $where = [];
    $params = [];
    $types = '';

    if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if ($typeFilter !== '' && in_array($typeFilter, $allowedTypes, true)) {
        $where[] = 'order_type = ?';
        $params[] = $typeFilter;
        $types .= 's';
    }

    if ($dateFilter !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dateFilter)) {
        $where[] = 'DATE(created_at) = ?';
        $params[] = $dateFilter;
        $types .= 's';
    } elseif ($periodFilter === 'month') {
        $where[] = 'YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())';
    } elseif ($periodFilter === 'year') {
        $where[] = 'YEAR(created_at) = YEAR(CURDATE())';
    }

    if ($queryFilter !== '') {
        $where[] = '(customer_name LIKE ? OR customer_phone LIKE ? OR CAST(id AS CHAR) LIKE ?)';
        $like = '%' . $queryFilter . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    $ordersSql = 'SELECT id, customer_name, customer_phone, order_type, payment_method, total, status, created_at FROM orders';
    if (count($where) > 0) {
        $ordersSql .= ' WHERE ' . implode(' AND ', $where);
    }
    $ordersSql .= ' ORDER BY id DESC LIMIT 200';

    $orders = [];
    if (count($params) > 0) {
        $ordersStmt = $conn->prepare($ordersSql);
        $ordersStmt->bind_param($types, ...$params);
        $ordersStmt->execute();
        $ordersResult = $ordersStmt->get_result();
        while ($ordersResult && $row = $ordersResult->fetch_assoc()) {
            $orders[] = $row;
        }
        $ordersStmt->close();
    } else {
        $ordersResult = $conn->query($ordersSql);
        if ($ordersResult) {
            while ($row = $ordersResult->fetch_assoc()) {
                $orders[] = $row;
            }
            $ordersResult->close();
        }
    }

    $summary = [
        'pending_orders' => 0,
        'today_orders' => 0,
        'total_orders' => 0,
        'today_revenue' => 0,
        'month_revenue' => 0,
        'year_revenue' => 0,
        'in_progress' => 0,
        'pickup_count' => 0,
        'delivery_count' => 0
    ];

    $summaryQuery = $conn->query("SELECT
        SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_orders,
        COUNT(*) AS total_orders,
        SUM(CASE WHEN DATE(created_at) = CURDATE() AND status <> 'cancelled' THEN total ELSE 0 END) AS today_revenue,
        SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND status <> 'cancelled' THEN total ELSE 0 END) AS month_revenue,
        SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND status <> 'cancelled' THEN total ELSE 0 END) AS year_revenue,
        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS in_progress,
        SUM(CASE WHEN order_type = 'pickup' THEN 1 ELSE 0 END) AS pickup_count,
        SUM(CASE WHEN order_type = 'delivery' THEN 1 ELSE 0 END) AS delivery_count
        FROM orders");
    if ($summaryQuery && $summaryQuery->num_rows > 0) {
        $summary = array_merge($summary, $summaryQuery->fetch_assoc());
        $summaryQuery->close();
    }

    // Add staff sales reports to revenue totals
    $staffRevenueResult = $conn->query("SELECT
        COALESCE(SUM(CASE WHEN report_date = CURDATE() THEN total_sales ELSE 0 END), 0) AS staff_today,
        COALESCE(SUM(CASE WHEN YEAR(report_date) = YEAR(CURDATE()) AND MONTH(report_date) = MONTH(CURDATE()) THEN total_sales ELSE 0 END), 0) AS staff_month,
        COALESCE(SUM(CASE WHEN YEAR(report_date) = YEAR(CURDATE()) THEN total_sales ELSE 0 END), 0) AS staff_year
        FROM staff_sales_reports");
    if ($staffRevenueResult && $staffRevenueResult->num_rows > 0) {
        $staffRevRow = $staffRevenueResult->fetch_assoc();
        $summary['today_revenue'] = (float)$summary['today_revenue'] + (float)$staffRevRow['staff_today'];
        $summary['month_revenue'] = (float)$summary['month_revenue'] + (float)$staffRevRow['staff_month'];
        $summary['year_revenue'] = (float)$summary['year_revenue'] + (float)$staffRevRow['staff_year'];
        $staffRevenueResult->close();
    }

    $activeCountResult = $conn->query("SELECT COUNT(*) AS total FROM orders WHERE status IN ('received','processing','out_for_delivery')");
    $activeCount = 0;
    if ($activeCountResult && $activeCountResult->num_rows > 0) {
        $activeCountRow = $activeCountResult->fetch_assoc();
        $activeCount = (int)($activeCountRow['total'] ?? 0);
        $activeCountResult->close();
    }

    $conn->close();
    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'count' => count($orders),
        'active_count' => $activeCount,
        'summary' => $summary
    ]);
    exit;
}

if ($mode === 'staff_detail') {
    if (!isset($_SESSION['username']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        $conn->close();
        exit;
    }

    $orderId = (int)($_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Order ID is required']);
        $conn->close();
        exit;
    }

    $orderStmt = $conn->prepare('SELECT id, customer_name, customer_phone, order_type, payment_method, delivery_address, delivery_lat, delivery_lng, delivery_distance_km, delivery_zone, note, subtotal, tax, delivery_fee, service_fee, total, estimated_minutes, status, created_at FROM orders WHERE id = ? LIMIT 1');
    $orderStmt->bind_param('i', $orderId);
    $orderStmt->execute();
    $orderResult = $orderStmt->get_result();
    $order = $orderResult ? $orderResult->fetch_assoc() : null;
    $orderStmt->close();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        $conn->close();
        exit;
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

    if (count($logs) === 0) {
        $logs[] = [
            'status' => (string)$order['status'],
            'note' => 'Current order status',
            'created_at' => (string)$order['created_at']
        ];
    }

    $conn->close();
    echo json_encode(['success' => true, 'order' => $order, 'items' => $items, 'timeline' => $logs]);
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
$identity = trim($_GET['identity'] ?? '');

if ($orderId <= 0 || $identity === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Order ID and identity are required']);
    $conn->close();
    exit;
}

$orderStmt = $conn->prepare('SELECT id, customer_name, customer_phone, order_type, payment_method, delivery_address, subtotal, tax, delivery_fee, service_fee, total, estimated_minutes, status, created_at FROM orders WHERE id = ? AND (customer_name = ? OR customer_phone = ?) LIMIT 1');
$orderStmt->bind_param('iss', $orderId, $identity, $identity);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult ? $orderResult->fetch_assoc() : null;
$orderStmt->close();

if (!$order) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found']);
    $conn->close();
    exit;
}

$logs = [];
$logStmt = $conn->prepare('SELECT status, note, created_at FROM order_status_logs WHERE order_id = ? ORDER BY id ASC');
$logStmt->bind_param('i', $orderId);
$logStmt->execute();
$logResult = $logStmt->get_result();
while ($logResult && $row = $logResult->fetch_assoc()) {
    $logs[] = $row;
}
$logStmt->close();

if (count($logs) === 0) {
    $logs[] = [
        'status' => (string)$order['status'],
        'note' => 'Current order status',
        'created_at' => (string)$order['created_at']
    ];
}

$conn->close();

echo json_encode([
    'success' => true,
    'order' => $order,
    'timeline' => $logs
]);
