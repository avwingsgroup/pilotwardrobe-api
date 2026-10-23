#!/usr/bin/env bash
# Zero-downtime deploy for the Pilotwardrobe API on a VPS (see docs/deployment-vps.md).
#
#   /var/www/pilotwardrobe-api/
#     releases/<timestamp>/   one checkout per deploy
#     shared/.env             production environment (never in git)
#     shared/storage/         logs, cache, sessions, generated files
#     current -> releases/<timestamp>
#
# Usage (as the deploy user):  deploy.sh [branch]          default branch: main
#                              deploy.sh --rollback        switch back to the previous release
set -euo pipefail

# The app folder is wherever this script is installed (e.g. /var/www/pilotwardrobe-api/deploy.sh);
# override with APP_DIR=/path deploy.sh if needed.
APP_DIR=${APP_DIR:-$(cd "$(dirname "$(readlink -f "$0")")" && pwd)}
REPO=${REPO:-git@github.com:avwingsgroup/pilotwardrobe-api.git}
BRANCH=${1:-main}
KEEP=5
PHP=/usr/bin/php
PHP_FPM_SERVICE=php8.5-fpm

cd "$APP_DIR"

finish() {
    sudo /usr/bin/systemctl reload "$PHP_FPM_SERVICE"   # OPcache does not re-check files
    "$PHP" current/artisan horizon:terminate             # Supervisor restarts it on the new code
    "$PHP" current/artisan queue:restart || true
}

if [[ "$BRANCH" == "--rollback" ]]; then
    active=$(basename "$(readlink -f current)")
    previous=$(find releases -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort | awk -v a="$active" '$0 < a' | tail -n 1)
    [[ -n "$previous" ]] || { echo "No release older than $active."; exit 1; }
    ln -sfn "$APP_DIR/releases/$previous" current.tmp && mv -Tf current.tmp current
    finish
    echo "Rolled back to $previous (database migrations were NOT reverted)."
    exit 0
fi

RELEASE="releases/$(date +%Y%m%d%H%M%S)"
echo "==> Cloning $BRANCH into $RELEASE"
git clone --depth 1 --branch "$BRANCH" "$REPO" "$RELEASE"

echo "==> Linking shared .env and storage"
rm -rf "$RELEASE/storage"
ln -s "$APP_DIR/shared/storage" "$RELEASE/storage"
ln -s "$APP_DIR/shared/.env" "$RELEASE/.env"

cd "$RELEASE"
echo "==> Installing dependencies"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --classmap-authoritative

echo "==> Migrating (one deploy at a time)"
"$PHP" artisan migrate --force --isolated

echo "==> Caching config, routes, events and views"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan event:cache
"$PHP" artisan view:cache
"$PHP" artisan storage:link --force >/dev/null

cd "$APP_DIR"
echo "==> Switching current -> $RELEASE"
ln -sfn "$APP_DIR/$RELEASE" current.tmp && mv -Tf current.tmp current
finish

echo "==> Health check"
APP_HOST=$(grep -E '^APP_URL=' shared/.env | cut -d= -f2- | tr -d '"' | sed -E 's#^https?://##; s#/.*$##')
HEALTHY=1
if curl -fsS --max-time 10 --resolve "$APP_HOST:443:127.0.0.1" "https://$APP_HOST/health" >/dev/null; then
    echo "Healthy."
else
    HEALTHY=0
    echo "WARNING: https://$APP_HOST/health did not return 200; check shared/storage/logs and consider: deploy.sh --rollback"
fi

echo "==> Removing old releases (keeping $KEEP)"
ls -1d releases/* | sort | head -n -"$KEEP" | xargs -r rm -rf

echo "==> Updating the installed deploy script from this release"
# Write a temp file and rename it, so this running copy (already open) is unaffected.
cp current/deploy/vps/deploy.sh "$APP_DIR/deploy.sh.new" && chmod 755 "$APP_DIR/deploy.sh.new" \
    && mv -f "$APP_DIR/deploy.sh.new" "$APP_DIR/deploy.sh"

echo "Deployed $BRANCH ($(git -C current rev-parse --short HEAD))."
# Non-zero exit so an unattended (CI) deploy shows as failed when the app is unhealthy.
[[ $HEALTHY == 1 ]] || exit 1
