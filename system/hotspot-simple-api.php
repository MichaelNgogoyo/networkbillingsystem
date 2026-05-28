<?php
/**
 * Simplified Hotspot Login API
 * Phone + 4-digit PIN authentication
 * Integrates directly with MikroTik profiles
 */

// Initialize application
set_include_path(dirname(__FILE__) . '/..:' . get_include_path());
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(['status' => 'ok']));
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;

try {
    switch ($action) {
        case 'check-phone':
            checkPhone();
            break;
        case 'verify-pin':
            verifyPin();
            break;
        case 'create-user':
            createNewUser();
            break;
        case 'select-package':
            selectPackage();
            break;
        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('[hotspot-login] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

function checkPhone() {
    $phone = $_POST['phone'] ?? null;

    if (!$phone) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Phone required']);
        exit;
    }

    // Validate phone format
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid phone format. Use 254XXXXXXXXX'
        ]);
        exit;
    }

    // Check if user exists
    $user = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();

    if ($user) {
        // User exists - needs PIN
        echo json_encode([
            'status' => 'success',
            'exists' => true,
            'phone' => $phone,
            'message' => 'Enter your 4-digit PIN'
        ]);
    } else {
        // New user - can create account or try trial
        echo json_encode([
            'status' => 'success',
            'exists' => false,
            'phone' => $phone,
            'message' => 'New user detected. Set up 4-digit PIN to get free trial!'
        ]);
    }
}

function verifyPin() {
    $phone = $_POST['phone'] ?? null;
    $pin = $_POST['pin'] ?? null;

    if (!$phone || !$pin) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Phone and PIN required']);
        exit;
    }

    // Validate phone format
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid phone format']);
        exit;
    }

    // Validate PIN format (4 digits)
    if (!preg_match('/^[0-9]{4}$/', $pin)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'PIN must be 4 digits']);
        exit;
    }

    // Find user
    $user = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'User not found'
        ]);
        exit;
    }

    // Verify PIN
    if (!password_verify($pin, $user->pin)) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid PIN'
        ]);
        exit;
    }

    // Update last login
    $user->last_login = date('Y-m-d H:i:s');
    $user->save();

    // Create session
    $sessionId = bin2hex(random_bytes(32));
    $session = ORM::for_table('hotspot_user_sessions')->create();
    $session->user_id = $user->id;
    $session->phone = $phone;
    $session->session_id = $sessionId;
    $session->ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $session->expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $session->save();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'PIN verified! Select a package.',
        'user_id' => $user->id,
        'phone' => $phone,
        'session_id' => $sessionId,
        'trial_used' => (bool)$user->trial_used
    ]);
}

function createNewUser() {
    $phone = $_POST['phone'] ?? null;
    $pin = $_POST['pin'] ?? null;

    if (!$phone || !$pin) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Phone and PIN required']);
        exit;
    }

    // Validate phone format
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid phone format. Use 254XXXXXXXXX'
        ]);
        exit;
    }

    // Validate PIN format (4 digits)
    if (!preg_match('/^[0-9]{4}$/', $pin)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'PIN must be exactly 4 digits'
        ]);
        exit;
    }

    // Check if phone already exists
    $existingUser = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();

    if ($existingUser) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'Phone number already registered'
        ]);
        exit;
    }

    // Create new user
    $user = ORM::for_table('hotspot_users')->create();
    $user->phone = $phone;
    $user->pin = password_hash($pin, PASSWORD_BCRYPT);
    $user->email = 'phone_' . $phone . '@hotspot.local'; // Default email for backward compat
    $user->password = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT); // Unused, for backward compat
    $user->trial_used = 0;
    $user->status = 'active';
    $user->created_at = date('Y-m-d H:i:s');
    $user->save();

    // Create session
    $sessionId = bin2hex(random_bytes(32));
    $session = ORM::for_table('hotspot_user_sessions')->create();
    $session->user_id = $user->id;
    $session->phone = $phone;
    $session->session_id = $sessionId;
    $session->ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $session->expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $session->save();

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => '✓ Account created! Get your free 10-minute trial now.',
        'user_id' => $user->id,
        'phone' => $phone,
        'session_id' => $sessionId,
        'grant_trial' => true
    ]);
}

