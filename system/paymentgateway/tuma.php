<?php

/**
 * Tuma STK payment gateway integration.
 *
 * This file supports:
 * 1) Admin key/secret configuration UI
 * 2) Generic STK push for order flow (optional)
 * 3) Hotspot STK push helper used by hotspot-payment-api.php
 * 4) Callback handler endpoint via /?_route=callback/tuma
 *
 * NOTE:
 * Exact Tuma endpoint and payload can vary by account plan/version.
 * Settings include an override URL for rapid testing against your live docs.
 */

function tuma_show_config()
{
    global $ui, $config;

    $ui->assign('_title', 'Tuma STK Settings');
    $ui->assign('tuma_env', $config['tuma_env'] ?? 'sandbox');
    $ui->assign('tuma_api_base', $config['tuma_api_base'] ?? 'https://api.tuma.co.ke');
    $ui->assign('tuma_stk_path', $config['tuma_stk_path'] ?? '/v1/payments/stk-push');
    $ui->assign('tuma_api_key', $config['tuma_api_key'] ?? '');
    $ui->assign('tuma_api_secret', $config['tuma_api_secret'] ?? '');
    $ui->assign('tuma_shortcode', $config['tuma_shortcode'] ?? '');
    $ui->assign('tuma_callback_url', $config['tuma_callback_url'] ?? Text::url('callback/tuma'));
    $ui->assign('hotspot_payment_provider', $config['hotspot_payment_provider'] ?? 'mpesa');
    $ui->display('admin/paymentgateway/tuma.tpl');
}

function tuma_save_config()
{
    $provider = _post('hotspot_payment_provider');
    if ($provider !== 'tuma' && $provider !== 'mpesa') {
        $provider = 'mpesa';
    }

    $settings = [
        'tuma_env' => _post('tuma_env') == 'production' ? 'production' : 'sandbox',
        'tuma_api_base' => trim(_post('tuma_api_base')),
        'tuma_stk_path' => trim(_post('tuma_stk_path')),
        'tuma_api_key' => trim(_post('tuma_api_key')),
        'tuma_api_secret' => trim(_post('tuma_api_secret')),
        'tuma_shortcode' => trim(_post('tuma_shortcode')),
        'tuma_callback_url' => trim(_post('tuma_callback_url')),
        'hotspot_payment_provider' => $provider,
    ];

    foreach ($settings as $setting => $value) {
        $d = ORM::for_table('tbl_appconfig')->where('setting', $setting)->find_one();
        if ($d) {
            $d->value = $value;
            $d->save();
        } else {
            $d = ORM::for_table('tbl_appconfig')->create();
            $d->setting = $setting;
            $d->value = $value;
            $d->save();
        }
    }

    r2(getUrl('paymentgateway/tuma'), 's', Lang::T('Settings Saved Successfully'));
}

function tuma_payment_notification()
{
    // Generic callback hook for order flow.
    // Hotspot callbacks are also handled by /system/tuma-callback.php for direct flow.
    $payload = json_decode(file_get_contents('php://input'), true);
    if (empty($payload)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
        return;
    }

    $checkoutId = tuma_extract_checkout_id($payload);
    if (empty($checkoutId)) {
        echo json_encode(['status' => 'error', 'message' => 'Missing checkout id']);
        return;
    }

    $trx = ORM::for_table('tbl_payment_gateway')
        ->where('gateway', 'tuma')
        ->where('gateway_trx_id', $checkoutId)
        ->find_one();

    if (!$trx) {
        echo json_encode(['status' => 'error', 'message' => 'Transaction not found']);
        return;
    }

    $trx->pg_paid_response = json_encode($payload);
    $trx->save();

    if (tuma_is_success($payload)) {
        tuma_mark_transaction_paid($trx['id'], $payload, 'Callback');
    }

    echo json_encode(['status' => 'ok']);
}

