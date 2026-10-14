#!/bin/bash
set -e

echo "🚀 NUSKJO — Starting Production Setup..."

cd /var/www/html

# مسار القاعدة: يعمل مع التخطيط القديم (database/database.sqlite) والجديد (database/data/)
DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
DB_DIR="$(dirname "$DB_FILE")"

as_www() { su -s /bin/sh www-data -c "$*"; }

# Ensure .env file exists (needed for key:generate and config:cache)
if [ ! -f .env ]; then
    if [ -f .env.production ]; then
        cp .env.production .env
    else
        touch .env
    fi
fi

# مفتاح التطبيق: توليد مفتاح جديد في الإنتاج يُفسد الإعدادات المشفّرة (رموز واتساب ومفاتيح الذكاء)
if [ -z "$APP_KEY" ] || ! grep -q '^APP_KEY=base64:' .env; then
    if [ "$APP_ENV" = "production" ] && [ "${ALLOW_KEY_GENERATE:-0}" != "1" ]; then
        echo "❌ APP_KEY غير مضبوط في .env.production — رفض الإقلاع (ALLOW_KEY_GENERATE=1 لتجاوز ذلك عمداً)"
        exit 1
    fi
    echo "🔑 Generating application key..."
    php artisan key:generate --force --no-interaction
fi

# Export APP_KEY from .env file so it overrides the empty env var from docker-compose
APP_KEY=$(grep '^APP_KEY=' .env | cut -d'=' -f2-)
export APP_KEY

# القاعدة: لا ننشئ ملفاً فارغاً في الإنتاج، فغيابها يعني حجماً خاطئاً أو غير مركّب
if [ ! -s "$DB_FILE" ]; then
    if [ "$APP_ENV" = "production" ] && [ "${ALLOW_EMPTY_DB:-0}" != "1" ]; then
        echo "❌ القاعدة غير موجودة في $DB_FILE — رفض الإقلاع (ALLOW_EMPTY_DB=1 لقاعدة جديدة عمداً)"
        exit 1
    fi
    echo "📦 Creating SQLite database at $DB_FILE..."
    mkdir -p "$DB_DIR"
    touch "$DB_FILE"
fi

# صلاحيات محددة: القاعدة والنسخ لـ www-data وحده، والقابل للكتابة المشتركة يبقى 775
chown www-data:www-data "$DB_DIR" "$DB_FILE"
find "$DB_DIR" -maxdepth 1 -name "$(basename "$DB_FILE")*" -exec chown www-data:www-data {} +
chmod 700 "$DB_DIR"
chmod 600 "$DB_FILE"
mkdir -p storage/app/backups storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod 700 storage/app/backups
chmod -R 775 storage/framework storage/logs bootstrap/cache

# Cache configuration for production
echo "⚡ Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# الهجرة والبذرة باسم www-data: النسخة قبل الهجرة والسجلات لا تُنشأ مملوكة لـ root
echo "🗄️ Running migrations..."
as_www "php artisan migrate --force --no-interaction"

# Create storage symlink
php artisan storage:link --force 2>/dev/null || true

# Seed chart of accounts if fresh database
as_www "php artisan db:seed --class=ChartOfAccountsSeeder --force --no-interaction" 2>/dev/null || true

# ما قد يكون أنشأه root أثناء الإقلاع (التخزين المؤقت للعروض والإعدادات)
chown -R www-data:www-data storage/framework storage/logs bootstrap/cache

echo "✅ NUSKJO is ready! Listening on port 4020"

# Execute the main process (supervisord)
exec "$@"
