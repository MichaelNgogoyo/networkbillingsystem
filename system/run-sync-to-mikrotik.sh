#!/bin/bash
# Wrapper script to run sync-to-mikrotik.php with proper Docker network access
# This script must run on the production server where Docker is available

cd /home/michael/networkbillingsystem

# Run the PHP script inside a Docker container on the same network as MySQL
# Use official PHP image with MySQL extensions
docker run --rm \
  --network networkbillingsystem_default \
  -v /home/michael/networkbillingsystem:/app \
  -w /app \
  php:8.2-cli-alpine \
  sh -c 'docker-php-ext-install pdo pdo_mysql && php system/sync-to-mikrotik.php'

echo "MikroTik sync completed at $(date)"
