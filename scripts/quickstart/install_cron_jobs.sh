#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/networkbillingsystem}"
APP_DOMAIN="${APP_DOMAIN:-network.apperstar.dev}"

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD="docker-compose"
else
  COMPOSE_CMD="$HOME/.local/bin/docker-compose"
fi

mkdir -p "$APP_DIR/.runtime/logs"

CRON_TMP="$(mktemp)"
crontab -l 2>/dev/null \
  | grep -v "PHPNuxBill Managed Cron" \
  | grep -v "Run main cron every hour" \
  | grep -v "Run reminder cron every day at 7 AM" \
  | grep -v "docker-compose.example.yml exec -T nuxbill sh -lc" \
  > "$CRON_TMP" || true

{
  echo "# PHPNuxBill Managed Cron - do not edit manually"
  echo "* * * * * cd $APP_DIR && $COMPOSE_CMD -f docker-compose.example.yml exec -T nuxbill sh -lc \"cd /var/www/html/system && HTTP_HOST=$APP_DOMAIN php cron.php\" >> $APP_DIR/.runtime/logs/cron-main.log 2>&1"
  echo "0 7 * * * cd $APP_DIR && $COMPOSE_CMD -f docker-compose.example.yml exec -T nuxbill sh -lc \"cd /var/www/html/system && HTTP_HOST=$APP_DOMAIN php cron_reminder.php\" >> $APP_DIR/.runtime/logs/cron-reminder.log 2>&1"
} >> "$CRON_TMP"

crontab "$CRON_TMP"
rm -f "$CRON_TMP"

echo "Installed cron entries:"
crontab -l
