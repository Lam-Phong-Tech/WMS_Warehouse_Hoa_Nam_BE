FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip python3 libpq-dev libsqlite3-dev libzip-dev libpng-dev \
    libjpeg62-turbo-dev libfreetype6-dev libicu-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath gd intl mbstring pcntl pdo_pgsql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
# The workflow builds from git archive only, never a runtime directory or .env.
COPY source/ /app/
RUN composer install --no-scripts --no-interaction --prefer-dist --no-progress
