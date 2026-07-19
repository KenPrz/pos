#!/bin/sh
# backend/docker/entrypoint.sh
# Config is cached at BOOT, never at build: the image carries no environment.
set -eu

# APP_KEY is never baked into the image (config is cached at boot, not build —
# see the comment above) so a blank one only surfaces here, at container start.
# compose.dev.yml deliberately no longer hard-fails on a blank
# POS_DEV_APP_KEY at the compose-file level (that broke `up -d db` and even
# `dev-key`'s own `build api` — the whole file's interpolation dies on one
# unset required var, before any service-specific logic runs). So the loud
# failure moves here, to the one service that actually needs the key.
if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is empty — dev: run 'make dev-key' and put the value in the root .env as POS_DEV_APP_KEY; prod: set POS_APP_KEY in the .env beside compose.prod.yml" >&2
    exit 1
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    # compose.prod.yml deliberately uses soft env defaults (`:-`) — hard `:?`
    # guards fail interpolation for EVERY compose command, including `down -v`
    # and `config`. So the required-var check lives here, at boot, where it
    # only stops the one service that needs the values. POS_CURRENCY and
    # POS_BUSINESS_NAME are guarded by the app itself (AppServiceProvider);
    # the db password is guarded by the postgres image. The domains have no
    # later guard — an empty vhost would just serve nothing — so check them now.
    missing=""
    [ -n "${POS_REGISTER_DOMAIN:-}" ] || missing="$missing POS_REGISTER_DOMAIN"
    [ -n "${POS_ADMIN_DOMAIN:-}" ]    || missing="$missing POS_ADMIN_DOMAIN"
    if [ -n "$missing" ]; then
        echo "Missing required production env:$missing — set them in the .env beside compose.prod.yml (see .env.prod.example)" >&2
        exit 1
    fi

    php artisan config:cache
    php artisan route:cache
fi

if [ "${MIGRATE_ON_BOOT:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
