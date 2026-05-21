<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: lagin.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff_panel.php');
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$status = trim((string)($_POST['status'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));
$allowed = ['received', 'processing', 'out_for_delivery', 'completed', 'cancelled'];

if ($orderId <= 0 || !in_array($status, $allowed, true)) {
    header('Location: staff_panel.php?status=invalid');
    exit;
}

$conn = get_auth_database_connection();

$stmt = $conn->prepare('UPDATE orders SET status = ? WHERE id = ?');
$stmt->bind_param('si', $status, $orderId);
$ok = $stmt->execute();
$stmt->close();

if ($ok) {
    $logStmt = $conn->prepare('INSERT INTO order_status_logs (order_id, status, note) VALUES (?, ?, ?)');
    $logNote = $note !== '' ? $note : ('Updated by ' . ($_SESSION['role'] ?? 'staff'));
    $logStmt->bind_param('iss', $orderId, $status, $logNote);
    $logStmt->execute();
    $logStmt->close();
}

$conn->close();
header('Location: staff_panel.php?status=' . ($ok ? 'updated' : 'error'));
exit;
