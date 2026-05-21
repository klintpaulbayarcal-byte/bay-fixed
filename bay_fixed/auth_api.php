<?php
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/cors_bootstrap.php';
apply_api_cors_headers();
handle_api_preflight_request();
start_app_session();
header('Content-Type: application/json');

require_once __DIR__ . '/auth_bootstrap.php';

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = strtolower(trim((string)($payload['action'] ?? $_GET['action'] ?? 'login')));
$username = trim((string)($payload['username'] ?? ''));
$password = trim((string)($payload['password'] ?? ''));

$conn = null;

try {
    $conn = get_auth_database_connection();
    ensure_default_login_accounts($conn);
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
    exit;
}

if ($action === 'register') {
    $fullname = trim((string)($payload['fullname'] ?? ''));
    $email = trim((string)($payload['email'] ?? ''));
    $confirmPassword = trim((string)($payload['confirmPassword'] ?? ''));

    if ($fullname === '' || $email === '' || $username === '' || $password === '' || $confirmPassword === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'All fields are required']);
        $conn->close();
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
        $conn->close();
        exit;
    }

    if ($password !== $confirmPassword) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        $conn->close();
        exit;
    }

    $checkStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $checkStmt->bind_param('s', $username);
    $checkStmt->execute();
    $checkStmt->store_result();

    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        $conn->close();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit;
    }
    $checkStmt->close();

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $role = 'user';

    $stmt = $conn->prepare('INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sssss', $fullname, $email, $username, $hashedPassword, $role);

    if (!$stmt->execute()) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Registration failed']);
        $stmt->close();
        $conn->close();
        exit;
    }

    $stmt->close();
    $conn->close();

    echo json_encode([
        'success' => true,
        'message' => 'Registration successful',
        'redirect_to' => 'lagin.html'
    ]);
    exit;
}

if ($username === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Username and password are required']);
    $conn->close();
    exit;
}

$stmt = $conn->prepare('SELECT id, fullname, password, role, must_change_password FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$user || !password_verify($password, (string)$user['password'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    $conn->close();
    exit;
}

$_SESSION['username'] = $username;
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['role'] = $user['role'];
$_SESSION['id'] = (int)$user['id'];
$_SESSION['force_password_change'] = ((int)($user['must_change_password'] ?? 0) === 1);

$redirect = 'cafe.php';
if ($user['role'] === 'admin') {
    $redirect = 'admin_dashboard.php';
} elseif ($user['role'] === 'staff') {
    $redirect = 'staff_panel.php';
}

$conn->close();

echo json_encode([
    'success' => true,
    'message' => 'Login successful',
    'role' => $user['role'],
    'fullname' => $user['fullname'],
    'redirect_to' => $redirect,
    'force_password_change' => $_SESSION['force_password_change']
]);
