<?php

/**
 * M-Pesa (Safaricom Daraja) Payment Gateway
 * Sandbox-first implementation for PHPNuxBill.
 */

function mpesa_show_config()
{
    global $ui, $config;

    $ui->assign('_title', 'M-Pesa Settings');
    $ui->assign('mpesa_env', $config['mpesa_env'] ?? 'sandbox');
    $ui->assign('mpesa_consumer_key', $config['mpesa_consumer_key'] ?? '');
    $ui->assign('mpesa_consumer_secret', $config['mpesa_consumer_secret'] ?? '');
    $ui->assign('mpesa_shortcode', $config['mpesa_shortcode'] ?? '174379');
    $ui->assign('mpesa_passkey', $config['mpesa_passkey'] ?? '');
    $ui->assign('mpesa_callback_url', $config['mpesa_callback_url'] ?? Text::url('callback/mpesa'));
    $ui->display('admin/paymentgateway/mpesa.tpl');
}

function mpesa_save_config()
{
    $settings = [
        'mpesa_env' => _post('mpesa_env') == 'production' ? 'production' : 'sandbox',
        'mpesa_consumer_key' => trim(_post('mpesa_consumer_key')),
        'mpesa_consumer_secret' => trim(_post('mpesa_consumer_secret')),
        'mpesa_shortcode' => trim(_post('mpesa_shortcode')),
        'mpesa_passkey' => trim(_post('mpesa_passkey')),
        'mpesa_callback_url' => trim(_post('mpesa_callback_url')),
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

    r2(getUrl('paymentgateway/mpesa'), 's', Lang::T('Settings Saved Successfully'));
}

function mpesa_validate_config()
{
    global $config;

    if (empty($config['mpesa_consumer_key']) || empty($config['mpesa_consumer_secret'])) {
        r2(getUrl('paymentgateway/mpesa'), 'e', 'M-Pesa consumer key/secret is required.');
    }

    if (empty($config['mpesa_shortcode']) || empty($config['mpesa_passkey'])) {
        r2(getUrl('paymentgateway/mpesa'), 'e', 'M-Pesa shortcode/passkey is required.');
    }
}

function mpesa_create_transaction($trx, $user)
{
    global $config;

    $phoneRaw = $user['phonenumber'] ?? $user['username'] ?? '';
    $phone = mpesa_normalize_phone($phoneRaw);
    if (empty($phone)) {
        r2(getUrl('order/view/' . $trx['id']), 'e', 'M-Pesa requires customer phone number in format 07XXXXXXXX or 2547XXXXXXXX');
    }

    $token = mpesa_get_access_token();
    if (empty($token)) {
        r2(getUrl('order/view/' . $trx['id']), 'e', 'Failed to get M-Pesa access token. Check M-Pesa settings.');
    }

    $timestamp = date('YmdHis');
    $shortcode = trim($config['mpesa_shortcode']);
    $password = base64_encode($shortcode . trim($config['mpesa_passkey']) . $timestamp);
    $callbackUrl = !empty($config['mpesa_callback_url']) ? trim($config['mpesa_callback_url']) : Text::url('callback/mpesa');

    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => max(1, (int) round($trx['price'])),
        'PartyA' => $phone,
        'PartyB' => $shortcode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callbackUrl,
        'AccountReference' => 'TRX-' . $trx['id'],
        'TransactionDesc' => 'PHPNuxBill Order #' . $trx['id'],
    ];

    $response = mpesa_http_json(mpesa_base_url() . '/mpesa/stkpush/v1/processrequest', $payload, [
        'Authorization: Bearer ' . $token,
    ]);

    $trx->pg_request = json_encode($payload);
    $trx->pg_paid_response = json_encode($response['json']);
    $trx->payment_method = 'M-Pesa';
    $trx->payment_channel = 'STK Push';
    $trx->expired_date = date('Y-m-d H:i:s', strtotime('+10 minutes'));
    $trx->pg_url_payment = Text::url('order/view/' . $trx['id'] . '/check');

    if (($response['json']['ResponseCode'] ?? '') === '0') {
        $trx->gateway_trx_id = $response['json']['CheckoutRequestID'] ?? '';
        $trx->save();
        r2(getUrl('order/view/' . $trx['id']), 's', 'M-Pesa STK push sent. Complete payment on phone then click Check for Payment.');
    }

    $trx->save();
    $errorMsg = $response['json']['errorMessage'] ?? $response['json']['ResponseDescription'] ?? 'Failed to initiate M-Pesa STK push';
    r2(getUrl('order/view/' . $trx['id']), 'e', $errorMsg);
}

