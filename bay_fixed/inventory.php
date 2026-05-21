<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: lagin.html');
    exit;
}

$conn = get_auth_database_connection();

$conn->query("CREATE TABLE IF NOT EXISTS inventory_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    change_qty INT NOT NULL,
    note VARCHAR(255) NULL,
    actor VARCHAR(120) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");

$status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['add_qty'] ?? 0);
    $note = trim((string)($_POST['note'] ?? 'Stock added'));

    if ($productId > 0 && $qty !== 0) {
        $update = $conn->prepare('UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ?');
        $update->bind_param('ii', $qty, $productId);
        $ok = $update->execute();
        $update->close();

        if ($ok) {
            $actor = (string)($_SESSION['username'] ?? 'staff');
            $log = $conn->prepare('INSERT INTO inventory_logs (product_id, change_qty, note, actor) VALUES (?, ?, ?, ?)');
            $log->bind_param('iiss', $productId, $qty, $note, $actor);
            $log->execute();
            $log->close();
            $status = 'saved';
        }
    }
}

$products = [];
$pRes = $conn->query('SELECT id, name, stock_quantity FROM products ORDER BY name ASC');
if ($pRes) {
    while ($row = $pRes->fetch_assoc()) { $products[] = $row; }
    $pRes->close();
}

$logs = [];
$lRes = $conn->query('SELECT il.id, il.product_id, p.name AS product_name, il.change_qty, il.note, il.actor, il.created_at FROM inventory_logs il JOIN products p ON p.id = il.product_id ORDER BY il.id DESC LIMIT 200');
if ($lRes) {
    while ($row = $lRes->fetch_assoc()) { $logs[] = $row; }
    $lRes->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Inventory</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-4">
    <h3 class="mb-3">Inventory Management</h3>
    <?php if ($status === 'saved'): ?><div class="alert alert-success">Stock updated and logged.</div><?php endif; ?>
    <div class="card mb-3"><div class="card-body">
        <form method="POST" class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label">Product</label><select class="form-select" name="product_id" required><?php foreach ($products as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['name']); ?> (<?php echo (int)$p['stock_quantity']; ?>)</option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Change Qty</label><input type="number" class="form-control" name="add_qty" required></div>
            <div class="col-md-4"><label class="form-label">Note</label><input class="form-control" name="note" placeholder="restock, correction, damage"></div>
            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Save</button></div>
        </form>
    </div></div>

    <div class="card"><div class="card-header">Stock Logs</div><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>ID</th><th>Product</th><th>Qty Change</th><th>Note</th><th>Actor</th><th>Date</th></tr></thead><tbody>
        <?php if (count($logs) === 0): ?><tr><td colspan="6" class="text-center">No logs yet.</td></tr><?php else: foreach ($logs as $log): ?>
            <tr><td>#<?php echo (int)$log['id']; ?></td><td><?php echo htmlspecialchars((string)$log['product_name']); ?></td><td><?php echo (int)$log['change_qty']; ?></td><td><?php echo htmlspecialchars((string)$log['note']); ?></td><td><?php echo htmlspecialchars((string)$log['actor']); ?></td><td><?php echo htmlspecialchars((string)$log['created_at']); ?></td></tr>
        <?php endforeach; endif; ?>
    </tbody></table></div></div>

    <div class="mt-3"><a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=products">Back to Dashboard</a></div>
</div></body></html>
