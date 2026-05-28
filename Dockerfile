# ============================================================
# NUSUK — Production Dockerfile
# Laravel 11 + Vue 3 + Inertia + SQLite
# ============================================================

# ---- Stage 1: Build Frontend Assets ----
FROM node:22-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

COPY resources/ resources/
COPY .env.production .env
COPY vite.config.js postcss.config.js tailwind.config.js ./
RUN npm run build

# ---- Stage 2: PHP Production Image ----
FROM php:8.3-fpm-alpine AS production

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    sqlite \
    sqlite-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    curl \
    bash \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo_sqlite \
        gd \
        mbstring \
        zip \
        intl \
        bcmath \
        opcache \
        pcntl \
    && rm -rf /var/cache/apk/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first (for layer caching)
COPY composer.json composer.lock ./

# Install PHP dependencies (no dev)
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application code
COPY . .

# Copy built frontend assets from Stage 1
COPY --from=frontend /app/public/build public/build

# Finalize composer (generate autoloader with app code)
RUN composer dump-autoload --optimize --no-dev

# Create necessary directories
RUN mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    database \
    bootstrap/cache

# Create SQLite database if not exists
RUN touch database/database.sqlite

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache database

# ---- Nginx Configuration ----
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# ---- PHP-FPM Configuration ----
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-custom.ini

# ---- Supervisor Configuration ----
COPY docker/supervisord.conf /etc/supervisord.conf

# ---- Entrypoint Script ----
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 4020

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
