<?php
/**
 * Tuma callback handler for hotspot payment flow.
 *
 * Expected to receive JSON payload from Tuma with identifiers/status.
 * Supports flexible field names and uses helper parser in paymentgateway/tuma.php.
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../init.php');
require_once(__DIR__ . '/paymentgateway/tuma.php');

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid payload',
    ]);
    exit;
}

try {
    [$ok, $message] = tuma_process_hotspot_callback_payload($payload);
    if (!$ok) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => $message,
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'message' => $message,
    ]);
} catch (Throwable $e) {
    error_log('Tuma callback error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Callback processing failed',
    ]);
}
