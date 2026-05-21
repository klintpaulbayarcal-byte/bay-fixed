<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: lagin.html');
    exit;
}

$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
$conn = get_auth_database_connection();

$conn->query("CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL
)");

$status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        $parentId = (int)($_POST['parent_id'] ?? 0);
        if ($name !== '') {
            if ($parentId > 0) {
                $stmt = $conn->prepare('INSERT INTO product_categories (parent_id, name) VALUES (?, ?)');
                $stmt->bind_param('is', $parentId, $name);
            } else {
                $stmt = $conn->prepare('INSERT INTO product_categories (parent_id, name) VALUES (NULL, ?)');
                $stmt->bind_param('s', $name);
            }
            $stmt->execute();
            $stmt->close();
            $status = 'saved';
        }
    }
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $conn->query("UPDATE product_categories SET is_active = IF(is_active=1,0,1) WHERE id = $id");
            $status = 'saved';
        }
    }
}

$categories = [];
$result = $conn->query('SELECT id, parent_id, name, is_active FROM product_categories ORDER BY parent_id ASC, name ASC');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
    $result->close();
}

$conn->close();

$byParent = [];
foreach ($categories as $cat) {
    $pid = $cat['parent_id'] === null ? 0 : (int)$cat['parent_id'];
    if (!isset($byParent[$pid])) { $byParent[$pid] = []; }
    $byParent[$pid][] = $cat;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h3 class="mb-3">Categories (Hierarchy)</h3>
    <?php if ($status === 'saved'): ?><div class="alert alert-success">Category updated.</div><?php endif; ?>

    <?php if ($isAdmin): ?>
        <div class="card mb-3"><div class="card-body">
            <form method="POST" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="add">
                <div class="col-md-5"><label class="form-label">Category Name</label><input class="form-control" name="name" required></div>
                <div class="col-md-5"><label class="form-label">Parent Category (optional)</label>
                    <select class="form-select" name="parent_id"><option value="0">No Parent</option>
                        <?php foreach ($categories as $cat): ?><option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars((string)$cat['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Add</button></div>
            </form>
        </div></div>
    <?php endif; ?>

    <div class="card"><div class="card-header">Category Tree</div><div class="table-responsive">
        <table class="table table-striped mb-0"><thead><tr><th>Name</th><th>Parent</th><th>Status</th><th>Action</th></tr></thead><tbody>
            <?php if (count($categories) === 0): ?>
                <tr><td colspan="4" class="text-center">No categories yet.</td></tr>
            <?php else: ?>
                <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)$cat['name']); ?></td>
                        <td>
                            <?php
                                $parentName = '-';
                                if (!empty($cat['parent_id'])) {
                                    foreach ($categories as $p) {
                                        if ((int)$p['id'] === (int)$cat['parent_id']) { $parentName = (string)$p['name']; break; }
                                    }
                                }
                                echo htmlspecialchars($parentName);
                            ?>
                        </td>
                        <td><?php echo ((int)$cat['is_active'] === 1) ? 'Active' : 'Hidden'; ?></td>
                        <td>
                            <?php if ($isAdmin): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">Toggle</button>
                                </form>
                            <?php else: ?>-
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody></table>
    </div></div>

    <div class="mt-3"><a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=products">Back to Dashboard</a></div>
</div>
</body>
</html>
