# Beginner Guide: MikroTik + PHPNuxBill + M-Pesa (L2TP or WireGuard)

This guide is written for beginners and uses simple, tested steps.

You will learn how to:

1. Connect MikroTik to your VPS (choose L2TP or WireGuard).
2. Run PHPNuxBill in Docker from scratch.
3. Secure MikroTik API access so only your VPS can use it.
4. Add and test your router in PHPNuxBill.
5. Set up MikroTik hotspot and customized captive portal login template.
6. Enable and configure the M-Pesa plugin.
7. Set up cron jobs (scheduled tasks, sometimes called "clone jobs" by mistake).

## 0) Before You Start

You need:

- A VPS with Ubuntu or Debian.
- A public domain (example: `network.apperstar.dev`) pointing to your VPS.
- PHPNuxBill already running in Docker (this project uses `/home/michael/networkbillingsystem`).
- A MikroTik router.
- For M-Pesa: a Safaricom Daraja app (Consumer Key/Secret) and shortcode/passkey.

## 1) Architecture (Simple View)

```text
Customer Phone -> PHPNuxBill (VPS) -> M-Pesa Daraja
                         |
                         v
                VPN Tunnel (L2TP or WireGuard)
                         |
                         v
                  MikroTik API (port 8728)
```

Important security idea:

- Do not open MikroTik API to the internet.
- Allow API only from VPS tunnel IP.

## 2) Run the Project in Docker (Step by Step)

This section is the exact beginner flow to run PHPNuxBill with Docker.

### 2.1 On your laptop or test VM (quick local run)

```bash
git clone https://github.com/hotspotbilling/phpnuxbill.git
cd phpnuxbill

cat > .env <<EOF
TZ=Africa/Nairobi
MYSQL_ROOT_PASSWORD=change-me-root
MYSQL_DATABASE=nuxbill
MYSQL_USER=nuxbill
MYSQL_PASSWORD=change-me-user
APP_DOMAIN=localhost
EOF

docker compose -f docker-compose.example.yml up -d --build
docker compose -f docker-compose.example.yml ps
```

Open:

- `http://localhost:8080/install`

Installer database values:

- Host: `mysql`
- Database: `nuxbill` (or your `.env` value)
- User: `nuxbill` (or your `.env` value)
- Password: from `.env`

### 2.2 On VPS (production baseline)

Recommended app path:

- `/home/michael/networkbillingsystem`

Quick manual run:

```bash
cd /home/michael/networkbillingsystem
/home/michael/.local/bin/docker-compose -f docker-compose.example.yml up -d --build
/home/michael/.local/bin/docker-compose -f docker-compose.example.yml ps
```

This project binds app web port as:

- `127.0.0.1:8080:80`

So in production, place Nginx in front of it and proxy your domain to `127.0.0.1:8080`.

### 2.3 Useful Docker commands for beginners

```bash
# See running containers
docker ps

# See project services
cd /home/michael/networkbillingsystem
/home/michael/.local/bin/docker-compose -f docker-compose.example.yml ps

# Tail app logs
/home/michael/.local/bin/docker-compose -f docker-compose.example.yml logs -f nuxbill

# Restart app only
/home/michael/.local/bin/docker-compose -f docker-compose.example.yml restart nuxbill

# Stop everything
/home/michael/.local/bin/docker-compose -f docker-compose.example.yml down
```

### 2.4 Fast script option

You can use the included script:

- `scripts/quickstart/vps_15min_deploy.sh`

Example:

```bash
cd /home/michael/networkbillingsystem || true
bash /path/to/phpnuxbill/scripts/quickstart/vps_15min_deploy.sh
```

## 3) Connect MikroTik to VPS (Choose One Method)

Choose exactly one:

- Option A: L2TP/IPsec (best for older RouterOS 6 devices)
- Option B: WireGuard (best for RouterOS 7+)

---

## 3A) Option A: L2TP/IPsec (Beginner Friendly, RouterOS 6 Compatible)

