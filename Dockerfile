# ============================================================
# NUSUK — Production Dockerfile
# Laravel 11 + Vue 3 + Inertia + SQLite
# ============================================================

# صور الأساس مثبّتة بالبصمة (E26.8): الوسم العائم قد يتبدل تحتنا بين بناءين. التحديث شهرياً عبر Dependabot (docker)
# أو يدوياً: docker buildx imagetools inspect <الوسم>، ثم بناء وتوليد بطاقة على نسك قبل الدمج.

# ---- Stage 1: Build Frontend Assets ----
# node:22-alpine (Node 22.23.3)، البصمة في 2026-10-16
FROM node:22-alpine@sha256:0a7108bf6c7bf5de370ffb1a3ed6be93d405b43ff159f681a8d18c0e2bc2e402 AS frontend

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

COPY resources/ resources/
COPY vite.config.js postcss.config.js tailwind.config.js ./
# متغيرات VITE_* من .env.production عبر سرّ بناء: Vite يقرأ .env.production ويضمّن VITE_* وحدها،
# والملف لا يبقى في أي طبقة (docker-compose.yml: build.secrets)
RUN --mount=type=secret,id=vite_env,target=/app/.env.production,required=true npm run build

# ---- Stage 2: PHP Production Image ----
# php:8.3-fpm-alpine (PHP 8.3.35)، البصمة في 2026-10-16
FROM php:8.3-fpm-alpine@sha256:454b11c8907e32878ce92e87b13c14e1bbe6e1b10f4f96d4a19c9b62ec675d41 AS production

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
    # تشفير الأرشيفات على المضيف بلا أدوات إضافية (scripts/backup)
    age \
    # ── توليد بطاقات العروض: كروميوم بلا واجهة + خطوط عربية ──
    # gd لا يُشكّل الحروف العربية ولا يضبط اتجاه النصّ، فالتصيير يتم عبر
    # كروميوم من قالب HTML. الخطوط إلزامية: الصورة الأساسية بلا أي خطّ.
    chromium \
    nss \
    freetype \
    harfbuzz \
    fontconfig \
    ttf-freefont \
    font-noto \
    font-noto-arabic \
    # ── رسائل الموظف الصوتية (E34): تحويل تسجيل المتصفح إلى ogg/opus أحادي. الحزمة تحمل ffprobe ومرمّز libopus ──
    ffmpeg \
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

# عمّال nginx (المستخدم nginx) يقرؤون ملفات storage عبر مجموعة www-data (E26.7)، فتصير 640/750 بلا كسر الصور.
# وإصدار كروميوم مسجّلاً: apk لا يثبّت إصداره، فيظهر في سجل البناء وفي whatsapp:doctor
RUN addgroup nginx www-data \
    && { chromium-browser --version 2>/dev/null || chromium --version; } > /etc/chromium-version \
    && cat /etc/chromium-version

# Install Composer — composer:2 (Composer 2.10.3)، البصمة في 2026-10-16
COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer

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
    storage/app/backups \
    storage/app/private \
    database/data \
    bootstrap/cache

# لا قاعدة داخل الصورة: تأتي من الحجم، والإقلاع يرفض غيابها في الإنتاج (docker/entrypoint.sh)

# Set permissions: بلا كتابة للمجموعة ولا قراءة للغير (E26.7)؛ الإقلاع يضبط الحجم نفسه (docker/entrypoint.sh)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache database

# ---- Nginx Configuration ----
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
# ترويسات الأمان المشتركة، يضمّنها كل location فيه add_header (E26.5)
COPY docker/nginx-security-headers.conf /etc/nginx/snippets/security-headers.conf

# ---- PHP-FPM Configuration ----
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/99-custom.ini

# ---- Supervisor Configuration ----
COPY docker/supervisord.conf /etc/supervisord.conf

# ---- Entrypoint Script ----
COPY docker/entrypoint.sh /entrypoint.sh
COPY docker/check-env.sh /usr/local/bin/check-env.sh
RUN chmod +x /entrypoint.sh /usr/local/bin/check-env.sh

# رقم الإصدار في manifest النسخ الاحتياطية (يمرّره scripts/deploy.sh)
ARG GIT_COMMIT=unknown
ENV APP_COMMIT=$GIT_COMMIT

EXPOSE 4020

# للتشغيل بلا compose (compose يتقدّم عليه): /up يفحص القاعدة والمجدول والعاملين
HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 CMD curl -fsS -o /dev/null http://localhost:4020/up || exit 1

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
