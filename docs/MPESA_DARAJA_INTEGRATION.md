# Hotspot Payment Integration - Complete Overview

## What Has Been Built

A complete **hotspot login → M-Pesa payment** system for PHPNuxBill that allows users to purchase WiFi access directly from the MikroTik login page.

### Components

#### 1. **Frontend - Hotspot Login Page** ✅
**Location**: `/mikrotik-hotspot/login.html` (uploaded to MikroTik router)

**Features**:
- Dark theme UI with package selection
- 8 WiFi package options (Free Trial → Monthly)
- Phone number input (254XXXXXXXXX format)
- Usage profile recommendations
- Form validation with MikroTik variables capture
- Mobile responsive design
- Error handling and success messaging

**MikroTik Variables Used**:
- `$(link-login-only)` - Login form action
- `$(link-orig-url)` - Post-auth redirect URL
- `$(ip)` - User device IP
- `$(mac)` - User device MAC  
- `$(mac-esc)` - Escaped MAC for URLs
- `$(identity)` - Router identity

**Form Posts To**: `https://network.apperstar.dev/api/hotspot-payment.php`

---

#### 2. **Backend Payment API** ✅
**Location**: `/system/hotspot-payment-api.php`

**Responsibilities**:
- Receive phone + package data from hotspot login form
- Validate inputs (phone format, package data)
- Route to trial flow (instant) or M-Pesa flow
- Initiate Daraja STK Push for paid packages
- Return JSON response with payment status/credentials

**Key Functions**:
- `handleTrialPackage()` - Instant free access
- `initiateM_PesaPayment()` - STK Push request
- `getDarajaConfig()` - Fetch M-Pesa credentials from database
- `storeHotspotPaymentSession()` - Save payment for tracking

**Request Format**:
```
POST /api/hotspot-payment.php
Content-Type: application/x-www-form-urlencoded

phone=254712345678
package={"plan":"1 Hour","price":10,"duration":"1H","system":"unlimited","usage":"General"}
device_mac=00:11:22:33:44:55
device_ip=192.168.88.100
router=MikroTik-hAP-lite
redirect_url=http://192.168.88.1/login/?dst=http://google.com
```

**Response Format (Success)**:
```json
{
  "status": "success",
  "type": "mpesa_pending",
  "message": "M-Pesa prompt sent to your phone",
  "package": "1 HOUR",
  "amount": "10 KES",
  "duration": "1H",
  "checkout_id": "WS_CO_DMZ_123456789",
  "next_step": "Enter your M-Pesa PIN when prompted. You will be connected automatically after payment.",
  "expires_in": "2 minutes"
}
```

**Response Format (Trial)**:
```json
{
  "status": "success",
  "type": "trial",
  "message": "Trial access granted - enjoy 1H of free WiFi!",
  "username": "trial_abc123_1234567890",
  "password": "RandomPassword123!",
  "expires_at": "2024-01-15 14:30:45"
}
```

---

#### 3. **M-Pesa Callback Handler** ✅
**Location**: `/system/daraja-callback.php`

**Responsibilities**:
- Receive STK Push callback from Safaricom Daraja API
- Extract payment details (receipt, amount, timestamp)
- Create temporary MikroTik user credentials
- Store payment completion in database
- Provide credentials for user WiFi access

**Callback Receiver**:
- URL: `https://network.apperstar.dev/api/daraja-callback.php`
- Method: POST
- Content-Type: application/json
- Source: Safaricom (Daraja) servers

**Key Functions**:
- `findPaymentSession()` - Lookup pending payment
- `createMikrotikUserForPayment()` - Generate credentials
- `markPaymentAsCompleted()` - Update database
- `markPaymentAsFailed()` - Handle payment failures

