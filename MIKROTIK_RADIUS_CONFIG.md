# MikroTik RADIUS Configuration Guide

**Objective**: Configure MikroTik RouterOS to authenticate WiFi hotspot users against PHPNuxBill using RADIUS protocol.

---

## 🎯 Overview

MikroTik will forward hotspot authentication requests to PHPNuxBill's RADIUS server. Users will:
1. Connect to WiFi ("Hostel Wifi" SSID)
2. Enter their phone number (username) and PIN (password)
3. MikroTik sends RADIUS request to PHPNuxBill (91.189.120.113)
4. PHPNuxBill validates credentials against tbl_customers
5. RADIUS response returns ACCEPT/REJECT
6. MikroTik grants or denies internet access

---

## 📋 Prerequisites

- MikroTik RouterOS access (192.168.88.1, admin/1234)
- PHPNuxBill server IP: 91.189.120.113
- Network connectivity between MikroTik and PHPNuxBill (port 1812/UDP open)
- Hotspot profile already configured with users or RADIUS

---

## 🔧 Configuration Steps

### Step 1: SSH Into MikroTik

```bash
ssh admin@192.168.88.1
# Enter password: 1234
```

Or use WebFig (http://192.168.88.1:80)

---

### Step 2: Add RADIUS Server

Connect to MikroTik and run:

```mikrotik
/radius add name="PHPNuxBill" address=91.189.120.113 secret="phpnuxbill_radius_secret" service=login timeout=3s
```

**Parameters Explained**:
- `name="PHPNuxBill"` - Server name (for reference)
- `address=91.189.120.113` - PHPNuxBill server IP
- `secret="phpnuxbill_radius_secret"` - **IMPORTANT**: Shared secret (must match on both sides)
- `service=login` - Use for login authentication
- `timeout=3s` - Wait 3 seconds for RADIUS response

**Verify**:
```mikrotik
/radius print
```

Should show:
```
Flags: * = disabled
0 X name="PHPNuxBill" address=91.189.120.113 timeout=3s service=login
```

---

### Step 3: Configure Hotspot Profile to Use RADIUS

First, check existing hotspot profiles:

```mikrotik
/ip hotspot profile print
```

Output example:
```
Flags: * = disabled, X = all-caps-name
0 name="hap" hotspot-address=192.168.88.1 idle-timeout=none keepalive-timeout=2h login-by=cookie,http-chap ...
```

Update the profile to use RADIUS:

```mikrotik
/ip hotspot profile set hap auth-methods=radius,http-chap radius-server=PHPNuxBill
```

**Parameters**:
- `auth-methods=radius,http-chap` - Try RADIUS first, fallback to HTTP CHAP if RADIUS fails
- `radius-server=PHPNuxBill` - Reference the RADIUS server we created in Step 2

**Verify**:
```mikrotik
/ip hotspot profile print detail
```

Look for:
```
auth-methods: radius,http-chap
radius-server: *0 name="PHPNuxBill"
```

---

### Step 4: Configure Hotspot User

If your hotspot requires users to log in, ensure at least one user exists (even if RADIUS will handle most):

```mikrotik
/ip hotspot user print
```

If using RADIUS authentication only, users don't need to be pre-created in MikroTik - they'll be authenticated against PHPNuxBill.

---

### Step 5: Enable Hotspot Logging (Optional but Recommended)

```mikrotik
/ip hotspot set [find default=yes] logging-enabled=yes
```

Check logs:
```mikrotik
/log print where topics~"hotspot"
```

---

## ✅ Verification Steps

### 1. Test RADIUS Connectivity

From MikroTik terminal:

```mikrotik
/radius test address=91.189.120.113 secret="phpnuxbill_radius_secret" username="254111222333" password="5678"
```

**Success Response**:
```
 radius: sent query, round-trip-time: 45ms
 status: 2 (AccessAccept)
```

**Failure Response**:
```
 radius: connect() timeout
```

If timeout → Check firewall rules, ensure port 1812/UDP is open from MikroTik to 91.189.120.113

---

### 2. Test WiFi Hotspot Login

**On your phone/laptop**:
1. Connect to "Hostel Wifi" SSID
2. Open browser (should redirect to login page if not logged in)
3. Enter credentials:
   - **Username**: 254111222333 (phone number)
   - **Password**: 5678 (PIN)
4. Click Login

**Expected behavior**:
- ✅ Login succeeds → Redirected to internet/splash page
- ❌ Login fails → "Invalid credentials" or "Authentication failed"

---

### 3. Check MikroTik Hotspot Logs

```mikrotik
/log print where topics~"hotspot" count=20
```

You should see entries like:
```
11:30:45 hotspot login: user=254111222333 ip=192.168.1.50 mac=AA:BB:CC:DD:EE:FF
11:30:46 hotspot login: 254111222333 authenticated
11:30:46 hotspot status: user=254111222333 uptime=0s sent=0 received=0
```

---

### 4. Verify Database Records

SSH into PHPNuxBill server and check:

```bash
docker exec -i mysql mysql -unuxbill -p'Mackavelli2#' nuxbill -e \
  "SELECT username, created_at, last_login FROM tbl_customers WHERE username='254111222333';"
```

Should show the user with updated `last_login` timestamp.

---

## 🐛 Troubleshooting

### Issue: "RADIUS timeout"
**Cause**: MikroTik cannot reach PHPNuxBill server  
**Solution**:
1. Ping from MikroTik: `ping 91.189.120.113`
2. Check firewall: `/ip firewall filter print` - ensure port 1812/UDP is allowed
3. Verify PHPNuxBill server is running: `docker ps` on 91.189.120.113

### Issue: "RADIUS authentication failed"
**Cause**: Shared secret mismatch or user not found  
**Solution**:
1. Verify shared secret matches: `echo "phpnuxbill_radius_secret" | sha256sum`
2. Ensure user exists: Test with known credentials (254111222333 / 5678)
3. Check RADIUS server logs on PHPNuxBill: `docker logs nuxbill | grep -i radius`

### Issue: "Authentication failed" on WiFi login
**Cause**: MikroTik received REJECT from RADIUS  
**Solution**:
1. Verify credentials are correct (phone number format: 254XXXXXXXXX, PIN: 4 digits)
2. Check user hasn't already used trial (MAC prevention): Test with new phone number
3. Verify trial plan is active: Check tbl_plans where id=9999 on PHPNuxBill

### Issue: "User already exists / MAC blocked"
**Cause**: Device already used trial within last 30 days  
**Solution**:
1. Use a different phone number + new MAC address for testing
2. Or wait 30 days from `expires_date` in tbl_hotspot_mac_trial
3. Or admin can manually clear: `DELETE FROM tbl_hotspot_mac_trial WHERE mac_address='AA:BB:CC:DD:EE:FF';`

---

## 📊 Test Scenarios

### Scenario 1: Brand New User (Should Get Trial)
```
Phone: 254777888999
PIN: 1234 (or any 4 digits)
MAC: Any MAC address

Expected: ✅ Gets 10-minute trial internet access
```

### Scenario 2: Same Device, Different Phone (Should Get Trial)
```
Phone: 254111222333
PIN: 5678
MAC: AA:BB:CC:DD:EE:FF (different from Scenario 1)

Expected: ✅ Gets 10-minute trial internet access
```

### Scenario 3: Same MAC, Different Phone (Should Be Blocked)
```
Phone: 254555666777
PIN: 9999
MAC: BB:CC:DD:EE:FF:00 (same as Scenario 2)

Expected: ❌ "Device already used trial" - Blocked for 30 days
```

### Scenario 4: Returning User (PIN Auth Without Trial)
```
Phone: 254111222333 (existing user)
PIN: 5678 (existing PIN)
MAC: Can be anything

Expected: ✅ Logs in without trial, uses existing plan
```

---

## 🔐 Security Notes

1. **Change Shared Secret**: Replace "phpnuxbill_radius_secret" with a strong random value
   ```bash
   openssl rand -base64 32
   # Output: V8pK9mN2xYzQ7lR4tS3uW6vJ1kHgFdEbCaP5oI8nL+M=
   ```

2. **Update on Both Sides**:
   - MikroTik: `/radius set PHPNuxBill secret="[NEW_SECRET]"`
   - PHPNuxBill: Configure in `config.php` (if needed)

3. **Firewall Rules**: Only allow RADIUS (1812/UDP) from your MikroTik IP:
   ```bash
   # On VPS hosting PHPNuxBill
   ufw allow from 192.168.88.0/24 to any port 1812 proto udp
   ```

---

## 📝 Configuration Checklist

- [ ] RADIUS server added to MikroTik (`/radius add ...`)
- [ ] RADIUS server connectivity tested (`/radius test ...`)
- [ ] Hotspot profile updated with RADIUS (`auth-methods=radius,http-chap`)
- [ ] WiFi hotspot login tested with credentials
- [ ] MikroTik hotspot logs show successful authentication
- [ ] Database shows user login in `tbl_customers.last_login`
- [ ] MAC prevention tested (same MAC blocked on 2nd attempt)
- [ ] Admin panel accessible to view users

---

## 🚀 Quick Reference Commands

```mikrotik
# Add RADIUS server
/radius add name="PHPNuxBill" address=91.189.120.113 secret="phpnuxbill_radius_secret" service=login

# List RADIUS servers
/radius print

# Configure hotspot profile
/ip hotspot profile set hap auth-methods=radius,http-chap radius-server=PHPNuxBill

# Test RADIUS
/radius test address=91.189.120.113 secret="phpnuxbill_radius_secret" username="254111222333" password="5678"

# Check hotspot logs
/log print where topics~"hotspot" count=50

# Enable hotspot logging
/ip hotspot set [find default=yes] logging-enabled=yes

# View active hotspot users
/ip hotspot active print
```

---

## 📞 Support

If configuration fails:
1. Verify all credentials are correct
2. Check network connectivity: `ping 91.189.120.113` from MikroTik
3. Review PHPNuxBill container logs: `docker logs nuxbill`
4. Verify database is accessible: `mysql -u root -p -e "SELECT * FROM nuxbill.tbl_customers;"`

---

**Last Updated**: June 1, 2026  
**PHPNuxBill Server**: 91.189.120.113  
**MikroTik Address**: 192.168.88.1  
**Protocol**: RADIUS (UDP 1812)
