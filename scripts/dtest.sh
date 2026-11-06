#!/usr/bin/env bash
# تشغيل اختبارات بعينها في صورة الاختبار مع الكود الحالي (تطوير محلي على ويندوز، حيث SQLite المحلي أقدم من المطلوب)
#   bash scripts/dtest.sh tests/Feature/WhatsApp/OpenWa [--filter x]
# المستودع يُربط للقراءة في /src ثم تُنسخ المسارات إلى /app: قراءة PHP للمجلدات عبر ربط Docker Desktop على ويندوز
# تُرجع قوائم ناقصة (readdir)، فتنجح حراسات المعمار أو تفشل خطأً. النسخ المحلي داخل الحاوية يُصلح ذلك.
cd "$(dirname "$0")/.." || exit 1
R="$(pwd -W 2>/dev/null || pwd)"
P="app tests database config routes resources docs docker scripts .github docker-compose.yml bootstrap/app.php .env.example README.md SECURITY.md Dockerfile composer.json package.json"
MSYS_NO_PATHCONV=1 exec docker run --rm --network none -v "$R:/src:ro" \
  nuskjo-test:latest sh -c "for p in $P; do rm -rf /app/\$p; mkdir -p /app/\$(dirname \$p); cp -a /src/\$p /app/\$p; done; composer dump-autoload -q 2>/dev/null; php -d memory_limit=1G vendor/bin/phpunit --no-coverage $*"
