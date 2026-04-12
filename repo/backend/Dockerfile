# =============================================================================
# Meridian Backend — PHP 8.3 / Laravel 11
# =============================================================================
# Single-stage production image for offline single-host deployment.
# No reverse proxy — Laravel's built-in server serves on port 8000.
# =============================================================================

# ---------------------------------------------------------------------------
# Base image: php:8.3-cli-alpine
# We use `php artisan serve` (Laravel's built-in server) managed by
# supervisord — php-fpm is NOT used. cli-alpine keeps the image lean.
# ---------------------------------------------------------------------------
FROM php:8.3-cli-alpine AS base

# ---------------------------------------------------------------------------
# System dependencies
# ---------------------------------------------------------------------------
RUN apk add --no-cache \
    # Build tools
    bash \
    git \
    unzip \
    curl \
    # MySQL client libraries (required by pdo_mysql)
    mysql-client \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    # For TCPDF/FPDI PDF watermarking
    ghostscript \
    # Cron for Laravel scheduler
    dcron \
    # Process supervisor
    supervisor

# ---------------------------------------------------------------------------
# PHP extensions
# ---------------------------------------------------------------------------
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        bcmath \
        pcntl \
        intl \
        zip \
        gd \
        opcache

# fileinfo is bundled into this PHP build, so there is no shared module to enable.

# ---------------------------------------------------------------------------
# PHP configuration
# ---------------------------------------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/conf.d/meridian.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# ---------------------------------------------------------------------------
# Composer
# ---------------------------------------------------------------------------
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------
WORKDIR /var/www/html

# Copy dependency manifests first for layer caching.
# Supports repositories that have only composer.json (no composer.lock yet).
COPY composer.* ./

# Install production dependencies only (no dev packages in production image)
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-autoloader \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# Copy application source
COPY . .

# ---------------------------------------------------------------------------
# Storage and permissions
# ---------------------------------------------------------------------------
RUN mkdir -p \
        storage/app/attachments \
        storage/app/backups \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Generate optimized autoloader and run the Laravel asset publish step
# only after the application source (including artisan) is present.
RUN composer dump-autoload --no-dev --optimize \
    && php artisan vendor:publish --tag=laravel-assets --ansi --force

# ---------------------------------------------------------------------------
# Laravel scheduler via cron
# ---------------------------------------------------------------------------
# Alpine's dcron uses per-user crontabs without an explicit username column.
RUN echo "* * * * * php /var/www/html/artisan schedule:run >> /dev/null 2>&1" \
    > /etc/crontabs/root \
    && chmod 0644 /etc/crontabs/root

# ---------------------------------------------------------------------------
# Supervisor configuration (manages artisan serve + queue worker + cron)
# ---------------------------------------------------------------------------
RUN mkdir -p /var/log/supervisor
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# ---------------------------------------------------------------------------
# Expose application port
# ---------------------------------------------------------------------------
EXPOSE 8000

# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# ---------------------------------------------------------------------------
# Test image target
# ---------------------------------------------------------------------------
# Includes dev dependencies (Pest/PHPUnit tooling) without changing the
# production runtime image.
FROM base AS test

# Disable OPcache in test image to reduce memory footprint (~256MB savings)
RUN echo "opcache.enable=0" > /usr/local/etc/php/conf.d/zz-test-overrides.ini \
    && echo "opcache.enable_cli=0" >> /usr/local/etc/php/conf.d/zz-test-overrides.ini \
    && echo "memory_limit=256M" >> /usr/local/etc/php/conf.d/zz-test-overrides.ini

RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader
