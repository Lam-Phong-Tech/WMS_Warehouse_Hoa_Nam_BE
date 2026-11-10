#!/bin/sh
set -u

cd /var/www/html

timeout="${QUEUE_WORKER_TIMEOUT:-90}"
memory="${QUEUE_WORKER_MEMORY_MB:-768}"
notifications_pid=""
default_pid=""

cleanup() {
  [ -n "$notifications_pid" ] && kill "$notifications_pid" 2>/dev/null || true
  [ -n "$default_pid" ] && kill "$default_pid" 2>/dev/null || true
  [ -n "$notifications_pid" ] && wait "$notifications_pid" 2>/dev/null || true
  [ -n "$default_pid" ] && wait "$default_pid" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

php artisan queue:work --queue=notifications --tries=3 --timeout="$timeout" --memory="$memory" &
notifications_pid=$!

php artisan queue:work --queue=default --tries=3 --timeout="$timeout" --memory="$memory" &
default_pid=$!

# If either worker dies, stop the sibling and let Docker restart both. This keeps
# notification delivery isolated from long-running report exports.
while kill -0 "$notifications_pid" 2>/dev/null && kill -0 "$default_pid" 2>/dev/null; do
  sleep 2
done
