#!/usr/bin/env bash
# فصل حجم القاعدة: من حجم يغطي database/ كله (فيحجب هجرات الصورة الجديدة) إلى حجم لمجلد database/data وحده.
#
#   move-db-volume.sh --compose-dir /opt/nuskJO --project nuskjo --service nuskjo-app \
#       --old nuskjo_nuskjo-database --new nuskjo_nuskjo-dbdata [--check-only] [--yes]
#   move-db-volume.sh ... --reverse      # يعيد القاعدة (ومعها ما كُتب بعد التبديل) إلى الحجم القديم
#
# الشرط: docker-compose.yml في --compose-dir هو الجديد (حجم على database/data وDB_DATABASE)، والصورة مبنية منه.
# لا يحذف شيئاً: الحجم القديم يبقى كما هو (30 يوماً على الأقل، وحذفه بقرار المالك).
# الترتيب المقترح: Demo، ثم Tawasul بعد يوم بلا مشاكل، ثم nuskJO. ونسخة قبل كل خطوة (يأخذها السكربت).
# shellcheck disable=SC2016,SC2001  # كود PHP بين علامتي اقتباس مفردة عن قصد
set -euo pipefail
umask 077

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

DIR="" SERVICE="nuskjo-app" OLD="" NEW="" REVERSE=0 CHECK_ONLY=0 YES=0 ARG_CONTAINER=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --compose-dir) DIR="${2:-}"; shift ;;
        --project) PROJECT="${2:-}"; shift ;;
        --service) SERVICE="${2:-}"; shift ;;
        --container) ARG_CONTAINER="${2:-}"; shift ;;
        --old) OLD="${2:-}"; shift ;;
        --new) NEW="${2:-}"; shift ;;
        --reverse) REVERSE=1 ;;
        --check-only) CHECK_ONLY=1 ;;
        --yes) YES=1 ;;
        *) echo "خيار غير معروف: $1" >&2; exit 2 ;;
    esac
    shift
