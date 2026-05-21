<?php
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/cors_bootstrap.php';
require_once __DIR__ . '/auth_bootstrap.php';

apply_api_cors_headers();
handle_api_preflight_request();

header('Content-Type: application/json');

$requiredToken = trim((string)(getenv('HEALTH_TOKEN') ?: ''));
$provided = trim((string)($_GET['token'] ?? ''));

if ($requiredToken !== '' && $provided !== $requiredToken) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized (invalid health token)']);
    exit;
}

$checks = [];

// DB connect
try {
    $conn = get_auth_database_connection();
    $checks['db'] = ['ok' => true];

    // table presence checks
    $tables = ['users', 'products', 'orders'];
    foreach ($tables as $t) {
        $safe = $conn->real_escape_string($t);
        $res = $conn->query("SHOW TABLES LIKE '" . $safe . "'");
        $checks['tables'][$t] = ($res && $res->num_rows > 0);
        if ($res) $res->close();
    }

    // auth ping: check existence of a test user (does not authenticate)
    $testUser = trim((string)(getenv('HEALTH_TEST_USER') ?: 'jai'));
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $testUser);
        $stmt->execute();
        $r = $stmt->get_result();
        $checks['auth_ping'] = ['ok' => ($r && $r->num_rows > 0)];
        $stmt->close();
    } else {
        $checks['auth_ping'] = ['ok' => false, 'error' => 'prepare_failed'];
    }

    $conn->close();
} catch (RuntimeException $ex) {
    $checks['db'] = ['ok' => false, 'error' => $ex->getMessage()];
}

// CORS info
$checks['cors'] = ['allowed_origins' => get_allowed_cors_origins()];

$overall = ($checks['db']['ok'] ?? false) && ($checks['auth_ping']['ok'] ?? false);

http_response_code($overall ? 200 : 503);
echo json_encode(['ok' => $overall, 'checks' => $checks]);
