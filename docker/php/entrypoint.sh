#!/bin/sh
set -e

cd /var/www/html

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader
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

php artisan migrate --force
if [ ! -e public/storage ] && [ ! -L public/storage ]; then
    php artisan storage:link
fi

exec "$@"
