<?php
/**
 * Hotspot Trial Registration & Authentication API
 * Uses PHPNuxBill native tables: tbl_customers, tbl_user_recharges, tbl_hotspot_mac_trial
 * Phone + 4-digit PIN authentication via RADIUS
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
        case 'check-trial-used':
            checkMacTrialStatus();
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

/**
 * Check if phone number exists in tbl_customers
 */
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

    // Check if user exists in tbl_customers (phone as username)
    $user = ORM::for_table('tbl_customers')
        ->where('username', $phone)
        ->where('service_type', 'Hotspot')
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
        // New user - can create account to get trial
        echo json_encode([
            'status' => 'success',
            'exists' => false,
            'phone' => $phone,
            'message' => 'New user detected. Set up 4-digit PIN to get free trial!'
        ]);
    }
}

/**
 * Verify PIN against tbl_customers password
 */
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

    // Find user in tbl_customers
    $user = ORM::for_table('tbl_customers')
        ->where('username', $phone)
        ->where('service_type', 'Hotspot')
        ->find_one();

    if (!$user) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit;
    }

    if ($user->status !== 'Active') {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Account is not active']);
        exit;
    }

    // Verify PIN (stored as bcrypt in password field)
    if (!password_verify($pin, $user->password)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid PIN']);
        exit;
    }

    // Update last login
    $user->last_login = date('Y-m-d H:i:s');
    $user->save();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'PIN verified! Credentials ready for MikroTik login.',
        'customer_id' => $user->id,
        'phone' => $phone,
        'username' => $user->username,
        'password' => $user->password // PIN is stored as bcrypt, used directly for RADIUS
    ]);
}

/**
 * Create new customer in tbl_customers and assign trial plan
 */
function createNewUser() {
    $phone = $_POST['phone'] ?? null;
    $pin = $_POST['pin'] ?? null;
    $device_mac = $_POST['device_mac'] ?? null;

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

    // Check if MAC has already used trial in last 30 days
    if ($device_mac) {
        $macTrial = ORM::for_table('tbl_hotspot_mac_trial')
            ->where('mac_address', $device_mac)
            ->where_gte('expires_date', date('Y-m-d H:i:s'))
            ->find_one();

        if ($macTrial) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'This device already used the trial. Try again after ' . $macTrial->expires_date
            ]);
            exit;
        }
    }

    // Check if phone already exists
    $existingUser = ORM::for_table('tbl_customers')
        ->where('username', $phone)
        ->where('service_type', 'Hotspot')
        ->find_one();

    if ($existingUser) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'Phone number already registered'
        ]);
        exit;
    }

    // Create new customer in tbl_customers
    $customer = ORM::for_table('tbl_customers')->create();
    $customer->username = $phone;
    $customer->password = password_hash($pin, PASSWORD_BCRYPT); // PIN stored as bcrypt
    $customer->phonenumber = $phone;
    $customer->fullname = 'Trial User ' . $phone;
    $customer->email = 'trial_' . $phone . '@hotspot.local';
    $customer->service_type = 'Hotspot';
    $customer->account_type = 'Personal';
    $customer->balance = 0;
    $customer->status = 'Active';
    $customer->created_by = 1; // System admin
    $customer->created_at = date('Y-m-d H:i:s');
    $customer->save();

    // Get trial plan (id=9999, created in migration)
    $trialPlan = ORM::for_table('tbl_plans')
        ->where('id', 9999)
        ->find_one();

    if (!$trialPlan) {
        // Fallback: find first hotspot plan
        $trialPlan = ORM::for_table('tbl_plans')
            ->where('type', 'Hotspot')
            ->find_one();
    }

    if (!$trialPlan) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Trial plan not configured']);
        exit;
    }

    // Create entry in tbl_user_recharges to activate trial
    $recharge = ORM::for_table('tbl_user_recharges')->create();
    $recharge->customer_id = $customer->id;
    $recharge->username = $phone;
    $recharge->plan_id = $trialPlan->id;
    $recharge->namebp = $trialPlan->name_plan;
    $recharge->recharged_on = date('Y-m-d');
    $recharge->recharged_time = date('H:i:s');
    
    // Calculate expiration based on plan validity
    $expiryTime = strtotime('+' . $trialPlan->validity . ' ' . strtolower($trialPlan->validity_unit));
    $recharge->expiration = date('Y-m-d', $expiryTime);
    $recharge->time = date('H:i:s', $expiryTime);
    
    $recharge->status = 'on';
    $recharge->method = 'Trial';
    $recharge->routers = $trialPlan->routers;
    $recharge->type = $trialPlan->type;
    $recharge->admin_id = 1;
    $recharge->save();

    // Record MAC address if provided (30-day prevention)
    if ($device_mac) {
        $macEntry = ORM::for_table('tbl_hotspot_mac_trial')->create();
        $macEntry->mac_address = $device_mac;
        $macEntry->customer_id = $customer->id;
        $macEntry->used_date = date('Y-m-d H:i:s');
        $macEntry->expires_date = date('Y-m-d H:i:s', strtotime('+30 days'));
        $macEntry->status = 'active';
        $macEntry->save();
    }

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => '✓ Account created! You can now login to WiFi.',
        'customer_id' => $customer->id,
        'phone' => $phone,
        'username' => $customer->username,
        'password' => $pin, // Return PIN (user will enter PIN on MikroTik hotspot)
        'grant_trial' => true,
        'trial_duration' => $trialPlan->time_limit . ' ' . $trialPlan->time_unit
    ]);
}

/**
 * Check if MAC address can still use trial (not in 30-day cooldown)
 */
function checkMacTrialStatus() {
    $device_mac = $_POST['device_mac'] ?? $_GET['device_mac'] ?? null;

    if (!$device_mac) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'MAC address required']);
        exit;
    }

    // Normalize MAC address format
    $device_mac = strtoupper(str_replace('-', ':', $device_mac));

    $macTrial = ORM::for_table('tbl_hotspot_mac_trial')
        ->where('mac_address', $device_mac)
        ->find_one();

    if (!$macTrial) {
        // This MAC hasn't used trial yet
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'can_use_trial' => true,
            'message' => 'This device is eligible for trial'
        ]);
    } else if ($macTrial->status === 'expired' || strtotime($macTrial->expires_date) < time()) {
        // Trial cooldown has expired
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'can_use_trial' => true,
            'message' => 'Trial cooldown period expired. You can use trial again.'
        ]);
    } else {
        // Still in 30-day cooldown
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'can_use_trial' => false,
            'expires_date' => $macTrial->expires_date,
            'message' => 'This device already used trial. Try again after ' . $macTrial->expires_date
        ]);
    }
}

