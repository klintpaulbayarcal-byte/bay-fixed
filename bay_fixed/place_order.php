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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);

$customerName = trim($payload['customer_name'] ?? '');
$customerPhone = trim($payload['customer_phone'] ?? '');
$note = trim($payload['note'] ?? '');
$orderType = strtolower(trim($payload['order_type'] ?? 'pickup'));
$paymentMethod = strtolower(trim($payload['payment_method'] ?? 'cod'));
$deliveryAddress = trim($payload['delivery_address'] ?? '');
$deliveryLat = (float)($payload['delivery_lat'] ?? 0);
$deliveryLng = (float)($payload['delivery_lng'] ?? 0);
$distanceKmInput = (float)($payload['distance_km'] ?? 0);
$deliveryZoneInput = strtolower(trim((string)($payload['delivery_zone'] ?? 'near')));
$serviceFeeInput = (float)($payload['service_fee'] ?? 0);
$estimatedMinutesInput = (int)($payload['estimated_minutes'] ?? 0);
$items = $payload['items'] ?? [];

$allowedOrderTypes = ['pickup', 'delivery'];
$allowedPaymentMethods = ['cod', 'cash', 'gcash', 'maya', 'card'];

if (!in_array($orderType, $allowedOrderTypes, true)) {
    $orderType = 'pickup';
}

if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
    $paymentMethod = 'cod';
}

if ($orderType === 'delivery' && $deliveryAddress === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Delivery address is required for delivery orders']);
    exit;
}

if ($orderType === 'delivery' && ($deliveryLat === 0.0 || $deliveryLng === 0.0)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please pin your delivery location on the map']);
    exit;
}

if ($customerName === '' || !is_array($items) || count($items) === 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Customer name and items are required']);
    exit;
}

