#!/bin/sh
set -e

cd /var/www/html

# Keep Composer dependencies in a Docker named volume (see docker-compose.yml)
# and only reinstall when composer.lock really changes. This avoids thousands of
# vendor filesystem reads/writes through the Windows bind mount on every boot.
lock_hash="no-lock"
if [ -f composer.lock ]; then
    lock_hash="$(sha256sum composer.lock | awk '{print $1}')"
fi
vendor_stamp="vendor/.composer-lock.sha256"
installed_hash=""
if [ -f "$vendor_stamp" ]; then
    installed_hash="$(cat "$vendor_stamp")"
fi

if [ ! -f vendor/autoload.php ] || [ "$installed_hash" != "$lock_hash" ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
    printf '%s\n' "$lock_hash" > "$vendor_stamp"
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64:" .env; then
    php artisan key:generate --force
fi

if ! grep -q "^JWT_SECRET=.\+" .env; then
    php artisan jwt:secret --force
fi

# Only the HTTP backend owns schema migration at startup. Queue workers share
# the same code/vendor volume and must not repeat the migration pass.
if [ "${AUTO_MIGRATE:-true}" = "true" ]; then
    php artisan migrate --force
fi

if [ ! -e public/storage ] && [ ! -L public/storage ]; then
    php artisan storage:link
fi

exec "$@"
