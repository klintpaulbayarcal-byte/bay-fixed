<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

if (!isset($_SESSION['username']) || !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: lagin.html');
    exit;
}

$conn = get_auth_database_connection();

$conn->query("CREATE TABLE IF NOT EXISTS product_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
)");

$status = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int)($_POST['product_id'] ?? 0);
    if ($productId > 0 && isset($_FILES['images'])) {
        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileCount = count($_FILES['images']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if (!isset($_FILES['images']['tmp_name'][$i]) || $_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $name = basename((string)$_FILES['images']['name'][$i]);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                continue;
            }
            $newName = 'p' . $productId . '_' . time() . '_' . $i . '.' . $ext;
            $dest = $uploadDir . DIRECTORY_SEPARATOR . $newName;
            if (move_uploaded_file($_FILES['images']['tmp_name'][$i], $dest)) {
                $relativePath = 'assets/uploads/' . $newName;
                $stmt = $conn->prepare('INSERT INTO product_images (product_id, image_path, is_primary) VALUES (?, ?, 0)');
                $stmt->bind_param('is', $productId, $relativePath);
                $stmt->execute();
                $stmt->close();
            }
        }

        $primaryRes = $conn->query("SELECT image_path FROM product_images WHERE product_id = $productId ORDER BY id ASC LIMIT 1");
        if ($primaryRes && $primaryRes->num_rows > 0) {
            $primary = $primaryRes->fetch_assoc();
            $img = (string)$primary['image_path'];
            $u = $conn->prepare('UPDATE products SET image_url = ? WHERE id = ?');
            $u->bind_param('si', $img, $productId);
            $u->execute();
            $u->close();
            $primaryRes->close();
        }

        $status = 'saved';
    }
}

$products = [];
$r = $conn->query('SELECT id, name FROM products ORDER BY name ASC');
if ($r) { while ($row = $r->fetch_assoc()) { $products[] = $row; } $r->close(); }

$images = [];
$imgRes = $conn->query('SELECT pi.id, pi.product_id, p.name AS product_name, pi.image_path, pi.created_at FROM product_images pi JOIN products p ON p.id = pi.product_id ORDER BY pi.id DESC LIMIT 200');
if ($imgRes) { while ($row = $imgRes->fetch_assoc()) { $images[] = $row; } $imgRes->close(); }

$conn->close();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Product Images</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container py-4">
    <h3 class="mb-3">Product Images (Multiple Upload)</h3>
    <?php if ($status === 'saved'): ?><div class="alert alert-success">Images uploaded.</div><?php endif; ?>
    <div class="card mb-3"><div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label">Product</label><select class="form-select" name="product_id" required><?php foreach ($products as $p): ?><option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars((string)$p['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label">Images</label><input type="file" class="form-control" name="images[]" accept="image/*" multiple required></div>
            <div class="col-md-2 d-grid"><button class="btn btn-primary" type="submit">Upload</button></div>
        </form>
    </div></div>

    <div class="card"><div class="card-header">Recent Uploaded Images</div><div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Product</th><th>Preview</th><th>Path</th><th>Date</th></tr></thead><tbody>
        <?php if (count($images) === 0): ?><tr><td colspan="4" class="text-center">No images yet.</td></tr><?php else: foreach ($images as $img): ?>
            <tr>
                <td><?php echo htmlspecialchars((string)$img['product_name']); ?></td>
                <td><img src="<?php echo htmlspecialchars((string)$img['image_path']); ?>" alt="img" style="width:72px;height:52px;object-fit:cover;border-radius:6px;"></td>
                <td><small><?php echo htmlspecialchars((string)$img['image_path']); ?></small></td>
                <td><?php echo htmlspecialchars((string)$img['created_at']); ?></td>
            </tr>
        <?php endforeach; endif; ?>
    </tbody></table></div></div>

    <div class="mt-3"><a class="btn btn-outline-secondary" href="admin_dashboard.php?tab=products">Back to Dashboard</a></div>
</div></body></html>
