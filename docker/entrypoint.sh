#!/bin/sh
set -eu

if [ "${MIGRATE_ON_START:-0}" = "1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction
fi

exec "$@"
