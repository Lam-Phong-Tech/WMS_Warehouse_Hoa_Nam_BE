#!/bin/sh
# Smoke cục bộ của job ci (hn-devops-ci-cd.yml): migrate + seed THẬT, bật
# server, rồi chờ GET /api/v1/health trả success.
#
# Vì sao tách ra file: bản một dòng cũ viết `migrate:fresh --seed && serve &
# sleep 5 && health` — dấu `&` đưa CẢ chuỗi migrate→serve xuống nền, còn
# `sleep 5 && health` chạy song song ngay lập tức; seed mất ~10 phút nên sau
# 5 giây server chưa tồn tại → "Connection refused" (run 34337763564, lần đầu
# pipeline sống tới bước này). Ở đây seed chạy TRƯỚC, server bật SAU, health
# được chờ có giới hạn thay vì đoán mò 5 giây.
set -eu

: "${CI_SERVER_HOST:?thiếu CI_SERVER_HOST}"
: "${CI_SERVER_PORT:?thiếu CI_SERVER_PORT}"
: "${CI_APP_URL:?thiếu CI_APP_URL}"

php artisan migrate:fresh --seed --force

php artisan serve --host="$CI_SERVER_HOST" --port="$CI_SERVER_PORT" > /tmp/laravel.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

i=0
while [ "$i" -lt 90 ]; do
  body=$(php -r '$b=@file_get_contents(getenv("CI_APP_URL")."/api/v1/health"); if ($b === false) exit(1); echo $b;' 2>/dev/null || true)
  if [ -n "$body" ] && printf '%s' "$body" | grep -q '"success":true'; then
    printf '%s\n' "$body"
    echo "health OK sau ${i}s"
    exit 0
  fi
  i=$((i + 1))
  sleep 1
done

echo "health failed sau ${i}s — phản hồi cuối: ${body:-<không có>}" >&2
echo "--- /tmp/laravel.log ---" >&2
cat /tmp/laravel.log >&2 || true
exit 1
