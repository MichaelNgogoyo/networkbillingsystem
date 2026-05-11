# Chat Delivery Journal and Fast Go-Live Playbook

This document records what was done in this project from start to current live state, including key issues, fixes, and the fastest safe path to deliver the same result again.

## 1) Executive Summary

Goal:

- Deploy PHPNuxBill to VPS with CI/CD and domain.
- Connect MikroTik securely from remote site to VPS.
- Enable M-Pesa plugin and callback path.
- Keep deployment stable across updates.
- Configure working cron jobs.

Outcome:

- Site is live behind HTTPS.
- Deploy pipeline is working.
- Runtime state survives redeploys.
- MikroTik VPN link is stable.
- API access is secure and confirmed from app container.
- Hotspot template flow is documented with session parameters (`nux-mac`, `nux-ip`, `nux-router`, `nux-hostname`).
- M-Pesa gateway exists and is configurable.
- Cron jobs are installed and verified.
- 15-minute script pack exists for VPS deploy, cron setup, and MikroTik templates.

## 2) What Happened in This Chat (Chronological)

## Phase A: Deployment and Production Access

### A1. Domain returned wrong content / 404

Problem:

- Host Nginx and Docker app port mapping conflicted.

Fix:

- Docker app was bound to localhost only (`127.0.0.1:8080:80`).
- Nginx vhost reverse proxied domain to `127.0.0.1:8080`.

Result:

- Domain routed correctly to PHPNuxBill.

### A2. TLS certificate mismatch (`ERR_CERT_COMMON_NAME_INVALID`)

Problem:

- Wrong cert served for subdomain.

Fix:

- Correct Let’s Encrypt cert installed for `network.apperstar.dev`.

Result:

- HTTPS served correctly.

### A3. Missing runtime file after deploy

Problem:

- `notifications.default.json` missing due ignore rule.

Fix:

- File was tracked and deployed.

Result:

- Admin routes stopped failing on missing file.

### A4. Reinstall behavior after redeploy

Problem:

- Config and uploads were inside container filesystem and lost on rebuild.

Fix:

- `deploy/deploy.sh` now backs up runtime state to `.runtime` and restores after build.
- CI rsync excludes `.runtime` to keep state persistent.

Result:

- Redeploy no longer forces reinstall.

### A5. Mixed content / CSS issues on HTTPS

Problem:

- URL generation not proxy-aware.

Fix:

- Installer/config logic updated to respect `HTTP_X_FORWARDED_PROTO`.
- `APP_URL` handling aligned for HTTPS behind Nginx.

Result:

- CSS/JS loaded correctly over HTTPS.

## Phase B: MikroTik Remote Connectivity

### B1. WireGuard not available on target router

Finding:

- RouterOS 6.49.19 (hAP lite) does not support WireGuard.

Decision:

- Use L2TP/IPsec.

### B2. Initial IPsec failures (PSK and phase2)

Problems seen:

- PSK/decryption failures first.
- Then phase1 succeeded but phase2 failed with `NO-PROPOSAL-CHOSEN`.

Fix:

- strongSwan child proposals updated to include MikroTik ROS6 proposal:
  `ESP:AES_CBC_256/HMAC_SHA1_96/MODP_1024`.

Result:

- CHILD_SA established successfully.

### B3. L2TP dropped immediately after phase2 success

Problem:

- `pppd` exited with option errors (`crtscts` invalid for PPPoL2TP).

Fix:

- Removed incompatible PPP options.

Result:

- PPP process started correctly.

### B4. PPP auth negotiation mismatch

Problem:

- `peer refused to authenticate`.

Fix:

- Relaxed L2TP/PPP auth negotiation to allow compatible method negotiation.

Result:

- CHAP success seen; tunnel IPs assigned (`10.0.10.1 <-> 10.0.10.10`).

### B5. Router API still unreachable

Problem:

- API service/rules not fully aligned.

Fix:

- API enabled and restricted to VPS tunnel IP.
- Dedicated `phpnuxbill` API user/group configured.
- Input firewall allow rule inserted at top.
- Broad extra API rules removed.

Result:

- API reachable from VPS host and PHPNuxBill container.
- RouterOS API login verified from container with project library.

## Phase C: Cron Jobs

### C1. Legacy cron entries were wrong

Problem:

- Old entries used non-container path and wrong script context.

Fix:

- Replaced with Docker-aware cron commands.
- Ensured command runs from `/var/www/html/system` inside container.
- Added `HTTP_HOST=network.apperstar.dev` to remove warnings.
- Added persistent logs under `.runtime/logs/`.

Result:

- Both cron tasks run successfully and log clean output.

## 3) Final Live State (Current)

Application:

- Running in Docker from `/home/michael/networkbillingsystem`.
- Nginx fronting app over HTTPS.

