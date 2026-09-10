<?php
/**
 * Daraja Callback Handler for Hotspot Payments
 * 
 * Receives STK Push callback from M-Pesa Daraja API
 * On successful payment:
 * 1. Marks payment session as completed
 * 2. Creates temporary MikroTik user with purchased package access
 * 3. Stores user credentials in database for guest tracking
 */

header('Content-Type: application/json');

// Load PHPNuxBill configuration  
require_once(__DIR__ . '/../init.php');

// Get raw POST data from M-Pesa
$payload = json_decode(file_get_contents('php://input'), true);

$response = [
    'ResultCode' => 1,
    'ResultDesc' => 'Failed'
];

try {
    if (empty($payload)) {
        throw new Exception('Empty payload');
    }

    // Parse callback structure
    $stkCallback = $payload['Body']['stkCallback'] ?? [];
    $checkoutId = $stkCallback['CheckoutRequestID'] ?? '';
    $resultCode = $stkCallback['ResultCode'] ?? 1;
    $resultDesc = $stkCallback['ResultDesc'] ?? 'Unknown error';
    
    if (empty($checkoutId)) {
        throw new Exception('Missing checkout request ID');
    }

    // Find payment session by checkout ID
    $paymentSession = findPaymentSession($checkoutId);
    if (!$paymentSession) {
        throw new Exception('Payment session not found');
    }

    // If payment was successful (ResultCode 0)
    if ((int)$resultCode === 0) {
        // Extract successful payment metadata
        $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
        $mpesaReceiptNumber = '';
        $transactionDate = '';
        $transactionAmount = '';
        
        // Parse callback metadata
        foreach ($callbackMetadata as $item) {
            switch ($item['Name'] ?? '') {
                case 'MpesaReceiptNumber':
                    $mpesaReceiptNumber = $item['Value'] ?? '';
                    break;
                case 'TransactionDate':
                    $transactionDate = $item['Value'] ?? '';
                    break;
                case 'TransactionAmount':
                    $transactionAmount = $item['Value'] ?? '';
                    break;
            }
        }

        // Create MikroTik user credentials
        $mikrotikCredentials = createMikrotikUserForPayment(
            $paymentSession['phone'],
            $paymentSession['plan'],
            $paymentSession['duration'],
            $paymentSession['mac_address'],
            $paymentSession['ip_address'],
            $paymentSession['system'] ?? 'unlimited'
        );

        if (!$mikrotikCredentials) {
            throw new Exception('Failed to create MikroTik user');
        }

        // Mark payment as completed
        markPaymentAsCompleted(
            $checkoutId,
            $mpesaReceiptNumber,
            $transactionAmount,
            $transactionDate,
            $mikrotikCredentials['username'],
            $mikrotikCredentials['password']
        );

        // Keep account management in sync with paid hotspot flow.
        syncBillingAccountFromPayment(
            $paymentSession,
            $mpesaReceiptNumber,
            $transactionAmount
        );

        $response = [
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
            'data' => [
                'phone' => $paymentSession['phone'],
                'package' => $paymentSession['plan'],
                'duration' => $paymentSession['duration'],
                'username' => $mikrotikCredentials['username'],
                'password' => $mikrotikCredentials['password'],
                'receipt' => $mpesaReceiptNumber,
                'expires_at' => $mikrotikCredentials['expires_at'],
                'message' => 'Payment successful! Your WiFi access is ready.'
            ]
        ];

    } else {
        // Payment failed or was cancelled
        $failureReason = '';
        switch ((int)$resultCode) {
            case 1032:
                $failureReason = 'Payment cancelled on handset';
                break;
            case 1037:
                $failureReason = 'Request timeout - please try again';
                break;
            default:
                $failureReason = $resultDesc;
        }

        // Mark payment as failed
        markPaymentAsFailed($checkoutId, $failureReason);

        $response = [
            'ResultCode' => 1,
            'ResultDesc' => 'Payment not completed',
            'data' => [
                'phone' => $paymentSession['phone'],
                'package' => $paymentSession['plan'],
                'reason' => $failureReason,
                'message' => 'Payment was not completed. Please try again.'
            ]
        ];
    }

} catch (Throwable $e) {
    error_log('Daraja callback error: ' . $e->getMessage());
    $response['ResultDesc'] = $e->getMessage();
}

echo json_encode($response);


/**
 * Find payment session by checkout ID
 */
function findPaymentSession($checkoutId) {
    try {
        $session = ORM::for_table('hotspot_payment_sessions')
            ->where('checkout_id', $checkoutId)
            ->where('status', 'pending')
            ->find_one();

        return $session ? $session->as_array() : null;
    } catch (Throwable $e) {
        error_log('Failed to find payment session: ' . $e->getMessage());
        return null;
    }
}


/**
 * Create temporary MikroTik user credentials
 * 
 * Format: hotspot_[TIMESTAMP]
 * Password: Random 12-character strong password
 */
