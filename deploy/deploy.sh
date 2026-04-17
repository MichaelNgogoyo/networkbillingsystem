#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/home/michael/networkbillingsystem"
COMPOSE_BIN="${HOME}/.local/bin/docker-compose"

# Use classic builder on older VPS Docker stacks where buildx is outdated.
export DOCKER_BUILDKIT=0
export COMPOSE_DOCKER_CLI_BUILD=0

if [[ ! -d "$APP_DIR" ]]; then
  echo "App directory not found: $APP_DIR" >&2
  exit 1
fi

cd "$APP_DIR"

# Keep production credentials out of git by writing env values at deploy time.
cat > .env <<EOF
TZ=${TZ:-Africa/Nairobi}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
MYSQL_DATABASE=${MYSQL_DATABASE:-nuxbill}
MYSQL_USER=${MYSQL_USER:-nuxbill}
MYSQL_PASSWORD=${MYSQL_PASSWORD}
APP_DOMAIN=${APP_DOMAIN:-network.apperstar.dev}
EOF

if [[ ! -x "$COMPOSE_BIN" ]]; then
  echo "docker-compose binary not found at $COMPOSE_BIN" >&2
  exit 1
fi

"$COMPOSE_BIN" -f docker-compose.example.yml down || true
"$COMPOSE_BIN" -f docker-compose.example.yml up -d --build
"$COMPOSE_BIN" -f docker-compose.example.yml ps