// Ensure orders table exists even if admin dashboard was never opened.
$conn->query("CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(40) NULL,
    order_type VARCHAR(20) NOT NULL DEFAULT 'pickup',
    payment_method VARCHAR(30) NOT NULL DEFAULT 'cod',
    delivery_address VARCHAR(255) NULL,
    delivery_lat DECIMAL(10,7) NULL,
    delivery_lng DECIMAL(10,7) NULL,
    delivery_distance_km DECIMAL(10,2) NOT NULL DEFAULT 0,
    delivery_zone VARCHAR(30) NOT NULL DEFAULT 'near',
    note VARCHAR(255) NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    tax DECIMAL(10,2) NOT NULL,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    service_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
    total DECIMAL(10,2) NOT NULL,
    estimated_minutes INT NOT NULL DEFAULT 20,
    status VARCHAR(30) NOT NULL DEFAULT 'received',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$requiredOrderColumns = [
    "ALTER TABLE orders ADD COLUMN order_type VARCHAR(20) NOT NULL DEFAULT 'pickup' AFTER customer_phone",
    "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cod' AFTER order_type",
    "ALTER TABLE orders ADD COLUMN delivery_address VARCHAR(255) NULL AFTER payment_method",
    "ALTER TABLE orders ADD COLUMN delivery_lat DECIMAL(10,7) NULL AFTER delivery_address",
    "ALTER TABLE orders ADD COLUMN delivery_lng DECIMAL(10,7) NULL AFTER delivery_lat",
    "ALTER TABLE orders ADD COLUMN delivery_distance_km DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER delivery_lng",
    "ALTER TABLE orders ADD COLUMN delivery_zone VARCHAR(30) NOT NULL DEFAULT 'near' AFTER delivery_distance_km",
    "ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER tax",
    "ALTER TABLE orders ADD COLUMN service_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER delivery_fee",
    "ALTER TABLE orders ADD COLUMN estimated_minutes INT NOT NULL DEFAULT 20 AFTER total"
];

$columnsToCheck = ['order_type', 'payment_method', 'delivery_address', 'delivery_lat', 'delivery_lng', 'delivery_distance_km', 'delivery_zone', 'delivery_fee', 'service_fee', 'estimated_minutes'];
foreach ($columnsToCheck as $index => $columnName) {
    $columnExists = $conn->query("SHOW COLUMNS FROM orders LIKE '$columnName'");
    if ($columnExists && $columnExists->num_rows === 0) {
        $conn->query($requiredOrderColumns[$index]);
    }
}

$conn->query("CREATE TABLE IF NOT EXISTS order_status_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status VARCHAR(30) NOT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
)");

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

// Backward-compatible: add user_id when database was created by older code.
$userIdColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'user_id'");
if ($userIdColumn && $userIdColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN user_id INT NULL AFTER id");
}

// Backward-compatible: add product stock tracking when missing.
$stockColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
if ($stockColumn && $stockColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 20 AFTER price");
}

// Build a product map so we trust server-side prices only.
$productIds = [];
$qtyByProductId = [];

foreach ($items as $item) {
    $productId = (int) ($item['product_id'] ?? 0);
    $quantity = (int) ($item['quantity'] ?? 0);

    if ($productId <= 0 || $quantity <= 0) {
        continue;
    }

    if (!isset($qtyByProductId[$productId])) {
        $qtyByProductId[$productId] = 0;
    }

    $qtyByProductId[$productId] += $quantity;
    $productIds[$productId] = true;
}

if (count($qtyByProductId) === 0) {
    $conn->close();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No valid cart items found']);
    exit;
}

$idList = implode(',', array_map('intval', array_keys($productIds)));
$productResult = $conn->query("SELECT id, name, price, stock_quantity FROM products WHERE is_available = 1 AND id IN ($idList)");

if (!$productResult || $productResult->num_rows === 0) {
    $conn->close();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Selected products are unavailable']);
    exit;
}

$products = [];
while ($row = $productResult->fetch_assoc()) {
    $products[(int)$row['id']] = $row;
}
$productResult->close();

$subtotal = 0.0;
$orderLines = [];

foreach ($qtyByProductId as $productId => $quantity) {
    if (!isset($products[$productId])) {
        continue;
    }

    $stockAvailable = max(0, (int)$products[$productId]['stock_quantity']);
    if ($quantity > $stockAvailable) {
        $conn->close();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient stock for ' . $products[$productId]['name'] . '. Remaining: ' . $stockAvailable
        ]);
        exit;
    }

    $unitPrice = (float) $products[$productId]['price'];
    $lineTotal = $unitPrice * $quantity;
    $subtotal += $lineTotal;

    $orderLines[] = [
        'product_id' => $productId,
        'product_name' => $products[$productId]['name'],
        'unit_price' => $unitPrice,
        'quantity' => $quantity,
        'line_total' => $lineTotal
    ];
}

if (count($orderLines) === 0) {
    $conn->close();
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No available items to order']);
    exit;
}

$tax = round($subtotal * 0.10, 2);
$distanceKm = $orderType === 'delivery' ? max(0, round($distanceKmInput, 2)) : 0.00;
$deliveryZone = $deliveryZoneInput;
if (!in_array($deliveryZone, ['near', 'mid', 'far', 'extended'], true)) {
    if ($distanceKm <= 3) {
        $deliveryZone = 'near';
    } elseif ($distanceKm <= 7) {
        $deliveryZone = 'mid';
    } elseif ($distanceKm <= 12) {
        $deliveryZone = 'far';
    } else {
        $deliveryZone = 'extended';
    }
}

$deliveryFee = 0.00;
if ($orderType === 'delivery') {
    if ($deliveryZone === 'near') {
        $deliveryFee = 39;
    } elseif ($deliveryZone === 'mid') {
        $deliveryFee = 59;
    } elseif ($deliveryZone === 'far') {
        $deliveryFee = 89;
    } else {
        $deliveryFee = 119 + max(0, ($distanceKm - 12) * 5);
    }
    $deliveryFee = round($deliveryFee, 2);
}
$serviceFee = max(0, round($serviceFeeInput, 2));
$estimatedMinutes = $estimatedMinutesInput > 0 ? $estimatedMinutesInput : ($orderType === 'delivery' ? (int)round(20 + ($distanceKm * 4)) : 20);
$total = round($subtotal + $tax + $deliveryFee + $serviceFee, 2);
$subtotal = round($subtotal, 2);

