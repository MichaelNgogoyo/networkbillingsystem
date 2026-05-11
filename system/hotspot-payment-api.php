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
require_once(__DIR__ . '/autoload/Mikrotik.php');

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$response = [
    'status' => 'error',
    'message' => 'Invalid request',
    'timestamp' => date('Y-m-d H:i:s')
];

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
    $response = initiateM_PesaPayment($phone, $price, $plan, $duration, $deviceMac, $deviceIp, $router, $system);

} catch (Exception $e) {
    http_response_code(400);
    $response['message'] = $e->getMessage();
    $response['error_code'] = 'INVALID_REQUEST';
}

echo json_encode($response);


/**
 * Handle trial package - instant access
 * Creates actual MikroTik user and activates session
 */
function handleTrialPackage($phone, $plan, $mac, $ip, $duration, $system) {
    global $_DB;
    
    try {
        // Clean phone number
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Decode URL-encoded MAC address from MikroTik's $(mac-esc)
        $mac = urldecode($mac);
        // Remove colons to get clean MAC for short ID
        $macClean = strtolower(str_replace(':', '', $mac));
        // Generate trial username: trial_[MAC_LAST_6]_[TIMESTAMP]
        $macShort = substr($macClean, -6);
        $trialUser = 'trial_' . $macShort . '_' . time();
        $trialPass = generateSecurePassword(8);
        
        // Parse duration to seconds
        $durationSeconds = parseDurationToSeconds($duration);
        $expiryTime = time() + $durationSeconds;
        
        // Generate unique session ID
        $sessionId = bin2hex(random_bytes(16));
        
        // Store session in database for tracking
        $session = ORM::for_table('hotspot_guest_sessions')->create();
        $session->phone = $phone;
        $session->username = $trialUser;
        $session->access_type = 'trial';
        $session->mac_address = $mac;
        $session->device_ip = $ip;
        $session->plan = $plan;
        $session->duration = $duration;
        $session->session_id = $sessionId;
        $session->created_at = date('Y-m-d H:i:s');
        $session->expires_at = date('Y-m-d H:i:s', $expiryTime);
        $session->save();
        
        // Set browser cookie to track this user
        setcookie('hotspot_user', $trialUser, $expiryTime, '/', '', false, false);
        $_SESSION['hotspot_session'] = $sessionId;
        $_SESSION['hotspot_user'] = $trialUser;
        
        return [
            'status' => 'success',
            'message' => 'Trial access granted - enjoy ' . $duration . ' of free WiFi!',
            'type' => 'trial',
            'duration' => $duration,
            'username' => $trialUser,
            'password' => $trialPass,
            'expires_at' => date('Y-m-d H:i:s', $expiryTime),
            'session_id' => $sessionId,
            'next_step' => 'You are now connected! Enjoy free WiFi.',
            'dashboard_url' => '/system/hotspot-dashboard.html',
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
function initiateM_PesaPayment($phone, $amount, $plan, $duration, $mac, $ip, $router, $system) {
    
    try {
        // Decode URL-encoded MAC address from MikroTik's $(mac-esc)
        $mac = urldecode($mac);
        
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
        $macClean = strtolower(str_replace(':', '', $mac));
        $reference = 'WIFI_' . time() . '_' . substr($macClean, -4);
        $timestamp = date('YmdHis');
        $password = base64_encode($daraja['shortcode'] . $daraja['passkey'] . $timestamp);
        
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
            'CallBackURL' => 'https://network.apperstar.dev/system/daraja-callback.php',
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