function tuma_create_transaction($trx, $user)
{
    global $config;

    $phoneRaw = $user['phonenumber'] ?? $user['username'] ?? '';
    $phone = tuma_normalize_phone($phoneRaw);
    if (empty($phone)) {
        r2(getUrl('order/view/' . $trx['id']), 'e', 'Tuma requires phone number in format 07XXXXXXXX or 2547XXXXXXXX');
    }

    $callbackUrl = !empty($config['tuma_callback_url']) ? trim($config['tuma_callback_url']) : Text::url('callback/tuma');
    $reference = 'TRX-' . $trx['id'];
    $amount = max(1, (int) round($trx['price']));

    $payload = tuma_build_stk_payload($phone, $amount, $reference, $callbackUrl, [
        'description' => 'PHPNuxBill Order #' . $trx['id'],
        'shortcode' => trim($config['tuma_shortcode'] ?? ''),
    ]);

    $resp = tuma_post_stk_push($payload);

    $trx->pg_request = json_encode($payload);
    $trx->pg_paid_response = json_encode($resp['json']);
    $trx->payment_method = 'Tuma';
    $trx->payment_channel = 'STK Push';
    $trx->expired_date = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $trx->pg_url_payment = Text::url('order/view/' . $trx['id'] . '/check');

    $checkoutId = tuma_extract_checkout_id($resp['json']);
    if (!empty($checkoutId)) {
        $trx->gateway_trx_id = $checkoutId;
        $trx->save();
        r2(getUrl('order/view/' . $trx['id']), 's', 'Tuma STK push sent. Complete payment on phone then click Check for Payment.');
    }

    $trx->save();
    $errorMsg = tuma_extract_error($resp['json']);
    r2(getUrl('order/view/' . $trx['id']), 'e', $errorMsg ?: 'Failed to initiate Tuma STK push');
}

function tuma_get_status($trx, $user)
{
    if (empty($trx) || empty($trx['id'])) {
        r2(getUrl('order/package'), 'e', 'Transaction not found');
    }

    if ((int)$trx['status'] === 2) {
        r2(getUrl('order/view/' . $trx['id']), 's', 'Payment already completed.');
    }

    r2(getUrl('order/view/' . $trx['id']), 'w', 'Use callback/audit status for Tuma confirmation.');
}

function tuma_mark_transaction_paid($trxId, $responsePayload, $channel)
{
    $trx = ORM::for_table('tbl_payment_gateway')->find_one($trxId);
    if (!$trx || (int)$trx['status'] === 2) {
        return;
    }

    $trx->status = 2;
    $trx->paid_date = date('Y-m-d H:i:s');
    $trx->payment_method = 'Tuma';
    $trx->payment_channel = $channel;
    $trx->pg_paid_response = json_encode($responsePayload);

    $invoice = Package::rechargeUser(
        (int)$trx['user_id'],
        $trx['routers'],
        (int)$trx['plan_id'],
        'Tuma',
        'STK Push'
    );

    if (!empty($invoice)) {
        $trx->trx_invoice = $invoice;
    }

    $trx->save();
}

