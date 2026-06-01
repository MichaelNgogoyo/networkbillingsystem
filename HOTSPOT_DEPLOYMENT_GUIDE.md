# PHPNuxBill Hotspot Architecture Refactor - Deployment Guide

## Overview
This refactor migrates from custom `hotspot_users` tables to PHPNuxBill's native architecture:
- **Users**: tbl_customers (phone as username, PIN as password)
- **Plans**: tbl_plans (pre-configured trial plan)
- **Trial Tracking**: tbl_user_recharges (links customers to plans)
- **MAC Blocking**: tbl_hotspot_mac_trial (30-day trial prevention)
- **Authentication**: radius.php (existing RADIUS endpoint)

## Changes Summary

### 1. API Endpoint Changes
**File**: `/system/hotspot-simple-api.php`

**Old Endpoints** (DEPRECATED):
- `select-package` - Queued MikroTik sync
- `check-password` - Polled for generated password

**New Endpoints** (RADIUS-NATIVE):
- `check-phone` - Validates phone exists in tbl_customers
- `verify-pin` - Authenticates against tbl_customers.password (bcrypt)
- `create-user` - Creates customer + trial plan + MAC tracking
- `check-trial-used` - Validates MAC address isn't in 30-day cooldown

**Key Changes**:
- Returns phone/PIN directly (RADIUS validates)
- No password generation or sync wait
- MAC-based 30-day trial prevention
- Uses tbl_user_recharges for plan assignment

### 2. Database Schema Changes
**File**: `/install/migrations/2026-06-01-create-trial-system.sql`

**NEW TABLES**:
```sql
CREATE TABLE tbl_hotspot_mac_trial (
  id INT AUTO_INCREMENT PRIMARY KEY,
  mac_address VARCHAR(17) UNIQUE NOT NULL,
  customer_id INT NOT NULL,
  used_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  expires_date DATETIME NOT NULL,
  status ENUM('active', 'expired')
)
```

**NEW PLAN**:
- Plan ID: 9999
- Name: "Trial - 10 Minutes Free"
- Type: Hotspot
- Time Limit: 10 minutes
- Price: 0
- RADIUS enabled: Yes

**DEPRECATED TABLES** (safe to drop after verification):
- hotspot_users
- hotspot_user_sessions
- hotspot_guest_sessions

### 3. Admin Panel Changes
**File**: `/system/controllers/hotspot-users.php`

**Data Source**: tbl_customers (filtered by service_type='Hotspot')

**Actions**:
- list: Shows all hotspot customers with search/sort
- view: Shows customer profile + active plans + MAC trial history
- delete: Removes customer and all related records
- reset-trial: Clears MAC trial list (allows device to retry)

**Templates Updated**:
- list.tpl: Uses username (phone), fullname, email, status
- view.tpl: Shows tbl_user_recharges and tbl_hotspot_mac_trial data

## Deployment Steps

### Step 1: Run Database Migration
```bash
# SSH into production server
ssh user@91.189.120.113

# Run migration via web interface
curl -X POST https://91.189.120.113/system/run-migration.php

# OR via CLI with PHP
php /var/www/html/system/run-migration.php
```

**What it does**:
- Creates tbl_hotspot_mac_trial table
- Creates trial plan (ID 9999) if not exists
- Adds index on tbl_customers.phonenumber

### Step 2: Deploy Code Changes
```bash
# From local workstation
git add -A
git commit -m "feat: Refactor hotspot to use native RADIUS architecture"
git push origin main

# CI/CD automatically deploys to production
```

**Files Changed**:
- system/hotspot-simple-api.php (API endpoints)
- system/controllers/hotspot-users.php (admin controller)
- ui/ui/admin/hotspot-users/list.tpl (list template)
- ui/ui/admin/hotspot-users/view.tpl (detail template)
- system/run-migration.php (new migration runner)
- install/migrations/2026-06-01-create-trial-system.sql (new migration)

### Step 3: Clear Template Cache
```bash
ssh user@91.189.120.113
rm -rf /var/www/html/ui/cache/*
```

### Step 4: Update MikroTik FreeRADIUS Configuration
**On MikroTik RouterOS @ 192.168.88.1**:

```
/ip hotspot profile
print  # Note the profile name (default: default)

/ip hotspot access-profile
# Ensure it has RADIUS enabled and configured

/interface hotspot
print  # Note interface name and settings

# Configure RADIUS Server
/radius
add service=login server=91.189.120.113 secret="YOUR_SHARED_SECRET"
print  # Verify it's added
```

**Configure Hotspot to use RADIUS**:
```
/ip hotspot user
# Remove old test users (optional)

/radius
# Verify server points to API server IP: 91.189.120.113
```

**Verify RADIUS Server Listening**:
```bash
# On API server (91.189.120.113):
sudo netstat -ulnp | grep 1812  # RADIUS port
```

### Step 5: Test Full Authentication Flow

**Test 1: User Registration**
```bash
curl -X POST https://91.189.120.113/system/hotspot-simple-api.php \
  -d "action=check-phone&phone=254777888999"

# Response: {"status":"success","exists":false,"message":"..."}
```

