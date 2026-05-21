<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

require_once __DIR__ . '/auth_bootstrap.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
    header("Location: lagin.html");
    exit;
}

$userId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($userId === 0) {
    echo "Invalid user ID";
    exit;
}

$conn = get_auth_database_connection();

// Fetch user data needed for admin edit form
$stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "User not found";
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Edit User</title>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">Edit User</span>
            <a href="admin_dashboard.php" class="btn btn-light">Back to Dashboard</a>
        </div>
    </nav>
    
    <div class="container mt-5">
        <div class="card" style="max-width: 400px; margin: auto;">
            <div class="card-body">
                <h5 class="card-title">Edit Username and Role</h5>
                <form action="update_user.php" method="POST">
                    <input type="hidden" name="id" value="<?php echo htmlspecialchars($user['id']); ?>">
                    
                    <label for="username" class="form-label">Username</label>
                    <input type="text" class="form-control mb-3" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                    
                    <label for="role" class="form-label">Role</label>
                    <select class="form-select mb-3" id="role" name="role" required>
                        <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                    
                    <button class="btn btn-primary w-100" type="submit">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
$conn->close();
?>