function tuma_initiate_hotspot_stk($phone, $amount, $plan, $duration, $mac, $ip, $router, $system, $forceMockSuccess = false)
{
    global $config;

    $phone = tuma_normalize_phone($phone);
    if (empty($phone)) {
        return [
            'status' => 'error',
            'message' => 'Invalid phone number format. Use 254XXXXXXXXX',
            'type' => 'tuma_error',
        ];
    }

    $callbackUrl = trim($config['tuma_callback_url'] ?? '');
    if ($callbackUrl === '') {
        $callbackUrl = Text::url('callback/tuma');
    }

    $reference = 'WIFI_' . time() . '_' . strtolower(substr(str_replace(':', '', (string)$mac), -4));

    if ($forceMockSuccess) {
        $checkoutId = 'TUMA_MOCK_' . date('YmdHis') . '_' . random_int(1000, 9999);
        storeHotspotPaymentSession($phone, $amount, $plan, $duration, $reference, $mac, $ip, $checkoutId, $system);

        // Reuse existing callback processing model to keep behavior identical.
        $mockPayload = [
            'checkout_id' => $checkoutId,
            'status' => 'success',
            'reference' => 'MOCK' . random_int(100000, 999999),
            'amount' => $amount,
            'phone' => $phone,
        ];

        tuma_process_hotspot_callback_payload($mockPayload);
        $state = getHotspotPaymentStatusByCheckoutId($checkoutId);

        if (!$state || ($state['status'] ?? '') !== 'completed') {
            return [
                'status' => 'error',
                'message' => 'Mock callback did not complete payment session',
                'type' => 'tuma_error',
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Mock payment completed (staging). User can be connected now.',
            'type' => 'mpesa_success',
            'package' => $plan,
            'amount' => $amount . ' KES',
            'duration' => $duration,
            'system' => $system,
            'phone' => $phone,
            'reference' => $reference,
            'checkout_id' => $checkoutId,
            'username' => $state['hotspot_username'] ?? '',
            'password' => $state['hotspot_password'] ?? '',
        ];
    }

    $payload = tuma_build_stk_payload($phone, $amount, $reference, $callbackUrl, [
        'description' => 'WiFi: ' . $plan . ' (' . $duration . ')',
        'shortcode' => trim($config['tuma_shortcode'] ?? ''),
        'metadata' => [
            'plan' => $plan,
            'duration' => $duration,
            'router' => $router,
            'device_mac' => $mac,
            'device_ip' => $ip,
            'system' => $system,
        ],
    ]);

    $resp = tuma_post_stk_push($payload);
    $json = $resp['json'] ?? [];
    $checkoutId = tuma_extract_checkout_id($json);

    if (empty($checkoutId)) {
        return [
            'status' => 'error',
            'message' => tuma_extract_error($json) ?: 'Tuma request failed',
            'type' => 'tuma_error',
            'support' => 'info@apperstar.dev',
        ];
    }

    storeHotspotPaymentSession($phone, $amount, $plan, $duration, $reference, $mac, $ip, $checkoutId, $system);

    return [
        'status' => 'success',
        'message' => 'Tuma STK prompt sent to your phone',
        'type' => 'mpesa_pending',
        'package' => $plan,
        'amount' => $amount . ' KES',
        'duration' => $duration,
        'system' => $system,
        'phone' => $phone,
        'reference' => $reference,
        'checkout_id' => $checkoutId,
        'next_step' => 'Enter your payment PIN when prompted. You will be connected automatically after payment.',
        'expires_in' => '2 minutes',
        'support' => 'Contact: info@apperstar.dev | WhatsApp: https://wa.link/0sib65',
    ];
}

function tuma_process_hotspot_callback_payload($payload)
{
    $checkoutId = tuma_extract_checkout_id($payload);
    if (empty($checkoutId)) {
        return [false, 'Missing checkout request ID'];
    }

    $session = ORM::for_table('hotspot_payment_sessions')
        ->where('checkout_id', $checkoutId)
        ->find_one();

    if (!$session) {
        return [false, 'Payment session not found'];
    }

    $status = strtolower(trim((string)($payload['status'] ?? $payload['result'] ?? '')));
    $isSuccess = in_array($status, ['success', 'successful', 'completed', 'paid'], true) || tuma_is_success($payload);

    if ($isSuccess) {
        $receipt = tuma_extract_receipt($payload);
        $amount = tuma_extract_amount($payload);

        $creds = tuma_create_hotspot_user_for_payment($session->as_array());
        if (!$creds) {
            return [false, 'Failed to create hotspot credentials'];
        }

        $session->status = 'completed';
        $session->mpesa_receipt = $receipt;
        $session->transaction_amount = $amount;
        $session->transaction_date = date('YmdHis');
        $session->hotspot_username = $creds['username'];
        $session->hotspot_password = $creds['password'];
        $session->updated_at = date('Y-m-d H:i:s');
        $session->save();

        return [true, 'Accepted'];
    }

    $reason = tuma_extract_error($payload);
    if ($reason === '') {
        $reason = 'Payment not completed';
    }

    $session->status = 'failed';
    $session->failure_reason = $reason;
    $session->updated_at = date('Y-m-d H:i:s');
    $session->save();

    tuma_disconnect_hotspot_session($session->as_array());

    return [true, 'Payment marked failed'];
}

function tuma_disconnect_hotspot_session($session)
{
    // Defensive disconnect path on failed callback.
    // If account was somehow created before callback failure, deactivate and disconnect.
    $username = trim((string)($session['hotspot_username'] ?? ''));
    if ($username === '') {
        return;
    }

    try {
        $customer = ORM::for_table('tbl_customers')->where('username', $username)->find_one();
        if ($customer) {
            $customer->status = 'Inactive';
            $customer->save();
        }

        $recharges = ORM::for_table('tbl_user_recharges')
            ->where('username', $username)
            ->where('status', 'on')
            ->find_many();

        foreach ($recharges as $r) {
            $r->status = 'off';
            $r->save();
        }

        if (class_exists('Radius')) {
            $radius = new Radius();
            $radius->disconnectCustomer($username);
        }
    } catch (Throwable $e) {
        error_log('Tuma disconnect fallback failed: ' . $e->getMessage());
    }
}

function tuma_create_hotspot_user_for_payment($paymentSession)
{
    try {
        $phone = preg_replace('/[^0-9]/', '', (string)($paymentSession['phone'] ?? ''));
        if (!preg_match('/^254[0-9]{9}$/', $phone)) {
            return null;
        }

        $planName = trim((string)($paymentSession['plan'] ?? ''));
        if ($planName === '') {
            return null;
        }

        $plan = ORM::for_table('tbl_plans')
            ->where('type', 'Hotspot')
            ->where('name_plan', $planName)
            ->find_one();

        if (!$plan) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', $planName));
            $allPlans = ORM::for_table('tbl_plans')->where('type', 'Hotspot')->find_many();
            foreach ($allPlans as $candidate) {
                $candidateName = strtolower(preg_replace('/[^a-z0-9]+/', '', (string)$candidate['name_plan']));
                if ($candidateName === $normalized || ($normalized !== '' && strpos($candidateName, $normalized) !== false)) {
                    $plan = $candidate;
                    break;
                }
            }
        }

        if (!$plan) {
            return null;
        }

        $username = $phone;
        $password = substr($phone, -4);

        $customer = ORM::for_table('tbl_customers')
            ->where('username', $username)
            ->where('service_type', 'Hotspot')
            ->find_one();

        if (!$customer) {
            $customer = ORM::for_table('tbl_customers')->create();
            $customer->username = $username;
            $customer->fullname = 'Hotspot User ' . $phone;
            $customer->email = 'paid_' . $phone . '@hotspot.local';
            $customer->created_by = 1;
            $customer->created_at = date('Y-m-d H:i:s');
        }

        $customer->password = $password;
        $customer->pppoe_password = $password;
        $customer->phonenumber = $phone;
        $customer->service_type = 'Hotspot';
        $customer->account_type = 'Personal';
        $customer->balance = 0;
        $customer->status = 'Active';
        $customer->save();

        $routerName = trim((string)($plan['routers'] ?? ''));
        if ($routerName === '') {
            $routerName = 'radius';
        }

        Package::rechargeUser(
            (int)$customer->id,
            $routerName,
            (int)$plan['id'],
            'Tuma',
            'Tuma Callback'
        );

        $latestRecharge = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', (int)$customer->id)
            ->where('plan_id', (int)$plan['id'])
            ->order_by_desc('id')
            ->find_one();

        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
        if ($latestRecharge) {
            $candidateExpiry = trim(($latestRecharge['expiration'] ?? '') . ' ' . ($latestRecharge['time'] ?? ''));
            if ($candidateExpiry !== '') {
                $expiresAt = $candidateExpiry;
            }
        }

        return [
            'username' => $username,
            'password' => $password,
            'expires_at' => $expiresAt,
        ];
    } catch (Throwable $e) {
        error_log('Tuma hotspot user provisioning failed: ' . $e->getMessage());
        return null;
    }
}

