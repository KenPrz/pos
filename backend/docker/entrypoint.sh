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
    echo "APP_KEY is empty — run 'make dev-key' and put the value in the root .env as POS_DEV_APP_KEY" >&2
    exit 1
fi

if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
fi

if [ "${MIGRATE_ON_BOOT:-0}" = "1" ]; then
    php artisan migrate --force
fi

exec "$@"
