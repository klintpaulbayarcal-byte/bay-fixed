<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: lagin.html');
    exit;
}

if (!empty($_SESSION['force_password_change'])) {
    header('Location: change_password.php?status=force');
    exit;
}

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$allowedCategories = ['coffee', 'non-coffee', 'food', 'pastry'];
$allowedStatuses = ['received', 'processing', 'out_for_delivery', 'completed', 'cancelled'];

$conn = get_auth_database_connection();

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS staff_sales_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_user_id INT NOT NULL,
    staff_name VARCHAR(120) NOT NULL,
    report_date DATE NOT NULL,
    shift_label VARCHAR(30) NOT NULL DEFAULT 'full_day',
    total_sales DECIMAL(10,2) NOT NULL,
    total_orders INT NOT NULL DEFAULT 0,
    cash_on_hand DECIMAL(10,2) NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_staff_report_date (report_date),
    INDEX idx_staff_report_read (is_read),
    UNIQUE KEY uniq_staff_shift_day (staff_user_id, report_date, shift_label)
)");

$conn->query("CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'coffee',
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock_quantity INT NOT NULL DEFAULT 20,
    image_url VARCHAR(255) NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(40) NULL,
    order_type VARCHAR(20) NOT NULL DEFAULT 'pickup',
    payment_method VARCHAR(30) NOT NULL DEFAULT 'cod',
    delivery_address VARCHAR(255) NULL,
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

$ordersUserColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'user_id'");
if ($ordersUserColumn && $ordersUserColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN user_id INT NULL AFTER id");
}

$ordersTypeColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'order_type'");
if ($ordersTypeColumn && $ordersTypeColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN order_type VARCHAR(20) NOT NULL DEFAULT 'pickup' AFTER customer_phone");
}

$ordersPaymentColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'payment_method'");
if ($ordersPaymentColumn && $ordersPaymentColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'cod' AFTER order_type");
}

$ordersAddressColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_address'");
if ($ordersAddressColumn && $ordersAddressColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN delivery_address VARCHAR(255) NULL AFTER payment_method");
}

$ordersLatColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_lat'");
if ($ordersLatColumn && $ordersLatColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN delivery_lat DECIMAL(10,7) NULL AFTER delivery_address");
}

$ordersLngColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_lng'");
if ($ordersLngColumn && $ordersLngColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN delivery_lng DECIMAL(10,7) NULL AFTER delivery_lat");
}

$ordersDistanceColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_distance_km'");
if ($ordersDistanceColumn && $ordersDistanceColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN delivery_distance_km DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER delivery_lng");
}

$ordersZoneColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_zone'");
if ($ordersZoneColumn && $ordersZoneColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN delivery_zone VARCHAR(30) NOT NULL DEFAULT 'near' AFTER delivery_distance_km");
}

$ordersDeliveryFeeColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'delivery_fee'");
if ($ordersDeliveryFeeColumn && $ordersDeliveryFeeColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER tax");
}

$ordersServiceFeeColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'service_fee'");
if ($ordersServiceFeeColumn && $ordersServiceFeeColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN service_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER delivery_fee");
}

$ordersEtaColumn = $conn->query("SHOW COLUMNS FROM orders LIKE 'estimated_minutes'");
if ($ordersEtaColumn && $ordersEtaColumn->num_rows === 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN estimated_minutes INT NOT NULL DEFAULT 20 AFTER total");
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

$conn->query("UPDATE orders SET status = 'received' WHERE status = 'pending'");
$conn->query("UPDATE orders SET status = 'processing' WHERE status = 'preparing'");
$conn->query("UPDATE orders SET status = 'out_for_delivery' WHERE status = 'ready'");

$conn->query("CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(120) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL
)");

$categoryColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
if ($categoryColumn && $categoryColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'coffee' AFTER name");
}

$stockColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
if ($stockColumn && $stockColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 20 AFTER price");
}

// Seed fallback menu items when products table is empty.
$productCountResult = $conn->query("SELECT COUNT(*) AS total FROM products");
$productCountRow = $productCountResult ? $productCountResult->fetch_assoc() : ['total' => 0];
if ((int)($productCountRow['total'] ?? 0) === 0) {
    $seedProducts = [
        ["Espresso", "coffee", "Strong and bold single-shot espresso.", 120.00, 30, "https://images.unsplash.com/photo-1510591509098-f4fdc6d0ff04?w=400&h=300&fit=crop"],
        ["Americano", "coffee", "Espresso topped with hot water.", 140.00, 25, "https://assets.beanbox.com/blog_images/AB7ud4YSE6nmOX0iGlgA.jpeg"],
        ["Latte", "coffee", "Creamy milk coffee with smooth texture.", 160.00, 20, "https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=400&h=300&fit=crop"],
        ["Cappuccino", "coffee", "Espresso with steamed milk and foam.", 165.00, 20, "https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=400&h=300&fit=crop"],
        ["Iced Tea Lemon", "non-coffee", "Refreshing brewed tea with lemon.", 110.00, 15, "https://images.unsplash.com/photo-1556679343-c7306c1976bc?w=400&h=300&fit=crop"],
        ["Chocolate Muffin", "pastry", "Freshly baked chocolate muffin.", 85.00, 18, "https://images.unsplash.com/photo-1604882406195-d94d4f33b0a9?w=400&h=300&fit=crop"],
        ["Ham and Cheese Sandwich", "food", "Toasted sandwich with ham and cheese.", 180.00, 12, "https://images.unsplash.com/photo-1528735602780-2552fd46c7af?w=400&h=300&fit=crop"]
    ];

    $insertProduct = $conn->prepare("INSERT INTO products (name, category, description, price, stock_quantity, image_url, is_available) VALUES (?, ?, ?, ?, ?, ?, 1)");
    foreach ($seedProducts as $product) {
        $productName = $product[0];
        $productCategory = $product[1];
        $productDescription = $product[2];
        $productPrice = $product[3];
        $productStock = (int)$product[4];
        $productImage = $product[5];
        $insertProduct->bind_param("sssdis", $productName, $productCategory, $productDescription, $productPrice, $productStock, $productImage);
        $insertProduct->execute();
    }
    $insertProduct->close();
}

$redirectWith = function ($params) {
    $query = http_build_query($params);
    header('Location: admin_dashboard.php' . ($query !== '' ? '?' . $query : ''));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_product' || $action === 'update_product') {
        $productId = (int)($_POST['product_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? 'coffee');
        $description = trim($_POST['description'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $stockQuantity = max(0, (int)($_POST['stock_quantity'] ?? 0));
        $imageUrl = trim($_POST['image_url'] ?? '');
        $isAvailable = isset($_POST['is_available']) ? 1 : 0;

        if (!in_array($category, $allowedCategories, true)) {
            $category = 'coffee';
        }

        if ($name === '' || $price <= 0) {
            $redirectWith(['tab' => 'products', 'status' => 'invalid_product']);
        }

        if ($action === 'add_product') {
            $stmt = $conn->prepare('INSERT INTO products (name, category, description, price, stock_quantity, image_url, is_available) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssdisi', $name, $category, $description, $price, $stockQuantity, $imageUrl, $isAvailable);
        } else {
            if ($productId <= 0) {
                $redirectWith(['tab' => 'products', 'status' => 'invalid_product']);
            }
            $stmt = $conn->prepare('UPDATE products SET name = ?, category = ?, description = ?, price = ?, stock_quantity = ?, image_url = ?, is_available = ? WHERE id = ?');
            $stmt->bind_param('sssdisii', $name, $category, $description, $price, $stockQuantity, $imageUrl, $isAvailable, $productId);
        }

        $ok = $stmt->execute();
        $stmt->close();
        $redirectWith(['tab' => 'products', 'status' => $ok ? 'product_saved' : 'product_error']);
    }

    if ($action === 'delete_product') {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            $redirectWith(['tab' => 'products', 'status' => 'product_error']);
        }

        $stmt = $conn->prepare('DELETE FROM products WHERE id = ?');
        $stmt->bind_param('i', $productId);
        $ok = $stmt->execute();
        $stmt->close();
        $redirectWith(['tab' => 'products', 'status' => $ok ? 'product_deleted' : 'product_error']);
    }

    if ($action === 'add_staff') {
        if (!$isAdmin) {
            $redirectWith(['tab' => 'staff', 'status' => 'unauthorized']);
        }

        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullname === '' || $email === '' || $username === '' || strlen($password) < 8) {
            $redirectWith(['tab' => 'staff', 'status' => 'invalid_staff']);
        }

        $checkStmt = $conn->prepare('SELECT id FROM users WHERE username = ?');
        $checkStmt->bind_param('s', $username);
        $checkStmt->execute();
        $checkStmt->store_result();

        if ($checkStmt->num_rows > 0) {
            $checkStmt->close();
            $redirectWith(['tab' => 'staff', 'status' => 'staff_exists']);
        }
        $checkStmt->close();

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $role = 'staff';

        $stmt = $conn->prepare('INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('sssss', $fullname, $email, $username, $hashed, $role);
        $ok = $stmt->execute();
        $stmt->close();

        $redirectWith(['tab' => 'staff', 'status' => $ok ? 'staff_added' : 'staff_error']);
    }

    if ($action === 'set_order_status') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $status = trim($_POST['status'] ?? 'received');

        if ($orderId <= 0 || !in_array($status, $allowedStatuses, true)) {
            $redirectWith(['tab' => 'orders', 'status' => 'order_error']);
        }

        $currentStatusStmt = $conn->prepare('SELECT status FROM orders WHERE id = ?');
        $currentStatusStmt->bind_param('i', $orderId);
        $currentStatusStmt->execute();
        $currentStatusResult = $currentStatusStmt->get_result();
        $currentStatusRow = $currentStatusResult ? $currentStatusResult->fetch_assoc() : null;
        $currentStatusStmt->close();

        if (!$currentStatusRow) {
            $redirectWith(['tab' => 'orders', 'status' => 'order_error']);
        }

        $stmt = $conn->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $stmt->bind_param('si', $status, $orderId);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && $currentStatusRow['status'] !== $status) {
            $logStmt = $conn->prepare('INSERT INTO order_status_logs (order_id, status, note) VALUES (?, ?, ?)');
            $logNote = 'Updated by ' . ($_SESSION['role'] ?? 'staff');
            $logStmt->bind_param('iss', $orderId, $status, $logNote);
            $logStmt->execute();
            $logStmt->close();
        }

        $redirectWith(['tab' => 'orders', 'status' => $ok ? 'order_updated' : 'order_error']);
    }

    if ($action === 'promote_users_to_staff') {
        if (!$isAdmin) {
            $redirectWith(['tab' => 'staff', 'status' => 'unauthorized']);
        }

        $ok = $conn->query("UPDATE users SET role = 'staff' WHERE role = 'user'");
        $redirectWith(['tab' => 'staff', 'status' => $ok ? 'users_promoted' : 'promote_error']);
    }

    if ($action === 'mark_sales_report_read') {
        if (!$isAdmin) {
            $redirectWith(['tab' => 'reports', 'status' => 'unauthorized']);
        }

        $reportId = (int)($_POST['report_id'] ?? 0);
        if ($reportId <= 0) {
            $redirectWith(['tab' => 'reports', 'status' => 'report_error']);
        }

        $stmt = $conn->prepare('UPDATE staff_sales_reports SET is_read = 1 WHERE id = ?');
        $stmt->bind_param('i', $reportId);
        $ok = $stmt->execute();
        $stmt->close();

        $redirectWith(['tab' => 'reports', 'status' => $ok ? 'report_marked_read' : 'report_error']);
    }

    if ($action === 'mark_all_sales_reports_read') {
        if (!$isAdmin) {
            $redirectWith(['tab' => 'reports', 'status' => 'unauthorized']);
        }

        $ok = $conn->query('UPDATE staff_sales_reports SET is_read = 1 WHERE is_read = 0');
        $redirectWith(['tab' => 'reports', 'status' => $ok ? 'reports_cleared' : 'report_error']);
    }
}

$tab = $_GET['tab'] ?? 'products';
$status = $_GET['status'] ?? '';
$editProductId = (int)($_GET['edit_product'] ?? 0);

$editingProduct = null;
if ($editProductId > 0) {
    $stmt = $conn->prepare('SELECT id, name, category, description, price, stock_quantity, image_url, is_available FROM products WHERE id = ?');
    $stmt->bind_param('i', $editProductId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $editingProduct = $result->fetch_assoc();
    }
    $stmt->close();
}

$products = [];
$productResult = $conn->query('SELECT id, name, category, description, price, stock_quantity, image_url, is_available, updated_at FROM products ORDER BY category ASC, name ASC');
if ($productResult) {
    while ($row = $productResult->fetch_assoc()) {
        $products[] = $row;
    }
    $productResult->close();
}

$staffUsers = [];
$adminUsers = [];
$staffResult = $conn->query("SELECT id, fullname, email, username, role FROM users ORDER BY id DESC");
if ($staffResult) {
    while ($row = $staffResult->fetch_assoc()) {
        $role = strtolower((string)($row['role'] ?? 'user'));
        if ($role === 'staff') {
            $staffUsers[] = $row;
        } elseif ($role === 'admin') {
            $adminUsers[] = $row;
        }
    }
    $staffResult->close();
}

$orderStatusFilter = trim($_GET['order_status'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$orderQuery = trim($_GET['order_q'] ?? '');

$sql = 'SELECT id, customer_name, customer_phone, order_type, payment_method, delivery_address, delivery_lat, delivery_lng, delivery_distance_km, delivery_zone, note, subtotal, tax, delivery_fee, service_fee, total, estimated_minutes, status, created_at FROM orders';
$where = [];
$params = [];
$types = '';

if ($orderStatusFilter !== '' && in_array($orderStatusFilter, $allowedStatuses, true)) {
    $where[] = 'status = ?';
    $params[] = $orderStatusFilter;
    $types .= 's';
}

if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'DATE(created_at) >= ?';
    $params[] = $dateFrom;
    $types .= 's';
}

if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'DATE(created_at) <= ?';
    $params[] = $dateTo;
    $types .= 's';
}

if ($orderQuery !== '') {
    $where[] = '(customer_name LIKE ? OR customer_phone LIKE ? OR CAST(id AS CHAR) LIKE ?)';
    $like = '%' . $orderQuery . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if (count($where) > 0) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY id DESC LIMIT 200';

$orders = [];
if (count($params) > 0) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }
    $stmt->close();
} else {
    $orderResult = $conn->query($sql);
    if ($orderResult) {
        while ($row = $orderResult->fetch_assoc()) {
            $orders[] = $row;
        }
        $orderResult->close();
    }
}

$orderItems = [];
if (count($orders) > 0) {
    $orderIds = array_map(static function ($row) {
        return (int)$row['id'];
    }, $orders);
    $ids = implode(',', $orderIds);

    $itemsResult = $conn->query("SELECT order_id, product_name, quantity FROM order_items WHERE order_id IN ($ids) ORDER BY id ASC");
    if ($itemsResult) {
        while ($line = $itemsResult->fetch_assoc()) {
            $id = (int)$line['order_id'];
            if (!isset($orderItems[$id])) {
                $orderItems[$id] = [];
            }
            $orderItems[$id][] = $line['product_name'] . ' x' . $line['quantity'];
        }
        $itemsResult->close();
    }
}

$dashboardSummary = [
    'today_orders' => 0,
    'today_revenue' => 0.0,
    'active_tasks' => 0
];
$summaryResult = $conn->query("SELECT
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_orders,
    SUM(CASE WHEN DATE(created_at) = CURDATE() AND status <> 'cancelled' THEN total ELSE 0 END) AS today_revenue,
    SUM(CASE WHEN status IN ('received', 'processing', 'out_for_delivery') THEN 1 ELSE 0 END) AS active_tasks
    FROM orders");
if ($summaryResult && $summaryResult->num_rows > 0) {
    $dashboardSummary = array_merge($dashboardSummary, $summaryResult->fetch_assoc());
    $summaryResult->close();
}

// Add staff sales reports to revenue
$staffRevenueResult = $conn->query("SELECT COALESCE(SUM(total_sales), 0) AS staff_revenue FROM staff_sales_reports WHERE report_date = CURDATE()");
if ($staffRevenueResult && $staffRevenueResult->num_rows > 0) {
    $staffRevRow = $staffRevenueResult->fetch_assoc();
    $dashboardSummary['today_revenue'] = (float)$dashboardSummary['today_revenue'] + (float)$staffRevRow['staff_revenue'];
    $staffRevenueResult->close();
}

$dailyRevenue = [];
$dailyResult = $conn->query("
SELECT DATE(created_at) AS label, SUM(total) AS amount FROM orders 
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND status <> 'cancelled' 
GROUP BY DATE(created_at)
UNION ALL
SELECT report_date AS label, SUM(total_sales) AS amount FROM staff_sales_reports 
WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) 
GROUP BY report_date
ORDER BY label ASC");
if ($dailyResult) {
    $dailyRevenueMap = [];
    while ($row = $dailyResult->fetch_assoc()) {
        $label = $row['label'];
        if (isset($dailyRevenueMap[$label])) {
            $dailyRevenueMap[$label]['amount'] += (float)$row['amount'];
        } else {
            $dailyRevenueMap[$label] = $row;
        }
    }
    $dailyRevenue = array_values($dailyRevenueMap);
    $dailyResult->close();
}

$weeklyRevenue = [];
$weeklyResult = $conn->query("
SELECT YEARWEEK(created_at, 1) AS label, SUM(total) AS amount FROM orders 
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK) AND status <> 'cancelled' 
GROUP BY YEARWEEK(created_at, 1)
UNION ALL
SELECT YEARWEEK(report_date, 1) AS label, SUM(total_sales) AS amount FROM staff_sales_reports 
WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK) 
GROUP BY YEARWEEK(report_date, 1)
ORDER BY label ASC");
if ($weeklyResult) {
    $weeklyRevenueMap = [];
    while ($row = $weeklyResult->fetch_assoc()) {
        $label = $row['label'];
        if (isset($weeklyRevenueMap[$label])) {
            $weeklyRevenueMap[$label]['amount'] += (float)$row['amount'];
        } else {
            $weeklyRevenueMap[$label] = $row;
        }
    }
    $weeklyRevenue = array_values($weeklyRevenueMap);
    $weeklyResult->close();
}

$monthlyRevenue = [];
$monthlyResult = $conn->query("
SELECT DATE_FORMAT(created_at, '%Y-%m') AS label, SUM(total) AS amount FROM orders 
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND status <> 'cancelled' 
GROUP BY DATE_FORMAT(created_at, '%Y-%m')
UNION ALL
SELECT DATE_FORMAT(report_date, '%Y-%m') AS label, SUM(total_sales) AS amount FROM staff_sales_reports 
WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) 
GROUP BY DATE_FORMAT(report_date, '%Y-%m')
ORDER BY label ASC");
if ($monthlyResult) {
    $monthlyRevenueMap = [];
    while ($row = $monthlyResult->fetch_assoc()) {
        $label = $row['label'];
        if (isset($monthlyRevenueMap[$label])) {
            $monthlyRevenueMap[$label]['amount'] += (float)$row['amount'];
        } else {
            $monthlyRevenueMap[$label] = $row;
        }
    }
    $monthlyRevenue = array_values($monthlyRevenueMap);
    $monthlyResult->close();
}

$topProducts = [];
$topProductsResult = $conn->query("SELECT oi.product_name, SUM(oi.quantity) AS total_qty, SUM(oi.line_total) AS total_sales FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE o.status <> 'cancelled' GROUP BY oi.product_name ORDER BY total_qty DESC LIMIT 10");
if ($topProductsResult) {
    while ($row = $topProductsResult->fetch_assoc()) {
        $topProducts[] = $row;
    }
    $topProductsResult->close();
}

$paymentBreakdown = [];
$paymentResult = $conn->query("SELECT payment_method, COUNT(*) AS orders_count, SUM(total) AS total_amount FROM orders WHERE status <> 'cancelled' GROUP BY payment_method ORDER BY orders_count DESC");
if ($paymentResult) {
    while ($row = $paymentResult->fetch_assoc()) {
        $paymentBreakdown[] = $row;
    }
    $paymentResult->close();
}

$reviewRatingFilter = (int)($_GET['review_rating'] ?? 0);
$reviewProductFilter = (int)($_GET['review_product'] ?? 0);
$reviewDateFrom = trim($_GET['review_from'] ?? '');
$reviewDateTo = trim($_GET['review_to'] ?? '');

$reviewProducts = [];
$reviewProductsResult = $conn->query("SELECT id, name FROM products ORDER BY name ASC");
if ($reviewProductsResult) {
    while ($p = $reviewProductsResult->fetch_assoc()) {
        $reviewProducts[] = $p;
    }
    $reviewProductsResult->close();
}

$lowStockThreshold = 5;
$thresholdResult = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'low_stock_threshold' LIMIT 1");
if ($thresholdResult && $thresholdResult->num_rows > 0) {
    $thresholdRow = $thresholdResult->fetch_assoc();
    $lowStockThreshold = max(1, (int)($thresholdRow['setting_value'] ?? 5));
    $thresholdResult->close();
}

$reviewWhere = [];
$reviewParams = [];
$reviewTypes = '';
if ($reviewRatingFilter >= 1 && $reviewRatingFilter <= 5) {
    $reviewWhere[] = 'pr.rating = ?';
    $reviewParams[] = $reviewRatingFilter;
    $reviewTypes .= 'i';
}
if ($reviewProductFilter > 0) {
    $reviewWhere[] = 'pr.product_id = ?';
    $reviewParams[] = $reviewProductFilter;
    $reviewTypes .= 'i';
}
if ($reviewDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewDateFrom)) {
    $reviewWhere[] = 'DATE(pr.created_at) >= ?';
    $reviewParams[] = $reviewDateFrom;
    $reviewTypes .= 's';
}
if ($reviewDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewDateTo)) {
    $reviewWhere[] = 'DATE(pr.created_at) <= ?';
    $reviewParams[] = $reviewDateTo;
    $reviewTypes .= 's';
}

