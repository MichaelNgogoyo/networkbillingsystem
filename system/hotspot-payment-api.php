<?php
/**
 * Hotspot Payment API - M-Pesa Daraja Integration
 * 
 * Handles WiFi package payments directly from MikroTik hotspot login page
 * Flow:
 * 1. User enters phone number + selects package on hotspot login page
 * 2. Form POSTs to this endpoint (/api/hotspot-payment.php)
 * 3. API initiates M-Pesa STK Push via Daraja
 * 4. User enters M-Pesa PIN on their phone
 * 5. Payment callback creates temporary WiFi credentials
 * 6. User is redirected to internet with purchased access
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Load PHPNuxBill configuration
require_once(__DIR__ . '/../init.php');
require_once(__DIR__ . '/paymentgateway/mpesa.php');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$response = [
    'status' => 'error',
    'message' => 'Invalid request',
    'timestamp' => date('Y-m-d H:i:s')
];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'status') {
    $checkoutId = sanitize($_GET['checkout_id'] ?? '');
    if (empty($checkoutId)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'checkout_id is required'
        ]);
        exit;
    }

    $session = getHotspotPaymentStatusByCheckoutId($checkoutId);
    if (!$session) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Payment session not found'
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'checkout_id' => $checkoutId,
        'payment_status' => $session['status'],
        'username' => $session['hotspot_username'] ?? '',
        'password' => $session['hotspot_password'] ?? '',
        'failure_reason' => $session['failure_reason'] ?? '',
        'updated_at' => $session['updated_at'] ?? null
    ]);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode($response);
    exit;
}

try {
    // Get request data
    $phone = isset($_POST['phone']) ? sanitize_phone($_POST['phone']) : null;
    $packageJson = isset($_POST['package']) ? $_POST['package'] : null;
    $deviceMac = isset($_POST['device_mac']) ? sanitize($_POST['device_mac']) : null;
    $deviceIp = isset($_POST['device_ip']) ? sanitize($_POST['device_ip']) : null;
    $router = isset($_POST['router']) ? sanitize($_POST['router']) : null;
    $redirectUrl = isset($_POST['redirect_url']) ? $_POST['redirect_url'] : null;

    // Parse package data
    $packageData = null;
    if ($packageJson) {
        $packageData = json_decode($packageJson, true);
    }

    // Validate inputs
    if (!$phone || !preg_match('/^254[0-9]{9}$/', $phone)) {
        throw new Exception('Invalid phone number format. Use 254XXXXXXXXX');
    }

    if (!$packageData || !isset($packageData['plan'], $packageData['price'], $packageData['duration'])) {
        throw new Exception('Invalid package data');
    }

    $plan = sanitize($packageData['plan']);
    $price = (int)$packageData['price'];
    $duration = sanitize($packageData['duration']);
    $system = sanitize($packageData['system'] ?? 'unlimited');
    $usage = sanitize($packageData['usage'] ?? 'General');
    $mockSuccessRequested = isMockModeEnabled() && (
        (($_POST['mock_success'] ?? '0') === '1') ||
        (($_GET['mock'] ?? '0') === '1')
    );

    // ============================================
    // TRIAL PACKAGE - NO PAYMENT REQUIRED
    // ============================================
    if ($price === 0) {
        $response = handleTrialPackage($phone, $plan, $deviceMac, $deviceIp, $duration, $system);
        echo json_encode($response);
        exit;
    }

    // ============================================
    // PAID PACKAGE - DARAJA M-PESA PAYMENT
    // ============================================
    $response = initiateM_PesaPayment($phone, $price, $plan, $duration, $deviceMac, $deviceIp, $router, $system, $mockSuccessRequested);

} catch (Exception $e) {
    http_response_code(400);
    $response['message'] = $e->getMessage();
    $response['error_code'] = 'INVALID_REQUEST';
}

echo json_encode($response);


/**
 * Handle trial package - instant access
 * Trial accounts expire after specified duration
 */