### A1. VPS: install packages

```bash
sudo apt update
sudo apt install -y strongswan strongswan-swanctl xl2tpd ppp
```

### A2. VPS: create strongSwan config

Create `/etc/swanctl/conf.d/l2tp-vps.conf`:

```ini
connections {
    l2tp-vps {
        version = 1
        proposals = aes128-sha1-modp1024
        local_addrs  = YOUR_VPS_PUBLIC_IP
        remote_addrs = 0.0.0.0

        local {
            auth = psk
            id = YOUR_VPS_PUBLIC_IP
        }
        remote {
            auth = psk
        }

        children {
            l2tp {
                local_ts  = 0.0.0.0/0[udp/l2tp]
                remote_ts = 0.0.0.0/0[udp/l2tp]
                mode = transport
                # MikroTik ROS6 compatible proposals
                esp_proposals = aes256-sha1-modp1024,aes128-sha1-modp1024,aes256-sha1,aes128-sha1
                start_action = none
                rekey_time = 1h
                life_time  = 8h
            }
        }
    }
}

secrets {
    ike-psk {
        secret = "YOUR_STRONG_PSK"
    }
}
```

### A3. VPS: create xl2tpd config

Create `/etc/xl2tpd/xl2tpd.conf`:

```ini
[global]
port = 1701

[lns default]
ip range = 10.0.10.10-10.0.10.100
local ip = 10.0.10.1
require chap = no
refuse pap = no
require authentication = yes
name = L2TP-VPN
pppoptfile = /etc/ppp/options.xl2tpd
length bit = yes
```

Create `/etc/ppp/options.xl2tpd`:

```ini
ms-dns 1.1.1.1
ms-dns 8.8.8.8
asyncmap 0
auth
hide-password
mtu 1410
mru 1410
lcp-echo-interval 30
lcp-echo-failure 4
noccp
```

Create `/etc/ppp/chap-secrets`:

```ini
# client    server   secret            IP addresses
mikrotik    *        YOUR_L2TP_PASS    10.0.10.10
```

### A4. VPS: restart and verify services

```bash
sudo swanctl --load-all
sudo systemctl restart strongswan
sudo systemctl restart xl2tpd
sudo systemctl is-active strongswan
sudo systemctl is-active xl2tpd
```

Expected result: both are `active`.

### A5. MikroTik: create L2TP client

```rsc
/interface l2tp-client add \
  name=l2tp-to-vps \
  connect-to=YOUR_VPS_PUBLIC_IP \
  user=mikrotik \
  password=YOUR_L2TP_PASS \
  use-ipsec=yes \
  ipsec-secret=YOUR_STRONG_PSK \
  allow=mschap2 \
  max-mtu=1410 \
  max-mru=1410 \
  disabled=no
```

Check status:

```rsc
/interface l2tp-client monitor l2tp-to-vps once
```

Expected:

- `status: connected`
- `local-address: 10.0.10.10`
- `remote-address: 10.0.10.1`

---

## 3B) Option B: WireGuard (RouterOS 7+)

Use this only if your MikroTik supports WireGuard.

### B1. VPS: install WireGuard

```bash
sudo apt update
sudo apt install -y wireguard
```

### B2. VPS: generate keys

```bash
wg genkey | tee /tmp/vps-wg.key | wg pubkey > /tmp/vps-wg.pub
cat /tmp/vps-wg.pub
```

Save the VPS public key output.

### B3. VPS: create `/etc/wireguard/wg0.conf`

```ini
[Interface]
Address = 10.20.20.1/24
ListenPort = 51820
PrivateKey = VPS_PRIVATE_KEY

[Peer]
PublicKey = MIKROTIK_PUBLIC_KEY
AllowedIPs = 10.20.20.2/32
PersistentKeepalive = 25
```

### B4. VPS: enable and start WireGuard

