#!/bin/sh
set -eu
cd /var/www/html
. storage/sandbox/keys.env
export APP_KEY JWT_SECRET
exec su-exec www-data php artisan "$@"
