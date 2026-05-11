# 15-Minute Quickstart: GitHub to VPS + MikroTik + PHPNuxBill

This is a fast path for technicians.

Goal:

- Deploy PHPNuxBill from GitHub to VPS in Docker.
- Connect MikroTik (L2TP or WireGuard).
- Lock down API.
- Add router and test.
- Install cron jobs.

## 1) Script Pack Location

Use these files:

1. `scripts/quickstart/vps_15min_deploy.sh`
2. `scripts/quickstart/install_cron_jobs.sh`
3. `scripts/quickstart/prepare_mikrotik_login_template.sh`
4. `scripts/quickstart/mikrotik/l2tp_client_and_api.rsc`
5. `scripts/quickstart/mikrotik/wireguard_and_api.rsc`
6. `scripts/quickstart/mikrotik/hotspot_minimal_setup.rsc`

## 2) VPS: Deploy Docker Stack

On VPS terminal:

```bash
export APP_DOMAIN=network.apperstar.dev
export MYSQL_ROOT_PASSWORD='CHANGE_ME_ROOT'
export MYSQL_DATABASE='nuxbill'
export MYSQL_USER='nuxbill'
export MYSQL_PASSWORD='CHANGE_ME_DB_USER'

bash scripts/quickstart/vps_15min_deploy.sh
```

What it does:

- Installs Docker if missing.
- Clones/updates repo in `/home/<user>/networkbillingsystem`.
- Writes `.env`.
- Builds and starts Docker services.

Installer URL (first run):

- `http://SERVER_IP:8080/install`

DB host in installer must be:

- `mysql`

## 3) MikroTik: Connect to VPS

Choose one script:

1. RouterOS 6.x: `scripts/quickstart/mikrotik/l2tp_client_and_api.rsc`
2. RouterOS 7+: `scripts/quickstart/mikrotik/wireguard_and_api.rsc`

How to use:

1. Open script.
2. Replace placeholders (`VPS_PUBLIC_IP`, passwords, keys).
3. Paste into MikroTik terminal.

Expected:

- Tunnel connected.
- API enabled on `8728`.
- API restricted to tunnel source IP.

## 4) MikroTik Hotspot + Custom Login Template

Optional but recommended for captive portal flow.

Prepare portal template:

```bash
BILLING_URL=https://network.apperstar.dev ROUTER_ID=1 bash scripts/quickstart/prepare_mikrotik_login_template.sh
```

Output:

- `mikrotik-hotspot/login.generated.html`

Upload to MikroTik hotspot files as:

- `hotspot/login.html`

If hotspot is not created yet, use template starter:

- `scripts/quickstart/mikrotik/hotspot_minimal_setup.rsc`

## 5) Add Router in PHPNuxBill and Test

In Admin UI:

1. Network -> Routers -> Add
2. IP: tunnel IP + `:8728`
3. User: `phpnuxbill`
4. Password: your API password
5. Click Test It
6. Save

## 6) Enable M-Pesa

In Admin UI:

1. Payment Gateway -> activate `mpesa`
2. Open `mpesa` settings
3. Fill Daraja fields
4. Callback URL: `https://network.apperstar.dev/callback/mpesa`
5. Save
6. Test in sandbox first

## 7) Install Cron Jobs

```bash
APP_DIR=/home/michael/networkbillingsystem APP_DOMAIN=network.apperstar.dev bash scripts/quickstart/install_cron_jobs.sh
```

Verify:

```bash
crontab -l
systemctl is-active cron
```

## 8) Final Validation Commands

```bash
cd /home/michael/networkbillingsystem
/home/michael/.local/bin/docker-compose -f docker-compose.example.yml ps

tail -n 50 /home/michael/networkbillingsystem/.runtime/logs/cron-main.log
tail -n 50 /home/michael/networkbillingsystem/.runtime/logs/cron-reminder.log
```

## 9) Do and Don't

## Do

1. Use tunnel IPs for API access.
2. Use dedicated API user only for PHPNuxBill.
3. Keep M-Pesa in sandbox until callback and STK checks pass.
4. Keep `.runtime` and DB backups before major updates.

## Don't

1. Do not expose MikroTik API port to public internet.
2. Do not run WireGuard script on RouterOS 6.
3. Do not use admin user for API integration.
4. Do not skip cron installation.

## 10) Time Target

A prepared engineer can finish this in about 15 minutes if:

1. DNS and domain already point to VPS.
2. Daraja credentials are ready.
3. MikroTik has internet and terminal access.
4. You use the script templates exactly as provided.
