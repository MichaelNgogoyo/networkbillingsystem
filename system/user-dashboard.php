<?php
/**
 * User Dashboard - Shows connected users their WiFi status and options
 */

header('Content-Type: application/json');
require_once(__DIR__ . '/../init.php');

// For now, we'll use session-based approach
// In real implementation, would use JWT or similar for hotspot-only auth

session_start();

$response = [
    'status' => 'error',
    'message' => 'Unauthorized',
    'timestamp' => date('Y-m-d H:i:s')
];

// Check if user has active hotspot session cookie/session
$username = isset($_COOKIE['hotspot_user']) ? sanitize($_COOKIE['hotspot_user']) : null;
// Try to get session_id from either SESSION or COOKIE
$sessionId = isset($_SESSION['hotspot_session']) ? $_SESSION['hotspot_session'] : (isset($_COOKIE['hotspot_session']) ? sanitize($_COOKIE['hotspot_session']) : null);

// Verify session is valid
if (!$username || !$sessionId) {
    echo json_encode($response);
    exit;
}

$action = isset($_GET['action']) ? sanitize($_GET['action']) : 'status';

switch($action) {
    case 'status':
        // Get user's remaining time
        $session = ORM::for_table('hotspot_guest_sessions')
            ->where('username', $username)
            ->where('session_id', $sessionId)
            ->find_one();
        
        if (!$session) {
            // Try looking up without session_id if it's not found (fallback)
            $session = ORM::for_table('hotspot_guest_sessions')
                ->where('username', $username)
                ->order_by_desc('created_at')
                ->find_one();
        }
        
        if ($session && strtotime($session['expires_at']) > time()) {
            $response = [
                'status' => 'success',
                'is_logged_in' => true,
                'username' => $username,
                'type' => isset($session['access_type']) ? $session['access_type'] : 'trial',
                'expires_at' => $session['expires_at'],
                'remaining_seconds' => max(0, strtotime($session['expires_at']) - time()),
                'created_at' => $session['created_at']
            ];
        } else {
            $response['message'] = 'Session expired';
        }
        break;

    case 'packages':
        // List available packages for purchase
        $packages = ORM::for_table('tbl_bandwidth_usageperson')->find_many();
        $response = [
            'status' => 'success',
            'packages' => $packages
        ];
        break;

    case 'logout':
        // Logout and end session
        setcookie('hotspot_user', '', time() - 3600, '/');
        unset($_SESSION['hotspot_session']);
        $response = [
            'status' => 'success',
            'message' => 'Logged out successfully'
        ];
        break;

    default:
        $response['message'] = 'Unknown action';
}

echo json_encode($response);
