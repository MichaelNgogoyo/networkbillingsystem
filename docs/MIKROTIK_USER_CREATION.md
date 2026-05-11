# MikroTik User Creation - Implementation Guide

## Current Status

**Payment Flow**: ✅ COMPLETE
- Hotspot login form captures phone + package
- M-Pesa STK Push initiated via Daraja API
- Callback received and processed
- Credentials generated and stored

**What's Missing**: ❌ MikroTik User Creation
- Callback handler creates credentials but doesn't add them to router
- Need to integrate `system/autoload/Mikrotik.php` class
- Users can see credentials but can't use them yet

---

## What Needs to Be Done

In `/system/daraja-callback.php`, the function `createMikrotikUserForPayment()` needs to:

1. Connect to MikroTik router via SSH/API
2. Create new user in `/ip/hotspot/user`
3. Set user profile based on package
4. Set expiry time
5. Return confirmation

---

## Current Code (TODO)

**File**: `/system/daraja-callback.php` (lines ~90-120)

```php
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
        
    } catch (Exception $e) {
        error_log('Failed to create MikroTik user: ' . $e->getMessage());
        return null;
    }
}
```

---

## Integration Steps

### Step 1: Load MikroTik Class

Add to `/system/daraja-callback.php` at top:

```php
require_once(__DIR__ . '/autoload/Mikrotik.php');
```

### Step 2: Update `createMikrotikUserForPayment()` Function

Replace the TODO section with:

```php
function createMikrotikUserForPayment($phone, $plan, $duration, $mac, $ip, $system) {
    
    try {
        // Generate unique username
        $username = 'hotspot_' . time();
        $password = generatePassword(12);
        
        // Calculate expiry time
        $durationSeconds = parseDurationToSeconds($duration);
        $expiryTime = time() + $durationSeconds;
        $expiryDate = date('Y-m-d H:i:s', $expiryTime);
        
        // Get MikroTik configuration
        $mikrotik = new Mikrotik();
        $mikrotik->connect();
        
        // Determine profile based on package system
        $profile = 'default';  // Change based on $system parameter
        if ($system === 'premium') {
            $profile = 'premium';
        } elseif ($system === 'basic') {
            $profile = 'basic';
        }
        
        // Create user on MikroTik
        $userData = [
            'name' => $username,
            'password' => $password,
            'profile' => $profile,
            'disabled' => 'false',
            'comment' => "Phone: $phone | Plan: $plan | Expires: $expiryDate"
        ];
        
        // Add user via MikroTik API/SSH
        $result = $mikrotik->api('/ip/hotspot/user/add', $userData);
        
        if (!$result) {
            throw new Exception('Failed to create user on MikroTik');
        }
        
        // Set expiry time
        $commands = [
            '/ip/hotspot/user/set',
            '=.id=*' . $username,
            '=disabled=after 1d'  // Alternative: use expiry-time if available
        ];
        $mikrotik->query($commands);
        
        $mikrotik->disconnect();
        
        return [
            'username' => $username,
            'password' => $password,
            'expires_at' => $expiryDate,
            'expiry_timestamp' => $expiryTime,
            'plan' => $plan,
            'duration' => $duration,
            'profile' => $profile
        ];
        
    } catch (Exception $e) {
        error_log('Failed to create MikroTik user: ' . $e->getMessage());
        return null;
    }
}
```

### Step 3: Check Existing Mikrotik Class

**Location**: `/system/autoload/Mikrotik.php`

**Methods to use**:
- `connect()` - Establish connection
- `api($path, $data)` - Execute API command
- `query($commands)` - Execute raw API query
- `disconnect()` - Close connection

**Example Usage** (from existing code):
```php
$mikrotik = new Mikrotik();
$mikrotik->connect();
$result = $mikrotik->api('/ip/address/print');
$mikrotik->disconnect();
```

### Step 4: Test Integration

After implementing:

1. **Test Trial Access** (should work immediately):
   ```bash
   # Access hotspot login page
   # Click "TRIAL - Free 1H"
   # Enter phone: 254712345678
   # Should get instant credentials
   # Test login to verify MikroTik user exists
   ```

2. **Test M-Pesa Payment**:
   ```bash
   # Complete M-Pesa payment via STK Push
   # Wait for callback
   # Should see payment marked as "completed" in database
   # Test login with new credentials
   ```

3. **Verify MikroTik User Creation**:
   ```bash
   # SSH to MikroTik router
   ssh admin@192.168.88.1
   
   # List created hotspot users
   [admin@MikroTik] /ip/hotspot/user> print
   
   # You should see: hotspot_1234567890
   ```

---

## Alternative: Using SSH Instead of API

If MikroTik class doesn't work, use SSH directly:

