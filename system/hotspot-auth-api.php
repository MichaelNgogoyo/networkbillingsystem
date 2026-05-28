<?php
/**
 * Hotspot Authentication API
 * Handles user sign up, sign in, and account management
 */

require_once __DIR__ . '/boot.php';

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
        case 'signup':
            handleSignUp();
            break;
        case 'signin':
            handleSignIn();
            break;
        case 'check-phone':
            checkPhoneExists();
            break;
        case 'check-email':
            checkEmailExists();
            break;
        case 'get-user':
            getUser();
            break;
        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('[hotspot-auth] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}

function handleSignUp() {
    $phone = $_POST['phone'] ?? null;
    $email = $_POST['email'] ?? null;
    $firstName = $_POST['first_name'] ?? null;
    $lastName = $_POST['last_name'] ?? null;
    $password = $_POST['password'] ?? null;
    $confirmPassword = $_POST['confirm_password'] ?? null;

    // Validation
    if (!$phone || !$email || !$firstName || !$password) {
        http_response_code(400);
        return echo json_encode([
            'status' => 'error',
            'message' => 'All fields are required'
        ]);
    }

    // Validate phone format (Kenyan: 254XXXXXXXXX)
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        http_response_code(400);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Invalid phone format. Use 254XXXXXXXXX'
        ]);
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Invalid email format'
        ]);
    }

    // Validate password match
    if ($password !== $confirmPassword) {
        http_response_code(400);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Passwords do not match'
        ]);
    }

    // Validate password strength (min 6 chars)
    if (strlen($password) < 6) {
        http_response_code(400);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Password must be at least 6 characters'
        ]);
    }

    // Check if phone already exists
    $existingPhone = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();
    
    if ($existingPhone) {
        http_response_code(409);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Phone number already registered'
        ]);
    }

    // Check if email already exists
    $existingEmail = ORM::for_table('hotspot_users')
        ->where('email', $email)
        ->find_one();
    
    if ($existingEmail) {
        http_response_code(409);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Email already registered'
        ]);
    }

    // Create new user
    $user = ORM::for_table('hotspot_users')->create();
    $user->phone = $phone;
    $user->email = $email;
    $user->first_name = $firstName;
    $user->last_name = $lastName;
    $user->password = password_hash($password, PASSWORD_BCRYPT);
    $user->status = 'active';
    $user->save();

    // Create session
    $sessionId = bin2hex(random_bytes(32));
    $session = ORM::for_table('hotspot_user_sessions')->create();
    $session->user_id = $user->id;
    $session->phone = $phone;
    $session->session_id = $sessionId;
    $session->ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $session->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $session->expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
    $session->save();

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Account created successfully',
        'user' => [
            'id' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name
        ],
        'session_id' => $sessionId
    ]);
}

function handleSignIn() {
    $phone = $_POST['phone'] ?? null;
    $password = $_POST['password'] ?? null;

    if (!$phone || !$password) {
        http_response_code(400);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Phone and password required'
        ]);
    }

    // Validate phone format
    if (!preg_match('/^254[0-9]{9}$/', $phone)) {
        http_response_code(400);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Invalid phone format'
        ]);
    }

    // Find user
    $user = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();

    if (!$user) {
        http_response_code(401);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Invalid phone or password'
        ]);
    }

    // Check if account is active
    if ($user->status !== 'active') {
        http_response_code(403);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Account is ' . $user->status
        ]);
    }

    // Verify password
    if (!password_verify($password, $user->password)) {
        http_response_code(401);
        return echo json_encode([
            'status' => 'error',
            'message' => 'Invalid phone or password'
        ]);
    }

    // Create session
    $sessionId = bin2hex(random_bytes(32));
    $session = ORM::for_table('hotspot_user_sessions')->create();
    $session->user_id = $user->id;
    $session->phone = $phone;
    $session->session_id = $sessionId;
    $session->ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $session->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $session->expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
    $session->save();

    // Update last login
    $user->last_login = date('Y-m-d H:i:s');
    $user->save();

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Signed in successfully',
        'user' => [
            'id' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name
        ],
        'session_id' => $sessionId
    ]);
}

function checkPhoneExists() {
    $phone = $_GET['phone'] ?? null;

    if (!$phone) {
        http_response_code(400);
        return echo json_encode(['status' => 'error', 'message' => 'Phone required']);
    }

    $exists = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();

    echo json_encode([
        'exists' => (bool)$exists,
        'phone' => $phone
    ]);
}

function checkEmailExists() {
    $email = $_GET['email'] ?? null;

    if (!$email) {
        http_response_code(400);
        return echo json_encode(['status' => 'error', 'message' => 'Email required']);
    }

    $exists = ORM::for_table('hotspot_users')
        ->where('email', $email)
        ->find_one();

    echo json_encode([
        'exists' => (bool)$exists,
        'email' => $email
    ]);
}

function getUser() {
    $sessionId = $_GET['session_id'] ?? $_POST['session_id'] ?? null;

    if (!$sessionId) {
        http_response_code(400);
        return echo json_encode(['status' => 'error', 'message' => 'Session ID required']);
    }

    $session = ORM::for_table('hotspot_user_sessions')
        ->where('session_id', $sessionId)
        ->find_one();

    if (!$session || strtotime($session->expires_at) < time()) {
        http_response_code(401);
        return echo json_encode(['status' => 'error', 'message' => 'Session expired']);
    }

    $user = ORM::for_table('hotspot_users')
        ->where('id', $session->user_id)
        ->find_one();

    if (!$user) {
        http_response_code(404);
        return echo json_encode(['status' => 'error', 'message' => 'User not found']);
    }

    echo json_encode([
        'status' => 'success',
        'user' => [
            'id' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name
        ]
    ]);
}
?>