$reviewsSql = "SELECT pr.id, pr.order_id, pr.rating, pr.review_text, pr.created_at, u.fullname, p.name AS product_name FROM product_reviews pr LEFT JOIN users u ON u.id = pr.user_id LEFT JOIN products p ON p.id = pr.product_id";
if (count($reviewWhere) > 0) {
    $reviewsSql .= ' WHERE ' . implode(' AND ', $reviewWhere);
}
$reviewsSql .= ' ORDER BY pr.id DESC LIMIT 200';

$reviews = [];
if (count($reviewParams) > 0) {
    $reviewStmt = $conn->prepare($reviewsSql);
    $reviewStmt->bind_param($reviewTypes, ...$reviewParams);
    $reviewStmt->execute();
    $reviewResultSet = $reviewStmt->get_result();
    while ($reviewResultSet && $row = $reviewResultSet->fetch_assoc()) {
        $reviews[] = $row;
    }
    $reviewStmt->close();
} else {
    $reviewResultSet = $conn->query($reviewsSql);
    if ($reviewResultSet) {
        while ($row = $reviewResultSet->fetch_assoc()) {
            $reviews[] = $row;
        }
        $reviewResultSet->close();
    }
}

$ratingBreakdown = [];
$ratingResult = $conn->query("SELECT rating, COUNT(*) AS total FROM product_reviews GROUP BY rating ORDER BY rating DESC");
if ($ratingResult) {
    while ($row = $ratingResult->fetch_assoc()) {
        $ratingBreakdown[] = $row;
    }
    $ratingResult->close();
}

