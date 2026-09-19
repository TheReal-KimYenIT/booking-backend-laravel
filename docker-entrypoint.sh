#!/bin/bash
set -e

# Render assigns a custom PORT (typically 10000)
PORT="${PORT:-80}"

# Adjust Apache port
sed -i "s/80/$PORT/g" /etc/apache2/ports.conf /etc/apache2/sites-available/*.conf

# Prepare Laravel
php artisan config:clear || true
php artisan route:clear || true
php artisan storage:link || true
php artisan migrate --force || true

# Execute Apache in foreground
exec apache2-foreground
