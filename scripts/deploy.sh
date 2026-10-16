#!/usr/bin/env bash
# نشر nuskJO على خادم الإنتاج ببوابة اختبار إلزامية.
#
#   cd /opt/nuskJO && bash scripts/deploy.sh
#
# الخطوات: سحب الكود، بناء صورة الاختبار وتشغيلها بلا شبكة، ثم البناء والتشغيل
# فقط إن نجحت الاختبارات. لا أسرار جديدة، ولا sudo (المستخدم في مجموعة docker).
#
# تجاوز البوابة بقرار المالك فقط، مع سبب يُسجَّل:
#   NUSKJO_DEPLOY_GATE=warn NUSKJO_DEPLOY_REASON="..." bash scripts/deploy.sh
set -euo pipefail

REPO_DIR="${REPO_DIR:-/opt/nuskJO}"
LOG_FILE="${LOG_FILE:-/opt/nuskJO-deploy.log}"
APP_CONTAINER="${APP_CONTAINER:-nuskjo-app}"
GATE="${NUSKJO_DEPLOY_GATE:-enforce}"

log() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) $*" | tee -a "$LOG_FILE" >&2; }

cd "$REPO_DIR"

# 1) السحب: المجلد ملك root، فالسحب عبر حاوية git
docker run --rm -v "$REPO_DIR":/repo -w /repo alpine/git:latest pull origin main
SHA="$(docker run --rm -v "$REPO_DIR":/repo -w /repo alpine/git:latest rev-parse --short HEAD)"
# وقت البناء مع الـ commit في الصورة (E24): يظهران للمدير في الإعدادات وwhatsapp:doctor
BUILD_TIME="$(date -u +%FT%TZ)"
log "deploy start sha=$SHA gate=$GATE"

# 1ب) بوابة تدقيق التبعيات (E26.9): ثغرة high أو critical جديدة غير مستثناة بسبب (composer.json audit.ignore
#     وsecurity/npm-audit-baseline.json). حاويتان للقراءة فقط على المضيف، لأن حاوية الاختبار بلا شبكة،
#     وبالصورتين نفسيهما في Dockerfile فلا تُسحب صورة إضافية. تعذّر الشبكة تحذير لا منع.
#     NUSKJO_AUDIT_GATE=warn (الافتراضي) أسبوع الخط الأساسي: يُسجَّل ولا يمنع؛ ثم enforce بقرار المالك.
AUDIT_GATE="${NUSKJO_AUDIT_GATE:-warn}"
COMPOSER_IMAGE="composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac"
NODE_IMAGE="node:22-alpine@sha256:0a7108bf6c7bf5de370ffb1a3ed6be93d405b43ff159f681a8d18c0e2bc2e402"
audit_findings=""
rc=0
docker run --rm -e COMPOSER_ALLOW_SUPERUSER=1 -v "$REPO_DIR":/app:ro -w /app "$COMPOSER_IMAGE" \
    audit --locked --no-dev --abandoned=report --ignore-severity=low --ignore-severity=medium || rc=$?
case "$rc" in
    0) ;;
    1|2|3) audit_findings+="composer " ;;
    *) log "sha=$SHA composer audit could not run (rc=$rc, network?): not blocking" ;;
esac
rc=0
docker run --rm -v "$REPO_DIR":/app:ro -w /app "$NODE_IMAGE" node scripts/audit/npm-audit-gate.mjs || rc=$?
case "$rc" in
    0) ;;
    1) audit_findings+="npm " ;;
    *) log "sha=$SHA npm audit could not run (rc=$rc, network?): not blocking" ;;
esac
if [[ -n "$audit_findings" ]]; then
    if [[ "$AUDIT_GATE" == "enforce" ]]; then
        log "sha=$SHA dependency audit FAILED (${audit_findings% }); deploy stopped before build"
        exit 1
    fi
    log "sha=$SHA dependency audit findings (${audit_findings% }) with NUSKJO_AUDIT_GATE=warn"
fi

