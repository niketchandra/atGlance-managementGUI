# AtGlance Community Edition - console + API image.
# One image serves three roles (app, worker, scheduler); the compose file
# picks the role through the container command.
FROM php:8.2-cli

# Set to "true" to keep dev dependencies (phpunit) for running tests.
ARG INSTALL_DEV=false

WORKDIR /app

# install-php-extensions pulls build deps, compiles and removes them again,
# which keeps the image much smaller than a manual apt + pecl install.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN set -eux \
    ; install-php-extensions pdo_mysql zip redis \
    ; apt-get update \
    ; apt-get install -y --no-install-recommends curl unzip \
    ; apt-get clean \
    ; rm -rf /var/lib/apt/lists/*

# Dependencies first so code changes do not re-download vendor/.
COPY composer/composer.json composer/composer.lock /app/
RUN set -eux \
    ; if [ "$INSTALL_DEV" = "true" ]; then NODEV=""; else NODEV="--no-dev"; fi \
    ; composer install $NODEV --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY composer /app

RUN set -eux \
    ; if [ "$INSTALL_DEV" = "true" ]; then NODEV=""; else NODEV="--no-dev"; fi \
    ; composer dump-autoload $NODEV --optimize \
    ; php artisan package:discover --ansi \
    ; rm -f /app/.env \
    ; chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/atglance-entrypoint
RUN chmod +x /usr/local/bin/atglance-entrypoint

# artisan serve forks this many PHP workers, so one slow request does not
# block the whole console.
ENV PHP_CLI_SERVER_WORKERS=4

EXPOSE 8000

HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=12 \
    CMD curl -fsS http://127.0.0.1:8000/up > /dev/null || exit 1

ENTRYPOINT ["atglance-entrypoint"]

# --no-reload: the installer writes APP_URL to .env mid-request; the watcher
# would restart the server and drop the in-flight response.
CMD ["php", "artisan", "serve", "--host", "0.0.0.0", "--port", "8000", "--no-reload"]
