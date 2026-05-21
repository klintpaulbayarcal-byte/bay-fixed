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

$stockColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'stock_quantity'");
if ($stockColumn && $stockColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN stock_quantity INT NOT NULL DEFAULT 20 AFTER price");
}

$categoryColumn = $conn->query("SHOW COLUMNS FROM products LIKE 'category'");
if ($categoryColumn && $categoryColumn->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'coffee' AFTER name");
}

$products = [];
$result = $conn->query("SELECT id, name, category, description, price, image_url, stock_quantity FROM products WHERE is_available = 1 ORDER BY category ASC, name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    $result->close();
}

$conn->close();

echo json_encode([
    'success' => true,
    'products' => $products,
    'count' => count($products)
]);
