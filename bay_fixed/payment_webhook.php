<?php
// Payment webhook stub: for production, verify gateway signature and payload.

header('Content-Type: application/json');

require_once __DIR__ . '/auth_bootstrap.php';

try {
    $conn = get_auth_database_connection();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
    exit;
}

$orderId = (int)($_GET['order_id'] ?? $_POST['order_id'] ?? 0);
$gateway = trim((string)($_GET['gateway'] ?? $_POST['gateway'] ?? ''));
$status = trim((string)($_GET['status'] ?? $_POST['status'] ?? 'paid'));
$ref = trim((string)($_GET['ref'] ?? $_POST['ref'] ?? ''));

if ($orderId > 0) {
    $conn->query("ALTER TABLE orders ADD COLUMN payment_status VARCHAR(30) NOT NULL DEFAULT 'unpaid'");
    $conn->query("ALTER TABLE orders ADD COLUMN payment_reference VARCHAR(80) NULL");

    $stmt = $conn->prepare('UPDATE orders SET payment_method = ?, payment_status = ?, payment_reference = ? WHERE id = ?');
    $stmt->bind_param('sssi', $gateway, $status, $ref, $orderId);
    $stmt->execute();
    $stmt->close();

    $logStmt = $conn->prepare('INSERT INTO order_status_logs (order_id, status, note) VALUES (?, ?, ?)');
    $logStatus = 'processing';
    $note = 'Payment updated via webhook: ' . $status;
    $logStmt->bind_param('iss', $orderId, $logStatus, $note);
    $logStmt->execute();
    $logStmt->close();
}

$conn->close();

echo 'Payment webhook processed';
