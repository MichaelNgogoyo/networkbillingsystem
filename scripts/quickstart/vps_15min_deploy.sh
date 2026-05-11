#!/usr/bin/env bash
set -euo pipefail

# Quick deploy script for beginners.
# Run on VPS as your normal user (for example: michael).

APP_DIR="${APP_DIR:-$HOME/networkbillingsystem}"
REPO_URL="${REPO_URL:-https://github.com/hotspotbilling/phpnuxbill.git}"
APP_DOMAIN="${APP_DOMAIN:-network.apperstar.dev}"
TZ_VALUE="${TZ:-Africa/Nairobi}"
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-change-me-root}"
MYSQL_DATABASE="${MYSQL_DATABASE:-nuxbill}"
MYSQL_USER="${MYSQL_USER:-nuxbill}"
MYSQL_PASSWORD="${MYSQL_PASSWORD:-change-me-user}"

command -v git >/dev/null || { echo "git is required"; exit 1; }

if ! command -v docker >/dev/null; then
  echo "Installing Docker..."
  curl -fsSL https://get.docker.com | sudo sh
  sudo usermod -aG docker "$USER"
  echo "Docker installed. Log out and log back in, then rerun this script."
  exit 0
fi

if docker compose version >/dev/null 2>&1; then
  COMPOSE_CMD="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE_CMD="docker-compose"
else
  echo "Installing docker-compose binary..."
  mkdir -p "$HOME/.local/bin"
  curl -fsSL "https://github.com/docker/compose/releases/download/v2.29.7/docker-compose-linux-x86_64" -o "$HOME/.local/bin/docker-compose"
  chmod +x "$HOME/.local/bin/docker-compose"
  COMPOSE_CMD="$HOME/.local/bin/docker-compose"
fi

if [[ ! -d "$APP_DIR/.git" ]]; then
  rm -rf "$APP_DIR"
  git clone "$REPO_URL" "$APP_DIR"
else
  git -C "$APP_DIR" fetch --all --prune
  git -C "$APP_DIR" pull --ff-only
fi

cd "$APP_DIR"

cat > .env <<EOF
TZ=$TZ_VALUE
MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASSWORD
MYSQL_DATABASE=$MYSQL_DATABASE
MYSQL_USER=$MYSQL_USER
MYSQL_PASSWORD=$MYSQL_PASSWORD
APP_DOMAIN=$APP_DOMAIN
EOF

mkdir -p .runtime/system/uploads .runtime/logs

$COMPOSE_CMD -f docker-compose.example.yml up -d --build
$COMPOSE_CMD -f docker-compose.example.yml ps

echo
echo "Done. Next steps:"
echo "1) Open http://SERVER_IP:8080/install (or your domain via nginx)."
echo "2) In installer, set DB host to: mysql"
echo "3) DB name/user/pass must match .env values."
