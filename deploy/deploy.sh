#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-$HOME/networkbillingsystem}"
COMPOSE_BIN=""
PERSIST_DIR="${APP_DIR}/.runtime"
PERSIST_CONFIG="${PERSIST_DIR}/config.php"
PERSIST_UPLOADS="${PERSIST_DIR}/system/uploads"

# Use classic builder on older VPS Docker stacks where buildx is outdated.
export DOCKER_BUILDKIT=0
export COMPOSE_DOCKER_CLI_BUILD=0

if [[ ! -d "$APP_DIR" ]]; then
  echo "App directory not found: $APP_DIR" >&2
  exit 1
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE_BIN="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_BIN="docker-compose"
elif [[ -x "${HOME}/.local/bin/docker-compose" ]]; then
  COMPOSE_BIN="${HOME}/.local/bin/docker-compose"
else
  echo "Docker Compose not found. Install docker compose plugin or docker-compose binary." >&2
  exit 1
fi

cd "$APP_DIR"

mkdir -p "$PERSIST_UPLOADS"

# Backup runtime state from the currently running container before rebuild.
if docker ps -a --format '{{.Names}}' | grep -qx 'nuxbill'; then
  docker cp nuxbill:/var/www/html/config.php "$PERSIST_CONFIG" >/dev/null 2>&1 || true
  rm -rf "$PERSIST_UPLOADS"
  mkdir -p "$PERSIST_UPLOADS"
  docker cp nuxbill:/var/www/html/system/uploads/. "$PERSIST_UPLOADS/" >/dev/null 2>&1 || true
fi

# Keep production credentials out of git by writing env values at deploy time.
cat > .env <<EOF
TZ=${TZ:-Africa/Nairobi}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
MYSQL_DATABASE=${MYSQL_DATABASE:-nuxbill}
MYSQL_USER=${MYSQL_USER:-nuxbill}
MYSQL_PASSWORD=${MYSQL_PASSWORD}
APP_DOMAIN=${APP_DOMAIN:-network.apperstar.dev}
EOF

$COMPOSE_BIN -f docker-compose.example.yml down || true
$COMPOSE_BIN -f docker-compose.example.yml up -d --build

# Restore runtime state into the new container after deploy.
if docker ps --format '{{.Names}}' | grep -qx 'nuxbill'; then
  if [[ -f "$PERSIST_CONFIG" ]]; then
    docker cp "$PERSIST_CONFIG" nuxbill:/var/www/html/config.php
  fi

  if [[ -d "$PERSIST_UPLOADS" ]] && [[ -n "$(ls -A "$PERSIST_UPLOADS" 2>/dev/null)" ]]; then
    docker cp "$PERSIST_UPLOADS/." nuxbill:/var/www/html/system/uploads/
  fi

  docker exec nuxbill sh -lc 'chown -R www-data:www-data /var/www/html/system/uploads /var/www/html/config.php >/dev/null 2>&1 || true'
fi

$COMPOSE_BIN -f docker-compose.example.yml ps