```bash
sudo sysctl -w net.ipv4.ip_forward=1
echo 'net.ipv4.ip_forward=1' | sudo tee /etc/sysctl.d/99-wireguard-forward.conf
sudo sysctl --system

sudo systemctl enable wg-quick@wg0
sudo systemctl start wg-quick@wg0
sudo wg show
```

### B5. MikroTik: add WireGuard interface and peer

```rsc
/interface wireguard add name=wg-vps private-key="MIKROTIK_PRIVATE_KEY"
/ip address add address=10.20.20.2/24 interface=wg-vps
/interface wireguard peers add \
  interface=wg-vps \
  public-key="VPS_PUBLIC_KEY" \
  endpoint-address=YOUR_VPS_PUBLIC_IP \
  endpoint-port=51820 \
  allowed-address=10.20.20.1/32 \
  persistent-keepalive=25
```

Verify:

```rsc
/interface wireguard print detail
/interface wireguard peers print detail
```

---

## 4) Secure MikroTik API (Required for PHPNuxBill)

Do this for either tunnel type.

### 4.1 Enable API service and restrict source

For L2TP (VPS tunnel IP is `10.0.10.1`):

```rsc
/ip service set api disabled=no port=8728 address=10.0.10.1/32
/ip service set api-ssl disabled=yes
```

For WireGuard example (VPS tunnel IP is `10.20.20.1`):

```rsc
/ip service set api disabled=no port=8728 address=10.20.20.1/32
/ip service set api-ssl disabled=yes
```

### 4.2 Create dedicated API user

```rsc
/user group add name=phpnuxbill policy=api,read,write,test,password
/user add name=phpnuxbill password="YOUR_STRONG_API_PASSWORD" group=phpnuxbill
```

If user already exists:

```rsc
/user set [find where name="phpnuxbill"] group=phpnuxbill password="YOUR_STRONG_API_PASSWORD" disabled=no
/user group set [find where name="phpnuxbill"] policy=api,read,write,test,password
```

### 4.3 Firewall allow rule (put on top)

L2TP example:

```rsc
/ip firewall filter add \
  chain=input action=accept protocol=tcp \
  src-address=10.0.10.1 in-interface=l2tp-to-vps dst-port=8728 \
  place-before=0 comment="Allow VPS PHPNuxBill API"
```

WireGuard example:

```rsc
/ip firewall filter add \
  chain=input action=accept protocol=tcp \
  src-address=10.20.20.1 in-interface=wg-vps dst-port=8728 \
  place-before=0 comment="Allow VPS PHPNuxBill API"
```

Verify:

```rsc
/ip firewall filter print where chain=input
/ip service print where name~"api"
```

## 5) Add Router in PHPNuxBill and Test

1. Login as Admin.
2. Go to Network -> Routers -> Add.
3. Fill fields:
   - Name: any friendly name (example `Hostel-Router`)
   - IP Address: `10.0.10.10:8728` (L2TP) or `10.20.20.2:8728` (WireGuard)
   - Username: `phpnuxbill`
   - Password: your API password
   - Enabled: Yes
4. Click Test It.
5. If success, click Save.

If Test It fails:

- Check router API user and password.
- Check API service source restriction.
- Check firewall rule order.
- Check tunnel is connected.

## 6) Set Up MikroTik Hotspot and Customized Login Template

This project includes a ready hotspot login template in:

- `mikrotik-hotspot/login.html`

Template guide:

- `mikrotik-hotspot/README.md`

### 6.0 If hotspot is not configured yet (MikroTik side)

Use one of these methods:

1. Use the template script in this repo:
  - `scripts/quickstart/mikrotik/hotspot_minimal_setup.rsc`
2. Or run MikroTik hotspot setup wizard manually:

```rsc
/ip hotspot setup
```

If you use the wizard, make sure at least these are correct:

1. Hotspot interface (your client LAN/bridge).
2. Address pool for hotspot users.
3. DNS name (optional but useful).
4. NAT masquerade exists for internet access.

Quick verification:

```rsc
/ip hotspot print detail
/ip dhcp-server print detail
/ip firewall nat print
```