**Callback Payload Structure**:
```json
{
  "Body": {
    "stkCallback": {
      "CheckoutRequestID": "WS_CO_DMZ_123456789",
      "ResultCode": 0,
      "ResultDesc": "The service request has been processed successfully",
      "CallbackMetadata": {
        "Item": [
          { "Name": "Amount", "Value": 10 },
          { "Name": "MpesaReceiptNumber", "Value": "LHD7AQBD60" },
          { "Name": "TransactionDate", "Value": 20240115143045 },
          { "Name": "PhoneNumber", "Value": 254712345678 }
        ]
      }
    }
  }
}
```

---

#### 4. **Database Schema** ✅
**Location**: `/install/hotspot-payment-schema.sql`

**Tables Created**:

**`hotspot_payment_sessions`** - Tracks all payment attempts
```sql
- id, phone, amount, plan, duration, system
- reference, checkout_id, mac_address, ip_address
- status (pending|completed|failed|expired)
- mpesa_receipt, transaction_date, transaction_amount
- hotspot_username, hotspot_password
- failure_reason
- created_at, updated_at
```

**`hotspot_guest_sessions`** - Active temporary users
```sql
- id, phone, username, password_hash
- plan, duration, system
- mac_address, ip_address, router_name
- status (active|expired|revoked)
- data_used, created_at, expires_at, last_activity
```

**`hotspot_trial_sessions`** - Trial users
```sql
- id, phone, username, password_hash
- mac_address, ip_address, duration
- status, created_at, expires_at
```

---

## Complete Payment Flow (Step by Step)

### User's Journey

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. USER OPENS HOTSPOT LOGIN PAGE                                 │
│    URL: http://192.168.88.1/login/                               │
│    → MikroTik serves: /login.html                                 │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. USER SELECTS PACKAGE & ENTERS PHONE NUMBER                   │
│    → Phone: 254712345678                                         │
│    → Package: "1 HOUR - 10 KES"                                  │
│    → Click: "Buy WiFi"                                           │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. FORM SUBMITS TO PAYMENT API                                   │
│    POST → https://network.apperstar.dev/api/hotspot-payment.php  │
│    Data: {phone, package, mac, ip, router, redirect_url}        │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. PAYMENT API VALIDATES & PROCESSES                             │
│    ✓ Phone format valid (254XXXXXXXXX)                           │
│    ✓ Package data complete                                       │
│    → Gets M-Pesa Daraja credentials from database                │
│    → Generates access token via OAuth                            │
│    → Creates payment reference (WIFI_[TIMESTAMP]_[MAC])         │
└─────────────────────────────────────────────────────────────────┘
                              ↓
         ┌────────────────────┴────────────────────┐
         ↓                                          ↓
    [TRIAL PACKAGE]              [PAID PACKAGE - M-PESA]
    (Price = 0 KES)              (Price > 0 KES)
         ↓                                          ↓
    ┌────────────────┐          ┌──────────────────────┐
    │ Create Instant │          │ Initiate STK Push    │
    │ Credentials    │          │ Via Daraja API       │
    │ trial_xxx_yyyy │          │                      │
    │ Password: rand │          │ POST:                │
    │               │          │ https://sandbox...   │
    │ Return to user │          │ /mpesa/stkpush/...   │
    └────────────────┘          │                      │
         ↓                       │ Response:            │
    ┌─────────────────────┐     │ CheckoutRequestID    │
    │ JSON SUCCESS        │     └──────────────────────┘
    │ username: trial_... │               ↓
    │ password: xxxxx     │     ┌──────────────────────┐
    │ expires_at: 15:30   │     │ Store Payment Session│
    │                     │     │ in database          │
    │ → User can login    │     │ (status=pending)     │
    │   immediately!      │     └──────────────────────┘
    └─────────────────────┘               ↓
                              ┌──────────────────────────────┐
                              │ JSON SUCCESS (PENDING)        │
                              │ message: "M-Pesa prompt..."   │
                              │ checkout_id: WS_CO_DMZ_xxx    │
                              │ expires_in: "2 minutes"       │
                              │                               │
                              │ → Wait for M-Pesa callback    │
                              └──────────────────────────────┘