$userId = isset($_SESSION['id']) ? (int)$_SESSION['id'] : 0;
$dbUserId = $userId > 0 ? $userId : 0;

if ($dbUserId > 0) {
    $pendingReviewSql = "SELECT COUNT(*) AS total
        FROM orders o
        LEFT JOIN product_reviews pr ON pr.order_id = o.id AND pr.user_id = o.user_id
        WHERE o.user_id = ? AND o.status = 'completed' AND pr.id IS NULL";
    $pendingReviewStmt = $conn->prepare($pendingReviewSql);
    if ($pendingReviewStmt) {
        $pendingReviewStmt->bind_param('i', $dbUserId);
        $pendingReviewStmt->execute();
        $pendingReviewResult = $pendingReviewStmt->get_result();
        $pendingReviewRow = $pendingReviewResult ? $pendingReviewResult->fetch_assoc() : ['total' => 0];
        $pendingReviewStmt->close();

        if ((int)($pendingReviewRow['total'] ?? 0) > 0) {
            $conn->close();
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Please submit your pending completed-order review(s) in My Orders before placing a new order.'
            ]);
            exit;
        }
    }
}

$conn->begin_transaction();

try {
    $insertOrder = $conn->prepare('INSERT INTO orders (user_id, customer_name, customer_phone, order_type, payment_method, delivery_address, delivery_lat, delivery_lng, delivery_distance_km, delivery_zone, note, subtotal, tax, delivery_fee, service_fee, total, estimated_minutes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $status = 'received';
    $insertOrder->bind_param('issssssdddsdddddis', $dbUserId, $customerName, $customerPhone, $orderType, $paymentMethod, $deliveryAddress, $deliveryLat, $deliveryLng, $distanceKm, $deliveryZone, $note, $subtotal, $tax, $deliveryFee, $serviceFee, $total, $estimatedMinutes, $status);

    if (!$insertOrder->execute()) {
        throw new Exception('Failed to create order');
    }

    $orderId = (int)$insertOrder->insert_id;
    $insertOrder->close();

    $insertItem = $conn->prepare('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total) VALUES (?, ?, ?, ?, ?, ?)');

    foreach ($orderLines as $line) {
        $productId = (int)$line['product_id'];
        $productName = $line['product_name'];
        $unitPrice = (float)$line['unit_price'];
        $quantity = (int)$line['quantity'];
        $lineTotal = (float)$line['line_total'];

        $insertItem->bind_param('iisdid', $orderId, $productId, $productName, $unitPrice, $quantity, $lineTotal);
        if (!$insertItem->execute()) {
            throw new Exception('Failed to save order items');
        }

        $updateStock = $conn->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
        $updateStock->bind_param('iii', $quantity, $productId, $quantity);
        $updateStock->execute();
        if ($updateStock->affected_rows <= 0) {
            $updateStock->close();
            throw new Exception('Insufficient stock while finalizing order');
        }
        $updateStock->close();
    }

    $insertItem->close();

    $logStmt = $conn->prepare('INSERT INTO order_status_logs (order_id, status, note) VALUES (?, ?, ?)');
    $logNote = 'Order placed by customer';
    $logStmt->bind_param('iss', $orderId, $status, $logNote);
    $logStmt->execute();
    $logStmt->close();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'order_code' => 'KC-' . str_pad((string)$orderId, 6, '0', STR_PAD_LEFT),
        'total' => $total,
        'order_type' => $orderType,
        'estimated_minutes' => $estimatedMinutes,
        'distance_km' => $distanceKm,
        'delivery_zone' => $deliveryZone,
        'delivery_fee' => $deliveryFee,
        'service_fee' => $serviceFee
    ]);
} catch (Exception $exception) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Order could not be processed']);
}

$conn->close();
