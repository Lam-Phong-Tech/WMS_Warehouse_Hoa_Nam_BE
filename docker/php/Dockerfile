FROM php:8.3-fpm-alpine

# su-exec: hạ quyền tiến trình chính từ root xuống www-data ở cuối entrypoint
# (xem docker/php/entrypoint.sh). ~20KB, không kéo theo runtime phụ nào, và
# khác `su` ở chỗ nó exec THẲNG chứ không tạo tiến trình trung gian — php-fpm /
# queue:work vẫn là PID 1 của container nên nhận đúng SIGTERM khi stop.
RUN apk add --no-cache \
        su-exec \
        postgresql-dev \
        libzip-dev \
        icu-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_pgsql \
        pgsql \
        bcmath \
        zip \
        intl \
        opcache \
        gd \
        pcntl

# ext-redis (phpredis) — client Redis viết bằng C, thay cho predis (thuần PHP).
# Cài SẴN nhưng KHÔNG bật mặc định: driver vẫn do REDIS_CLIENT quyết định
# (config/database.php). Stack dev giữ nguyên `predis`, chỉ hồ sơ đo tải/chạy
# thật đặt REDIS_CLIENT=phpredis. Mỗi request WMS chạm Redis nhiều lần (cache
# RBAC, session, rate limit) nên đây là phần chi phí giảm được nhiều nhất mà
# không đụng một dòng code ứng dụng nào.
RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .phpize-deps \
    && rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-wms-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-wms-pool.conf

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
# Chuẩn hoá quyền ghi cho storage và bootstrap/cache. Tách khỏi entrypoint để
# perf-entrypoint.sh gọi lại được sau khi hâm nóng cache bằng root.
COPY docker/php/ensure-runtime-permissions.sh /usr/local/bin/ensure-runtime-permissions.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/ensure-runtime-permissions.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