function handleTrialPackage($phone, $plan, $mac, $ip, $duration, $system) {
    global $_DB;
    
    try {
        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Generate trial username: trial_[MAC_LAST_6]_[TIMESTAMP]
        $macShort = strtolower(substr(str_replace(':', '', $mac), -6));
        $trialUser = 'trial_' . $macShort . '_' . time();
        $trialPass = generateSecurePassword(8);
        
        // Parse duration to seconds
        $durationSeconds = parseDurationToSeconds($duration);
        $expiryTime = time() + $durationSeconds;
        
        // Create MikroTik user (this assumes Mikrotik.php class exists)
        // For now, we'll just return the credentials
        // TODO: Integrate with actual MikroTik API via system/autoload/Mikrotik.php
        
        $trialData = [
            'phone' => $phone,
            'username' => $trialUser,
            'password' => $trialPass,
            'mac' => $mac,
            'ip' => $ip,
            'plan' => $plan,
            'system' => $system,
            'expires_in' => $duration,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', $expiryTime)
        ];
        
        // TODO: Store in database for tracking
        
        return [
            'status' => 'success',
            'message' => 'Trial access granted - enjoy ' . $duration . ' of free WiFi!',
            'type' => 'trial',
            'duration' => $duration,
            'username' => $trialUser,
            'password' => $trialPass,
            'expires_at' => date('Y-m-d H:i:s', $expiryTime),
            'next_step' => 'Login with these credentials. Access expires in ' . $duration . '.',
            'phone_for_purchase' => $phone
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Failed to create trial access: ' . $e->getMessage(),
            'type' => 'trial'
        ];
    }
}


/**
 * Initiate M-Pesa Daraja STK Push for payment
 */
function initiateM_PesaPayment($phone, $amount, $plan, $duration, $mac, $ip, $router, $system, $forceMockSuccess = false) {
    
    try {
        // Get Daraja credentials from config/database
        $daraja = getDarajaConfig();
        
        if (!$daraja) {
            throw new Exception('M-Pesa Daraja not configured. Contact support.');
        }
        
        // Get access token
        $accessToken = mpesa_get_access_token();
        
        if (!$accessToken) {
            throw new Exception('Failed to authenticate with M-Pesa. Try again.');
        }
        
        // Generate payment reference
        $reference = 'WIFI_' . time() . '_' . strtolower(substr(str_replace(':', '', $mac), -4));
        $timestamp = date('YmdHis');
        $password = base64_encode($daraja['shortcode'] . $daraja['passkey'] . $timestamp);
        $callbackUrl = resolveCallbackUrl();

        if ($forceMockSuccess) {
            return runMockSuccessfulPayment($phone, $amount, $plan, $duration, $reference, $mac, $ip, $system, $callbackUrl);
        }
        
        // Prepare STK Push payload
        $payload = [
            'BusinessShortCode' => $daraja['shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $daraja['shortcode'],
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => $reference,
            'TransactionDesc' => 'WiFi: ' . $plan . ' (' . $duration . ')'
        ];
        
        // Send STK Push request to Daraja
        $endpoint = 'sandbox.safaricom.co.ke';
        if (!empty($daraja['env']) && $daraja['env'] === 'production') {
            $endpoint = 'api.safaricom.co.ke';
        }
        
        $response = mpesa_http_json(
            'https://' . $endpoint . '/mpesa/stkpush/v1/processrequest',
            $payload,
            ['Authorization: Bearer ' . $accessToken]
        );
        
        $result = $response['json'] ?? [];
        
        if (($result['ResponseCode'] ?? null) !== '0') {
            throw new Exception('M-Pesa request failed: ' . ($result['ResponseDescription'] ?? 'Unknown error'));
        }
        
        // Store payment session for callback processing
        storeHotspotPaymentSession($phone, $amount, $plan, $duration, $reference, $mac, $ip, $result['CheckoutRequestID'] ?? '', $system);
        
        return [
            'status' => 'success',
            'message' => 'M-Pesa prompt sent to your phone',
            'type' => 'mpesa_pending',
            'package' => $plan,
            'amount' => $amount . ' KES',
            'duration' => $duration,
            'system' => $system,
            'phone' => $phone,
            'reference' => $reference,
            'checkout_id' => $result['CheckoutRequestID'] ?? '',
            'next_step' => 'Enter your M-Pesa PIN when prompted. You will be connected automatically after payment.',
            'expires_in' => '2 minutes',
            'support' => 'Contact: info@apperstar.dev | WhatsApp: https://wa.link/0sib65'
        ];
        
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage(),
            'type' => 'mpesa_error',
            'support' => 'info@apperstar.dev'
        ];
    }
}