### 6.1 Create hotspot plans in PHPNuxBill

Go to `Services -> Hotspot` and create plans that match the template package names:

- `Trial 10 Minutes`
- `1 Hour Package`
- `4 Hours Package`
- `8 Hours Package`
- `12 Hours Package`
- `Daily Package`
- `Weekly Package`
- `Monthly Package`

Set `shared_users = 1` for single-device behavior.

### 6.2 Ensure hotspot auth mode is compatible

Use the simple mode:

- `hs_auth_method = api`

This is the easiest for beginners.

### 6.3 Prepare the custom login template

The template contains placeholders:

- `__BILLING_URL__`
- `nux-router=1`

You must replace:

1. `__BILLING_URL__` with your real billing URL.
2. `nux-router=1` with your actual router ID from `Network -> Routers` in PHPNuxBill.

Fast way using included script:

- `scripts/quickstart/prepare_mikrotik_login_template.sh`

Example:

```bash
cd /path/to/phpnuxbill
BILLING_URL=https://network.apperstar.dev ROUTER_ID=1 bash scripts/quickstart/prepare_mikrotik_login_template.sh
```

This generates:

- `mikrotik-hotspot/login.generated.html`

### 6.4 Upload template to MikroTik hotspot

Upload `login.generated.html` to the MikroTik hotspot files and rename it to `login.html`.

Beginner-friendly methods:

1. Winbox Files drag-and-drop.
2. WebFig Files upload.

### 6.5 Why the template matters

The login template sends these values to PHPNuxBill:

- `nux-mac`
- `nux-ip`
- `nux-router`
- `nux-hostname`

This enables smoother hotspot login and package filtering per router.

## 7) Enable and Configure M-Pesa Plugin

This project already has M-Pesa gateway file at:

- `system/paymentgateway/mpesa.php`

And callback route is:

- `https://YOUR_DOMAIN/callback/mpesa`

### 7.1 Requirements from Safaricom Daraja

You need:

- Environment: Sandbox first, then Production.
- Consumer Key
- Consumer Secret
- Shortcode
- Passkey
- Public HTTPS callback URL

### 7.2 Activate gateway in PHPNuxBill

1. Admin -> Payment Gateway.
2. Tick `mpesa` in active gateways.
3. Save.

### 7.3 Configure M-Pesa settings

Open:

- Admin -> Payment Gateway -> mpesa

Fill:

- Environment: `sandbox` first
- Consumer Key
- Consumer Secret
- Shortcode
- Passkey
- Callback URL: `https://YOUR_DOMAIN/callback/mpesa`

Click Save.

### 7.4 Customer test flow

1. Customer selects package and chooses `mpesa` gateway.
2. System sends STK push to customer phone.
3. Customer enters M-Pesa PIN on phone.
4. Customer clicks Check for Payment in order view.
5. Transaction should move to paid and package gets activated.

Important:

- Customer phone format should be valid Kenyan format (`07XXXXXXXX` or `2547XXXXXXXX`).
- Start in sandbox until callback and status checks are stable.

## 8) Cron Jobs (Scheduled Tasks)

These are required for:

- Expiry handling
- Auto renew logic
- Router checks
- Reminder notifications

### 8.1 Install cron jobs on VPS user `michael`

```bash
crontab -e
```

Add:

```cron
# PHPNuxBill Managed Cron - do not edit manually
* * * * * cd /home/michael/networkbillingsystem && /home/michael/.local/bin/docker-compose -f docker-compose.example.yml exec -T nuxbill sh -lc "cd /var/www/html/system && HTTP_HOST=network.apperstar.dev php cron.php" >> /home/michael/networkbillingsystem/.runtime/logs/cron-main.log 2>&1
0 7 * * * cd /home/michael/networkbillingsystem && /home/michael/.local/bin/docker-compose -f docker-compose.example.yml exec -T nuxbill sh -lc "cd /var/www/html/system && HTTP_HOST=network.apperstar.dev php cron_reminder.php" >> /home/michael/networkbillingsystem/.runtime/logs/cron-reminder.log 2>&1
```