function mpesa_get_status($trx, $user)
{
    if (empty($trx) || empty($trx['id'])) {
        r2(getUrl('order/package'), 'e', 'Transaction not found');
    }

    if ((int) $trx['status'] === 2) {
        r2(getUrl('order/view/' . $trx['id']), 's', 'Payment already completed.');
    }

    if (empty($trx['gateway_trx_id'])) {
        r2(getUrl('order/view/' . $trx['id']), 'w', 'No M-Pesa checkout request found. Try payment again.');
    }

    $token = mpesa_get_access_token();
    if (empty($token)) {
        r2(getUrl('order/view/' . $trx['id']), 'e', 'Failed to get M-Pesa access token.');
    }

    $response = mpesa_stk_query($trx['gateway_trx_id']);
    $json = $response['json'];

    $trxObj = ORM::for_table('tbl_payment_gateway')->find_one($trx['id']);
    if ($trxObj) {
        $trxObj->pg_paid_response = json_encode($json);
        $trxObj->save();
    }

    if (($json['ResultCode'] ?? null) === '0' || ($json['ResultCode'] ?? null) === 0) {
        mpesa_mark_transaction_paid($trx['id'], $json, 'STK Query');
        r2(getUrl('order/view/' . $trx['id']), 's', 'Payment confirmed successfully.');
    }

    if (($json['ResultCode'] ?? null) === '1032') {
        r2(getUrl('order/view/' . $trx['id']), 'w', 'Payment was canceled on handset.');
    }

    if (($json['ResultCode'] ?? null) === '1037') {
        r2(getUrl('order/view/' . $trx['id']), 'w', 'M-Pesa request timeout. Please try again.');
    }

    r2(getUrl('order/view/' . $trx['id']), 'w', 'Payment is still pending. Check again in a few seconds.');
}

function mpesa_payment_notification()
{
    $payload = json_decode(file_get_contents('php://input'), true);
    if (empty($payload)) {
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload']);
        return;
    }

    $checkoutId = $payload['Body']['stkCallback']['CheckoutRequestID'] ?? '';
    $resultCode = $payload['Body']['stkCallback']['ResultCode'] ?? 1;

    if (empty($checkoutId)) {
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Missing checkout request id']);
        return;
    }

    $trx = ORM::for_table('tbl_payment_gateway')
        ->where('gateway', 'mpesa')
        ->where('gateway_trx_id', $checkoutId)
        ->find_one();

    if (!$trx) {
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
        return;
    }

    $trx->pg_paid_response = json_encode($payload);
    $trx->save();

    if ((string) $resultCode === '0') {
        mpesa_mark_transaction_paid($trx['id'], $payload, 'Callback');
    }

    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

function mpesa_mark_transaction_paid($trxId, $responsePayload, $channel)
{
    $trx = ORM::for_table('tbl_payment_gateway')->find_one($trxId);
    if (!$trx || (int) $trx['status'] === 2) {
        return;
    }

    $trx->status = 2;
    $trx->paid_date = date('Y-m-d H:i:s');
    $trx->payment_method = 'M-Pesa';
    $trx->payment_channel = $channel;
    $trx->pg_paid_response = json_encode($responsePayload);

    $invoice = Package::rechargeUser(
        (int) $trx['user_id'],
        $trx['routers'],
        (int) $trx['plan_id'],
        'M-Pesa',
        'STK Push'
    );

    if (!empty($invoice)) {
        $trx->trx_invoice = $invoice;
    }

    $trx->save();
}

function mpesa_get_access_token()
{
    global $config;

    $ck = trim($config['mpesa_consumer_key'] ?? '');
    $cs = trim($config['mpesa_consumer_secret'] ?? '');
    if (empty($ck) || empty($cs)) {
        return '';
    }

    $headers = ['Authorization: Basic ' . base64_encode($ck . ':' . $cs)];
    $response = mpesa_http_json(
        mpesa_base_url() . '/oauth/v1/generate?grant_type=client_credentials',
        null,
        $headers,
        'GET'
    );

    return $response['json']['access_token'] ?? '';
}

function mpesa_stk_query($checkoutRequestId)
{
    global $config;

    $token = mpesa_get_access_token();
    if (empty($token)) {
        return ['json' => ['ResultCode' => 1, 'ResultDesc' => 'Access token error']];
    }

    $timestamp = date('YmdHis');
    $shortcode = trim($config['mpesa_shortcode']);
    $password = base64_encode($shortcode . trim($config['mpesa_passkey']) . $timestamp);

    $payload = [
        'BusinessShortCode' => $shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkoutRequestId,
    ];

    return mpesa_http_json(mpesa_base_url() . '/mpesa/stkpushquery/v1/query', $payload, [
        'Authorization: Bearer ' . $token,
    ]);
}

function mpesa_base_url()
{
    global $config;
    $env = $config['mpesa_env'] ?? 'sandbox';
    if ($env === 'production') {
        return 'https://api.safaricom.co.ke';
    }
    return 'https://sandbox.safaricom.co.ke';
}

function mpesa_http_json($url, $payload = null, $headers = [], $method = 'POST')
{
    $ch = curl_init();
    $httpHeaders = ['Content-Type: application/json'];
    foreach ($headers as $h) {
        $httpHeaders[] = $h;
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);

    if (strtoupper($method) === 'GET') {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }
    }

    $responseBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($responseBody, true);
    if (!is_array($json)) {
        $json = [
            'raw' => $responseBody,
            'curl_error' => $curlErr,
            'http_code' => $status,
        ];
    }

    return [
        'http_code' => $status,
        'curl_error' => $curlErr,
        'json' => $json,
    ];
}

function mpesa_normalize_phone($phone)
{
    $phone = preg_replace('/[^0-9]/', '', (string) $phone);
    if (empty($phone)) {
        return '';
    }

    if (strpos($phone, '254') === 0 && strlen($phone) === 12) {
        return $phone;
    }

    if (strpos($phone, '0') === 0 && strlen($phone) === 10) {
        return '254' . substr($phone, 1);
    }

    if (strpos($phone, '7') === 0 && strlen($phone) === 9) {
        return '254' . $phone;
    }

    return '';
}
