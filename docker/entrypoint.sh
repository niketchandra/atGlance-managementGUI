#!/bin/sh
# AtGlance CE container entrypoint.
#
# The app writes settings back to .env at runtime (installer, S3, SMTP, SSO),
# so .env must survive container re-creation and be shared by the app, worker
# and scheduler containers. It lives on the storage volume and /app/.env is a
# symlink to it. Set ATGLANCE_ENV_FILE=/app/.env to use a bind-mounted file
# instead (development).
set -e

cd /app

ENV_FILE="${ATGLANCE_ENV_FILE:-/app/storage/.env}"

# A fresh named volume is empty: recreate the storage skeleton.
mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

if [ "$ENV_FILE" != "/app/.env" ]; then
    if [ ! -f "$ENV_FILE" ]; then
        if [ "${ATGLANCE_ROLE:-app}" = "app" ]; then
            echo "atglance: creating $ENV_FILE"
            cp .env.example "$ENV_FILE"
            sed -i \
                -e "s|^APP_NAME=.*|APP_NAME=AtGlance|" \
                -e "s|^APP_ENV=.*|APP_ENV=${ATGLANCE_APP_ENV:-production}|" \
                -e "s|^APP_DEBUG=.*|APP_DEBUG=${ATGLANCE_APP_DEBUG:-false}|" \
                -e "s|^LOG_LEVEL=.*|LOG_LEVEL=${ATGLANCE_LOG_LEVEL:-warning}|" \
                "$ENV_FILE"
        else
            # Only the app container creates .env and APP_KEY; worker and
            # scheduler wait for it so all three share one key.
            echo "atglance: waiting for $ENV_FILE from the app container"
            i=0
            while [ ! -f "$ENV_FILE" ] && [ "$i" -lt 120 ]; do
                sleep 2
                i=$((i + 1))
            done
            [ -f "$ENV_FILE" ] || { echo "atglance: $ENV_FILE not found" >&2; exit 1; }
        fi
    fi
    ln -sfn "$ENV_FILE" /app/.env
fi

if [ "${ATGLANCE_ROLE:-app}" = "app" ] && ! grep -q '^APP_KEY=base64:' /app/.env; then
    echo "atglance: generating APP_KEY (back up $ENV_FILE - it encrypts stored secrets)"
    php artisan key:generate --force --no-interaction
fi

# Web sessions live in the database, so even the setup wizard needs the
# schema. Running migrations here also applies new ones on image upgrades.
if [ "${ATGLANCE_ROLE:-app}" = "app" ] && [ "${ATGLANCE_AUTO_MIGRATE:-true}" = "true" ]; then
    i=0
    until php artisan migrate --force --no-interaction; do
        i=$((i + 1))
        [ "$i" -lt 10 ] || { echo "atglance: migrations failed" >&2; exit 1; }
        echo "atglance: database not ready, retrying migrations in 5s"
        sleep 5
    done
fi

chmod -R ug+rwX storage bootstrap/cache 2>/dev/null || true

exec "$@"