function isMockModeEnabled()
{
    global $config;

    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $isLocalHost = (strpos($host, 'localhost') === 0) || (strpos($host, '127.0.0.1') === 0);
    $configEnabled = (($config['hotspot_mock_mode'] ?? '0') === '1');

    return $isLocalHost || $configEnabled;
}

function resolveCallbackUrl()
{
    global $config;

    if (!empty($config['mpesa_callback_url'])) {
        return trim($config['mpesa_callback_url']);
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host . '/system/daraja-callback.php';
}

function runMockSuccessfulPayment($phone, $amount, $plan, $duration, $reference, $mac, $ip, $system, $callbackUrl)
{
    $checkoutId = 'MOCKCHK_' . date('YmdHis') . '_' . random_int(1000, 9999);

    storeHotspotPaymentSession($phone, $amount, $plan, $duration, $reference, $mac, $ip, $checkoutId, $system);

    $mockPayload = [
        'Body' => [
            'stkCallback' => [
                'MerchantRequestID' => 'mock_' . uniqid(),
                'CheckoutRequestID' => $checkoutId,
                'ResultCode' => 0,
                'ResultDesc' => 'Mock success from staging mode',
                'CallbackMetadata' => [
                    'Item' => [
                        ['Name' => 'Amount', 'Value' => $amount],
                        ['Name' => 'MpesaReceiptNumber', 'Value' => 'MOCK' . random_int(100000, 999999)],
                        ['Name' => 'TransactionDate', 'Value' => date('YmdHis')],
                        ['Name' => 'PhoneNumber', 'Value' => (int) $phone],
                    ]
                ]
            ]
        ]
    ];

    $callbackResult = dispatchMockCallback($callbackUrl, $mockPayload);
    $paymentState = getHotspotPaymentStatusByCheckoutId($checkoutId);

    if (!$paymentState || ($paymentState['status'] ?? '') !== 'completed') {
        throw new Exception('Mock callback did not complete payment session');
    }

    return [
        'status' => 'success',
        'message' => 'Mock payment completed (staging). User can be connected now.',
        'type' => 'mpesa_success',
        'package' => $plan,
        'amount' => $amount . ' KES',
        'duration' => $duration,
        'system' => $system,
        'phone' => $phone,
        'reference' => $reference,
        'checkout_id' => $checkoutId,
        'username' => $paymentState['hotspot_username'] ?? '',
        'password' => $paymentState['hotspot_password'] ?? '',
        'callback_url' => $callbackUrl,
        'callback_result' => $callbackResult
    ];
}

function dispatchMockCallback($callbackUrl, $payload)
{
    $ch = curl_init($callbackUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new Exception('Mock callback request failed: ' . $curlError);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new Exception('Mock callback returned invalid JSON');
    }

    if ($statusCode >= 400 || ((int)($decoded['ResultCode'] ?? 1) !== 0)) {
        $reason = $decoded['ResultDesc'] ?? ('HTTP ' . $statusCode);
        throw new Exception('Mock callback rejected: ' . $reason);
    }

    return $decoded;
}

function getHotspotPaymentStatusByCheckoutId($checkoutId)
{
    global $_DB;

    try {
        $stmt = $_DB->prepare('SELECT checkout_id,status,hotspot_username,hotspot_password,failure_reason,updated_at FROM hotspot_payment_sessions WHERE checkout_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $checkoutId);
        $stmt->execute();
        $result = $stmt->get_result();

        return $result ? $result->fetch_assoc() : null;
    } catch (Exception $e) {
        error_log('Failed to get payment status: ' . $e->getMessage());
        return null;
    }
}


/**
 * Get Daraja M-Pesa Configuration from database
 */
function getDarajaConfig() {
    global $_DB;
    
    try {
        // Query config table for M-Pesa settings
        $settings = [];
        $keys = ['mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_shortcode', 'mpesa_passkey', 'mpesa_env'];
        
        foreach ($keys as $key) {
            $stmt = $_DB->prepare("SELECT value FROM tbl_appconfig WHERE setting = ?");
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $settings[$key] = $row ? $row['value'] : '';
        }
        
        // Validate required settings
        if (empty($settings['mpesa_consumer_key']) || empty($settings['mpesa_consumer_secret']) ||
            empty($settings['mpesa_shortcode']) || empty($settings['mpesa_passkey'])) {
            return null;
        }
        
        return [
            'consumer_key' => $settings['mpesa_consumer_key'],
            'consumer_secret' => $settings['mpesa_consumer_secret'],
            'shortcode' => $settings['mpesa_shortcode'],
            'passkey' => $settings['mpesa_passkey'],
            'env' => $settings['mpesa_env'] ?? 'sandbox'
        ];
        
    } catch (Exception $e) {
        return null;
    }
}


/**
 * Store hotspot payment session for tracking
 */
function storeHotspotPaymentSession($phone, $amount, $plan, $duration, $reference, $mac, $ip, $checkoutId, $system) {
    global $_DB;
    
    try {
        // Create or update payment session
        $stmt = $_DB->prepare("
            INSERT INTO hotspot_payment_sessions 
            (phone, amount, plan, duration, reference, mac_address, ip_address, checkout_id, system, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ON DUPLICATE KEY UPDATE
            status = 'pending', updated_at = NOW()
        ");
        
        if ($stmt) {
            $stmt->bind_param(
                'sisssssss',
                $phone,
                $amount,
                $plan,
                $duration,
                $reference,
                $mac,
                $ip,
                $checkoutId,
                $system
            );
            $stmt->execute();
        }
    } catch (Exception $e) {
        // Log error but don't fail the request
        error_log('Failed to store payment session: ' . $e->getMessage());
    }
}


/**
 * Helper: Parse duration string to seconds
 */
function parseDurationToSeconds($duration) {
    $duration = strtoupper($duration);
    
    if (strpos($duration, 'H') !== false) {
        $hours = (int)$duration;
        return $hours * 3600;
    } elseif (strpos($duration, 'D') !== false) {
        $days = (int)$duration;
        return $days * 86400;
    } elseif (strpos($duration, 'M') !== false) {
        $months = (int)$duration;
        return $months * 30 * 86400;  // Approximate
    } elseif (strpos($duration, 'MONTH') !== false || strpos($duration, 'MONTHLY') !== false) {
        return 30 * 86400;
    }
    
    return 1 * 3600;  // Default 1 hour
}


/**
 * Helper: Sanitize input
 */
function sanitize($input) {
    return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
}


/**
 * Helper: Sanitize phone number
 */
function sanitize_phone($phone) {
    // Remove non-digits
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Convert 07XXXXXXXXX to 254XXXXXXXXX
    if (strlen($phone) === 10 && strpos($phone, '07') === 0) {
        $phone = '254' . substr($phone, 1);
    }
    
    return $phone;
}


/**
 * Helper: Generate secure password
 */
function generateSecurePassword($length = 12) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}
