#!/bin/bash
set -e

echo "🚀 NUSKJO — Starting Production Setup..."

cd /var/www/html

# مسار القاعدة: يعمل مع التخطيط القديم (database/database.sqlite) والجديد (database/data/)
DB_FILE="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
DB_DIR="$(dirname "$DB_FILE")"

as_www() { su -s /bin/sh www-data -c "$*"; }

# البيئة من env_file وحده (.env.production على المضيف)، لا من نسخة مخبوزة في الصورة.
# في الإنتاج: مفتاح لا يُفك إلى 32 بايتاً أو APP_DEBUG مفعّل يرفضان الإقلاع (BOOT_GUARD=warn للطوارئ)
/usr/local/bin/check-env.sh

# خارج الإنتاج فقط: مفتاح مؤقت لهذا التشغيل (لا يُكتب في أي ملف)
if [ -z "${APP_KEY:-}" ] && [ "$APP_ENV" != "production" ]; then
    echo "⚠️  APP_KEY غير مضبوط — مفتاح مؤقت لهذا التشغيل فقط (خارج الإنتاج)"
    APP_KEY=$(php artisan key:generate --show --no-interaction)
    export APP_KEY
fi

# بصمة لا المفتاح: تكفي لمطابقته بنسخة مدير كلمات المرور
echo "🔑 APP_KEY fingerprint: $(printf '%s' "$APP_KEY" | sha256sum | cut -c1-8)"

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
mkdir -p storage/app/backups storage/app/public storage/app/wa-media/.tmp storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod 700 storage/app/backups
# مرفقات العملاء (جوازات ووثائق، E14): لـ www-data وحده، ولا يفتحها أي chmod لاحق على storage
find storage/app/wa-media -type d -exec chmod 700 {} +
find storage/app/wa-media -type f -exec chmod 600 {} +
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

# صحة القناة في docker logs (لا يوقف الإقلاع): أسرار لا تُفك، رمز تحقق قصير، كلمة مرور افتراضية...
as_www "php artisan whatsapp:doctor --no-ansi" || true

# ما قد يكون أنشأه root أثناء الإقلاع (التخزين المؤقت للعروض والإعدادات)
chown -R www-data:www-data storage/framework storage/logs bootstrap/cache
# ملفا -wal و-shm إن أنشأهما root تصير القاعدة للقراءة فقط عند www-data
find "$DB_DIR" -maxdepth 1 -name "$(basename "$DB_FILE")*" -exec chown www-data:www-data {} + 2>/dev/null || true

# صلاحيات storage المتناسبة (E26.7)، بعد كل ما أنشأه root أثناء الإقلاع. مجلدا الجوازات والنسخ خارج كل find
# هنا وفي التراجع (-prune): يبقيان 700/600 كما ضبطهما ما سبق (A17، E14).
#   strict: مجلدات 750 وملفات 640؛ nginx يقرأ /storage عبر مجموعة www-data (addgroup في Dockerfile).
#   غير ذلك (الافتراضي حتى يُتحقق من ضم nginx): الملفات العامة 755/644 كما كانت، فإعادة legacy ثم up -d تراجعٌ كامل
if [ "${NUSKJO_STORAGE_PERMS:-legacy}" = "strict" ]; then
    find storage \( -path storage/app/wa-media -o -path storage/app/backups \) -prune -o -type d -exec chmod 750 {} +
    find storage \( -path storage/app/wa-media -o -path storage/app/backups \) -prune -o -type f -exec chmod 640 {} +
    chmod 750 bootstrap/cache && find bootstrap/cache -type f -exec chmod 640 {} +
    echo "🔒 storage: 750/640 (strict)"
else
    # المجلدان الأعليان أيضاً: بعد strict يبقيان 750 فلا يعبرهما nginx إن لم يكن في المجموعة (صورة أقدم)
    chmod 755 storage storage/app
    find storage/app/public -type d -exec chmod 755 {} +
    find storage/app/public -type f -exec chmod 644 {} +
fi

echo "✅ NUSKJO is ready! Listening on port 4020"

# Execute the main process (supervisord)
exec "$@"