### 8.2 Verify cron

```bash
systemctl is-active cron
crontab -l
```

### 8.3 Check logs

```bash
tail -n 50 /home/michael/networkbillingsystem/.runtime/logs/cron-main.log
tail -n 50 /home/michael/networkbillingsystem/.runtime/logs/cron-reminder.log
```

Fast script option:

- `scripts/quickstart/install_cron_jobs.sh`

Example:

```bash
APP_DIR=/home/michael/networkbillingsystem APP_DOMAIN=network.apperstar.dev bash scripts/quickstart/install_cron_jobs.sh
```

## 9) Common Problems and Fixes

### Problem: `NO-PROPOSAL-CHOSEN` in IPsec logs

Cause:

- Phase2 ESP proposal mismatch.

Fix:

- Include MikroTik-compatible ESP proposals in strongSwan:
  `aes256-sha1-modp1024,aes128-sha1-modp1024,aes256-sha1,aes128-sha1`

### Problem: L2TP connects then disconnects quickly

Cause:

- PPP options mismatch (for example invalid `crtscts`).

Fix:

- Use clean `/etc/ppp/options.xl2tpd` (no serial-only options like `crtscts`).

### Problem: API timeout from VPS

Cause:

- MikroTik firewall or API source restriction.

Fix:

- Keep API allow rule at top of `chain=input`.
- Restrict API service address to tunnel IP.

### Problem: PHP warning about `HTTP_HOST` in cron

Fix:

- Run cron command with `HTTP_HOST=your-domain` environment variable.

## 10) Helpful Resources

- PHPNuxBill Wiki: https://github.com/hotspotbilling/phpnuxbill/wiki
- PHPNuxBill Discussions: https://github.com/hotspotbilling/phpnuxbill/discussions
- MikroTik L2TP/IPsec docs: https://help.mikrotik.com/docs/
- MikroTik WireGuard docs: https://help.mikrotik.com/docs/display/ROS/WireGuard
- strongSwan docs: https://docs.strongswan.org/
- Safaricom Daraja docs: https://developer.safaricom.co.ke/
- Certbot docs: https://certbot.eff.org/
- Docker docs: https://docs.docker.com/
- MikroTik login template example: https://github.com/hotspotbilling/phpnuxbill-mikrotik-login-template

## 11) Do and Don't (Beginner Safety Rules)

## Do

1. Use a dedicated MikroTik API user (never admin).
2. Restrict API source to tunnel IP only.
3. Keep M-Pesa in sandbox until callback works.
4. Keep cron logs and check them weekly.
5. Keep backups of DB and `.runtime` before major changes.

## Don't

1. Do not expose port `8728` to the whole internet.
2. Do not run WireGuard instructions on RouterOS 6 devices.
3. Do not delete `.runtime` during deployment.
4. Do not switch to M-Pesa production before sandbox tests pass.
5. Do not edit many firewall rules at once without backup/export.

## 12) 15-Minute Script Pack (Included)

These templates are included in this repository:

1. `scripts/quickstart/vps_15min_deploy.sh`
2. `scripts/quickstart/install_cron_jobs.sh`
3. `scripts/quickstart/prepare_mikrotik_login_template.sh`
4. `scripts/quickstart/mikrotik/l2tp_client_and_api.rsc`
5. `scripts/quickstart/mikrotik/wireguard_and_api.rsc`
6. `scripts/quickstart/mikrotik/hotspot_minimal_setup.rsc`

Use them as templates. Replace placeholders before running.

## 13) Beginner Final Checklist

- Tunnel connected.
- MikroTik API reachable only via tunnel.
- Router added and Test It successful in PHPNuxBill.
- M-Pesa gateway enabled and configured.
- Callback URL reachable over HTTPS.
- Cron jobs installed and logs updating.

If all are true, your setup is ready for production.
