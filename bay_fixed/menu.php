<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();

// ensure user is logged in
if (!isset($_SESSION['username'])) {
    header('Location: lagin.html');
    exit;
}

// sanitize stored values for output
$fullname = htmlspecialchars($_SESSION['fullname'] ?? $_SESSION['username']);
$role = htmlspecialchars($_SESSION['role'] ?? 'user');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>User Menu</title>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand mb-0 h1">User Dashboard</span>
            <div class="d-flex gap-2">
                <form action="lagout.php" method="POST" style="display: inline;">
                    <button class="btn btn-light" type="submit">Logout</button>
                </form>
            </div>
        </div>
    </nav>
    
    <div class="container mt-5">
        <div class="alert alert-info" role="alert">
            <h4 class="alert-heading">Welcome!</h4>
            <p id="welcomeMessage">Welcome, <?php echo $fullname; ?>!</p>
        </div>
        
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">User Information</h5>
                <p id="userInfo">You are logged in as: <?php echo $role; ?></p>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
