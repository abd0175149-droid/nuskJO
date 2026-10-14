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
log "deploy start sha=$SHA gate=$GATE"

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

# 4) البناء والتشغيل
docker compose build --build-arg GIT_COMMIT="$SHA" "$APP_CONTAINER"
docker compose up -d "$APP_CONTAINER"

for _ in $(seq 1 30); do
    status="$(docker inspect "$APP_CONTAINER" --format '{{.State.Health.Status}}' 2>/dev/null || echo unknown)"
    [[ "$status" == "healthy" ]] && break
    sleep 5
done
log "sha=$SHA container health=${status:-unknown}"

# 5) تنظيف الصور المعلّقة (القرص ممتلئ بنحو 85%)
docker image prune -f --filter dangling=true >/dev/null

[[ "${status:-}" == "healthy" ]] || { log "sha=$SHA NOT healthy after deploy"; exit 1; }
log "sha=$SHA deployed"
