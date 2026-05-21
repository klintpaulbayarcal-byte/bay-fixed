<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: lagin.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: staff_panel.php');
    exit;
}

$conn = get_auth_database_connection();

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

$reportDate = trim((string)($_POST['report_date'] ?? ''));
$shiftLabel = trim((string)($_POST['shift_label'] ?? 'full_day'));
$totalSales = (float)($_POST['total_sales'] ?? 0);
$totalOrders = max(0, (int)($_POST['total_orders'] ?? 0));
$cashOnHand = (float)($_POST['cash_on_hand'] ?? 0);
$notes = trim((string)($_POST['notes'] ?? ''));

$allowedShifts = ['morning', 'afternoon', 'evening', 'full_day'];
if (!in_array($shiftLabel, $allowedShifts, true)) {
    $shiftLabel = 'full_day';
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) || $totalSales < 0 || $cashOnHand < 0) {
    $conn->close();
    header('Location: staff_panel.php?status=report_invalid');
    exit;
}

$staffId = (int)($_SESSION['id'] ?? 0);
$staffName = trim((string)($_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Staff'));
if ($staffId <= 0 || $staffName === '') {
    $conn->close();
    header('Location: staff_panel.php?status=report_error');
    exit;
}

$stmt = $conn->prepare("INSERT INTO staff_sales_reports (staff_user_id, staff_name, report_date, shift_label, total_sales, total_orders, cash_on_hand, notes, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE staff_name = VALUES(staff_name), total_sales = VALUES(total_sales), total_orders = VALUES(total_orders), cash_on_hand = VALUES(cash_on_hand), notes = VALUES(notes), is_read = 0, created_at = CURRENT_TIMESTAMP");
$stmt->bind_param('isssdids', $staffId, $staffName, $reportDate, $shiftLabel, $totalSales, $totalOrders, $cashOnHand, $notes);
$ok = $stmt->execute();
$stmt->close();

$conn->close();

header('Location: staff_panel.php?status=' . ($ok ? 'report_saved' : 'report_error'));
exit;
