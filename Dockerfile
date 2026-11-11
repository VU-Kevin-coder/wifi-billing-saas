# syntax=docker/dockerfile:1.7

# ─────────────────────────────────────────────────────────────
# Base: PHP 8.3 FPM (matches Laravel 12 requirement: 8.2+)
# ─────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS base

# Install system utilities + PHP extensions via mlocati's
# install-php-extensions (handles build deps + cleanup + the
# known touch /usr/src/php/.docker-delete-me bug)
RUN apk add --no-cache \
        bash \
        git \
        unzip \
        linux-headers \
        curl \
        iproute2 \
        wireguard-tools \
        libcap-utils \
    && curl -sSLf https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions \
       -o /usr/local/bin/install-php-extensions \
    && chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions \
        pdo_mysql \
        pdo_sqlite \
        mbstring \
        bcmath \
        gd \
        zip \
        intl \
        opcache \
        pcntl \
        redis

RUN setcap cap_net_admin+ep "$(command -v wg)"

# Install Composer (from official Composer image — pinned)
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ─────────────────────────────────────────────────────────────
# App: copy project + install dependencies
# ─────────────────────────────────────────────────────────────
WORKDIR /var/www/html

# Copy composer files first for better layer caching
COPY composer.json composer.lock ./
RUN composer install \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

# Copy the rest of the project
COPY . .

# Regenerate autoloader + run scripts
RUN composer dump-autoload --optimize --no-interaction \
    && php artisan storage:link \
    && mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Copy PHP config + entrypoint
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# ─────────────────────────────────────────────────────────────
# Runtime
# ─────────────────────────────────────────────────────────────
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]