#!/bin/sh
# Container entrypoint. CONTAINER_ROLE: web (default) | horizon | scheduler | migrate.
# Config/route/event caches are built at start so they reflect the runtime environment.
set -e

cd /app

php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache

case "${CONTAINER_ROLE:-web}" in
  web)
    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
      php artisan migrate --force --isolated
    fi
    exec frankenphp php-server --root /app/public --listen :8080
    ;;
  horizon)
    exec php artisan horizon
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  migrate)
    exec php artisan migrate --force --isolated
    ;;
  *)
    exec "$@"
    ;;
esac
