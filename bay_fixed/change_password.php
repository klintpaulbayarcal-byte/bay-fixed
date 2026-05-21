<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username'])) {
    header('Location: lagin.html');
    exit;
}

$status = $_GET['status'] ?? '';
$message = '';
$messageClass = 'alert-danger';

if ($status === 'success') {
    $message = 'Password changed successfully.';
    $messageClass = 'alert-success';
} elseif ($status === 'invalid_current') {
    $message = 'Current password is incorrect.';
} elseif ($status === 'mismatch') {
    $message = 'New password and confirm password do not match.';
} elseif ($status === 'same') {
    $message = 'New password must be different from current password.';
} elseif ($status === 'short') {
    $message = 'New password must be at least 8 characters.';
} elseif ($status === 'required') {
    $message = 'All fields are required.';
} elseif ($status === 'error') {
    $message = 'Unable to change password. Please try again.';
} elseif ($status === 'force') {
    $message = 'Password reset detected. Please create a new password to continue.';
    $messageClass = 'alert-warning';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        header('Location: change_password.php?status=required');
        exit;
    }

    if (strlen($newPassword) < 8) {
        header('Location: change_password.php?status=short');
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        header('Location: change_password.php?status=mismatch');
        exit;
    }

    $conn = get_auth_database_connection();

    // Ensure force-change column exists for older databases.
    $columnCheck = $conn->query("SHOW COLUMNS FROM users LIKE 'must_change_password'");
    if ($columnCheck && $columnCheck->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    }

    $userId = (int) ($_SESSION['id'] ?? 0);

    $stmt = $conn->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($hashedPassword);

    if (!$stmt->fetch()) {
        $stmt->close();
        $conn->close();
        header('Location: change_password.php?status=error');
        exit;
    }
    $stmt->close();

    if (!password_verify($currentPassword, $hashedPassword)) {
        $conn->close();
        header('Location: change_password.php?status=invalid_current');
        exit;
    }

    if (password_verify($newPassword, $hashedPassword)) {
        $conn->close();
        header('Location: change_password.php?status=same');
        exit;
    }

    $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = $conn->prepare('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?');
    $updateStmt->bind_param('si', $newHashedPassword, $userId);

    $isUpdated = $updateStmt->execute();
    $updateStmt->close();
    $conn->close();

    if ($isUpdated) {
        $_SESSION['force_password_change'] = false;
        $redirectPage = in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true) ? 'admin_dashboard.php' : 'cafe.php';
        header('Location: ' . $redirectPage . '?password=changed');
        exit;
    }

    header('Location: change_password.php?status=error');
    exit;
}

$backHref = in_array(($_SESSION['role'] ?? ''), ['admin', 'staff'], true) ? 'admin_dashboard.php' : 'cafe.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet">
    <link href="assets/css/auth.css" rel="stylesheet">
    <title>Change Password</title>
</head>
<body class="auth-page">
    <div class="password-shell row g-0">
        <div class="col-md-6 password-left d-flex flex-column justify-content-center">
            <span class="brand-chip">
                <span class="brand-dot"></span>
                BAY ACCESS
            </span>
            <h2>Secure Your Account<br>With a New Password</h2>
            <p>Use a strong password with at least 8 characters and keep your account protected at all times.</p>
            <ul class="feature-stack">
                <li>Password updates are stored with secure hashing</li>
                <li>Forced resets are completed on this page</li>
                <li>Go back to dashboard after successful update</li>
            </ul>
        </div>

        <div class="col-md-6 password-right">
            <h1 class="password-title h3">Change Password</h1>
            <p class="password-subtitle">Enter your current password and set a new one.</p>

            <?php if ($message !== ''): ?>
                <div class="alert <?php echo htmlspecialchars($messageClass); ?>" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form action="change_password.php" method="POST">
                <label for="current_password" class="form-label">Current Password</label>
                <input type="password" class="form-control mb-3" id="current_password" name="current_password" required>

                <label for="new_password" class="form-label">New Password</label>
                <input type="password" class="form-control mb-3" id="new_password" name="new_password" minlength="8" required>

                <label for="confirm_password" class="form-label">Confirm New Password</label>
                <input type="password" class="form-control mb-3" id="confirm_password" name="confirm_password" minlength="8" required>

                <button class="btn btn-primary w-100" type="submit">Update Password</button>
            </form>

            <p class="text-center password-footer">
                <a class="password-back" href="<?php echo htmlspecialchars($backHref); ?>">Back to dashboard</a>
            </p>
        </div>
    </div>
</body>
</html>
