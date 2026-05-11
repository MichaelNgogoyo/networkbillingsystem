<?php
/**
 * User Authentication - Registration, Login, Password Management
 */

header('Content-Type: application/json');
require_once(__DIR__ . '/../init.php');

$response = [
    'status' => 'error',
    'message' => 'Invalid request',
    'timestamp' => date('Y-m-d H:i:s')
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode($response);
    exit;
}

$action = isset($_POST['action']) ? sanitize($_POST['action']) : null;

switch($action) {
    case 'register':
        handleRegister();
        break;
    case 'login':
        handleLogin();
        break;
    case 'change_password':
        handleChangePassword();
        break;
    default:
        http_response_code(400);
        echo json_encode($response);
}

function handleRegister() {
    global $response;
    
    $phone = isset($_POST['phone']) ? sanitize_phone($_POST['phone']) : null;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : null;
    $password = isset($_POST['password']) ? $_POST['password'] : null;
    $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : null;

    if (!$phone || !preg_match('/^254[0-9]{9}$/', $phone)) {
        $response['message'] = 'Invalid phone number. Use 254XXXXXXXXX';
        echo json_encode($response);
        return;
    }

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Invalid email address';
        echo json_encode($response);
        return;
    }

    if (strlen($password) < 6) {
        $response['message'] = 'Password must be at least 6 characters';
        echo json_encode($response);
        return;
    }

    if ($password !== $password_confirm) {
        $response['message'] = 'Passwords do not match';
        echo json_encode($response);
        return;
    }

    // Check if user already exists
    $existing = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();
    
    if ($existing) {
        $response['message'] = 'Phone number already registered';
        echo json_encode($response);
        return;
    }

    try {
        $user = ORM::for_table('hotspot_users')->create();
        $user->phone = $phone;
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_BCRYPT);
        $user->created_at = date('Y-m-d H:i:s');
        $user->save();

        $response = [
            'status' => 'success',
            'message' => 'Registration successful. Please login with your phone number.',
            'user_id' => $user->id,
            'phone' => $phone
        ];
    } catch (Exception $e) {
        $response['message'] = 'Registration failed: ' . $e->getMessage();
    }

    echo json_encode($response);
}

function handleLogin() {
    global $response;
    
    $phone = isset($_POST['phone']) ? sanitize_phone($_POST['phone']) : null;
    $password = isset($_POST['password']) ? $_POST['password'] : null;

    if (!$phone || !$password) {
        $response['message'] = 'Phone and password required';
        echo json_encode($response);
        return;
    }

    $user = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();
    
    if (!$user || !password_verify($password, $user->password)) {
        $response['message'] = 'Invalid credentials';
        http_response_code(401);
        echo json_encode($response);
        return;
    }

    // Create session
    $sessionId = bin2hex(random_bytes(16));
    $session = ORM::for_table('hotspot_user_sessions')->create();
    $session->user_id = $user->id;
    $session->session_id = $sessionId;
    $session->phone = $phone;
    $session->created_at = date('Y-m-d H:i:s');
    $session->expires_at = date('Y-m-d H:i:s', time() + 86400); // 24 hours
    $session->save();

    $response = [
        'status' => 'success',
        'message' => 'Login successful',
        'user' => [
            'id' => $user->id,
            'phone' => $user->phone,
            'email' => $user->email
        ],
        'session_id' => $sessionId,
        'expires_at' => $session->expires_at
    ];

    echo json_encode($response);
}

function handleChangePassword() {
    global $response;
    
    $phone = isset($_POST['phone']) ? sanitize_phone($_POST['phone']) : null;
    $old_password = isset($_POST['old_password']) ? $_POST['old_password'] : null;
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : null;
    $new_password_confirm = isset($_POST['new_password_confirm']) ? $_POST['new_password_confirm'] : null;

    if (!$phone || !$old_password) {
        $response['message'] = 'Phone and current password required';
        echo json_encode($response);
        return;
    }

    $user = ORM::for_table('hotspot_users')
        ->where('phone', $phone)
        ->find_one();
    
    if (!$user || !password_verify($old_password, $user->password)) {
        $response['message'] = 'Invalid credentials';
        http_response_code(401);
        echo json_encode($response);
        return;
    }

    if (strlen($new_password) < 6) {
        $response['message'] = 'New password must be at least 6 characters';
        echo json_encode($response);
        return;
    }

    if ($new_password !== $new_password_confirm) {
        $response['message'] = 'New passwords do not match';
        echo json_encode($response);
        return;
    }

    try {
        $user->password = password_hash($new_password, PASSWORD_BCRYPT);
        $user->save();

        $response = [
            'status' => 'success',
            'message' => 'Password changed successfully'
        ];
    } catch (Exception $e) {
        $response['message'] = 'Failed to change password: ' . $e->getMessage();
    }

    echo json_encode($response);
}

function sanitize_phone($phone) {
    return preg_replace('/[^0-9]/', '', $phone);
}

function sanitize_email($email) {
    return strtolower(trim($email));
}
