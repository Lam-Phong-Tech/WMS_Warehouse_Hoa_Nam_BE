#!/bin/sh
set -eu
cd /var/www/html
# This image never mounts an operational database. Refuse unexpected settings
# before migration, not only in the fixture command that runs afterwards.
[ "$APP_ENV" = testing ]
[ "$DB_CONNECTION" = sqlite ]
[ "$DB_DATABASE" = /var/www/html/storage/sandbox/hn_public_quote_test.sqlite ]
[ "${DB_URL:-}" = '' ]
[ "$PUBLIC_QUOTE_SANDBOX_ENABLED" = true ]
[ "$MAIL_MAILER" = array ]
[ "$QUEUE_CONNECTION" = sync ]
[ "$BROADCAST_CONNECTION" = null ]
mkdir -p storage/sandbox storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
if [ ! -f storage/sandbox/keys.env ]; then
    (umask 077; php -r 'echo "APP_KEY=base64:".base64_encode(random_bytes(32))."\nJWT_SECRET=".bin2hex(random_bytes(32))."\n";' > storage/sandbox/keys.env)
fi
# Keep the sandbox encryption keys with its database across container restarts.
. storage/sandbox/keys.env
export APP_KEY JWT_SECRET
[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"
chown -R www-data:www-data storage bootstrap/cache
chmod 700 storage/sandbox
su-exec www-data php artisan package:discover --no-interaction
su-exec www-data php artisan config:clear --no-interaction
su-exec www-data php -d memory_limit=1G artisan migrate --force --no-interaction
su-exec www-data php artisan wms:public-quote-sandbox prepare > storage/sandbox/manifest.json
chown www-data:www-data storage/sandbox/manifest.json
# No db:seed, migrate:fresh, queue worker or external notification service.
exec su-exec www-data php artisan serve --host=0.0.0.0 --port=8080 --no-reload
