<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$orderId = (int)($payload['order_id'] ?? 0);
$gateway = strtolower(trim((string)($payload['gateway'] ?? '')));
$allowed = ['gcash', 'maya', 'card'];
if ($orderId <= 0 || !in_array($gateway, $allowed, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid payment request']);
    exit;
}

$ref = strtoupper($gateway) . '-' . $orderId . '-' . substr(md5((string)microtime(true)), 0, 6);
$checkoutUrl = 'payment_webhook.php?mock=1&order_id=' . urlencode((string)$orderId) . '&gateway=' . urlencode($gateway) . '&ref=' . urlencode($ref);

echo json_encode([
    'success' => true,
    'gateway' => $gateway,
    'reference' => $ref,
    'checkout_url' => $checkoutUrl,
    'note' => 'Gateway stub ready. Replace with live provider API keys/webhooks in production.'
]);