```

### M-Pesa Payment & Callback

```
         ┌─────────────────────────────────────────┐
         │ 5. SAFARICOM SENDS STK PUSH TO PHONE    │
         │    User sees: [M-Pesa Prompt]           │
         │    "Enter PIN to pay 10 KES"            │
         └─────────────────────────────────────────┘
                        ↓
         ┌─────────────────────────────────────────┐
         │ 6. USER ENTERS M-PESA PIN & PAYS        │
         │    Transaction: 254712345678 → 174379   │
         │    Amount: 10 KES                       │
         │    Ref: WIFI_[timestamp]_[mac]         │
         └─────────────────────────────────────────┘
                        ↓
         ┌─────────────────────────────────────────┐
         │ 7. SAFARICOM SENDS CALLBACK             │
         │    POST → https://network.apperstar.dev│
         │           /api/daraja-callback.php      │
         │    Content: {CheckoutRequestID, Status} │
         │    Result: 0 = Success                  │
         └─────────────────────────────────────────┘
                        ↓
         ┌─────────────────────────────────────────┐
         │ 8. CALLBACK HANDLER PROCESSES PAYMENT   │
         │    ✓ Find payment session by ID         │
         │    ✓ Parse M-Pesa receipt #             │
         │    ✓ Create MikroTik user:              │
         │      - username: hotspot_1234567890     │
         │      - password: RandomPassword123      │
         │      - expires: [duration]              │
         │    ✓ Save to hotspot_guest_sessions    │
         │    ✓ Update payment to "completed"      │
         └─────────────────────────────────────────┘
                        ↓
         ┌─────────────────────────────────────────┐
         │ 9. USER RECEIVES CREDENTIALS            │
         │    (Via SMS, email, or API response)    │
         │    Username: hotspot_1234567890         │
         │    Password: RandomPassword123          │
         │    Valid for: 1 Hour                    │
         └─────────────────────────────────────────┘
                        ↓
         ┌─────────────────────────────────────────┐
         │ 10. USER CONNECTS TO HOTSPOT            │
         │     • Open WiFi networks list           │
         │     • Select "RouterName"               │
         │     • Open browser                      │
         │     • Enter credentials from step 9     │
         │     • Click "Login"                     │
         │     • ✓ Internet access granted!        │
         └─────────────────────────────────────────┘
```

---

## System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                          USER'S DEVICE                           │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ MikroTik Hotspot Login Page (HTML)                           ││
│  │ • Package selection UI                                       ││
│  │ • Phone number input form                                    ││
│  │ • Captures MikroTik variables (MAC, IP, router)             ││
│  │ • POSTs to: network.apperstar.dev/api/hotspot-payment.php   ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                              ↓ HTTPS POST
┌─────────────────────────────────────────────────────────────────┐
│                    PAYMENT API SERVER                            │
│                  network.apperstar.dev                           │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ /api/hotspot-payment-api.php                                 ││
│  │ • Validate phone + package                                   ││
│  │ • Route: Trial (instant) or M-Pesa                           ││
│  │ • Get Daraja credentials from DB                             ││
│  │ • Initiate OAuth token with Safaricom                        ││
│  │ • Send STK Push via Daraja API                               ││
│  │ • Store payment session in database                          ││
│  │ • Return JSON response                                       ││
│  └─────────────────────────────────────────────────────────────┘│
│                        ↓ (reads from)                            │
│  ┌─────────────────────────────────────────────────────────────┐│
│  │ PHPNuxBill Database                                          ││
│  │ • tbl_appconfig (M-Pesa credentials)                         ││
│  │ • hotspot_payment_sessions (track payments)                  ││
│  │ • hotspot_guest_sessions (temp users)                        ││
│  │ • hotspot_trial_sessions (trial users)                       ││
│  └─────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────┘
                              ↓ HTTPS POST
┌─────────────────────────────────────────────────────────────────┐
│                   SAFARICOM DARAJA API                           │
│              sandbox.safaricom.co.ke (or production)             │
│  • OAuth: /oauth/v1/generate                                    │
│  • STK Push: /mpesa/stkpush/v1/processrequest                   │
│  • Query: /mpesa/stkpushquery/v1/query                          │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│                      SAFARICOM NETWORK                           │
│  • Sends STK prompt to user's phone                              │
│  • User enters PIN to complete payment                           │
│  • Safaricom sends callback to payment API                       │
└─────────────────────────────────────────────────────────────────┘
                              ↓ HTTPS POST CALLBACK
                    /api/daraja-callback.php
             (Callback verification & user creation)
```

