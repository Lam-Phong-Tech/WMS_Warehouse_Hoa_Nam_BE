# Build the repository's existing runtime first (see PUBLIC_QUOTE_TESTING.md).
ARG RUNTIME_IMAGE=hn-public-sandbox-runtime
FROM ${RUNTIME_IMAGE}
ARG APP_BUILD_SHA
ENV APP_BUILD_SHA=${APP_BUILD_SHA}
LABEL org.opencontainers.image.revision=${APP_BUILD_SHA}
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader \
    && mkdir -p storage/sandbox storage/logs storage/framework/cache/data \
        storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["sh", "docker/public-quote-sandbox/start.sh"]
