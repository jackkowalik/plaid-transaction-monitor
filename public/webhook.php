<?php
declare(strict_types=1);

// Point Plaid's webhook URL for your items here. The event is verified and
// queued, and the response is sent right away; bin/worker.php processes it.

require __DIR__ . '/../bin/bootstrap.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method not allowed']);
    exit;
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = (string) $value;
    }
}

$rawBody = (string) file_get_contents('php://input');

try {
    $response = bootstrap()->receiver()->handle($rawBody, $headers);
} catch (\Throwable $e) {
    error_log('plaid-transaction-monitor webhook error: ' . $e->getMessage());
    $response = ['status' => 500, 'body' => ['error' => 'internal error']];
}

http_response_code($response['status']);
echo json_encode($response['body']);
