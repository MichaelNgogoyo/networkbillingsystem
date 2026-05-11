#!/usr/bin/env bash
set -euo pipefail

# Builds a MikroTik hotspot login.html from the project template.
# You still upload the output file to MikroTik hotspot files.

BILLING_URL="${BILLING_URL:-https://network.apperstar.dev}"
ROUTER_ID="${ROUTER_ID:-1}"

SRC="mikrotik-hotspot/login.html"
OUT="mikrotik-hotspot/login.generated.html"

if [[ ! -f "$SRC" ]]; then
  echo "Template not found: $SRC"
  exit 1
fi

cp "$SRC" "$OUT"
sed -i "s|__BILLING_URL__|$BILLING_URL|g" "$OUT"
sed -i "s|nux-router=1|nux-router=$ROUTER_ID|g" "$OUT"

echo "Generated: $OUT"
echo "Upload this file to MikroTik hotspot as login.html"
