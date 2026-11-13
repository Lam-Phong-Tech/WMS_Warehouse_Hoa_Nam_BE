#!/bin/sh
set -u

cd "${APP_BASE_PATH:-/var/www/html}" || exit 1

timeout="${QUEUE_WORKER_TIMEOUT:-90}"
memory="${QUEUE_WORKER_MEMORY_MB:-768}"
notifications_pid=""
delivery_pid=""
default_pid=""

cleanup() {
  for pid in "$notifications_pid" "$delivery_pid" "$default_pid"; do
    [ -n "$pid" ] && kill "$pid" 2>/dev/null || true
  done
  for pid in "$notifications_pid" "$delivery_pid" "$default_pid"; do
    [ -n "$pid" ] && wait "$pid" 2>/dev/null || true
  done
}
trap cleanup EXIT
trap 'exit 0' INT TERM

php artisan queue:work --queue="${NOTIFICATION_QUEUE:-notifications}" --tries=3 --timeout="$timeout" --memory="$memory" &
notifications_pid=$!

php artisan queue:work --queue="${NOTIFICATION_DELIVERY_QUEUE:-notification-delivery}" --tries=1 --timeout="$timeout" --memory="$memory" &
delivery_pid=$!

php artisan queue:work --queue=default --tries=3 --timeout="$timeout" --memory="$memory" &
default_pid=$!

# Any failed consumer restarts the group; HTTP push never blocks history or exports.
while kill -0 "$notifications_pid" 2>/dev/null && kill -0 "$delivery_pid" 2>/dev/null && kill -0 "$default_pid" 2>/dev/null; do
  sleep 2
done
