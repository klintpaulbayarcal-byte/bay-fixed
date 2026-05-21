<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: lagin.html");
    exit;
}

$id = intval($_POST['id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$role = trim($_POST['role'] ?? '');

// Validate inputs
if ($id <= 0 || empty($username) || empty($role)) {
    header("Location: admin_dashboard.php?update=error");
    exit;
}

if (!in_array($role, ['user', 'admin'], true)) {
    header("Location: admin_dashboard.php?update=error");
    exit;
}

// Prevent admin from removing their own admin role in the same active session.
if ($id === (int)($_SESSION['id'] ?? 0) && $role !== 'admin') {
    header("Location: admin_dashboard.php?update=selfdemote");
    exit;
}

$conn = get_auth_database_connection();

// Update username and role
$stmt = $conn->prepare("UPDATE users SET username = ?, role = ? WHERE id = ?");
$stmt->bind_param("ssi", $username, $role, $id);

if ($stmt->execute()) {
    header("Location: admin_dashboard.php?update=success");
    exit;
} else {
    header("Location: admin_dashboard.php?update=error");
    exit;
}

$stmt->close();
$conn->close();
?>
