<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: lagin.html');
    exit;
}

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

$lowStockThreshold = 5;
$thresholdRes = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'low_stock_threshold' LIMIT 1");
if ($thresholdRes && $thresholdRes->num_rows > 0) {
    $thresholdRow = $thresholdRes->fetch_assoc();
    $lowStockThreshold = max(1, (int)($thresholdRow['setting_value'] ?? 5));
    $thresholdRes->close();
}

$lowStocks = [];
$lowStockResult = $conn->query("SELECT id, name, stock_quantity FROM products WHERE stock_quantity <= $lowStockThreshold ORDER BY stock_quantity ASC, name ASC LIMIT 30");
if ($lowStockResult) {
    while ($row = $lowStockResult->fetch_assoc()) {
        $lowStocks[] = $row;
    }
    $lowStockResult->close();
}

$orders = [];
$ordersResult = $conn->query("SELECT id, customer_name, customer_phone, order_type, payment_method, total, status, created_at FROM orders ORDER BY id DESC LIMIT 200");
if ($ordersResult) {
    while ($row = $ordersResult->fetch_assoc()) {
        $orders[] = $row;
    }
    $ordersResult->close();
}

$summary = [
    'pending_orders' => 0,
    'today_orders' => 0,
    'total_orders' => 0,
    'today_revenue' => 0,
    'month_revenue' => 0,
    'year_revenue' => 0,
    'in_progress' => 0,
    'pickup_count' => 0,
    'delivery_count' => 0
];

$summaryResult = $conn->query("SELECT
    SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) AS pending_orders,
    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_orders,
    COUNT(*) AS total_orders,
    SUM(CASE WHEN DATE(created_at) = CURDATE() AND status <> 'cancelled' THEN total ELSE 0 END) AS today_revenue,
    SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND status <> 'cancelled' THEN total ELSE 0 END) AS month_revenue,
    SUM(CASE WHEN YEAR(created_at) = YEAR(CURDATE()) AND status <> 'cancelled' THEN total ELSE 0 END) AS year_revenue,
    SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS in_progress,
    SUM(CASE WHEN order_type = 'pickup' THEN 1 ELSE 0 END) AS pickup_count,
    SUM(CASE WHEN order_type = 'delivery' THEN 1 ELSE 0 END) AS delivery_count
    FROM orders");
if ($summaryResult && $summaryResult->num_rows > 0) {
    $summary = array_merge($summary, $summaryResult->fetch_assoc());
    $summaryResult->close();
}

