#!/bin/sh
set -e

cd /var/www/html

# User mà ứng dụng thực sự chạy bằng. Phải khớp `user` của pool php-fpm trong
# image gốc (php:8.3-fpm-alpine đặt user = www-data).
RUNTIME_USER="${RUNTIME_USER:-www-data}"
export RUNTIME_USER

# Quyền ghi runtime phải chuẩn TRƯỚC khi bất kỳ lệnh artisan nào chạy: composer,
# key:generate, migrate đều có thể ghi log, và nếu storage/logs chưa ghi được
# thì chính lệnh khởi động đã hỏng. Xem docker/php/ensure-runtime-permissions.sh.
ensure_runtime_permissions() {
    /usr/local/bin/ensure-runtime-permissions.sh
}

ensure_runtime_permissions

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

# Gọi lại LẦN CUỐI: mọi lệnh ở trên chạy bằng root nên vừa sinh ra file mới
# (vendor stamp, bootstrap/cache/*.php, storage/logs/laravel.log, session/view
# cache). Không nhận lại ownership ở đây thì tiến trình chính chạy bằng
# www-data mở ghi không được và request đầu tiên trả 500.
ensure_runtime_permissions

# HẠ QUYỀN tiến trình chính. Đây là lớp phòng thủ thứ hai, khác lớp chown ở
# trên: chown chỉ sửa được những file ĐÃ tồn tại lúc container khởi động, còn
# hạ quyền làm mọi file ứng dụng sinh ra SAU đó (log, cache, session, view)
# đúng owner ngay từ lúc tạo.
#
# Vì sao cần: queue:work, schedule:work và reverb:start trước đây chạy thẳng
# bằng root. Tiến trình root nào ghi log trước sẽ tạo file root:root; php-fpm
# mất quyền ghi, Monolog ném UnexpectedValueException và login trả 500. Với
# LOG_STACK=single hiện tại thì lần chown ở boot còn cứu được, nhưng nếu đổi
# sang `daily` thì file log sinh lúc 00:00 tái hiện lỗi mà không cần deploy.
#
# NGOẠI LỆ php-fpm: master của nó PHẢI giữ root, vì hai lý do đo được:
#   1. Nó reopen `error_log = /proc/self/fd/2` (pipe stderr do root sở hữu).
#      Hạ quyền trước thì FPM chết ngay lúc khởi động:
#      "ERROR: failed to open error_log (/proc/self/fd/2): Permission denied"
#      -> "FPM initialization failed", container exit 78.
#   2. Chính master mới fork được worker theo `user = www-data` của pool.
# Nghĩa là code ứng dụng của backend VỐN ĐÃ chạy bằng www-data (ps: "www-data
# php-fpm: pool www"); master chỉ giám sát và không ghi file nào của ứng dụng.
# Hạ quyền master không đóng thêm lỗ hổng nào mà chỉ mất log FPM trong
# `docker logs`.

# Quét TOÀN BỘ đối số, không chỉ $1: perf-entrypoint.sh gọi entrypoint gốc dưới
# dạng `entrypoint.sh sh -c "<warm>; exec \"$@\"" wms-perf php-fpm`, nên php-fpm
# nằm ở CUỐI chứ không phải $1. Chỉ nhìn $1 thì hồ sơ perf sẽ hạ quyền master
# php-fpm và container chết exit 78.
for _arg in "$@"; do
    case "$_arg" in
        php-fpm | */php-fpm)
            exec "$@"
            ;;
    esac
done

# Chỉ hạ khi đang là root: `docker run/exec -u www-data` phải chạy được bình
# thường thay vì chết vì su-exec.
if [ "$(id -u)" = "0" ]; then
    exec su-exec "$RUNTIME_USER" "$@"
fi

exec "$@"
