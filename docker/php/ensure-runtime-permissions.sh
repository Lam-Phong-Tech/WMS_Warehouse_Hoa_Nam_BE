#!/bin/sh
# ---------------------------------------------------------------------------
# Chuẩn hoá quyền ghi runtime cho storage/ và bootstrap/cache.
#
# VÌ SAO CẦN: entrypoint chạy bằng `root` (composer install, key:generate,
# migrate, storage:link, optimize), nhưng php-fpm phục vụ request bằng
# `www-data` (pool www trong docker/php/www.conf). Mọi file do root sinh ra
# trong storage/ hay bootstrap/cache đều là root:root mode 644 — www-data mở
# ghi không được. Với storage/logs/laravel.log thì Monolog ném
# UnexpectedValueException ngay trong lúc ghi log, và vì RBAC/cache có ghi log
# nên request đăng nhập trả 500 thay vì 200. Triệu chứng nhìn như "lỗi login"
# nhưng thật ra là quyền file, nên clear cache hay chown thủ công chỉ chữa
# tạm — deploy/rebuild kế tiếp lại tái phát.
#
# CÁCH DÙNG: gọi HAI LẦN trong entrypoint.
#   - lần đầu: bảo đảm thư mục tồn tại và ghi được TRƯỚC khi artisan chạy;
#   - lần cuối: nhận lại ownership của file MỚI sinh trong composer/migrate/
#     optimize/log khởi động (đều chạy bằng root).
#
# Không dùng chmod 777: đúng ownership mới là điểm mấu chốt, 777 chỉ giấu bug
# và mở rộng quyền ghi cho mọi user trong container.
# ---------------------------------------------------------------------------
set -e

APP_DIR="${APP_DIR:-/var/www/html}"
RUNTIME_USER="${RUNTIME_USER:-www-data}"

cd "$APP_DIR"

mkdir -p \
    storage/logs \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/app/private \
    bootstrap/cache

# LOG_STACK=single nên chỉ có đúng một storage/logs/laravel.log. Tạo sẵn bằng
# root rồi giao lại cho www-data: nếu để trống, tiến trình root nào chạm log
# trước (queue worker, scheduler, artisan thủ công) sẽ tạo file root:root và
# php-fpm mất quyền ghi ngay từ request đầu tiên.
[ -e storage/logs/laravel.log ] || : > storage/logs/laravel.log

# Chỉ root mới chown được. Khi entrypoint đã bị hạ quyền (docker compose
# exec -u www-data ...) thì bỏ qua thay vì làm chết container.
if [ "$(id -u)" != "0" ]; then
    echo "[perm] Bo qua chuan hoa quyen: dang chay bang uid $(id -u), khong phai root."
    exit 0
fi

# Bind mount của Docker Desktop trên Windows không hỗ trợ chown/chmod thật —
# lệnh có thể báo lỗi. Đó là môi trường dev, không phải lý do để container
# không khởi động được, nên cảnh báo rồi đi tiếp.
if ! chown -R "${RUNTIME_USER}:${RUNTIME_USER}" storage bootstrap/cache 2>/dev/null; then
    echo "[perm] CANH BAO: chown storage/bootstrap-cache that bai (bind mount khong ho tro?)."
fi

# `-exec ... +` gộp nhiều đường dẫn vào một lần gọi chmod; dạng `\;` gọi một
# tiến trình cho MỖI file và rất chậm trên bind mount.
find storage bootstrap/cache -type d -exec chmod 775 {} + 2>/dev/null || true
find storage/logs storage/framework bootstrap/cache -type f -exec chmod 664 {} + 2>/dev/null || true
