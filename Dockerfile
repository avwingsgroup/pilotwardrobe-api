# syntax=docker/dockerfile:1.7
# Pilotwardrobe API: one image, three roles (web | horizon | scheduler), selected by CONTAINER_ROLE.
# Web is served by FrankenPHP (Caddy + PHP 8.5.12 in one process) on port 8080.

ARG PHP_VERSION=8.5.12

# ---------------------------------------------------------------------------
# Composer dependencies (cached separately from application code)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# The composer image's own PHP may be older than ^8.5; the runtime image (8.5.12) enforces the
# PHP version through Composer's platform check, so it is skipped here.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-req=ext-* --ignore-platform-req=php
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---------------------------------------------------------------------------
# Runtime
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS runtime

# OPcache is built into PHP 8.5, so it is not installed separately.
RUN install-php-extensions pdo_pgsql pgsql gd intl zip bcmath pcntl posix exif \
    && apt-get update && apt-get install -y --no-install-recommends postgresql-client curl \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-pilotwardrobe.ini

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SERVER_NAME=:8080 \
    CONTAINER_ROLE=web

WORKDIR /app
COPY --from=vendor --chown=www-data:www-data /app /app
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy \
    && rm -f .env

USER www-data
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD [ "$CONTAINER_ROLE" != "web" ] || curl -fsS http://127.0.0.1:8080/health > /dev/null || exit 1

ENTRYPOINT ["entrypoint"]