$staffSalesReports = [];
$staffReportsResult = $conn->query("SELECT id, staff_name, report_date, shift_label, total_sales, total_orders, cash_on_hand, notes, is_read, created_at FROM staff_sales_reports ORDER BY is_read ASC, id DESC LIMIT 200");
if ($staffReportsResult) {
    while ($row = $staffReportsResult->fetch_assoc()) {
        $staffSalesReports[] = $row;
    }
    $staffReportsResult->close();
}

$unreadStaffReportsCount = 0;
$unreadReportsResult = $conn->query('SELECT COUNT(*) AS total FROM staff_sales_reports WHERE is_read = 0');
if ($unreadReportsResult && $unreadReportsResult->num_rows > 0) {
    $unreadRow = $unreadReportsResult->fetch_assoc();
    $unreadStaffReportsCount = (int)($unreadRow['total'] ?? 0);
    $unreadReportsResult->close();
}

$conn->close();

$statusMap = [
    'product_saved' => ['success', 'Product saved successfully.'],
    'product_deleted' => ['success', 'Product deleted successfully.'],
    'product_error' => ['danger', 'Unable to process product action.'],
    'invalid_product' => ['warning', 'Please provide valid product information.'],
    'staff_added' => ['success', 'Staff account added successfully.'],
    'staff_exists' => ['warning', 'Username already exists.'],
    'staff_error' => ['danger', 'Unable to create staff account.'],
    'invalid_staff' => ['warning', 'Please provide complete staff details and password (8+ chars).'],
    'unauthorized' => ['danger', 'You are not allowed to perform that action.'],
    'users_promoted' => ['success', 'All user accounts were converted to staff.'],
    'promote_error' => ['danger', 'Unable to convert user accounts to staff.'],
    'order_updated' => ['success', 'Order status updated.'],
    'order_error' => ['danger', 'Unable to update order status.'],
    'report_marked_read' => ['success', 'Sales report marked as read.'],
    'reports_cleared' => ['success', 'All staff sales report notifications cleared.'],
    'report_error' => ['danger', 'Unable to update staff sales report notification.'],
    'reset_success' => ['success', 'Password reset successfully. Temporary password is password123.'],
    'reset_error' => ['danger', 'Unable to reset password.']
];

