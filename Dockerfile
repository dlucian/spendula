# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# Stage 1 — vendor install (composer)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app

# Copy only files needed to resolve + install dependencies.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 2 — runtime (php-fpm)
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine

RUN set -eux; \
    # Runtime libs we keep forever.
    apk add --no-cache \
        bash \
        icu-libs \
        libpq \
        oniguruma \
        postgresql-client \
        tzdata; \
    # Build-time headers/libs for the PHP extension compile; dropped at the end.
    apk add --no-cache --virtual .build-deps \
        icu-dev \
        libpq-dev \
        linux-headers \
        oniguruma-dev; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql; \
    apk del --no-network --no-cache .build-deps; \
    rm -rf /var/cache/apk/*

# Opcache tuned for CLI + fpm long-lived processes.
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.interned_strings_buffer=16'; \
} > /usr/local/etc/php/conf.d/opcache.ini

WORKDIR /var/www/html

# Copy application source (excluding things in .dockerignore).
COPY . .

# Drop in the vendored dependencies from stage 1.
COPY --from=vendor /app/vendor ./vendor

# Regenerate the package manifest fresh against the no-dev vendor tree.
# The host's bootstrap/cache is excluded by .dockerignore.
RUN php artisan package:discover --ansi

# Storage and cache must be writable by php-fpm's www-data.
# The Laravel source tree already has storage/framework/{cache,sessions,views,testing},
# storage/logs, and bootstrap/cache — we just re-own and re-permission them.
RUN set -eux; \
    mkdir -p storage/keys; \
    chown -R www-data:www-data storage bootstrap/cache; \
    chmod -R 775 storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm", "-F"]