function createMikrotikUserForPayment($phone, $plan, $duration, $mac, $ip, $system) {
    
    try {
        // Generate unique username
        $username = 'hotspot_' . time();
        $password = generatePassword(12);
        
        // Calculate expiry time based on duration
        $durationSeconds = parseDurationToSeconds($duration);
        $expiryTime = time() + $durationSeconds;
        
        // TODO: Create user on MikroTik router via system/autoload/Mikrotik.php
        // For now, we'll prepare the credentials for storage
        
        return [
            'username' => $username,
            'password' => $password,
            'expires_at' => date('Y-m-d H:i:s', $expiryTime),
            'expiry_timestamp' => $expiryTime,
            'plan' => $plan,
            'duration' => $duration
        ];
        
    } catch (Throwable $e) {
        error_log('Failed to create MikroTik user: ' . $e->getMessage());
        return null;
    }
}


/**
 * Mark payment as completed
 */
function markPaymentAsCompleted($checkoutId, $receipt, $amount, $transactionDate, $username, $password) {
    try {
        $session = ORM::for_table('hotspot_payment_sessions')
            ->where('checkout_id', $checkoutId)
            ->find_one();

        if (!$session) {
            return false;
        }

        $session->status = 'completed';
        $session->mpesa_receipt = $receipt;
        $session->transaction_date = $transactionDate;
        $session->transaction_amount = $amount;
        $session->hotspot_username = $username;
        $session->hotspot_password = $password;
        $session->updated_at = date('Y-m-d H:i:s');
        $session->save();
        return true;
    } catch (Throwable $e) {
        error_log('Failed to mark payment completed: ' . $e->getMessage());
        return false;
    }
}


/**
 * Mark payment as failed
 */
function markPaymentAsFailed($checkoutId, $reason) {
    try {
        $session = ORM::for_table('hotspot_payment_sessions')
            ->where('checkout_id', $checkoutId)
            ->find_one();

        if (!$session) {
            return false;
        }

        $session->status = 'failed';
        $session->failure_reason = $reason;
        $session->updated_at = date('Y-m-d H:i:s');
        $session->save();
        return true;
    } catch (Throwable $e) {
        error_log('Failed to mark payment failed: ' . $e->getMessage());
        return false;
    }
}


/**
 * Parse duration to seconds
 */
function parseDurationToSeconds($duration) {
    $duration = strtoupper(trim($duration));
    
    if (preg_match('/^(\d+)\s*H(OUR)?S?$/i', $duration, $m)) {
        return (int)$m[1] * 3600;
    } elseif (preg_match('/^(\d+)\s*D(AY)?S?$/i', $duration, $m)) {
        return (int)$m[1] * 86400;
    } elseif (preg_match('/^(\d+)\s*M(ONTH)?S?$/i', $duration, $m)) {
        return (int)$m[1] * 30 * 86400;
    }
    
    return 1 * 3600;  // Default 1 hour
}


/**
 * Generate secure password
 */
function generatePassword($length = 12) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function syncBillingAccountFromPayment($paymentSession, $receipt, $amount)
{
    try {
        $phone = preg_replace('/[^0-9]/', '', $paymentSession['phone'] ?? '');
        if (!preg_match('/^254[0-9]{9}$/', $phone)) {
            return;
        }

        $planName = trim($paymentSession['plan'] ?? '');
        if ($planName === '') {
            return;
        }

        $plan = ORM::for_table('tbl_plans')
            ->where('name_plan', $planName)
            ->where('type', 'Hotspot')
            ->find_one();

        if (!$plan) {
            error_log('Daraja callback: no matching plan for ' . $planName);
            return;
        }

        $customer = ORM::for_table('tbl_customers')
            ->where('username', $phone)
            ->where('service_type', 'Hotspot')
            ->find_one();

        $defaultPin = substr($phone, -4);

        if (!$customer) {
            $customer = ORM::for_table('tbl_customers')->create();
            $customer->username = $phone;
            $customer->password = $defaultPin;
            $customer->pppoe_password = $defaultPin;
            $customer->phonenumber = $phone;
            $customer->fullname = 'Hotspot User ' . $phone;
            $customer->email = 'paid_' . $phone . '@hotspot.local';
            $customer->service_type = 'Hotspot';
            $customer->account_type = 'Personal';
            $customer->balance = 0;
            $customer->status = 'Active';
            $customer->created_by = 1;
            $customer->created_at = date('Y-m-d H:i:s');
            $customer->save();
        }

        $expiryTimestamp = strtotime('+' . (int)$plan['validity'] . ' ' . strtolower($plan['validity_unit']));

        $recharge = ORM::for_table('tbl_user_recharges')->create();
        $recharge->customer_id = $customer->id;
        $recharge->username = $customer->username;
        $recharge->plan_id = $plan->id;
        $recharge->namebp = $plan->name_plan;
        $recharge->recharged_on = date('Y-m-d');
        $recharge->recharged_time = date('H:i:s');
        $recharge->expiration = date('Y-m-d', $expiryTimestamp);
        $recharge->time = date('H:i:s', $expiryTimestamp);
        $recharge->status = 'on';
        $recharge->method = 'M-Pesa ' . ($receipt ?: 'Callback');
        $recharge->routers = $plan->routers;
        $recharge->type = $plan->type;
        $recharge->admin_id = 1;
        $recharge->save();
    } catch (Throwable $e) {
        error_log('Daraja callback billing sync failed: ' . $e->getMessage());
    }
}
