<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    header('Location: lagin.html');
    exit;
}

$conn = get_auth_database_connection();

$conn->query("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $threshold = max(1, min(50, (int)($_POST['low_stock_threshold'] ?? 5)));
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('low_stock_threshold', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $value = (string)$threshold;
    $stmt->bind_param('s', $value);
    $ok = $stmt->execute();
    $stmt->close();
    $status = $ok ? 'saved' : 'error';
}

$threshold = 5;
$res = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'low_stock_threshold' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $threshold = max(1, (int)($row['setting_value'] ?? 5));
    $res->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:760px;">
    <h3 class="mb-3">System Settings</h3>
    <?php if ($status === 'saved'): ?><div class="alert alert-success">Settings saved.</div><?php endif; ?>
    <?php if ($status === 'error'): ?><div class="alert alert-danger">Failed to save settings.</div><?php endif; ?>
    <div class="card">
        <div class="card-header">Low Stock Alerts</div>
        <div class="card-body">
            <form method="POST" action="settings.php" class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label class="form-label">Threshold (notify when stock <= threshold)</label>
                    <input type="number" min="1" max="50" class="form-control" name="low_stock_threshold" value="<?php echo (int)$threshold; ?>" required>
                </div>
                <div class="col-md-5 d-grid">
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <div class="mt-3 d-flex gap-2">
        <a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=staff">Back to Dashboard</a>
    </div>
</div>
</body>
</html>