done
[[ -n "$DIR" && -n "$OLD" && -n "$NEW" ]] || { sed -n '3,12p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

# lib.sh بعد المعاملات: BASE وCFG تتبع --project (Demo وTawasul لهما مجلداتهما)
export PROJECT="${PROJECT:-nuskjo}"
# shellcheck source-path=SCRIPTDIR source=lib.sh
. "$HERE/lib.sh"

APP_UID="${APP_UID:-82}"
compose() { docker compose -f "$DIR/docker-compose.yml" -p "$PROJECT" "$@"; }
die() { log "❌ $*"; exit 1; }

# الصورة والحاوية من compose نفسه، لا من افتراضات nuskJO
IMAGE="$(compose config --images "$SERVICE" 2>/dev/null | head -1)"
[[ -n "$IMAGE" ]] || die "تعذّرت قراءة صورة $SERVICE من $DIR/docker-compose.yml"
CONTAINER="${ARG_CONTAINER:-$(compose ps -a --format '{{.Name}}' "$SERVICE" 2>/dev/null | head -1)}"
CONTAINER="${CONTAINER:-$SERVICE}"
helper() { docker run --rm --pull=never --network none "$@"; }

# أعداد الجداول الأساسية لقاعدة في مسار داخل حاوية مساعدة (قراءة فقط)
COUNT_PHP='$p=new PDO("sqlite:".$argv[1],null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
foreach(["users","clients","invoices","receipts","journal_entries","journal_entry_lines","wa_conversations","wa_messages","migrations"] as $t){
  $e=$p->query("SELECT 1 FROM sqlite_master WHERE type=\"table\" AND name=\"$t\"")->fetchColumn();
  echo $t,"=",$e?$p->query("SELECT COUNT(*) FROM $t")->fetchColumn():"-","\n"; }'
counts() { helper -v "$1":/v:ro --entrypoint php "$IMAGE" -r "$COUNT_PHP" /v/database.sqlite; }

mkdir -p "$BASE"
exec 9>"$BASE/.lock"
flock -n 9 || die "عملية نسخ أو استعادة أخرى تعمل"

docker volume inspect "$OLD" >/dev/null 2>&1 || die "الحجم القديم $OLD غير موجود"
docker image inspect "$IMAGE" >/dev/null 2>&1 || die "الصورة $IMAGE غير موجودة؛ ابنِها من compose الجديد أولاً"
compose config 2>/dev/null | grep -q '/var/www/html/database/data' \
    || die "docker-compose.yml في $DIR ليس بالتخطيط الجديد (حجم على database/data)"

wait_healthy() {
    local status=unknown
    for _ in $(seq 1 60); do
        status="$(docker inspect "$CONTAINER" --format '{{.State.Health.Status}}' 2>/dev/null || echo unknown)"
        [[ "$status" == healthy ]] && return 0
        sleep 5
    done
    die "$CONTAINER ليست healthy ($status). راجع: docker logs $CONTAINER"
}

confirm() {
    [[ $YES -eq 1 ]] && return 0
    read -r -p "$1 اكتب «نعم» للمتابعة: " answer
    [[ "$answer" == "نعم" ]] || die "أُلغي"
}

# ── الرجوع: من الحجم الجديد إلى القديم ──────────────────────────────────────
if [[ $REVERSE -eq 1 ]]; then
    docker volume inspect "$NEW" >/dev/null 2>&1 || die "الحجم الجديد $NEW غير موجود"
    TS="$(date -u +%Y%m%dT%H%M%SZ)"
    confirm "سيُوقف $SERVICE وتُنسخ قاعدة $NEW (بما كُتب بعد التبديل) إلى $OLD، وتُحفظ قاعدة $OLD الحالية باسم database.sqlite.before-reverse-$TS."
    compose stop "$SERVICE"
    helper -v "$NEW":/new:ro -v "$OLD":/old --entrypoint sh "$IMAGE" -c "
        set -e
        test -s /new/database.sqlite
        if [ -f /old/database.sqlite ]; then mv /old/database.sqlite /old/database.sqlite.before-reverse-$TS; fi
        rm -f /old/database.sqlite-journal /old/database.sqlite-wal /old/database.sqlite-shm
        cp -p /new/database.sqlite /old/database.sqlite
        php -r 'exit((new PDO(\"sqlite:/old/database.sqlite\"))->query(\"PRAGMA integrity_check\")->fetchColumn() === \"ok\" ? 0 : 1);'
        chown $APP_UID:$APP_UID /old/database.sqlite && chmod 600 /old/database.sqlite"
    log "✅ القاعدة الآن في $OLD. أعِد docker-compose.yml إلى التخطيط القديم (git revert لتغيير الحجم) ثم: docker compose -f $DIR/docker-compose.yml up -d $SERVICE"
    exit 0
fi

# ── الفحص المسبق (بلا أي تغيير) ────────────────────────────────────────────
log "── الفحص المسبق"
helper -v "$OLD":/old:ro --entrypoint sh "$IMAGE" -c 'test -s /old/database.sqlite' \
    || die "لا قاعدة في $OLD/database.sqlite"
if docker volume inspect "$NEW" >/dev/null 2>&1 \
    && helper -v "$NEW":/new:ro --entrypoint sh "$IMAGE" -c 'test -e /new/database.sqlite'; then
    die "الحجم الجديد $NEW فيه قاعدة بالفعل؛ لا كتابة فوقها"
fi

image_migrations="$(helper --entrypoint sh "$IMAGE" -c 'ls database/migrations' | sed 's/\.php$//' | sort)"
db_migrations="$(helper -v "$OLD":/old:ro --entrypoint php "$IMAGE" -r \
    '$p=new PDO("sqlite:/old/database.sqlite",null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]); foreach($p->query("SELECT migration FROM migrations") as $r) echo $r[0],"\n";' | sort)"
volume_migrations="$(helper -v "$OLD":/old:ro --entrypoint sh "$IMAGE" -c 'ls /old/migrations 2>/dev/null' | sed 's/\.php$//' | sort)"

pending="$(comm -23 <(echo "$image_migrations") <(echo "$db_migrations") | sed '/^$/d')"
orphan_records="$(comm -13 <(echo "$image_migrations") <(echo "$db_migrations") | sed '/^$/d')"
volume_only="$(comm -13 <(echo "$image_migrations") <(echo "$volume_migrations") | sed '/^$/d')"
volume_only_pending="$(comm -23 <(echo "$volume_only") <(echo "$db_migrations") | sed '/^$/d')"

echo "(أ) هجرات في الصورة لم تُشغَّل على القاعدة، ستعمل عند الإقلاع (يجب أن تكون متوقعة):"
echo "${pending:-  لا شيء}" | sed 's/^/  /'
echo "    سجلات هجرات بلا ملف في الصورة (لا تضر): $(echo "$orphan_records" | sed '/^$/d' | wc -l)"
echo "(ب) هجرات في الحجم القديم ليست في الصورة (دخلت بـ docker cp ولم تُحفظ في git):"
echo "${volume_only:-  لا شيء}" | sed 's/^/  /'
[[ -z "$volume_only_pending" ]] || die "هجرات في الحجم القديم لم تُشغَّل وليست في الصورة؛ ستضيع بعد الفصل: احفظها في git أولاً:
$volume_only_pending"

extras="$(helper -v "$OLD":/old:ro --entrypoint sh "$IMAGE" -c \
    'cd /old && ls -A | grep -vx -e database.sqlite -e migrations -e seeders -e factories -e data -e .gitignore || true')"
echo "(ج) ملفات زائدة في الحجم القديم (نسخ قديمة غير مشفّرة غالباً):"
echo "${extras:-  لا شيء}" | sed 's/^/  /'
if [[ -n "$extras" ]]; then
    if grep -q '^age1' "$CFG/recipients.txt" 2>/dev/null; then
        name="$PROJECT-$(date -u +%Y%m%dT%H%M%SZ)-manual-legacy-volume-files.tar.gz.age"
        # shellcheck disable=SC2086
        helper -v "$OLD":/old:ro --entrypoint tar "$IMAGE" -C /old -czf - $extras \
            | helper -i -v "$CFG":/keys:ro --entrypoint age "$IMAGE" -R /keys/recipients.txt > "$BASE/archive/$name"
        log "    شُفّرت في archive/$name (لم يُحذف شيء؛ حذفها من الحجم القديم بقرارك بعد التحقق)"
    else
        log "    لا مفتاح عام في $CFG؛ لم تُشفَّر (أعدّ المجمّع أولاً، انظر docs/BACKUP.md)"
    fi
fi

[[ $CHECK_ONLY -eq 1 ]] && { log "✅ فحص فقط: لم يتغير شيء"; exit 0; }
confirm "سيُوقف $SERVICE دقائق، وتُنسخ القاعدة إلى $NEW، ويُقلع على التخطيط الجديد."

# ── التنفيذ ────────────────────────────────────────────────────────────────
log "── نسخة قبل الفصل"
if docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    docker exec -u www-data "$CONTAINER" php artisan backup:run --reason=manual --label=pre-volume-split
    if grep -q '^age1' "$CFG/recipients.txt" 2>/dev/null; then
        BACKUP_LOCK_HELD=1 PROJECT="$PROJECT" CONTAINER="$CONTAINER" IMAGE="$IMAGE" BASE="$BASE" CFG="$CFG" bash "$HERE/collect.sh" \
            || die "تعذّر سحب النسخة مشفّرة؛ لم يتغير شيء"
    fi
else
    confirm "الحاوية لا تعمل فلا نسخة الآن (القاعدة القديمة تبقى في $OLD دون تغيير)."
fi

log "── إيقاف وإنشاء الحاوية والحجم الجديدين دون تشغيل"
compose stop "$SERVICE"
compose up --no-start "$SERVICE"
docker volume inspect "$NEW" >/dev/null 2>&1 || die "لم يُنشئ compose الحجم $NEW؛ راجع اسم الحجم في docker-compose.yml"

log "── نسخ القاعدة إلى $NEW"
helper -v "$OLD":/old:ro -v "$NEW":/new --entrypoint sh "$IMAGE" -c "
    set -e
    test ! -e /new/database.sqlite
    cp -p /old/database.sqlite* /new/
    php -r 'exit((new PDO(\"sqlite:/new/database.sqlite\"))->query(\"PRAGMA integrity_check\")->fetchColumn() === \"ok\" ? 0 : 1);' \
        || { rm -f /new/database.sqlite*; echo 'integrity_check فشل على النسخة الجديدة'; exit 1; }
    chown -R $APP_UID:$APP_UID /new && chmod 700 /new && chmod 600 /new/database.sqlite"

old_counts="$(counts "$OLD")"
new_counts="$(counts "$NEW")"
paste <(echo "$old_counts") <(echo "$new_counts") | awk -F'\t' '{printf "  %-24s %s\n", $1, ($1==$2 ? "✓" : "✗ الجديد: " $2)}'
[[ "$old_counts" == "$new_counts" ]] || die "الأعداد لا تتطابق بين الحجمين؛ لم يُقلع شيء. القديم كما هو: أعِد compose القديم وشغّل"

log "── الإقلاع على التخطيط الجديد"
compose up -d "$SERVICE"
wait_healthy
db_path="$(docker exec "$CONTAINER" printenv DB_DATABASE || true)"
[[ "$db_path" == */database/data/database.sqlite ]] || die "التطبيق لا يستعمل المسار الجديد (DB_DATABASE=$db_path)"
if docker exec -u www-data "$CONTAINER" php artisan migrate:status --pending 2>/dev/null | grep -q 'Pending'; then
    die "بقيت هجرات معلّقة بعد الإقلاع؛ راجع docker logs $CONTAINER"
fi

log "✅ القاعدة الآن في $NEW، والحجم $OLD باقٍ كما هو للرجوع (لا تحذفه قبل 30 يوماً وبقرار المالك)."
log "   الرجوع: bash $HERE/move-db-volume.sh --compose-dir $DIR --project $PROJECT --service $SERVICE --old $OLD --new $NEW --reverse"
log "   تحقق يدوياً: تصفّح النظام، وصفحة النسخ الاحتياطي، وأن الهجرات الجديدة وصلت بالبناء وحده دون docker cp."
