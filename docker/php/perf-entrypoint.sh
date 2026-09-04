#!/bin/sh
# ---------------------------------------------------------------------------
# Entrypoint của HỒ SƠ ĐO TẢI / CHẠY THẬT (perf profile).
#
# KHÔNG được dùng cho stack dev mặc định: file này chỉ được nạp khi khởi động
# kèm overlay `docker-compose.perf.yml`. Nó không sửa dòng code ứng dụng nào —
# chỉ sinh cấu hình PHP theo biến môi trường rồi giao lại cho entrypoint gốc.
#
# Ba việc nó làm, theo đúng thứ tự:
#   1. Ghi đè pool php-fpm và opcache theo tài nguyên máy thật (tham số hoá).
#   2. Giao cho /usr/local/bin/entrypoint.sh (composer install / key / migrate).
#   3. Trước khi chạy lệnh chính, hâm nóng cache config/route/event của Laravel.
#
# Vì sao phải sinh động thay vì COPY file tĩnh: php-fpm KHÔNG nội suy biến môi
# trường trong file cấu hình pool (chỉ hiểu $pool). Muốn một image chạy được cả
# máy 8GB lẫn server 64GB thì phải sinh file lúc khởi động.
# ---------------------------------------------------------------------------
set -e

# --- Kích thước pool php-fpm ------------------------------------------------
# Quy tắc: mỗi worker php-fpm là MỘT tiến trình OS chiếm ~40-55MB khi opcache đã
# nóng. Đặt max_children vượt quá RAM còn trống sẽ đổi lỗi "hết kết nối" lấy lỗi
# OOM-kill — tệ hơn nhiều. Luôn tính từ RAM thật, không đoán.
PHP_FPM_PM="${PHP_FPM_PM:-dynamic}"
PHP_FPM_MAX_CHILDREN="${PHP_FPM_MAX_CHILDREN:-64}"
PHP_FPM_START_SERVERS="${PHP_FPM_START_SERVERS:-16}"
PHP_FPM_MIN_SPARE="${PHP_FPM_MIN_SPARE:-12}"
PHP_FPM_MAX_SPARE="${PHP_FPM_MAX_SPARE:-32}"
PHP_FPM_MAX_REQUESTS="${PHP_FPM_MAX_REQUESTS:-1000}"
# Hàng đợi kết nối của php-fpm. Mặc định 511; nâng lên để đợt tải dồn (nhiều
# người bấm cùng lúc) được XẾP HÀNG thay vì bị OS từ chối kết nối.
PHP_FPM_BACKLOG="${PHP_FPM_BACKLOG:-8192}"

cat > /usr/local/etc/php-fpm.d/zz-wms-pool.conf <<CONF
; Sinh tự động bởi docker/php/perf-entrypoint.sh — KHÔNG sửa tay trong container.
[www]
pm = ${PHP_FPM_PM}
pm.max_children = ${PHP_FPM_MAX_CHILDREN}
pm.start_servers = ${PHP_FPM_START_SERVERS}
pm.min_spare_servers = ${PHP_FPM_MIN_SPARE}
pm.max_spare_servers = ${PHP_FPM_MAX_SPARE}
pm.max_requests = ${PHP_FPM_MAX_REQUESTS}
pm.process_idle_timeout = 10s
listen.backlog = ${PHP_FPM_BACKLOG}
; Ghi log request chậm để biết đường nào đang kéo p95 lên, thay vì đoán.
slowlog = /proc/self/fd/2
request_slowlog_timeout = ${PHP_FPM_SLOWLOG_TIMEOUT:-3s}
catch_workers_output = yes
decorate_workers_output = no
CONF