$alert = $statusMap[$status] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Store Management Dashboard</title>
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#dashboardSidebar" aria-controls="dashboardSidebar">Menu</button>
                <span class="navbar-brand mb-0 h1">Store Management Dashboard</span>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <span class="text-light small">Logged in as <?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']); ?> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <a href="cafe.php" class="btn btn-outline-light btn-sm">Open Customer View</a>
                <form action="lagout.php" method="POST" style="display:inline;">
                    <button class="btn btn-light btn-sm" type="submit">Logout</button>
                </form>
            </div>
        </div>
    </nav>

    <div class="offcanvas offcanvas-start" tabindex="-1" id="dashboardSidebar" aria-labelledby="dashboardSidebarLabel">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title" id="dashboardSidebarLabel">Dashboard Menu</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body d-grid gap-2">
            <a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=products">Products</a>
            <a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=orders">Orders</a>
            <a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=staff">Staff</a>
            <a class="btn btn-outline-secondary d-flex justify-content-between align-items-center" href="admin_dashboard.php?tab=reports">
                <span>Reports</span>
                <?php if ($unreadStaffReportsCount > 0): ?><span class="badge text-bg-danger"><?php echo $unreadStaffReportsCount; ?></span><?php endif; ?>
            </a>
            <a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=reviews">Reviews</a>
            <hr>
            <a class="btn btn-outline-secondary" href="categories.php">Categories</a>
            <a class="btn btn-outline-secondary" href="inventory.php">Inventory</a>
            <a class="btn btn-outline-secondary" href="product_images.php">Product Images</a>
            <?php if ($isAdmin): ?><a class="btn btn-outline-secondary" href="settings.php">Settings</a><?php endif; ?>
            <a class="btn btn-outline-secondary" href="change_password.php">Change Password</a>
        </div>
    </div>

    <main class="container py-4">
        <?php if ($unreadStaffReportsCount > 0): ?>
            <div class="alert alert-info d-flex justify-content-between align-items-center" role="alert">
                <span><strong>Notification:</strong> <?php echo $unreadStaffReportsCount; ?> new staff sales report(s) submitted.</span>
                <a href="admin_dashboard.php?tab=reports" class="btn btn-sm btn-outline-primary">View Reports</a>
            </div>
        <?php endif; ?>

        <?php if ($alert): ?>
            <div class="alert alert-<?php echo htmlspecialchars($alert[0]); ?>" role="alert">
                <?php echo htmlspecialchars($alert[1]); ?>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link <?php echo $tab === 'products' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=products">Products</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab === 'orders' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=orders">Orders</a></li>
            <li class="nav-item"><a class="nav-link <?php echo $tab === 'staff' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=staff">Staff</a></li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'reports' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=reports">
                    Reports<?php if ($unreadStaffReportsCount > 0): ?> <span class="badge text-bg-danger"><?php echo $unreadStaffReportsCount; ?></span><?php endif; ?>
                </a>
            </li>
            <li class="nav-item"><a class="nav-link <?php echo $tab === 'reviews' ? 'active' : ''; ?>" href="admin_dashboard.php?tab=reviews">Reviews</a></li>
        </ul>

        <?php if ($tab === 'products'): ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><?php echo $editingProduct ? 'Edit Product' : 'Add Product'; ?></div>
                        <div class="card-body">
                            <form method="POST" action="admin_dashboard.php?tab=products">
                                <input type="hidden" name="action" value="<?php echo $editingProduct ? 'update_product' : 'add_product'; ?>">
                                <?php if ($editingProduct): ?>
                                    <input type="hidden" name="product_id" value="<?php echo (int)$editingProduct['id']; ?>">
                                <?php endif; ?>

                                <label class="form-label">Product Name</label>
                                <input type="text" class="form-control mb-3" name="name" required value="<?php echo htmlspecialchars($editingProduct['name'] ?? ''); ?>">

                                <label class="form-label">Category</label>
                                <select class="form-select mb-3" name="category" required>
                                    <?php foreach ($allowedCategories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo (($editingProduct['category'] ?? 'coffee') === $cat) ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucfirst($cat)); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <label class="form-label">Description</label>
                                <textarea class="form-control mb-3" name="description" rows="3"><?php echo htmlspecialchars($editingProduct['description'] ?? ''); ?></textarea>

                                <label class="form-label">Price (PHP)</label>
                                <input type="number" class="form-control mb-3" name="price" step="0.01" min="0.01" required value="<?php echo htmlspecialchars($editingProduct['price'] ?? ''); ?>">

                                <label class="form-label">Stock Quantity</label>
                                <input type="number" class="form-control mb-3" name="stock_quantity" min="0" required value="<?php echo htmlspecialchars((string)($editingProduct['stock_quantity'] ?? '20')); ?>">

                                <label class="form-label">Image URL</label>
                                <input type="url" class="form-control mb-3" name="image_url" value="<?php echo htmlspecialchars($editingProduct['image_url'] ?? ''); ?>" placeholder="https://...">

                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="is_available" id="is_available" <?php echo (!isset($editingProduct['is_available']) || (int)$editingProduct['is_available'] === 1) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_available">Available for ordering</label>
                                </div>

                                <button type="submit" class="btn btn-primary w-100"><?php echo $editingProduct ? 'Update Product' : 'Add Product'; ?></button>
                                <?php if ($editingProduct): ?>
                                    <a href="admin_dashboard.php?tab=products" class="btn btn-outline-secondary w-100 mt-2">Cancel Edit</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">Current Menu</div>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Price</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($products) === 0): ?>
                                        <tr><td colspan="6" class="text-center">No products yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($products as $product): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong><br>
                                                    <small><?php echo htmlspecialchars($product['description']); ?></small>
                                                </td>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($product['category']); ?></span></td>
                                                <td>PHP <?php echo number_format((float)$product['price'], 2); ?></td>
                                                <td>
                                                    <?php $stock = (int)($product['stock_quantity'] ?? 0); ?>
                                                    <?php if ($stock <= 0): ?>
                                                        <span class="badge bg-danger">Out</span>
                                                    <?php elseif ($stock <= $lowStockThreshold): ?>
                                                        <span class="badge bg-warning text-dark">Low (<?php echo $stock; ?>)</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success"><?php echo $stock; ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$product['is_available'] === 1 && $stock > 0): ?>
                                                        <span class="badge bg-success">Available</span>
                                                    <?php elseif ((int)$product['is_available'] === 1 && $stock <= 0): ?>
                                                        <span class="badge bg-danger">Out of Stock</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Hidden</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="d-flex gap-2">
                                                    <a class="btn btn-sm btn-warning" href="admin_dashboard.php?tab=products&edit_product=<?php echo (int)$product['id']; ?>">Edit</a>
                                                    <form method="POST" action="admin_dashboard.php?tab=products" onsubmit="return confirm('Delete this product?');">
                                                        <input type="hidden" name="action" value="delete_product">
                                                        <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                                                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($tab === 'orders'): ?>
            <?php
                $activeQueueCount = count(array_filter($orders, static function ($o) {
                    return in_array((string)($o['status'] ?? ''), ['received', 'processing', 'out_for_delivery'], true);
                }));
            ?>
            <div class="alert alert-warning d-flex justify-content-between align-items-center" role="alert">
                <span><strong>Live Queue:</strong> <?php echo $activeQueueCount; ?> active orders</span>
                <small>Received / Processing / Out for Delivery</small>
            </div>

            <div class="card mb-3">
                <div class="card-body">
                    <form class="row g-3" method="GET" action="admin_dashboard.php">
                        <input type="hidden" name="tab" value="orders">
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="order_status" class="form-select">
                                <option value="">All</option>
                                <?php foreach ($allowedStatuses as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $orderStatusFilter === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $s))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date From</label>
                            <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date To</label>
                            <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Search</label>
                            <input type="text" class="form-control" name="order_q" value="<?php echo htmlspecialchars($orderQuery); ?>" placeholder="Customer, phone, or order #">
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary" type="submit">Apply Filters</button>
                            <a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=orders">Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Incoming Orders</div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Order</th>
                                <th>Customer</th>
                                <th>Items</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Receipts</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($orders) === 0): ?>
                                <tr><td colspan="8" class="text-center">No orders found for selected filter.</td></tr>
                            <?php else: ?>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td>#<?php echo (int)$order['id']; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($order['customer_phone'] ?: '-'); ?></small><br>
                                            <small><?php echo htmlspecialchars(strtoupper((string)($order['order_type'] ?? 'pickup'))); ?> | <?php echo htmlspecialchars(strtoupper((string)($order['payment_method'] ?? 'cod'))); ?></small><br>
                                            <small><?php echo htmlspecialchars($order['delivery_address'] ?: '-'); ?></small><br>
                                            <small><?php echo number_format((float)($order['delivery_distance_km'] ?? 0), 2); ?> km | <?php echo htmlspecialchars(strtoupper((string)($order['delivery_zone'] ?? 'near'))); ?></small><br>
                                            <?php if (!empty($order['delivery_lat']) && !empty($order['delivery_lng'])): ?>
                                                <small><a href="https://www.google.com/maps?q=<?php echo urlencode((string)$order['delivery_lat'] . ',' . (string)$order['delivery_lng']); ?>" target="_blank">Open Map Pin</a></small><br>
                                            <?php endif; ?>
                                            <small><?php echo htmlspecialchars($order['note'] ?: '-'); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars(implode(', ', $orderItems[(int)$order['id']] ?? [])); ?></td>
                                        <td>PHP <?php echo number_format((float)$order['total'], 2); ?></td>
                                        <td>
                                            <form method="POST" action="admin_dashboard.php?tab=orders&order_status=<?php echo urlencode($orderStatusFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&order_q=<?php echo urlencode($orderQuery); ?>">
                                                <input type="hidden" name="action" value="set_order_status">
                                                <input type="hidden" name="order_id" value="<?php echo (int)$order['id']; ?>">
                                                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                                    <?php foreach ($allowedStatuses as $s): ?>
                                                        <option value="<?php echo $s; ?>" <?php echo $order['status'] === $s ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $s)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </td>
                                        <td><?php echo htmlspecialchars($order['created_at']); ?></td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-dark mb-1" target="_blank" href="order_receipt.php?order_id=<?php echo (int)$order['id']; ?>&copy=customer">Customer</a>
                                            <a class="btn btn-sm btn-outline-primary" target="_blank" href="order_receipt.php?order_id=<?php echo (int)$order['id']; ?>&copy=kitchen">Kitchen</a>
                                        </td>
                                        <td>
                                            <a class="btn btn-sm btn-outline-secondary" href="order_view.php?order_id=<?php echo (int)$order['id']; ?>" target="_blank">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($tab === 'staff'): ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header">Add Staff Account</div>
                        <div class="card-body">
                            <?php if (!$isAdmin): ?>
                                <p class="text-muted mb-0">Only admins can add new staff accounts.</p>
                            <?php else: ?>
                                <form method="POST" action="admin_dashboard.php?tab=staff">
                                    <input type="hidden" name="action" value="add_staff">

                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control mb-3" name="fullname" required>

                                    <label class="form-label">Email</label>
                                    <input type="email" class="form-control mb-3" name="email" required>

                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control mb-3" name="username" required>

                                    <label class="form-label">Temporary Password</label>
                                    <input type="password" class="form-control mb-3" name="password" minlength="8" required>

                                    <button type="submit" class="btn btn-primary w-100">Create Staff</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card mb-3">
                        <div class="card-header">Role Permissions Matrix</div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>Feature</th>
                                            <th>Admin</th>
                                            <th>Staff</th>
                                            <th>User</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Open management dashboard</td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-danger">No</span></td>
                                        </tr>
                                        <tr>
                                            <td>Manage products (add/edit/delete)</td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-danger">No</span></td>
                                        </tr>
                                        <tr>
                                            <td>Update order status</td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-danger">No</span></td>
                                        </tr>
                                        <tr>
                                            <td>Create staff accounts</td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-danger">No</span></td>
                                            <td><span class="badge bg-danger">No</span></td>
                                        </tr>
                                        <tr>
                                            <td>Edit roles and reset user passwords</td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-danger">No</span></td>
                                            <td><span class="badge bg-danger">No</span></td>
                                        </tr>
                                        <tr>
                                            <td>Place order from customer page</td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                        </tr>
                                        <tr>
                                            <td>Track order (public page)</td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                            <td><span class="badge bg-success">Yes</span></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">Staff Accounts</div>
                        <?php if ($isAdmin): ?>
                            <div class="card-body border-bottom py-2">
                                <form method="POST" action="admin_dashboard.php?tab=staff" onsubmit="return confirm('Convert all user-role accounts to staff?');">
                                    <input type="hidden" name="action" value="promote_users_to_staff">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Convert All Users to Staff</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($staffUsers) === 0): ?>
                                        <tr><td colspan="5" class="text-center">No staff accounts found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($staffUsers as $staff): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($staff['fullname']); ?></td>
                                                <td><?php echo htmlspecialchars($staff['username']); ?></td>
                                                <td><?php echo htmlspecialchars($staff['email']); ?></td>
                                                <td><span class="badge bg-primary"><?php echo htmlspecialchars($staff['role']); ?></span></td>
                                                <td class="d-flex gap-2">
                                                    <?php if ($isAdmin): ?>
                                                        <a href="edit_user.php?id=<?php echo (int)$staff['id']; ?>" class="btn btn-sm btn-warning">Edit Role</a>
                                                        <form method="POST" action="reset_user_password.php" onsubmit="return confirm('Reset this user password to password123?');" style="display:inline;">
                                                            <input type="hidden" name="id" value="<?php echo (int)$staff['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-secondary">Reset Password</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <small class="text-muted">-</small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card mt-3">
                        <div class="card-header">Admin Accounts</div>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($adminUsers) === 0): ?>
                                        <tr><td colspan="4" class="text-center">No admin accounts found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($adminUsers as $user): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($user['fullname']); ?></td>
                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                <td><?php echo htmlspecialchars($user['email']); ?></td>
                                                <td><span class="badge bg-dark"><?php echo htmlspecialchars($user['role']); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        <?php elseif ($tab === 'reports'): ?>
            <?php if ($unreadStaffReportsCount > 0): ?>
                <div class="card mb-3 border-info">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <strong>Staff Submissions Alert:</strong>
                            <?php echo $unreadStaffReportsCount; ?> unread sales report(s).
                        </div>
                        <form method="POST" action="admin_dashboard.php?tab=reports" onsubmit="return confirm('Mark all sales report notifications as read?');">
                            <input type="hidden" name="action" value="mark_all_sales_reports_read">
                            <button type="submit" class="btn btn-sm btn-outline-secondary">Mark All As Read</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <div class="card"><div class="card-body"><small class="text-muted">Today's Orders</small><h4 class="mb-0"><?php echo (int)($dashboardSummary['today_orders'] ?? 0); ?></h4></div></div>
                </div>
                <div class="col-md-4">
                    <div class="card"><div class="card-body"><small class="text-muted">Today's Revenue</small><h4 class="mb-0">PHP <?php echo number_format((float)($dashboardSummary['today_revenue'] ?? 0), 2); ?></h4></div></div>
                </div>
                <div class="col-md-4">
                    <div class="card"><div class="card-body"><small class="text-muted">Pending Tasks</small><h4 class="mb-0"><?php echo (int)($dashboardSummary['active_tasks'] ?? 0); ?></h4></div></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Revenue Charts</div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-lg-4"><canvas id="dailyRevenueChart" height="170"></canvas></div>
                        <div class="col-lg-4"><canvas id="weeklyRevenueChart" height="170"></canvas></div>
                        <div class="col-lg-4"><canvas id="monthlyRevenueChart" height="170"></canvas></div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">Top Products/Services</div>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead><tr><th>Product</th><th>Units Sold</th><th>Sales</th></tr></thead>
                                <tbody>
                                <?php if (count($topProducts) === 0): ?>
                                    <tr><td colspan="3" class="text-center">No sales data yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($topProducts as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$row['product_name']); ?></td>
                                            <td><?php echo (int)$row['total_qty']; ?></td>
                                            <td>PHP <?php echo number_format((float)$row['total_sales'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header">Payment Breakdown</div>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead><tr><th>Method</th><th>Orders</th><th>Total</th></tr></thead>
                                <tbody>
                                <?php if (count($paymentBreakdown) === 0): ?>
                                    <tr><td colspan="3" class="text-center">No payment data yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($paymentBreakdown as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(strtoupper((string)$row['payment_method'])); ?></td>
                                            <td><?php echo (int)$row['orders_count']; ?></td>
                                            <td>PHP <?php echo number_format((float)$row['total_amount'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">Staff Submitted Sales Reports</div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Staff</th>
                                <th>Report Date</th>
                                <th>Shift</th>
                                <th>Total Sales</th>
                                <th>Orders</th>
                                <th>Cash On Hand</th>
                                <th>Notes</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($staffSalesReports) === 0): ?>
                                <tr><td colspan="10" class="text-center">No staff sales reports submitted yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($staffSalesReports as $report): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$report['staff_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$report['report_date']); ?></td>
                                        <td><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', (string)$report['shift_label']))); ?></td>
                                        <td>PHP <?php echo number_format((float)$report['total_sales'], 2); ?></td>
                                        <td><?php echo (int)$report['total_orders']; ?></td>
                                        <td>PHP <?php echo number_format((float)$report['cash_on_hand'], 2); ?></td>
                                        <td><?php echo htmlspecialchars((string)($report['notes'] ?: '-')); ?></td>
                                        <td>
                                            <?php if ((int)$report['is_read'] === 1): ?>
                                                <span class="badge bg-secondary">Read</span>
                                            <?php else: ?>
                                                <span class="badge bg-info text-dark">New</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)$report['created_at']); ?></td>
                                        <td>
                                            <?php if ((int)$report['is_read'] === 0): ?>
                                                <form method="POST" action="admin_dashboard.php?tab=reports">
                                                    <input type="hidden" name="action" value="mark_sales_report_read">
                                                    <input type="hidden" name="report_id" value="<?php echo (int)$report['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Mark Read</button>
                                                </form>
                                            <?php else: ?>
                                                <small class="text-muted">-</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($tab === 'reviews'): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <form class="row g-3" method="GET" action="admin_dashboard.php">
                        <input type="hidden" name="tab" value="reviews">
                        <div class="col-md-3">
                            <label class="form-label">Rating</label>
                            <select name="review_rating" class="form-select">
                                <option value="0">All</option>
                                <?php for ($r = 5; $r >= 1; $r--): ?>
                                    <option value="<?php echo $r; ?>" <?php echo $reviewRatingFilter === $r ? 'selected' : ''; ?>><?php echo $r; ?> Stars</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Product</label>
                            <select name="review_product" class="form-select">
                                <option value="0">All</option>
                                <?php foreach ($reviewProducts as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>" <?php echo $reviewProductFilter === (int)$p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="review_from" class="form-control" value="<?php echo htmlspecialchars($reviewDateFrom); ?>"></div>
                        <div class="col-md-2"><label class="form-label">To</label><input type="date" name="review_to" class="form-control" value="<?php echo htmlspecialchars($reviewDateTo); ?>"></div>
                        <div class="col-md-2 d-grid align-items-end"><button class="btn btn-primary" type="submit">Filter</button></div>
                    </form>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <?php foreach ($ratingBreakdown as $rb): ?>
                    <div class="col-md-2 col-6">
                        <div class="card"><div class="card-body text-center"><div class="text-muted small"><?php echo (int)$rb['rating']; ?> Stars</div><div class="h5 mb-0"><?php echo (int)$rb['total']; ?></div></div></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <div class="card-header">Customer Reviews</div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead><tr><th>Order</th><th>Customer</th><th>Product</th><th>Rating</th><th>Review</th><th>Date</th></tr></thead>
                        <tbody>
                        <?php if (count($reviews) === 0): ?>
                            <tr><td colspan="6" class="text-center">No reviews found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reviews as $review): ?>
                                <tr>
                                    <td>#<?php echo (int)$review['order_id']; ?></td>
                                    <td><?php echo htmlspecialchars((string)($review['fullname'] ?? 'Customer')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($review['product_name'] ?? '-')); ?></td>
                                    <td><?php echo str_repeat('★', (int)$review['rating']); ?></td>
                                    <td><?php echo htmlspecialchars((string)($review['review_text'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)$review['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
    <?php if ($tab === 'reports'): ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            const dailyLabels = <?php echo json_encode(array_map(static function ($r) { return (string)$r['label']; }, $dailyRevenue)); ?>;
            const dailyValues = <?php echo json_encode(array_map(static function ($r) { return (float)$r['amount']; }, $dailyRevenue)); ?>;
            const weeklyLabels = <?php echo json_encode(array_map(static function ($r) { return (string)$r['label']; }, $weeklyRevenue)); ?>;
            const weeklyValues = <?php echo json_encode(array_map(static function ($r) { return (float)$r['amount']; }, $weeklyRevenue)); ?>;
            const monthlyLabels = <?php echo json_encode(array_map(static function ($r) { return (string)$r['label']; }, $monthlyRevenue)); ?>;
            const monthlyValues = <?php echo json_encode(array_map(static function ($r) { return (float)$r['amount']; }, $monthlyRevenue)); ?>;

            const commonOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            };

            new Chart(document.getElementById('dailyRevenueChart'), {
                type: 'line',
                data: { labels: dailyLabels, datasets: [{ label: 'Daily', data: dailyValues, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.15)', fill: true }] },
                options: commonOptions
            });
            new Chart(document.getElementById('weeklyRevenueChart'), {
                type: 'bar',
                data: { labels: weeklyLabels, datasets: [{ label: 'Weekly', data: weeklyValues, backgroundColor: '#198754' }] },
                options: commonOptions
            });
            new Chart(document.getElementById('monthlyRevenueChart'), {
                type: 'bar',
                data: { labels: monthlyLabels, datasets: [{ label: 'Monthly', data: monthlyValues, backgroundColor: '#ffc107' }] },
                options: commonOptions
            });
        </script>
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