# 2) بوابة الاختبار
if ! nice -n 10 docker build -f Dockerfile.test -t nuskjo-test:latest . \
   || ! docker run --rm --network none --cpus=1 --memory=1g nuskjo-test:latest; then
    if [[ "$GATE" == "warn" ]]; then
        if [[ -z "${NUSKJO_DEPLOY_REASON:-}" ]]; then
            log "sha=$SHA FAILED tests; gate=warn requires NUSKJO_DEPLOY_REASON"
            exit 1
        fi
        log "sha=$SHA tests FAILED but gate bypassed: $NUSKJO_DEPLOY_REASON"
    else
        log "sha=$SHA FAILED tests; deploy stopped before build"
        exit 1
    fi
fi

# 3) تنبيه للقراءة فقط: هجرات في المستودع غائبة داخل الحاوية (حجم database يحجبها حتى E01)
if docker ps --format '{{.Names}}' | grep -qx "$APP_CONTAINER"; then
    missing=$(comm -23 \
        <(ls database/migrations | sort) \
        <(docker exec "$APP_CONTAINER" ls database/migrations 2>/dev/null | sort) || true)
    if [[ -n "$missing" ]]; then
        log "migrations missing inside container (copy manually per deploy notes):"
        while read -r f; do
            [[ -z "$f" ]] && continue
            echo "  docker cp $REPO_DIR/database/migrations/$f $APP_CONTAINER:/var/www/html/database/migrations/" >&2
        done <<< "$missing"
    fi
fi

# 3ب) حارس سياق البناء: لا ملف بيئة ولا قاعدة ولا نسخة ولا مفتاح يدخل الصورة (E06)
if ! sh docker/check-context.sh; then
    log "sha=$SHA build context carries env files, databases or keys; deploy stopped before build"
    exit 1
fi

# 4) حارس فصل الحجم: compose بالتخطيط الجديد قبل نقل القاعدة يُقلع على حجم فارغ (فيرفض الإقلاع)
DB_VOLUME="${DB_VOLUME:-nuskjo_nuskjo-dbdata}"
if grep -q '^ *- nuskjo-dbdata:/var/www/html/database/data' docker-compose.yml \
    && ! docker volume inspect "$DB_VOLUME" >/dev/null 2>&1; then
    docker compose build --build-arg GIT_COMMIT="$SHA" --build-arg BUILD_TIME="$BUILD_TIME" "$APP_CONTAINER"
    log "sha=$SHA built; database volume not split yet: run scripts/backup/move-db-volume.sh (docs/RELEASE.md)"
    echo "التالي: bash scripts/backup/move-db-volume.sh --compose-dir $REPO_DIR --project nuskjo --service $APP_CONTAINER --old nuskjo_nuskjo-database --new $DB_VOLUME --check-only" >&2
    exit 1
fi

# 5) البناء والتشغيل
docker compose build --build-arg GIT_COMMIT="$SHA" --build-arg BUILD_TIME="$BUILD_TIME" "$APP_CONTAINER"
docker compose up -d "$APP_CONTAINER"

for _ in $(seq 1 30); do
    status="$(docker inspect "$APP_CONTAINER" --format '{{.State.Health.Status}}' 2>/dev/null || echo unknown)"
    [[ "$status" == "healthy" ]] && break
    sleep 5
done
log "sha=$SHA container health=${status:-unknown}"

# 6) تنظيف الصور المعلّقة (القرص ممتلئ بنحو 85%)
docker image prune -f --filter dangling=true >/dev/null

[[ "${status:-}" == "healthy" ]] || { log "sha=$SHA NOT healthy after deploy"; exit 1; }

# 7) اختبار الدخان (E26.8): /up والبرامج وتوليد بطاقة وصلاحيات المرفقات والترويسات. الفشل لا يوقف الحاوية
if ! bash scripts/smoke.sh; then
    log "sha=$SHA smoke FAILED; container left running (rollback: docs/RELEASE.md)"
    exit 1
fi
log "sha=$SHA deployed"
