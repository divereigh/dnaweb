#!/usr/bin/env bash
# DNAWeb deploy — pull, build, migrate, cache, reload php-fpm.
# Idempotent. Run as a sudo-capable user (e.g. `damo`) — NOT as `dnaweb`.
# File operations are wrapped in `sudo -u dnaweb` so the repo stays owned by dnaweb.

set -euo pipefail

cd "$(dirname "$0")/.."
APP_DIR="$(pwd)"

if [ "$(id -un)" = "dnaweb" ]; then
    echo "✗ Run this script as a sudo-capable user (e.g. damo), not as dnaweb." >&2
    echo "  It will use 'sudo -u dnaweb' internally for the file operations." >&2
    exit 2
fi

# Verify we have sudo access; fail fast with a friendlier message than mid-run.
if ! sudo -n true 2>/dev/null; then
    echo "▸ this script needs sudo (you'll be prompted once)"
    sudo -v
fi

run_as_dnaweb() {
    sudo -u dnaweb -- bash -lc "cd '$APP_DIR' && $*"
}

echo "▸ git pull"
run_as_dnaweb "git pull --ff-only"

echo "▸ composer install"
run_as_dnaweb "composer install --no-dev --optimize-autoloader --no-interaction"

echo "▸ npm ci && npm run build"
run_as_dnaweb "npm ci --no-audit --no-fund && npm run build"

echo "▸ migrate"
run_as_dnaweb "php artisan migrate --force"

echo "▸ caches (clear first so any .env edits take effect)"
run_as_dnaweb "php artisan config:clear"
run_as_dnaweb "php artisan config:cache"
run_as_dnaweb "php artisan route:cache"
run_as_dnaweb "php artisan view:cache"

echo "▸ reload php-fpm"
sudo systemctl reload php8.4-fpm

echo "✓ deploy complete"