---

## Configuration Required

### In PHPNuxBill Admin Panel

**Admin Dashboard → Paymentgateway → M-Pesa**

```
Environment:         sandbox  (for testing)
                     production  (for live)

Consumer Key:        [From Daraja dev portal]
Consumer Secret:     [From Daraja dev portal]
Shortcode:           174379  (test) or your business shortcode
M-Pesa Passkey:      [From Daraja dev portal]
Callback URL:        https://network.apperstar.dev/api/daraja-callback.php
```

### Database Setup

Execute schema once during installation:

```bash
mysql -u user -p database < install/hotspot-payment-schema.sql
```

---

## Testing Checklist

- [ ] Database tables created
- [ ] M-Pesa credentials configured in admin panel
- [ ] Both API endpoints accessible via HTTPS
- [ ] Trial package creates instant credentials
- [ ] M-Pesa STK Push initiated for paid packages
- [ ] Callback received and processed
- [ ] Payment marked as completed in database
- [ ] MikroTik user created (TODO)
- [ ] User can login with provided credentials
- [ ] Access expires after specified duration

**Full Testing Guide**: See [docs/MPESA_DARAJA_TESTING.md](MPESA_DARAJA_TESTING.md)

---

## Phase 2: MikroTik Integration (TODO)

The callback handler currently creates credentials but doesn't add them to the MikroTik router yet.

**TODO**: Call `system/autoload/Mikrotik.php` to:
1. Connect to MikroTik router
2. Create user in `/ip/hotspot/user`
3. Assign profile based on package
4. Set expiry time
5. Return confirmation

---

## File Manifest

```
/ phpnuxbill
├── mikrotik-hotspot/
│   └── login.html                         ✅ Hotspot login page
├── system/
│   ├── hotspot-payment-api.php            ✅ Main payment API
│   ├── daraja-callback.php                ✅ Callback handler
│   ├── paymentgateway/
│   │   └── mpesa.php                      ✅ M-Pesa library (existing)
│   └── autoload/
│       └── Mikrotik.php                   (TODO: Integration needed)
├── install/
│   └── hotspot-payment-schema.sql         ✅ Database schema
├── docs/
│   ├── MPESA_DARAJA_TESTING.md            ✅ Testing guide
│   └── MPESA_DARAJA_INTEGRATION.md        ✅ This file
└── .git/
    └── commits: d6533a0, a8e0ab8         ✅ Tracked in version control
```

---

## Support & Next Steps

### Immediate Actions
1. Run database schema
2. Configure M-Pesa credentials
3. Deploy backend files to server
4. Test trial package
5. Test M-Pesa payment flow
6. Check testing guide for debugging

### Next Phase
- Integrate MikroTik user creation in callback
- Add cron job for expiry management
- Implement admin dashboard
- Add fraud detection

### Resources
- **Daraja Developer**: https://developer.safaricom.co.ke
- **PHPNuxBill GitHub**: https://github.com/hotspotbilling/phpnuxbill
- **Support Email**: info@apperstar.dev
- **WhatsApp Support**: https://wa.link/0sib65

---

**Status**: ✅ Complete for Phase 1 (Payment API + Callback)
**Last Updated**: 2024
**Maintained By**: Michael Ngogoyo (info@apperstar.dev)
