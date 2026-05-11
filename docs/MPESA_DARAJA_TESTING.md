# M-Pesa Daraja Integration - Testing Guide

## System Overview

The hotspot login flow now integrates M-Pesa Daraja for WiFi package payments:

```
User Flow:
1. User accesses hotspot login page on MikroTik router (192.168.88.1)
2. Enters phone number (254712345678) + selects package
3. Form POSTs to: https://network.apperstar.dev/api/hotspot-payment.php
4. Backend initiates M-Pesa STK Push via Daraja API
5. User receives M-Pesa prompt, enters PIN to complete payment
6. M-Pesa sends callback to: https://network.apperstar.dev/api/daraja-callback.php
7. Backend creates temporary MikroTik user with purchased access
8. User gets internet access with credentials from step 7
```

## Files Created

### Backend Files
- **system/hotspot-payment-api.php** - Main API endpoint
  - Accepts phone + package selection from login form
  - Routes to trial (free) or paid (Daraja) flow
  - Uses existing mpesa.php helper functions
  
- **system/daraja-callback.php** - Payment callback handler  
  - Receives M-Pesa STK Push callback
  - Creates MikroTik user on successful payment
  - Stores payment/user data in database
  
- **install/hotspot-payment-schema.sql** - Database tables
  - hotspot_payment_sessions: Payment tracking
  - hotspot_guest_sessions: Temporary users
  - hotspot_trial_sessions: Trial access
  
### Frontend (Already Created)
- **mikrotik-hotspot/login.html** - MikroTik hotspot login page
  - Uploaded to router at `/login.html`
  - Posts to hotspot-payment-api.php

## Pre-Testing Checklist

### 1. Database Setup
Execute the schema to create required tables:

```bash
# Connect to your PHPNuxBill database and run:
mysql> source /path/to/install/hotspot-payment-schema.sql;
```

Or manually create tables if using the SQL file above.

### 2. M-Pesa Daraja Credentials
Ensure these are configured in PHPNuxBill admin panel → Paymentgateway → M-Pesa:

```
Environment: sandbox (for testing) or production
Consumer Key: [Your Daraja Consumer Key]
Consumer Secret: [Your Daraja Consumer Secret]  
Shortcode: 174379 (test shortcode, or your production shortcode)
Passkey: [Your M-Pesa B2C passkey]
Callback URL: https://network.apperstar.dev/api/daraja-callback.php
```

**Note**: You can get test credentials from:
- https://developer.safaricom.co.ke/apis (create app)
- Generate OAuth credentials (Consumer Key/Secret)
- Use shortcode 174379 for sandbox testing

### 3. Deploy Backend Files
Copy the new files to your server:

```bash
# Copy API files to server
scp system/hotspot-payment-api.php user@network.apperstar.dev:/path/to/phpnuxbill/system/
scp system/daraja-callback.php user@network.apperstar.dev:/path/to/phpnuxbill/system/
```

### 4. Verify URLs
Ensure these endpoints are accessible:

```
https://network.apperstar.dev/api/hotspot-payment.php
https://network.apperstar.dev/api/daraja-callback.php
```

Test with curl:

```bash
curl -X POST https://network.apperstar.dev/api/hotspot-payment.php \
  -d "phone=254712345678" \
  -d "package={\"plan\":\"1 Hour\",\"price\":10,\"duration\":\"1H\"}" \
  -d "device_mac=00:11:22:33:44:55" \
  -d "device_ip=192.168.88.100"
```

## Testing Scenarios

### Scenario 1: Trial Package (Free)
**Goal**: Verify instant free WiFi access works

**Steps**:
1. On hotspot login page, click "TRIAL - Free 1H" button
2. Enter phone number: 254712345678
3. Click "Get Free WiFi"
4. Should receive credentials immediately (hotspot_[TIMESTAMP])
5. Use credentials to connect to WiFi
6. Verify access works for 1 hour, then expires

**Expected Result**:
```json
{
  "status": "success",
  "message": "Trial access granted - enjoy 1H of free WiFi!",
  "type": "trial",
  "username": "trial_abc123_1234567890",
  "password": "xxxxxxxxxxxx",
  "expires_at": "2024-01-15 14:30:45"
}
```

**Database Check**:
```sql
SELECT * FROM hotspot_trial_sessions 
WHERE phone = '254712345678' 
ORDER BY created_at DESC LIMIT 1;
```

---

### Scenario 2: M-Pesa Payment Flow (Paid Package)
**Goal**: Verify STK Push is initiated and payment callback works

**Steps**:
1. On hotspot login page, click "1 HOUR - 10 KES" button
2. Enter phone number: 254712345678 (or your actual test number)
3. Click "Buy WiFi"
4. Should see: "M-Pesa prompt sent to your phone"
5. On your phone, you should receive M-Pesa STK popup (or check on Safaricom sandbox)
6. Complete M-Pesa payment (use test credentials if sandbox)
7. Wait 5-10 seconds for callback to process
8. Should receive credentials automatically

**Expected Initial Response**:
```json
{
  "status": "success",
  "message": "M-Pesa prompt sent to your phone",
  "type": "mpesa_pending",
  "package": "1 HOUR",
  "amount": "10 KES",
  "duration": "1H",
  "checkout_id": "[Daraja CheckoutRequestID]",
  "next_step": "Enter your M-Pesa PIN when prompted..."
}
```

**Database Check - Payment Session**:
```sql
SELECT * FROM hotspot_payment_sessions 
WHERE phone = '254712345678' 
ORDER BY created_at DESC LIMIT 1;
```