```php
function createMikrotikUserForPayment($phone, $plan, $duration, $mac, $ip, $system) {
    
    try {
        $username = 'hotspot_' . time();
        $password = generatePassword(12);
        $durationSeconds = parseDurationToSeconds($duration);
        $expiryTime = time() + $durationSeconds;
        
        // SSH command to add user
        $sshCommand = sprintf(
            'ssh -o ConnectTimeout=5 admin@192.168.88.1 ' .
            '"/ip/hotspot/user/add name=%s password=%s profile=default disabled=false"',
            escapeshellarg($username),
            escapeshellarg($password)
        );
        
        // Execute with password via expect (if configured)
        // Or use SSH key authentication
        $output = shell_exec($sshCommand . ' 2>&1');
        
        if ($output === null || strpos($output, 'error') !== false) {
            throw new Exception('SSH command failed: ' . $output);
        }
        
        return [
            'username' => $username,
            'password' => $password,
            'expires_at' => date('Y-m-d H:i:s', $expiryTime)
        ];
        
    } catch (Exception $e) {
        error_log('SSH user creation failed: ' . $e->getMessage());
        return null;
    }
}
```

---

## MikroTik Configuration

### User Profiles to Create

In MikroTik router, create these profiles for different package tiers:

```
[admin@MikroTik] /ip/hotspot/user/profile> add name=default rate-limit=512k/512k
[admin@MikroTik] /ip/hotspot/user/profile> add name=premium rate-limit=2M/2M
[admin@MikroTik] /ip/hotspot/user/profile> add name=basic rate-limit=256k/256k
```

Then in code, assign profile based on `$system`:
```php
if ($system === 'heavy_streaming') {
    $profile = 'premium';
} elseif ($system === 'quick_tasks') {
    $profile = 'basic';
} else {
    $profile = 'default';
}
```

### User Expiry Setup

MikroTik hotspot has two ways to handle expiry:

**Option 1: Manual Cleanup (Simple)**
- Users disabled manually by cron job
- Cron checks `expires_at` timestamp

**Option 2: Automatic (Better)**
- Use MikroTik's built-in expiry features
- Set via API: `disable-after-time` parameter

Check MikroTik documentation for your version.

---

## Database Updates After Integration

Once user is created on MikroTik, also update database:

```php
// In createMikrotikUserForPayment() return
$dbUpdate = $_DB->prepare("
    INSERT INTO hotspot_guest_sessions 
    (phone, username, password_hash, plan, duration, system, 
     mac_address, ip_address, status, created_at, expires_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), ?)
");

$dbUpdate->bind_param(
    'sssssssss',
    $phone,
    $username,
    md5($password),  // Hash password before storing
    $plan,
    $duration,
    $system,
    $mac,
    $ip,
    $expiryDate
);

$dbUpdate->execute();
```

---

## Expiry Cron Job (TODO Phase 2)

Create `/system/cron_hotspot_expiry.php`:

```php
<?php
/**
 * Cron job to expire hotspot sessions
 * Run every 5 minutes via MikroTik scheduler
 */

require_once(__DIR__ . '/boot.php');

// Find expired sessions
$stmt = $_DB->prepare("
    SELECT * FROM hotspot_guest_sessions 
    WHERE status = 'active' 
    AND expires_at < NOW()
    LIMIT 100
");

$stmt->execute();
$result = $stmt->get_result();

while ($session = $result->fetch_assoc()) {
    // Mark as expired
    $update = $_DB->prepare("
        UPDATE hotspot_guest_sessions 
        SET status = 'expired' 
        WHERE id = ?
    ");
    $update->bind_param('i', $session['id']);
    $update->execute();
    
    // Remove from MikroTik (optional)
    // $mikrotik->api('/ip/hotspot/user/remove', ['=.id' => $session['username']]);
}

echo "Expired " . $result->num_rows . " sessions\n";
?>
```

Schedule in MikroTik:
```
[admin@MikroTik] /system/scheduler> add name=hotspot-cleanup \
  on-event="/ip ssh admin 192.168.88.1 'php /path/to/cron_hotspot_expiry.php'" \
  interval=5m
```

---

## Testing Checklist

- [ ] MikroTik class method calls work
- [ ] SSH command creates user on router
- [ ] User profile assigned correctly
- [ ] Trial user created immediately
- [ ] Paid user created after M-Pesa callback
- [ ] User can login with provided credentials
- [ ] Access works at correct speed tier
- [ ] User expires after duration
- [ ] Database records created
- [ ] Failed MikroTik creation logged properly

---

## Debugging

### Check if User Was Created

```bash
# SSH to MikroTik
ssh admin@192.168.88.1

# List hotspot users
/ip/hotspot/user> print
# or
/ip/hotspot/user> print where name~"hotspot_"

# Check specific user
/ip/hotspot/user> print where name=hotspot_1234567890

# Check user details
/ip/hotspot/user/print detail where name=hotspot_1234567890
```

### Check PHP Logs

```bash
tail -f /var/log/apache2/error.log | grep "MikroTik\|hotspot"
```

### Check Database

```sql
SELECT * FROM hotspot_guest_sessions 
WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY created_at DESC;
```

---

## Next Steps

1. **Implement**: Add MikroTik class integration to callback handler
2. **Test**: Verify user creation works
3. **Deploy**: Push to staging/production
4. **Monitor**: Check logs for errors
5. **Phase 2**: Add cron expiry job
6. **Phase 3**: Admin dashboard for user management

---

## Support

**Questions**: info@apperstar.dev
**MikroTik API Docs**: https://wiki.mikrotik.com/wiki/API
**PHPNuxBill Issues**: https://github.com/hotspotbilling/phpnuxbill/issues
