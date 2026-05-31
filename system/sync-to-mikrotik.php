<?php
/**
 * Sync Hotspot Users from Database to MikroTik Router
 * This script should run periodically (via cron) to create users on the MikroTik router
 * Based on entries in the hotspot_guest_sessions table
 */

// Initialize application
set_include_path(dirname(__FILE__) . '/..:' . get_include_path());
require_once __DIR__ . '/../init.php';

$MIKROTIK_HOST = '192.168.88.1';
$MIKROTIK_USER = 'admin';
$MIKROTIK_PASS = '1234';

// Get all unsynced guest sessions (created in last 5 minutes)
$recentSessions = ORM::for_table('hotspot_guest_sessions')
    ->where_raw('created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)')
    ->where('status', 'active')
    ->find_array();

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($recentSessions) . " recent guest sessions to sync to MikroTik\n";

$successCount = 0;
$failCount = 0;

foreach ($recentSessions as $session) {
    $username = $session['username'];
    $profile = $session['plan']; // This is the plan name from guest_sessions
    
    // Map plan names to MikroTik profiles
    $profileMap = [
        'Free Trial' => 'TEST-10M',
        'trial' => 'TEST-10M',
        '1h' => '1H',
        '1 Hour' => '1H',
        '4h' => '4H',
        '4 Hours' => '4H',
        '8h' => '8H',
        '8 Hours' => '8H',
        '12h' => '12H',
        '12 Hours' => '12H',
        'Daily' => 'Daily',
        '1 Day' => 'Daily',
        'Weekly' => 'Weekly',
        '1 Week' => 'Weekly',
        'Monthly' => 'Monthly',
        '1 Month' => 'Monthly'
    ];
    
    $profile = $profileMap[$profile] ?? $profile;
    
    // Get the password from our database
    $password = $session['password'];
    
    // SSH command to create user on MikroTik
    $sshCommand = sprintf(
        '/ip hotspot user add name=%s password=%s profile=%s disabled=no',
        escapeshellarg($username),
        escapeshellarg($password),
        escapeshellarg($profile)
    );
    
    echo "\n[" . date('Y-m-d H:i:s') . "] Creating user: $username (profile: $profile)\n";
    echo "  SSH Command: ssh admin@$MIKROTIK_HOST '$sshCommand'\n";
    
    // Execute SSH command with timeout
    $output = shell_exec(sprintf(
        'timeout 10 sshpass -p %s ssh -o ConnectTimeout=5 -o StrictHostKeyChecking=no %s@%s %s 2>&1',
        escapeshellarg($MIKROTIK_PASS),
        escapeshellarg($MIKROTIK_USER),
        escapeshellarg($MIKROTIK_HOST),
        escapeshellarg($sshCommand)
    ));
    
    // Check if successful
    if (empty($output) || (strpos($output, 'error') === false && strpos($output, 'syntax') === false)) {
        echo "  ✓ Success! User created on MikroTik\n";
        $successCount++;
    } else {
        echo "  ✗ Failed! Output: " . trim($output) . "\n";
        $failCount++;
    }
}

echo "\n[" . date('Y-m-d H:i:s') . "] Sync complete. Success: $successCount, Failed: $failCount\n";
?>