**Expected Status Progress**:
- Before payment: `status = 'pending'`, `checkout_id = filled`, `hotspot_username = NULL`
- After payment callback: `status = 'completed'`, `mpesa_receipt = filled`, `hotspot_username = filled`

**Database Check - User Session**:
```sql
SELECT * FROM hotspot_guest_sessions 
WHERE phone = '254712345678' 
AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
LIMIT 1;
```

---

### Scenario 3: Payment Failure/Cancellation
**Goal**: Verify failed payments are handled gracefully

**Steps**:
1. Initiate M-Pesa payment as in Scenario 2
2. When M-Pesa STK appears on phone, do NOT enter PIN
3. Cancel the prompt (wait 2 minutes for timeout)
4. Payment callback should be received with failure code

**Database Check**:
```sql
SELECT * FROM hotspot_payment_sessions 
WHERE checkout_id = '[the_checkout_id]' 
LIMIT 1;
```

**Expected Status**: `status = 'failed'`, `failure_reason = 'Payment cancelled on handset'` (or timeout)

---

## Debugging Checklist

### Issue: "M-Pesa Daraja not configured"
**Check**:
```sql
SELECT setting, value FROM tbl_appconfig 
WHERE setting LIKE 'mpesa%';
```

**Should show**:
- mpesa_consumer_key: [filled]
- mpesa_consumer_secret: [filled]
- mpesa_shortcode: 174379 (or production shortcode)
- mpesa_passkey: [filled]
- mpesa_env: sandbox (or production)

**Fix**: Go to admin panel → Paymentgateway → M-Pesa and save valid credentials

---

### Issue: "Failed to authenticate with M-Pesa"
**Possible Causes**:
1. Consumer Key/Secret are invalid
2. Credentials have expired
3. Wrong environment (sandbox vs production)
4. Network connectivity issue

**Debug**:
```bash
# Test access token generation
curl -X GET https://sandbox.safaricom.co.ke/oauth/v1/generate \
  -H "Authorization: Basic [base64(key:secret)]" \
  --header "Content-Type: application/json"
```

**Should return**:
```json
{
  "access_token": "xxx",
  "expires_in": "3599"
}
```

---

### Issue: Callback Not Received
**Check**:
1. Firewall allows HTTPS POST from Safaricom IP ranges
2. callback endpoint URL is accessible:
   ```bash
   curl -X POST https://network.apperstar.dev/api/daraja-callback.php
   ```
   Should return: `{"ResultCode":1,"ResultDesc":"Failed"}`

3. Check server logs for callback attempts:
   ```bash
   tail -f /var/log/apache2/error.log | grep daraja
   ```

4. Verify M-Pesa callback IP is whitelisted (if using WAF)
   - Safaricom IPs: [Check Daraja docs for current ranges]

---

### Issue: MikroTik User Not Created
**Current Status**: The callback handler creates credentials but doesn't YET call MikroTik API

**TODO**: Integrate with `system/autoload/Mikrotik.php` to actually create users on router

**Temporary Workaround**: Credentials are stored in database, can be manually added to router:

```
[admin@hAP-lite] /ip hotspot user> 
add server=hotspot1 name=hotspot_1234567890 password=GeneratedPassword profile=default
```

---

## Database Monitoring

### Check Recent Payments
```sql
SELECT id, phone, amount, plan, status, mpesa_receipt, created_at 
FROM hotspot_payment_sessions 
ORDER BY created_at DESC 
LIMIT 10;
```

### Check Active Users
```sql
SELECT username, phone, plan, status, expires_at 
FROM hotspot_guest_sessions 
WHERE status = 'active' 
AND expires_at > NOW()
ORDER BY created_at DESC;
```

### Check Failed Payments
```sql
SELECT phone, plan, amount, status, failure_reason, created_at 
FROM hotspot_payment_sessions 
WHERE status = 'failed' 
ORDER BY created_at DESC 
LIMIT 10;
```

---

## Performance Metrics to Track

### Response Times
- STK Push initiation: **< 2 seconds**
- Payment callback processing: **< 1 second**
- User credential generation: **instant**

### Success Rates
- Trial package creation: **99%** (instant)
- M-Pesa STK push: **95%+** (depends on Safaricom)
- Payment callback delivery: **98%+** (depends on network)

### Database Size
Monitor table sizes (especially `hotspot_payment_sessions`):
```sql
SELECT table_name, 
       ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
FROM information_schema.tables 
WHERE table_schema = 'phpnuxbill' 
AND table_name LIKE 'hotspot%'
ORDER BY (data_length + index_length) DESC;
```

**Recommendation**: Archive old records (> 90 days) monthly

---

## Next Steps

### Phase 1: Current (Testing)
- ✅ Hotspot payment API created
- ✅ Daraja callback handler created  
- ✅ Database schema ready
- 🔄 **Test end-to-end flow** ← YOU ARE HERE

### Phase 2: MikroTik Integration
- TODO: Call `Mikrotik.php` class to create users on router
- TODO: Add expiry jobs to clean up expired accounts
- TODO: Implement data usage tracking

### Phase 3: Admin Dashboard  
- TODO: Payment analytics and reports
- TODO: Guest session management UI
- TODO: Revenue tracking by package/day

### Phase 4: Security Hardening
- TODO: Rate limiting on payment API
- TODO: Phone number validation via SMS OTP
- TODO: Fraud detection for rapid payments

---

## Support

**Testing Issues**: Contact dev@apperstar.dev
**M-Pesa Daraja Issues**: https://developer.safaricom.co.ke/support
**PHPNuxBill Issues**: https://github.com/hotspotbilling/phpnuxbill/issues

**WhatsApp Support**: https://wa.link/0sib65