// Add staff sales reports to revenue totals
$staffRevenueResult = $conn->query("SELECT
    COALESCE(SUM(CASE WHEN report_date = CURDATE() THEN total_sales ELSE 0 END), 0) AS staff_today,
    COALESCE(SUM(CASE WHEN YEAR(report_date) = YEAR(CURDATE()) AND MONTH(report_date) = MONTH(CURDATE()) THEN total_sales ELSE 0 END), 0) AS staff_month,
    COALESCE(SUM(CASE WHEN YEAR(report_date) = YEAR(CURDATE()) THEN total_sales ELSE 0 END), 0) AS staff_year
    FROM staff_sales_reports");
if ($staffRevenueResult && $staffRevenueResult->num_rows > 0) {
    $staffRevRow = $staffRevenueResult->fetch_assoc();
    $summary['today_revenue'] = (float)$summary['today_revenue'] + (float)$staffRevRow['staff_today'];
    $summary['month_revenue'] = (float)$summary['month_revenue'] + (float)$staffRevRow['staff_month'];
    $summary['year_revenue'] = (float)$summary['year_revenue'] + (float)$staffRevRow['staff_year'];
    $staffRevenueResult->close();
}

$activeQueueCount = 0;
$activeCountResult = $conn->query("SELECT COUNT(*) AS total FROM orders WHERE status IN ('received','processing','out_for_delivery')");
if ($activeCountResult && $activeCountResult->num_rows > 0) {
    $activeCountRow = $activeCountResult->fetch_assoc();
    $activeQueueCount = (int)($activeCountRow['total'] ?? 0);
    $activeCountResult->close();
}

$status = trim((string)($_GET['status'] ?? ''));

$staffSalesReports = [];
$currentStaffId = (int)($_SESSION['id'] ?? 0);
if ($currentStaffId > 0) {
    $staffReportStmt = $conn->prepare("SELECT id, report_date, shift_label, total_sales, total_orders, cash_on_hand, notes, created_at FROM staff_sales_reports WHERE staff_user_id = ? ORDER BY id DESC LIMIT 20");
    $staffReportStmt->bind_param('i', $currentStaffId);
    $staffReportStmt->execute();
    $staffReportResult = $staffReportStmt->get_result();
    while ($staffReportResult && $row = $staffReportResult->fetch_assoc()) {
        $staffSalesReports[] = $row;
    }
    $staffReportStmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --panel-bg: #1f0f0b;
            --panel-border: #3c2016;
            --panel-card: #2b1610;
            --paper: #f6f2ed;
            --ink: #2d1f1a;
            --accent: #bc7b45;
        }

        body {
            background: linear-gradient(120deg, #f9f5f0, #f2ece3);
            color: var(--ink);
            min-height: 100vh;
        }

        .staff-shell {
            display: flex;
            min-height: 100vh;
        }

        .staff-sidebar {
            width: 265px;
            background: var(--panel-bg);
            color: #f6e8d7;
            border-right: 1px solid var(--panel-border);
            padding: 18px 14px;
            position: sticky;
            top: 0;
            max-height: 100vh;
            overflow-y: auto;
        }

        .brand-box {
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.03);
            padding: 12px;
            margin-bottom: 18px;
        }

        .brand-title {
            font-weight: 700;
            font-size: 20px;
            letter-spacing: 0.3px;
            color: #f3c793;
            margin: 0;
        }

        .section-label {
            font-size: 11px;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            opacity: 0.75;
            margin: 16px 6px 8px;
        }

        .menu-link {
            display: block;
            padding: 10px 12px;
            margin-bottom: 8px;
            border-radius: 10px;
            color: #f6e8d7;
            text-decoration: none;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid transparent;
            transition: 0.2s ease;
        }

        .menu-link:hover,
        .menu-link.active {
            color: #fff;
            border-color: var(--accent);
            background: rgba(188, 123, 69, 0.18);
        }

        .sidebar-filter {
            background: var(--panel-card);
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            color: #f8ead8;
            margin-bottom: 10px;
        }

        .sidebar-filter:focus {
            color: #fff;
            border-color: var(--accent);
            box-shadow: 0 0 0 0.2rem rgba(188, 123, 69, 0.2);
            background: #321b12;
        }

        .staff-main {
            flex: 1;
            padding: 22px;
        }

        .main-top {
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid #e9ded2;
            border-radius: 14px;
            padding: 12px 14px;
            margin-bottom: 16px;
        }

        .kpi-card {
            border: 1px solid #eadfcf;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 6px 18px rgba(63, 31, 17, 0.07);
        }

        .kpi-card .card-body {
            min-height: 106px;
        }

        .soft-card {
            border: 1px solid #eadfcf;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 6px 18px rgba(63, 31, 17, 0.05);
        }

        .soft-card .card-header {
            background: #fbf6ef;
            border-bottom: 1px solid #efe2d2;
            font-weight: 600;
        }

        @media (max-width: 991px) {
            .staff-shell {
                flex-direction: column;
            }

            .staff-sidebar {
                width: 100%;
                max-height: none;
                position: static;
            }

            .staff-main {
                padding: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="staff-shell">
        <aside class="staff-sidebar">
            <div class="brand-box">
                <p class="brand-title mb-1">Klint's Cafe</p>
                <small>Staff Operations</small>
            </div>

            <div class="section-label">Main Menu</div>
            <a class="menu-link active" href="#ordersBoard">Dashboard</a>
            <a class="menu-link" href="#salesReportForm">Submit Sales Report</a>

            <div class="section-label">Reports</div>
            <a class="menu-link report-filter-link" href="#ordersBoard" data-report-filter="today">Today's Sales</a>
            <a class="menu-link report-filter-link" href="#ordersBoard" data-report-filter="month">This Month's Sales</a>
            <a class="menu-link report-filter-link" href="#ordersBoard" data-report-filter="year">This Year's Sales</a>

            <div class="section-label">Filters</div>
            <input type="text" id="filterQuery" class="form-control sidebar-filter" placeholder="Search order/customer">
            <select id="filterStatus" class="form-select sidebar-filter">
                <option value="">All Statuses</option>
                <option value="received">Received</option>
                <option value="processing">Processing</option>
                <option value="out_for_delivery">Out for Delivery</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <select id="filterType" class="form-select sidebar-filter">
                <option value="">All Types</option>
                <option value="pickup">Pickup</option>
                <option value="delivery">Delivery</option>
            </select>
            <input type="date" id="filterDate" class="form-control sidebar-filter">
            <div class="d-grid gap-2 mt-2">
                <button id="applyFiltersBtn" class="btn btn-sm btn-warning" type="button">Apply Filters</button>
                <button id="resetFiltersBtn" class="btn btn-sm btn-outline-light" type="button">Reset</button>
            </div>
        </aside>

        <main class="staff-main">
            <div class="main-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h4 class="mb-0">Dashboard</h4>
                    <small class="text-muted">Welcome back, <?php echo htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']); ?></small>
                </div>
                <div class="d-flex gap-2">
                    <a href="change_password.php" class="btn btn-outline-secondary btn-sm">Change Password</a>
                    <form action="lagout.php" method="POST" style="display:inline;">
                        <button class="btn btn-dark btn-sm" type="submit">Logout</button>
                    </form>
                </div>
            </div>

            <?php if ($status === 'updated'): ?><div class="alert alert-success">Order status updated.</div><?php endif; ?>
            <?php if ($status === 'error'): ?><div class="alert alert-danger">Unable to update order.</div><?php endif; ?>
            <?php if ($status === 'invalid'): ?><div class="alert alert-warning">Invalid order update request.</div><?php endif; ?>
            <?php if ($status === 'report_saved'): ?><div class="alert alert-success">Sales report submitted to admin successfully.</div><?php endif; ?>
            <?php if ($status === 'report_invalid'): ?><div class="alert alert-warning">Please complete sales report fields correctly.</div><?php endif; ?>
            <?php if ($status === 'report_error'): ?><div class="alert alert-danger">Unable to submit sales report.</div><?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3 col-xxl-2"><div class="card kpi-card"><div class="card-body"><small class="text-muted">Pending Orders</small><h4 id="kpiPending" class="mb-0 text-warning"><?php echo (int)$summary['pending_orders']; ?></h4></div></div></div>
                <div class="col-6 col-lg-3 col-xxl-2"><div class="card kpi-card"><div class="card-body"><small class="text-muted">Today's Orders</small><h4 id="kpiTodayOrders" class="mb-0"><?php echo (int)$summary['today_orders']; ?></h4></div></div></div>
                <div class="col-6 col-lg-3 col-xxl-2"><div class="card kpi-card"><div class="card-body"><small class="text-muted">Total Orders</small><h4 id="kpiTotalOrders" class="mb-0"><?php echo (int)$summary['total_orders']; ?></h4></div></div></div>
                <div class="col-6 col-lg-3 col-xxl-2"><div class="card kpi-card"><div class="card-body"><small class="text-muted">Today's Revenue</small><h4 id="kpiTodayRevenue" class="mb-0 text-success">PHP <?php echo number_format((float)$summary['today_revenue'], 2); ?></h4></div></div></div>
                <div class="col-6 col-lg-3 col-xxl-2"><div class="card kpi-card"><div class="card-body"><small class="text-muted">This Month</small><h4 id="kpiMonthRevenue" class="mb-0 text-primary">PHP <?php echo number_format((float)$summary['month_revenue'], 2); ?></h4></div></div></div>
                <div class="col-6 col-lg-3 col-xxl-2"><div class="card kpi-card"><div class="card-body"><small class="text-muted">This Year</small><h4 id="kpiYearRevenue" class="mb-0 text-info">PHP <?php echo number_format((float)$summary['year_revenue'], 2); ?></h4></div></div></div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-4"><div class="card kpi-card"><div class="card-body"><small class="text-muted">In Progress</small><h4 id="kpiInProgress" class="mb-0 text-warning"><?php echo (int)$summary['in_progress']; ?></h4></div></div></div>
                <div class="col-lg-4"><div class="card kpi-card"><div class="card-body"><small class="text-muted">Pickup / Delivery</small><h4 class="mb-0"><span id="kpiPickup" class="text-primary"><?php echo (int)$summary['pickup_count']; ?></span> / <span id="kpiDelivery" class="text-danger"><?php echo (int)$summary['delivery_count']; ?></span></h4></div></div></div>
                <div class="col-lg-4"><div class="card kpi-card"><div class="card-body d-flex justify-content-between align-items-center"><div><small class="text-muted">Live Active Orders</small><h4 id="queueCount" class="mb-0"><?php echo $activeQueueCount; ?></h4></div><small class="text-success">Live updates</small></div></div></div>
            </div>

            <div class="card soft-card mb-3">
                <div class="card-header">Low Stock Alerts</div>
                <div class="card-body">
                    <?php if (count($lowStocks) > 0): ?>
                        <div class="alert alert-warning mb-0">
                            <ul class="mb-0 mt-2">
                                <?php foreach ($lowStocks as $item): ?>
                                    <li><?php echo htmlspecialchars((string)$item['name']); ?> - <?php echo (int)$item['stock_quantity']; ?> left</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="text-success">No low stock alerts right now.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card soft-card mb-3" id="ordersBoard">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>All Orders</span>
                    <small class="text-muted">Auto-refresh every 10 seconds</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0" id="ordersTable">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Contact</th>
                                <th>Total</th>
                                <th>Type</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="ordersBody">
                            <?php if (count($orders) === 0): ?>
                                <tr><td colspan="9" class="text-center">No orders found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td>#<?php echo (int)$order['id']; ?></td>
                                        <td><?php echo htmlspecialchars((string)$order['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)($order['customer_phone'] ?: '-')); ?></td>
                                        <td>PHP <?php echo number_format((float)$order['total'], 2); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst((string)$order['order_type'])); ?></td>
                                        <td><?php echo htmlspecialchars(strtoupper((string)$order['payment_method'])); ?></td>
                                        <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$order['status']))); ?></td>
                                        <td><?php echo htmlspecialchars((string)$order['created_at']); ?></td>
                                        <td><button class="btn btn-sm btn-outline-primary manage-order-btn" type="button" data-order-id="<?php echo (int)$order['id']; ?>">Manage</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-xl-6">
                    <div class="card soft-card" id="salesReportForm">
                        <div class="card-header">Submit Sales Report To Admin</div>
                        <div class="card-body">
                            <form method="POST" action="staff_submit_sales_report.php" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Report Date</label>
                                    <input type="date" name="report_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Shift</label>
                                    <select name="shift_label" class="form-select" required>
                                        <option value="morning">Morning</option>
                                        <option value="afternoon">Afternoon</option>
                                        <option value="evening">Evening</option>
                                        <option value="full_day" selected>Full Day</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Total Sales (PHP)</label>
                                    <input type="number" name="total_sales" step="0.01" min="0" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Total Orders</label>
                                    <input type="number" name="total_orders" min="0" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Cash On Hand (PHP)</label>
                                    <input type="number" name="cash_on_hand" step="0.01" min="0" class="form-control" required>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Notes</label>
                                    <input type="text" name="notes" maxlength="255" class="form-control" placeholder="Any issue, shortage, overage, or remarks.">
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-warning" type="submit">Submit Report</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-xl-6">
                    <div class="card soft-card h-100">
                        <div class="card-header">My Recent Sales Reports</div>
                        <div class="table-responsive">
                            <table class="table table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Shift</th>
                                        <th>Total Sales</th>
                                        <th>Orders</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($staffSalesReports) === 0): ?>
                                        <tr><td colspan="5" class="text-center">No submitted reports yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($staffSalesReports as $r): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars((string)$r['report_date']); ?></td>
                                                <td><?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', (string)$r['shift_label']))); ?></td>
                                                <td>PHP <?php echo number_format((float)$r['total_sales'], 2); ?></td>
                                                <td><?php echo (int)$r['total_orders']; ?></td>
                                                <td><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div class="modal fade" id="staffManageModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Manage Order <span id="manageOrderTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="manageOrderBody" class="small text-muted">Loading...</div>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="staff_order_update.php" class="d-flex gap-2 w-100 justify-content-end">
                        <input type="hidden" id="manageOrderId" name="order_id" value="0">
                        <input type="text" name="note" class="form-control form-control-sm" placeholder="Update note (optional)" style="max-width: 240px;">
                        <select name="status" id="manageStatus" class="form-select form-select-sm" style="max-width: 190px;">
                            <option value="received">Received</option>
                            <option value="processing">Processing</option>
                            <option value="out_for_delivery">Out for Delivery</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <button class="btn btn-primary btn-sm" type="submit">Save Status</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const manageModal = new bootstrap.Modal(document.getElementById('staffManageModal'));
        const ordersBody = document.getElementById('ordersBody');

        const filterQueryInput = document.getElementById('filterQuery');
        const filterStatusInput = document.getElementById('filterStatus');
        const filterTypeInput = document.getElementById('filterType');
        const filterDateInput = document.getElementById('filterDate');
        const applyFiltersBtn = document.getElementById('applyFiltersBtn');
        const resetFiltersBtn = document.getElementById('resetFiltersBtn');
        const reportFilterLinks = document.querySelectorAll('.report-filter-link');

        const appliedFilters = {
            q: '',
            status: '',
            type: '',
            date: '',
            period: ''
        };

        function prettyStatus(value) {
            return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (m) => m.toUpperCase());
        }

        function renderRows(orders) {
            if (!ordersBody) {
                return;
            }

            if (!Array.isArray(orders) || orders.length === 0) {
                ordersBody.innerHTML = '<tr><td colspan="9" class="text-center">No orders found.</td></tr>';
                return;
            }

            ordersBody.innerHTML = orders.map((order) => `
                <tr>
                    <td>#${Number(order.id || 0)}</td>
                    <td>${order.customer_name || ''}</td>
                    <td>${order.customer_phone || '-'}</td>
                    <td>PHP ${Number(order.total || 0).toFixed(2)}</td>
                    <td>${String(order.order_type || '').charAt(0).toUpperCase() + String(order.order_type || '').slice(1)}</td>
                    <td>${String(order.payment_method || '').toUpperCase()}</td>
                    <td>${prettyStatus(order.status || '')}</td>
                    <td>${order.created_at || ''}</td>
                    <td><button class="btn btn-sm btn-outline-primary manage-order-btn" type="button" data-order-id="${Number(order.id || 0)}">Manage</button></td>
                </tr>
            `).join('');
        }

        function updateSummary(summary, activeCount) {
            const setText = (id, value) => {
                const el = document.getElementById(id);
                if (el) {
                    el.textContent = value;
                }
            };

            setText('kpiPending', String(Number(summary.pending_orders || 0)));
            setText('kpiTodayOrders', String(Number(summary.today_orders || 0)));
            setText('kpiTotalOrders', String(Number(summary.total_orders || 0)));
            setText('kpiTodayRevenue', `PHP ${Number(summary.today_revenue || 0).toFixed(2)}`);
            setText('kpiMonthRevenue', `PHP ${Number(summary.month_revenue || 0).toFixed(2)}`);
            setText('kpiYearRevenue', `PHP ${Number(summary.year_revenue || 0).toFixed(2)}`);
            setText('kpiInProgress', String(Number(summary.in_progress || 0)));
            setText('kpiPickup', String(Number(summary.pickup_count || 0)));
            setText('kpiDelivery', String(Number(summary.delivery_count || 0)));
            setText('queueCount', String(Number(activeCount || 0)));
        }

        async function openManageOrder(orderId) {
            const title = document.getElementById('manageOrderTitle');
            const body = document.getElementById('manageOrderBody');
            const idInput = document.getElementById('manageOrderId');
            const statusSelect = document.getElementById('manageStatus');

            if (title) {
                title.textContent = '#' + orderId;
            }
            if (idInput) {
                idInput.value = String(orderId);
            }
            if (body) {
                body.textContent = 'Loading...';
            }
            manageModal.show();

            try {
                const response = await fetch(`order_status_api.php?mode=staff_detail&order_id=${encodeURIComponent(orderId)}`);
                const data = await response.json();
                if (!data.success || !data.order) {
                    if (body) {
                        body.textContent = 'Unable to load order details.';
                    }
                    return;
                }

                if (statusSelect) {
                    statusSelect.value = data.order.status || 'processing';
                }

                const itemsHtml = (data.items || []).map((item) => `<li>${item.product_name} x${item.quantity} - PHP ${Number(item.line_total || 0).toFixed(2)}</li>`).join('');
                const timelineHtml = (data.timeline || []).map((step) => `<li><strong>${prettyStatus(step.status)}</strong> - ${step.created_at || ''} ${step.note ? ('<span class="text-muted">(' + step.note + ')</span>') : ''}</li>`).join('');

                if (body) {
                    body.innerHTML = `
                        <div><strong>Customer:</strong> ${data.order.customer_name || ''} (${data.order.customer_phone || '-'})</div>
                        <div><strong>Type/Payment:</strong> ${String(data.order.order_type || '').toUpperCase()} / ${String(data.order.payment_method || '').toUpperCase()}</div>
                        <div><strong>Address:</strong> ${data.order.delivery_address || '-'}</div>
                        <div><strong>Distance/Zone:</strong> ${Number(data.order.delivery_distance_km || 0).toFixed(2)} km / ${String(data.order.delivery_zone || '').toUpperCase()}</div>
                        ${data.order.delivery_lat && data.order.delivery_lng ? `<div><a href="https://www.google.com/maps?q=${encodeURIComponent(String(data.order.delivery_lat) + ',' + String(data.order.delivery_lng))}" target="_blank">Open Delivery Map</a></div>` : ''}
                        <div class="mt-2"><strong>Items:</strong><ul>${itemsHtml || '<li>No items found</li>'}</ul></div>
                        <div class="mt-2"><strong>Status History:</strong><ul>${timelineHtml || '<li>No timeline yet</li>'}</ul></div>
                    `;
                }
            } catch (error) {
                if (body) {
                    body.textContent = 'Unable to load order details.';
                }
            }
        }

        async function refreshDashboard(silent = false) {
            try {
                const params = new URLSearchParams({ mode: 'staff_dashboard' });
                if (appliedFilters.q) {
                    params.set('q', appliedFilters.q);
                }
                if (appliedFilters.status) {
                    params.set('status', appliedFilters.status);
                }
                if (appliedFilters.type) {
                    params.set('type', appliedFilters.type);
                }
                if (appliedFilters.date) {
                    params.set('date', appliedFilters.date);
                }
                if (appliedFilters.period) {
                    params.set('period', appliedFilters.period);
                }

                const response = await fetch(`order_status_api.php?${params.toString()}`);
                const data = await response.json();
                if (!data.success) {
                    return;
                }

                renderRows(data.orders || []);
                updateSummary(data.summary || {}, data.active_count || 0);
            } catch (error) {
                if (!silent) {
                    console.error(error);
                }
            }
        }

        if (ordersBody) {
            ordersBody.addEventListener('click', (event) => {
                const btn = event.target.closest('.manage-order-btn');
                if (!btn) {
                    return;
                }
                const oid = Number(btn.getAttribute('data-order-id') || 0);
                if (oid > 0) {
                    openManageOrder(oid);
                }
            });
        }

        if (filterQueryInput) {
            filterQueryInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    if (applyFiltersBtn) {
                        applyFiltersBtn.click();
                    }
                }
            });
        }

        if (applyFiltersBtn) {
            applyFiltersBtn.addEventListener('click', () => {
                appliedFilters.q = (filterQueryInput ? filterQueryInput.value.trim() : '');
                appliedFilters.status = (filterStatusInput ? filterStatusInput.value : '');
                appliedFilters.type = (filterTypeInput ? filterTypeInput.value : '');
                appliedFilters.date = (filterDateInput ? filterDateInput.value : '');
                appliedFilters.period = '';
                refreshDashboard();
            });
        }

        if (resetFiltersBtn) {
            resetFiltersBtn.addEventListener('click', () => {
                if (filterQueryInput) {
                    filterQueryInput.value = '';
                }
                if (filterStatusInput) {
                    filterStatusInput.value = '';
                }
                if (filterTypeInput) {
                    filterTypeInput.value = '';
                }
                if (filterDateInput) {
                    filterDateInput.value = '';
                }

                appliedFilters.q = '';
                appliedFilters.status = '';
                appliedFilters.type = '';
                appliedFilters.date = '';
                appliedFilters.period = '';
                refreshDashboard();
            });
        }

        if (reportFilterLinks.length > 0) {
            reportFilterLinks.forEach((link) => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    const mode = String(link.getAttribute('data-report-filter') || '').toLowerCase();
                    const now = new Date();

                    if (filterQueryInput) {
                        filterQueryInput.value = '';
                    }
                    if (filterStatusInput) {
                        filterStatusInput.value = 'completed';
                    }
                    if (filterTypeInput) {
                        filterTypeInput.value = '';
                    }

                    if (mode === 'today') {
                        const yyyy = now.getFullYear();
                        const mm = String(now.getMonth() + 1).padStart(2, '0');
                        const dd = String(now.getDate()).padStart(2, '0');
                        const today = `${yyyy}-${mm}-${dd}`;
                        if (filterDateInput) {
                            filterDateInput.value = today;
                        }
                        appliedFilters.date = today;
                        appliedFilters.period = '';
                    } else if (mode === 'month') {
                        if (filterDateInput) {
                            filterDateInput.value = '';
                        }
                        appliedFilters.date = '';
                        appliedFilters.period = 'month';
                    } else if (mode === 'year') {
                        if (filterDateInput) {
                            filterDateInput.value = '';
                        }
                        appliedFilters.date = '';
                        appliedFilters.period = 'year';
                    } else {
                        if (filterDateInput) {
                            filterDateInput.value = '';
                        }
                        appliedFilters.date = '';
                        appliedFilters.period = '';
                    }

                    appliedFilters.q = '';
                    appliedFilters.status = 'completed';
                    appliedFilters.type = '';
                    refreshDashboard();

                    const target = document.getElementById('ordersBoard');
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
        }

        setInterval(() => refreshDashboard(true), 10000);
    </script>
</body>
</html>
