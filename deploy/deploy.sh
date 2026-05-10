#!/usr/bin/env bash
# Pull, build, migrate, cache. Run from the app root on the server.
# Idempotent — safe to re-run.

set -euo pipefail

cd "$(dirname "$0")/.."

echo "▸ git pull"
git pull --ff-only

echo "▸ composer install"
composer install --no-dev --optimize-autoloader --no-interaction

echo "▸ npm ci && npm run build"
npm ci --no-audit --no-fund
npm run build

echo "▸ migrations"
php artisan migrate --force

echo "▸ caches (clear first so any .env edits take effect)"
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "▸ restart php-fpm"
sudo systemctl reload php8.4-fpm || sudo systemctl reload php-fpm || true

echo "✓ deploy complete"
