#!/bin/sh
# Smoke cục bộ của job ci (hn-devops-ci-cd.yml): migrate + seed THẬT, bật
# server, chờ GET /api/v1/health trả success, rồi chạy probe hộp đen hợp đồng
# refresh token của trình duyệt trên chính server đó.
#
# Vì sao tách ra file: bản một dòng cũ viết `migrate:fresh --seed && serve &
# sleep 5 && health` — dấu `&` đưa CẢ chuỗi migrate→serve xuống nền, còn
# `sleep 5 && health` chạy song song ngay lập tức; seed mất ~10 phút nên sau
# 5 giây server chưa tồn tại → "Connection refused" (run 34337763564, lần đầu
# pipeline sống tới bước này). Ở đây seed chạy TRƯỚC, server bật SAU, health
# được chờ có giới hạn thay vì đoán mò 5 giây.
#
# Probe (P1 bảo mật 10/09/2026, scripts/sec-p1-refresh-runtime-probe.php):
# WwhnSecRefreshTokenTransportTest chứng minh hợp đồng ở tầng kernel trên
# SQLite; probe chứng minh CÙNG hợp đồng qua HTTP thật, trên PostgreSQL thật và
# nhiều tiến trình PHP — nơi cấu hình CORS/cookie của môi trường, header do máy
# chủ chèn và race giữa các worker mới lộ ra. Probe exit 1 (có check FAIL) hoặc
# 2 (cấu hình/máy chủ chưa sẵn sàng) làm bước này đỏ. Probe tự thu hồi mọi phiên
# nó tạo; tài khoản là fixture ACTIVE không bị bắt đổi mật khẩu hay MFA.
#
# Không nuốt mã lỗi nào: một lần hỏi health hụt nằm trong điều kiện của `if`
# (server đang khởi động là chuyện bình thường), còn kết quả của cổng do các
# nhánh `exit` tường minh bên dưới quyết định.
set -eu

: "${CI_SERVER_HOST:?thiếu CI_SERVER_HOST}"
: "${CI_SERVER_PORT:?thiếu CI_SERVER_PORT}"
: "${CI_APP_URL:?thiếu CI_APP_URL}"
# Biến của probe: workflow truyền tường minh từng biến, thiếu là dừng trước khi
# seed. Mật khẩu fixture không bao giờ được in (probe chỉ in tên check + mã HTTP).
: "${SEC_PROBE_ORIGIN:?thiếu SEC_PROBE_ORIGIN}"
: "${SEC_PROBE_EMAIL:?thiếu SEC_PROBE_EMAIL}"
: "${WMS_FIXTURE_PASSWORD:?thiếu WMS_FIXTURE_PASSWORD}"

php artisan migrate:fresh --seed --force

# 4 worker: phép thử race của probe (N refresh song song cùng một cookie) phải là
# race giữa các tiến trình PHP thật, không phải request xếp hàng trong một tiến
# trình. ServeCommand của Laravel chỉ tôn trọng PHP_CLI_SERVER_WORKERS khi có
# --no-reload.
PHP_CLI_SERVER_WORKERS=4 php artisan serve --no-reload --host="$CI_SERVER_HOST" --port="$CI_SERVER_PORT" > /tmp/laravel.log 2>&1 &
SERVER_PID=$!
# Dọn server khi thoát. `set +e` trong trap: một lệnh kill hụt (server đã tự
# chết) không được đổi mã thoát mà cổng đã chốt — với busybox ash, lệnh lỗi
# trong trap EXIT dưới `set -e` sẽ ghi đè mã thoát.
trap 'set +e; kill "$SERVER_PID" 2>/dev/null' EXIT

healthy=0
body=''
i=0
while [ "$i" -lt 90 ]; do
  if body=$(php -r '$b=@file_get_contents(getenv("CI_APP_URL")."/api/v1/health"); if ($b === false) exit(1); echo $b;' 2>/dev/null) &&
    printf '%s' "$body" | grep -q '"success":true'; then
    healthy=1
    break
  fi
  i=$((i + 1))
  sleep 1
done

if [ "$healthy" -ne 1 ]; then
  echo "health failed sau ${i}s — phản hồi cuối: ${body:-<không có>}" >&2
  echo "--- /tmp/laravel.log ---" >&2
  if [ -f /tmp/laravel.log ]; then
    cat /tmp/laravel.log >&2
  fi
  exit 1
fi

printf '%s\n' "$body"
echo "health OK sau ${i}s"

echo "--- probe hợp đồng refresh token trên runtime đã serve ---"
if SEC_PROBE_BASE_URL="$CI_APP_URL" SEC_PROBE_PASSWORD="$WMS_FIXTURE_PASSWORD" \
  php scripts/sec-p1-refresh-runtime-probe.php; then
  echo "probe hợp đồng refresh token: PASS"
else
  probe_status=$?
  echo "probe hợp đồng refresh token: FAIL (exit ${probe_status}; 1 = có check FAIL, 2 = cấu hình/máy chủ chưa sẵn sàng)" >&2
  echo "--- /tmp/laravel.log (200 dòng cuối) ---" >&2
  if [ -f /tmp/laravel.log ]; then
    tail -n 200 /tmp/laravel.log >&2
  fi
  exit "$probe_status"
fi
