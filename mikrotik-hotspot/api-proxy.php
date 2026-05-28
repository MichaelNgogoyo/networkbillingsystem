<?php
/**
 * API Proxy for Hotspot - Forwards requests to remote API
 * This allows phones connected to hotspot WiFi to access the API before internet authentication
 */

// Get the action from query string
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if (!$action) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Action required']);
    exit;
}

// Remote API endpoint
$remoteApiUrl = 'https://network.apperstar.dev/system/hotspot-simple-api.php?action=' . urlencode($action);

// Prepare request
$postData = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get POST data
    if (!empty($_POST)) {
        $postData = $_POST;
    } else {
        // Parse raw POST body
        parse_str(file_get_contents('php://input'), $postData);
    }
}

// Forward to remote API using curl
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $remoteApiUrl);
curl_setopt($ch, CURLOPT_POST, !empty($postData));
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded',
    'Accept: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Return response
http_response_code($httpCode);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($response === false) {
    echo json_encode(['status' => 'error', 'message' => 'Proxy error: ' . $error]);
} else {
    echo $response;
}
exit;
?>
