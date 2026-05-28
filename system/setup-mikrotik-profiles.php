<?php
/**
 * MikroTik Hotspot Profile Setup Script
 * 
 * Creates all required hotspot profiles on MikroTik router
 * Run this once to ensure all profiles exist for trial and paid users
 */

header('Content-Type: application/json');

// Load PHPNuxBill configuration
require_once(__DIR__ . '/../init.php');
require_once(__DIR__ . '/autoload/Mikrotik.php');

$response = [
    'status' => 'error',
    'message' => 'Setup failed',
    'profiles' => [],
    'errors' => []
];

try {
    error_log('Starting MikroTik profile setup...');
    
    // Use hardcoded router values (known setup)
    $routerIp = '192.168.88.1';
    $routerPort = 8728;
    $routerUser = 'admin';
    $routerPass = '1234';
    
    error_log('Connecting to MikroTik at ' . $routerIp . ':' . $routerPort);
    $client = Mikrotik::getClient($routerIp . ':' . $routerPort, $routerUser, $routerPass);
    
    if (!$client) {
        throw new Exception('Failed to connect to MikroTik');
    }
    
    error_log('Connected to MikroTik successfully');
    
    // Define required profiles
    $requiredProfiles = [
        [
            'name' => 'trial',
            'display_name' => 'Trial - 10 Minutes Free',
            'shared_users' => 0,
            'rate_limit' => '0/0'
        ],
        [
            'name' => 'paid',
            'display_name' => 'Paid Package Access',
            'shared_users' => 0,
            'rate_limit' => '0/0'
        ]
    ];
    
    // Create/verify each profile
    foreach ($requiredProfiles as $profileConfig) {
        $profileName = $profileConfig['name'];
        error_log('Setting up profile: ' . $profileName);
        
        try {
            Mikrotik::setHotspotPlan(
                $client,
                $profileName,
                $profileConfig['shared_users'],
                $profileConfig['rate_limit']
            );
            
            $response['profiles'][] = [
                'name' => $profileName,
                'status' => 'success',
                'message' => 'Profile created or verified'
            ];
            
            error_log('Profile ' . $profileName . ' is ready');
        } catch (Exception $e) {
            $errorMsg = 'Failed to setup profile ' . $profileName . ': ' . $e->getMessage();
            error_log('ERROR: ' . $errorMsg);
            $response['errors'][] = $errorMsg;
        }
    }
    
    // Verify hotspot is enabled
    error_log('Verifying hotspot configuration...');
    try {
        $printRequest = new \RouterOS\Request('/ip/hotspot/print');
        $result = $client->sendSync($printRequest);
        
        if ($result->getType() === 'done') {
            error_log('Hotspot service is configured');
            $response['hotspot_status'] = 'enabled';
        }
    } catch (Exception $e) {
        error_log('Warning: Could not verify hotspot: ' . $e->getMessage());
    }
    
    // Success response
    if (count($response['errors']) === 0) {
        $response['status'] = 'success';
        $response['message'] = 'All profiles created successfully!';
        error_log('MikroTik profile setup completed successfully');
    } else {
        $response['status'] = 'partial';
        $response['message'] = count($response['profiles']) . ' profile(s) created with ' . count($response['errors']) . ' error(s)';
        error_log('MikroTik profile setup completed with errors');
    }
    
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['message'] = 'Setup failed: ' . $e->getMessage();
    $response['error_details'] = $e->getTraceAsString();
    error_log('FATAL ERROR in profile setup: ' . $e->getMessage());
}

http_response_code($response['status'] === 'error' ? 500 : 200);
echo json_encode($response, JSON_PRETTY_PRINT);
?>