function selectPackage() {
    $phone = $_POST['phone'] ?? null;
    $packageName = $_POST['package'] ?? null;
    $sessionId = $_POST['session_id'] ?? null;

    if (!$phone || !$packageName || !$sessionId) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
        exit;
    }

    // Verify session exists
    $session = ORM::for_table('hotspot_user_sessions')
        ->where('session_id', $sessionId)
        ->find_one();

    if (!$session || strtotime($session->expires_at) < time()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Session expired']);
        exit;
    }

    // Map package name to MikroTik profile
    $profileMap = [
        'trial' => 'TEST-10M',
        'freetrial' => 'TEST-10M',
        '1hour' => '1H',
        '1h' => '1H',
        '4hours' => '4H',
        '4h' => '4H',
        '8hours' => '8H',
        '8h' => '8H',
        '12hours' => '12H',
        '12h' => '12H',
        'daily' => 'Daily',
        '1day' => 'Daily',
        'weekly' => 'Weekly',
        '1week' => 'Weekly',
        'monthly' => 'Monthly',
        '1month' => 'Monthly'
    ];

    $packageKey = strtolower(str_replace([' ', 'hours', 'hour', 'days', 'day', 'weeks', 'week', 'months', 'month'], '', $packageName));
    if (!isset($profileMap[$packageKey])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid package: ' . $packageKey]);
        exit;
    }

    $mikrotikProfile = $profileMap[$packageKey];
    
    // Check if this is trial
    $isTrial = in_array($packageKey, ['trial', 'freetrial']);
    $user = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit;
    }

    if ($isTrial) {
        // Check if trial already used
        if ($user->trial_used) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Trial already used! Please select a paid package or upgrade.'
            ]);
            exit;
        }

        // Mark trial as used
        $user->trial_used = 1;
        $user->save();
    }

    // Create MikroTik username (phone-based)
    $shortPhone = substr($phone, -9); // Last 9 digits
    $username = strtolower($packageKey) . '_' . $shortPhone . '_' . time();
    $password = bin2hex(random_bytes(6)); // Random 12-char password

    // Create user in MikroTik via API (with timeout)
    $mikrotikSuccess = false;
    try {
        require_once __DIR__ . '/autoload/Mikrotik.php';
        
        // Set a 5-second timeout for connection attempts
        $originalTimeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', 5);
        
        $client = @Mikrotik::getClient('192.168.88.1', 'admin', '1234');
        
        if ($client) {
            // Create hotspot user with profile
            Mikrotik::addHotspotUser($client, $mikrotikProfile, [
                'name' => $username,
                'password' => $password,
                'profile' => $mikrotikProfile
            ]);

            // Server-side login
            Mikrotik::logMeIn($client, $username, $password, $_POST['device_ip'] ?? '0.0.0.0', $_POST['device_mac'] ?? '00:00:00:00:00:00');
            
            $client->disconnect();
            $mikrotikSuccess = true;
        }
        
        ini_set('default_socket_timeout', $originalTimeout);
    } catch (Exception $e) {
        ini_set('default_socket_timeout', $originalTimeout ?? ini_get('default_socket_timeout'));
        error_log('[hotspot-login] MikroTik error: ' . $e->getMessage());
    }

    // Record session
    $guestSession = ORM::for_table('hotspot_guest_sessions')->create();
    $guestSession->phone = $phone;
    $guestSession->username = $username;
    $guestSession->access_type = $isTrial ? 'trial' : 'paid';
    $guestSession->mac_address = $_POST['device_mac'] ?? '';
    $guestSession->device_ip = $_POST['device_ip'] ?? '';
    $guestSession->plan = $packageName;
    $guestSession->session_id = $sessionId;
    $guestSession->status = 'active';
    $guestSession->save();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => '✓ Connected! Enjoy your ' . $packageName . '!',
        'username' => $username,
        'password' => $password,
        'profile' => $mikrotikProfile,
        'is_trial' => $isTrial,
        'mikrotik_ready' => $mikrotikSuccess
    ]);
    exit;
}
?>
