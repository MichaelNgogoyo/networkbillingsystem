<?php
/**
 * Sync Hotspot Users from Database to MikroTik Router
 * This script should run periodically (via cron) to create users on the MikroTik router
 * Based on entries in the hotspot_guest_sessions table
 */

// Manually setup database connection without going through init.php
$baseDir = dirname(__FILE__) . '/..';
chdir($baseDir);

// CLI Mode - set up fake web environment to allow config.php to load
if (!isset($_SERVER['HTTPS'])) {
    $_SERVER['HTTPS'] = 'off';
}
if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
if (!isset($_SERVER['SCRIPT_NAME'])) {
    $_SERVER['SCRIPT_NAME'] = '/system/sync-to-mikrotik.php';
}
if (!isset($_SERVER['SERVER_PORT'])) {
    $_SERVER['SERVER_PORT'] = 80;
}

// Load database config - try multiple locations
$configFile = './config.php';
if (!file_exists($configFile)) {
    $configFile = './.runtime/config.php';
}
if (!file_exists($configFile)) {
    die("Error: config.php not found in " . getcwd() . "\n");
}
require_once $configFile;

// Load ORM
if (!file_exists('./system/orm.php')) {
    die("Error: orm.php not found\n");
}
require_once './system/orm.php';

// Setup database connection manually
ORM::configure("mysql:host={$db_host};dbname={$db_name}");
ORM::configure('username', $db_user);
ORM::configure('password', $db_pass);

// CLI mode: if host is 'mysql' (Docker hostname), try to get the container IP
if ($db_host === 'mysql' || $db_host === 'localhost') {
    // Try to get MySQL container IP from Docker
    $dockerOutput = shell_exec('docker inspect -f \'{{range.NetworkSettings.Networks}}{{.IPAddress}}{{end}}\' mysql 2>/dev/null');
    if (!empty($dockerOutput)) {
        $mysqlIp = trim($dockerOutput);
        if (!empty($mysqlIp)) {
            ORM::configure("mysql:host={$mysqlIp};dbname={$db_name}");
        }
    }
}

// Test connection
try {
    $testQuery = ORM::for_table('hotspot_guest_sessions')->limit(1)->find_one();
    // Connection successful
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage() . "\n");
}

// Now run the sync logic

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
    
    // Get the password from our database, or generate one if it doesn't exist
    $password = $session['password'];
    if (empty($password) || is_null($password)) {
        // Generate a random 12-character password if one doesn't exist
        $password = bin2hex(random_bytes(6));
        echo "[" . date('Y-m-d H:i:s') . "] Generated password for $username: $password\n";
        
        // Save the generated password back to the database for reference using ORM
        try {
            $guestSession = ORM::for_table('hotspot_guest_sessions')
                ->where('username', $username)
                ->find_one();
            if ($guestSession) {
                $guestSession->password = $password;
                $guestSession->save();
            }
        } catch (Exception $e) {
            // If we can't save it, that's ok - we'll still use it for MikroTik
            echo "[" . date('Y-m-d H:i:s') . "] Warning: Could not save password to database for $username\n";
        }
    }
    
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
