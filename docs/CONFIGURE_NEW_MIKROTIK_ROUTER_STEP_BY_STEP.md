# Configure New MikroTik Router Step by Step

This guide is the single source of truth for onboarding a new MikroTik router to this PHPNuxBill deployment.

It covers:

1. L2TP/IPsec tunnel setup (router -> VPS)
2. RADIUS setup (hotspot auth)
3. Hotspot profile wiring
4. Captive portal file deployment (`login.html` + `md5.js`)
5. PHPNuxBill router record setup
6. Verification and troubleshooting

All RouterOS commands below are intended to run at the base prompt:

```mikrotik
[admin@Router] >
```

## 1. Network Targets and Credentials

Use these values for this environment:

- VPS public IP: `31.97.116.56`
- Tunnel server-side IP: `10.99.0.1`
- Router tunnel IP (expected): `10.99.0.10`
- Router management IP (LAN): `192.168.88.1`
- RADIUS shared secret: `phpnuxbill_radius_secret`
- RADIUS auth/acct ports: `1812/1813` (legacy also enabled: `1645/1646`)
- Router API record in app: `10.99.0.10:8728`, user `admin`

## 2. VPS Prerequisites (must be true before router onboarding)

Verify on VPS:

1. FreeRADIUS active and listening:

```bash
systemctl is-active freeradius
ss -lunp | grep -E ':1812|:1813|:1645|:1646'
```

2. Firewall open:

```bash
ufw status numbered
```

Required inbound UDP allows:

- 1812
- 1813
- 1645
- 1646

3. Tunnel up on VPS:

```bash
ip -brief addr show ppp0
ip route | grep 10.99.0.10
```

Expected:

- `ppp0` exists
- route to `10.99.0.10` via `ppp0`

## 3. Configure L2TP/IPsec on MikroTik (RouterOS)

Create the tunnel client to VPS.

```mikrotik
/interface l2tp-client
add name=l2tp-to-vps connect-to=31.97.116.56 user=<L2TP_USER> password=<L2TP_PASS> use-ipsec=yes ipsec-secret=<IPSEC_PSK> add-default-route=no disabled=no
```

Check status:

```mikrotik
/interface l2tp-client print detail where name=l2tp-to-vps
/ip address print where interface=l2tp-to-vps
```

Expected:

- `running: yes`
- tunnel address present (client side should become `10.99.0.10` in this deployment)

## 4. Configure RADIUS for Hotspot (critical)

Important: use `service=hotspot` (not `login`).

Reset any broken entry first:

```mikrotik
/radius remove [find]
```

Add correct RADIUS profile through tunnel endpoint:

```mikrotik
/radius add service=hotspot address=10.99.0.1 secret=phpnuxbill_radius_secret authentication-port=1812 accounting-port=1813 timeout=5s src-address=10.99.0.10 protocol=udp require-message-auth=no
```

Verify:

```mikrotik
/radius print detail
```

Expected:

- `service=hotspot`
- `address=10.99.0.1`
- `src-address=10.99.0.10`
- correct secret and ports

## 5. Enable RADIUS on Hotspot Profile

```mikrotik
/ip hotspot profile set [find] use-radius=yes login-by=http-chap
```

Verify:

```mikrotik
/ip hotspot profile print detail
```

Expected:

- `use-radius=yes`
- `login-by` includes `http-chap`

## 6. Deploy Captive Portal Files to MikroTik

Upload these files into RouterOS hotspot files:

- `mikrotik-hotspot/login.html`
- `mikrotik-hotspot/md5.js`

Confirm from router file list:

```mikrotik
/file print where name~"hotspot"
```

The login page depends on RouterOS placeholders and CHAP helper.
If `md5.js` is missing, CHAP sign-in fails.

## 7. Add Router in PHPNuxBill Admin

In app admin router settings, use:

- Name: any descriptive name (for example `Hostel wifi`)
- IP/Host: `10.99.0.10:8728`
- Username: `admin`
- Password: router API password

If management API is filtered, save router anyway and validate tunnel + RADIUS first.

## 8. End-to-End Verification

### A. Router-side monitor

```mikrotik
/radius monitor [find]
```

Healthy behavior:

- `requests` increases
- `accepts` or `rejects` increases
- `timeouts` should stay low/not grow continuously
- `last-request-rtt` becomes non-zero

### B. Router-side logs

```mikrotik
/system logging add topics=radius,debug action=memory
/log print where topics~"radius"
/log print where topics~"hotspot"
```

### C. VPS-side packet capture (when debugging)

```bash
tcpdump -n -vvv -i any 'udp port 1812 or udp port 1645'
```

If capture shows `0 packets` during a real login attempt, router is not delivering traffic to VPS.

### D. Server-side auth sanity

```bash
radtest -t chap <username> <pin> 127.0.0.1 0 testing123
```

If `Access-Accept`, backend auth is fine and issue is network delivery from router.

## 9. Known Failure Patterns and Fixes

1. Symptom: `radius server is not responding`
Cause: no packets reach VPS RADIUS.
Fix:
- ensure `service=hotspot`
- use `address=10.99.0.1`
- set `src-address=10.99.0.10`
- verify tunnel running

2. Symptom: `requests` increase, `timeouts` increase, `accepts/rejects` stay `0`
Cause: path/firewall/drop before response.
Fix:
- validate UDP ports open on VPS (`1812/1813`, `1645/1646`)
- confirm tcpdump sees incoming packets

3. Symptom: login rejects valid credentials
Cause: CHAP helper or user mismatch.
Fix:
- verify `md5.js` exists in hotspot files
- confirm backend CHAP path and plan state

4. Symptom: payment/trial returns credentials but login still fails
Cause: router not reaching RADIUS, not a UI problem.
Fix:
- troubleshoot radius transport first (monitor + packet capture)

## 10. Security Hardening After Go-Live

After stability is confirmed:

1. Keep RADIUS client definitions narrowed to expected source IPs.
2. Rotate shared secret to a strong random value.
3. Restrict router management (API/Winbox/SSH/WebFig) to trusted networks.
4. Keep legacy ports `1645/1646` only if required by old clients.

## 11. Final Acceptance Checklist

- L2TP/IPsec tunnel is up (`l2tp-to-vps` running)
- Router has RADIUS entry with `service=hotspot`
- Hotspot profile has `use-radius=yes`
- Login page and `md5.js` uploaded on router
- Trial flow returns credentials
- Phone login succeeds with generated credentials
- `/radius monitor [find]` shows accepts/rejects, not only timeouts

---

If onboarding a second router, repeat this document exactly and only change router-specific values (router identity, API password, and local hotspot interface/profile names).
