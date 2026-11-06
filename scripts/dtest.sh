#!/usr/bin/env bash
# تشغيل اختبارات بعينها في صورة الاختبار مع ربط الكود الحالي (تطوير محلي على ويندوز، حيث SQLite المحلي أقدم من المطلوب)
#   bash scripts/dtest.sh tests/Feature/WhatsApp/OpenWa [--filter x]
cd "$(dirname "$0")/.." || exit 1
R="$(pwd -W 2>/dev/null || pwd)"
exec docker run --rm --network none \
  -v "$R/app:/app/app" -v "$R/tests:/app/tests" -v "$R/database:/app/database" -v "$R/config:/app/config" \
  -v "$R/routes:/app/routes" -v "$R/resources/views:/app/resources/views" -v "$R/docs:/app/docs" -v "$R/docker:/app/docker" \
  -v "$R/docker-compose.yml:/app/docker-compose.yml" -v "$R/bootstrap/app.php:/app/bootstrap/app.php" \
  nuskjo-test:latest sh -c "composer dump-autoload -q 2>/dev/null; php -d memory_limit=1G vendor/bin/phpunit --no-coverage $*"