Router Integration:

- Tunnel: active.
- MikroTik API restricted to tunnel source.
- API login from container: confirmed.

Payment:

- M-Pesa plugin available and configurable.
- Callback route implemented at `/callback/mpesa`.

Automation:

- Cron daemon active.
- Main cron every minute.
- Reminder cron daily 07:00.

## 4) Fastest Safe Path to Deliver This to Live Again

Use this as your quick production recipe.

### Step 1: VPS baseline

1. Install Docker and docker-compose binary used by deploy script.
2. Create app directory: `/home/michael/networkbillingsystem`.
3. Point domain DNS to VPS.
4. Optionally run script: `scripts/quickstart/vps_15min_deploy.sh`.

### Step 2: CI/CD

1. Add GitHub secrets and vars used by workflow.
2. Push to `main` or trigger workflow dispatch.
3. Verify deploy job completed.

### Step 3: Nginx and TLS

1. Reverse proxy domain to `127.0.0.1:8080`.
2. Install Let’s Encrypt certificate.
3. Test HTTPS page load.

### Step 4: Preserve runtime state

1. Keep `deploy/deploy.sh` runtime backup/restore logic.
2. Keep `.runtime` excluded from rsync delete.

### Step 5: VPN link

1. If RouterOS 6: use L2TP/IPsec.
2. If RouterOS 7: WireGuard is preferred.
3. Confirm tunnel IPs are assigned and stable.

### Step 6: Router API hardening

1. Enable API on `8728` only.
2. Restrict API source to VPS tunnel IP.
3. Create dedicated API user/group.
4. Add top firewall allow rule for tunnel source + port 8728.

### Step 7: Add router in PHPNuxBill

1. Admin -> Network -> Routers -> Add.
2. Use tunnel IP + `:8728`.
3. Click Test It, then Save.

### Step 7b: Hotspot custom login template

1. Generate template from `mikrotik-hotspot/login.html`.
2. Replace `__BILLING_URL__` and router id (`nux-router`).
3. Upload to MikroTik hotspot as `login.html`.
4. Ensure matching Hotspot plan names exist in PHPNuxBill.

### Step 8: Enable M-Pesa

1. Activate `mpesa` in Payment Gateway list.
2. Configure Daraja credentials and callback URL.
3. Test STK flow in sandbox.
4. Switch to production after successful tests.

### Step 9: Cron jobs

1. Install Docker-based cron commands.
2. Verify `systemctl is-active cron`.
3. Tail logs to confirm runs.
4. Optionally run script: `scripts/quickstart/install_cron_jobs.sh`.

### Step 10: Smoke tests

1. Open app over HTTPS.
2. Test router API from app (Test It).
3. Test one package order + M-Pesa callback.
4. Confirm cron logs update.

## 5) Important Lessons Learned

1. Separate web proxy and container ports early.
2. Treat runtime files as persistent state, not build artifacts.
3. For RouterOS 6, L2TP/IPsec is practical when WireGuard is unavailable.
4. Phase1 success does not mean VPN is complete; validate phase2 and PPP.
5. API reachability needs both service settings and firewall order.
6. Cron in containerized apps must run with correct working directory.

## 6) Useful Operational Commands

### App and deploy checks

```bash
cd /home/michael/networkbillingsystem
/home/michael/.local/bin/docker-compose -f docker-compose.example.yml ps
```

### VPN checks on VPS

```bash
sudo systemctl is-active strongswan
sudo systemctl is-active xl2tpd
sudo journalctl -u strongswan --since '15 min ago' --no-pager | tail -n 200
sudo journalctl -u xl2tpd --since '15 min ago' --no-pager | tail -n 200
```

### API reachability checks

```bash
timeout 3 bash -lc '</dev/tcp/10.0.10.10/8728' && echo OPEN || echo CLOSED
```

### Cron checks

```bash
systemctl is-active cron
crontab -l
tail -n 50 /home/michael/networkbillingsystem/.runtime/logs/cron-main.log
tail -n 50 /home/michael/networkbillingsystem/.runtime/logs/cron-reminder.log
```

## 7) Recommended Next Improvements

1. Add logrotate for cron log files.
2. Add healthcheck endpoint and uptime monitoring.
3. Add backup policy for MySQL and `.runtime` directory.
4. Add staging environment for plugin and payment testing.

## 8) Added Deliverables in This Repository

1. Beginner full guide: `docs/BEGINNER_MIKROTIK_PHPNUXBILL_MPESA_GUIDE.md`
2. 15-minute scripts guide: `docs/QUICKSTART_15_MIN_TERMINAL_SCRIPTS.md`
3. Script pack folder: `scripts/quickstart/`

---

If you follow the Fast Go-Live section exactly, you can reproduce this deployment quickly and safely.