function tuma_build_stk_payload($phone, $amount, $reference, $callbackUrl, $opts = [])
{
    return [
        'phone' => $phone,
        'amount' => (int)$amount,
        'reference' => $reference,
        'callback_url' => $callbackUrl,
        'description' => $opts['description'] ?? ('WiFi Purchase ' . $reference),
        'shortcode' => $opts['shortcode'] ?? '',
        'metadata' => $opts['metadata'] ?? (object)[],
    ];
}

function tuma_post_stk_push($payload)
{
    global $config;

    $apiBase = trim((string)($config['tuma_api_base'] ?? 'https://api.tuma.co.ke'));
    $stkPath = trim((string)($config['tuma_stk_path'] ?? '/v1/payments/stk-push'));
    if ($stkPath === '') {
        $stkPath = '/v1/payments/stk-push';
    }

    $url = rtrim($apiBase, '/') . '/' . ltrim($stkPath, '/');

    $apiKey = trim((string)($config['tuma_api_key'] ?? ''));
    $apiSecret = trim((string)($config['tuma_api_secret'] ?? ''));

    $headers = [
        'Content-Type: application/json',
    ];

    if ($apiKey !== '') {
        $headers[] = 'X-API-KEY: ' . $apiKey;
+        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    if ($apiSecret !== '') {
        $headers[] = 'X-API-SECRET: ' . $apiSecret;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        $json = [
            'raw' => $raw,
            'curl_error' => $curlErr,
            'http_code' => $status,
        ];
    }

    return [
        'http_code' => $status,
        'json' => $json,
        'raw' => $raw,
    ];
}

function tuma_extract_checkout_id($payload)
{
    $keys = [
        'checkout_id', 'checkoutId', 'CheckoutRequestID', 'transaction_id', 'transactionId', 'request_id', 'requestId',
    ];
    foreach ($keys as $k) {
        if (!empty($payload[$k])) {
            return (string)$payload[$k];
        }
    }

    if (!empty($payload['data']) && is_array($payload['data'])) {
        foreach ($keys as $k) {
            if (!empty($payload['data'][$k])) {
                return (string)$payload['data'][$k];
            }
        }
    }

    return '';
}

function tuma_extract_receipt($payload)
{
    $keys = ['receipt', 'receipt_number', 'receiptNumber', 'mpesa_receipt', 'MpesaReceiptNumber'];
    foreach ($keys as $k) {
        if (!empty($payload[$k])) {
            return (string)$payload[$k];
        }
    }
    if (!empty($payload['data']) && is_array($payload['data'])) {
        foreach ($keys as $k) {
            if (!empty($payload['data'][$k])) {
                return (string)$payload['data'][$k];
            }
        }
    }
    return '';
}

function tuma_extract_amount($payload)
{
    $keys = ['amount', 'transaction_amount', 'TransactionAmount'];
    foreach ($keys as $k) {
        if (isset($payload[$k]) && $payload[$k] !== '') {
            return (string)$payload[$k];
        }
    }
    if (!empty($payload['data']) && is_array($payload['data'])) {
        foreach ($keys as $k) {
            if (isset($payload['data'][$k]) && $payload['data'][$k] !== '') {
                return (string)$payload['data'][$k];
            }
        }
    }
    return '';
}

function tuma_extract_error($payload)
{
    $keys = ['message', 'error', 'error_message', 'errorMessage', 'ResultDesc', 'reason'];
    foreach ($keys as $k) {
        if (!empty($payload[$k])) {
            return (string)$payload[$k];
        }
    }

    if (!empty($payload['data']) && is_array($payload['data'])) {
        foreach ($keys as $k) {
            if (!empty($payload['data'][$k])) {
                return (string)$payload['data'][$k];
            }
        }
    }

    return '';
}

function tuma_is_success($payload)
{
    if (isset($payload['success']) && ($payload['success'] === true || $payload['success'] === 'true' || $payload['success'] === 1 || $payload['success'] === '1')) {
        return true;
    }

    $status = strtolower(trim((string)($payload['status'] ?? $payload['result'] ?? '')));
    if (in_array($status, ['success', 'successful', 'completed', 'paid'], true)) {
        return true;
    }

    $code = (string)($payload['result_code'] ?? $payload['code'] ?? $payload['ResultCode'] ?? '');
    if ($code === '0' || strtolower($code) === 'success') {
        return true;
    }

    if (!empty($payload['data']) && is_array($payload['data'])) {
        return tuma_is_success($payload['data']);
    }

    return false;
}

function tuma_normalize_phone($phone)
{
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($phone) === 10 && strpos($phone, '07') === 0) {
        return '254' . substr($phone, 1);
    }
    if (strlen($phone) === 9 && strpos($phone, '7') === 0) {
        return '254' . $phone;
    }
    if (strlen($phone) === 12 && strpos($phone, '254') === 0) {
        return $phone;
    }
    return '';
}