**Test 2: Create User**
```bash
curl -X POST https://91.189.120.113/system/hotspot-simple-api.php \
  -d "action=create-user&phone=254777888999&pin=1234&device_mac=AA:BB:CC:DD:EE:FF"

# Response: {"status":"success","customer_id":123,"username":"254777888999","password":"$2y$10...bcrypt"}
```

**Test 3: Verify PIN**
```bash
curl -X POST https://91.189.120.113/system/hotspot-simple-api.php \
  -d "action=verify-pin&phone=254777888999&pin=1234"

# Response: {"status":"success","username":"254777888999","password":"$2y$10...bcrypt"}
```

**Test 4: MikroTik Hotspot Login**
1. Connect to "Hostel Wifi" SSID
2. Enter credentials:
   - Username: `254777888999` (phone)
   - Password: `1234` (PIN)
3. Should authenticate via RADIUS and connect
4. Should have 10 minutes internet access (trial)

**Test 5: MAC-based Trial Prevention**
1. Connect same device again with new phone/PIN
2. Should receive error: "This device already used trial"
3. Check database: `SELECT * FROM tbl_hotspot_mac_trial WHERE mac_address='AA:BB:CC:DD:EE:FF'`

**Test 6: Admin Panel**
1. Login to admin: https://91.189.120.113/admin/
2. Navigate to: Admin → WiFi Users
3. Should list customer created in Test 2
4. Click View to see:
   - Profile (phone, name, status)
   - Active Plans (trial plan with 10-min validity)
   - MAC Trial History (MAC address with 30-day expiry)
5. Test "Reset Trial" button to clear MAC list
6. Test "Delete User" to remove customer

## Migration from Old System

### Data Migration (if needed)
If you want to migrate existing hotspot_users to tbl_customers:

```sql
-- BACKUP FIRST!
INSERT INTO tbl_customers (username, password, phonenumber, email, fullname, service_type, status, created_at)
SELECT phone, pin, phone, email, CONCAT('Trial User ', phone), 'Hotspot', 'Active', created_at
FROM hotspot_users
WHERE NOT EXISTS (
  SELECT 1 FROM tbl_customers WHERE username = hotspot_users.phone
);
```

### Cleanup (AFTER verification)
```sql
-- Only after confirming all data is in tbl_customers and everything works!
DROP TABLE IF EXISTS hotspot_guest_sessions;
DROP TABLE IF EXISTS hotspot_user_sessions;
DROP TABLE IF EXISTS hotspot_users;
```

## Rollback Plan

If issues arise, revert to old system:

```bash
git revert HEAD~1
git push origin main
# CI/CD deploys reverted code
```

**Old API still works**, but will error on missing hotspot_users table.

## Key Differences from Old System

| Feature | Old | New |
|---------|-----|-----|
| User Storage | hotspot_users | tbl_customers |
| Username | Random (trial_XXXXX_TIMESTAMP) | Phone (254XXXXXXXXX) |
| Password Field | PIN (bcrypt) in pin column | PIN (bcrypt) in password column |
| Authentication | Custom API + password polling | RADIUS (radius.php) |
| Trial Duration | Variable | 10 minutes (configurable in plan) |
| Trial Prevention | trial_used flag | MAC address (30-day cooldown) |
| Admin Panel | Custom tables | Native customers table |
| Plan Assignment | Implicit in guest session | tbl_user_recharges (explicit) |

## Troubleshooting

### "User not found" on login
**Cause**: Customer not in tbl_customers or wrong phone format
**Fix**: Verify phone is 254XXXXXXXXX format and customer exists

### "Invalid PIN"
**Cause**: PIN doesn't match bcrypt hash or wrong PIN entered
**Fix**: Reset user and try again with new PIN

### "This device already used trial"
**Cause**: MAC in tbl_hotspot_mac_trial within 30-day window
**Fix**: Admin can click "Reset Trial" button to clear MAC list or wait 30 days

### "This device already used trial" but it shouldn't
**Cause**: Stale MAC record or device MAC changed
**Fix**: Check tbl_hotspot_mac_trial, manually delete if needed

### MikroTik not connecting to RADIUS
**Cause**: RADIUS server IP wrong, port not listening, firewall blocked
**Fix**: 
```bash
# Check RADIUS listening
sudo netstat -ulnp | grep 1812

# Test connectivity
telnet 91.189.120.113 1812

# Check RADIUS config in MikroTik
/radius print
```

### No internet after hotspot auth
**Cause**: MikroTik hotspot profile not configured with routing
**Fix**: Configure hotspot profile to route traffic (beyond this guide's scope)

## Support

For issues:
1. Check system logs: `tail -f /var/log/php-fpm.log`
2. Check API errors: `curl -v https://91.189.120.113/system/hotspot-simple-api.php?action=check-phone -d 'phone=254777888999'`
3. Check database: Query tbl_customers, tbl_user_recharges, tbl_hotspot_mac_trial
4. Check MikroTik: Review RADIUS logs on router
