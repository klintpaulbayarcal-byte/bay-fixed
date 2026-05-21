<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: lagin.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_dashboard.php?tab=staff');
    exit;
}

$userId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($userId <= 0) {
    header('Location: admin_dashboard.php?tab=staff&status=reset_error');
    exit;
}

$conn = get_auth_database_connection();

// Ensure force-change column exists for older databases.
$columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
if ($columnCheck && $columnCheck->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
}

$defaultPassword = password_hash('password123', PASSWORD_DEFAULT);
$stmt = $conn->prepare('UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?');
$stmt->bind_param('si', $defaultPassword, $userId);

$isSuccess = $stmt->execute() && $stmt->affected_rows >= 0;

$stmt->close();
$conn->close();

if ($isSuccess && $userId === (int)($_SESSION['id'] ?? 0)) {
    $_SESSION['force_password_change'] = true;
    header('Location: change_password.php?status=force');
    exit;
}

header('Location: admin_dashboard.php?tab=staff&status=' . ($isSuccess ? 'reset_success' : 'reset_error'));
exit;
?>