# --- opcache ----------------------------------------------------------------
# validate_timestamps=0 là thay đổi có tác động lớn nhất trên bind mount Windows:
# tắt hẳn việc stat() từng file PHP ở mỗi request. ĐỔI LẠI: sửa code sẽ KHÔNG có
# hiệu lực cho tới khi khởi động lại container — chấp nhận được với hồ sơ đo
# tải/chạy thật, và chính vì vậy nó không nằm trong stack dev.
OPCACHE_VALIDATE_TIMESTAMPS="${OPCACHE_VALIDATE_TIMESTAMPS:-0}"
OPCACHE_MEMORY="${OPCACHE_MEMORY:-256}"
OPCACHE_MAX_FILES="${OPCACHE_MAX_FILES:-30000}"
OPCACHE_JIT="${OPCACHE_JIT:-tracing}"
OPCACHE_JIT_BUFFER="${OPCACHE_JIT_BUFFER:-64M}"

cat > /usr/local/etc/php/conf.d/zz-wms-opcache.ini <<CONF
; Sinh tự động bởi docker/php/perf-entrypoint.sh — KHÔNG sửa tay trong container.
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=${OPCACHE_MEMORY}
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=${OPCACHE_MAX_FILES}
opcache.max_wasted_percentage=10
opcache.validate_timestamps=${OPCACHE_VALIDATE_TIMESTAMPS}
opcache.revalidate_freq=0
; save_comments=1 là BẮT BUỘC: Laravel/Swagger đọc annotation từ docblock qua
; reflection. Tắt đi tiết kiệm chút bộ nhớ nhưng làm hỏng chức năng.
opcache.save_comments=1
opcache.jit=${OPCACHE_JIT}
opcache.jit_buffer_size=${OPCACHE_JIT_BUFFER}

; Laravel phân giải rất nhiều đường dẫn mỗi request; cache realpath lớn đặc biệt
; quan trọng khi mã nguồn được bind-mount từ Windows.
realpath_cache_size=8192K
realpath_cache_ttl=1200

memory_limit=${PHP_MEMORY_LIMIT:-512M}
CONF

# --- Hâm nóng cache của Laravel --------------------------------------------
# Chạy SAU entrypoint gốc (cần vendor/ và .env đã sẵn sàng) nhưng TRƯỚC lệnh
# chính, nên được bọc vào chuỗi lệnh truyền cho entrypoint gốc.
#
# Mỗi lệnh đều best-effort: một hệ thống chạy mà thiếu cache vẫn tốt hơn một
# container không khởi động được. Chỉ service `backend` nên đặt PERF_OPTIMIZE=true
# — bootstrap/cache nằm trên bind mount dùng chung, để nhiều container cùng ghi
# một lúc thì có thể đọc phải file đang viết dở.
if [ "${PERF_OPTIMIZE:-false}" = "true" ]; then
    WARM='php artisan config:cache || echo "[perf] BO QUA config:cache";
          php artisan route:cache  || echo "[perf] BO QUA route:cache (co route dung Closure?)";
          php artisan event:cache  || echo "[perf] BO QUA event:cache";
          /usr/local/bin/ensure-runtime-permissions.sh;'
else
    WARM=''
fi

# Ba lệnh cache trên chạy bằng root và chạy SAU lần chuẩn hoá quyền cuối cùng
# của entrypoint gốc, nên bootstrap/cache/{config,routes-v7,events}.php vừa sinh
# ra là root:root — php-fpm chạy bằng www-data không ghi đè được ở lần deploy
# sau. Vì vậy phải gọi lại ensure-runtime-permissions.sh ngay sau khối WARM.

# Giao lại cho entrypoint gốc, KHÔNG sao chép logic của nó (composer install có
# điều kiện, key:generate, jwt:secret, migrate, storage:link).
#
# Cách truyền lệnh: `sh -c '<script>' <tên> "$@"` đặt $0=<tên> và giữ nguyên
# từng đối số trong "$@". Nếu nối chuỗi bằng $* thì CMD dạng
# ["sh","-lc","php artisan queue:work --tries=3"] sẽ bị tách thành nhiều đối số
# rời và container queue không khởi động được.
exec /usr/local/bin/entrypoint.sh sh -c "${WARM}"' exec "$@"' wms-perf "$@"
