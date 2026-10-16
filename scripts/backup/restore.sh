#!/usr/bin/env bash
# استعادة آمنة لنسخة احتياطية على الخادم (لا زر ويب لها عن قصد). يشغّله sysadmin عبر SSH.
#
#   restore.sh --from-spool 20261014T023000Z-scheduled [--force] [--strict] [--with-media] [--dry-run]
#   restore.sh --archive FILE.tar.gz.age --identity KEYFILE [...]   # المفضّل: فك التشفير على جهازك ثم --dir
#   restore.sh --dir /path/to/20261014T023000Z-scheduled [...]
#   restore.sh --finalize 20261015T081500Z   # خلال 7 أيام: يشفّر لقطة ما قبل الاستعادة ويحذف نسختها الصريحة
#
# الترتيب: تجهيز وفحص النسخة ← مقارنة بالقاعدة الحية ← إيقاف ← لقطة ما قبل الاستعادة ←
# استبدال ذري ← وضع آمن (البوت مطفأ والإرسال مقفل) ← إقلاع وفحص ← تعليمات استرجاع الرسائل.
# السكربت لا يرفع قفل الإرسال أبداً؛ يرفعه المدير بعد المراجعة من صفحة النسخ الاحتياطي.
#
# الرجوع عن استعادة: restore.sh --dir ~/backups/nuskjo/<TS>-pre-restore --force
set -euo pipefail
umask 077

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR source=lib.sh
. "$HERE/lib.sh"

COMPOSE_FILE="${COMPOSE_FILE:-/opt/nuskJO/docker-compose.yml}"
SERVICE="${SERVICE:-nuskjo-app}"
APP_UID="${APP_UID:-82}"

die() { log "❌ $*"; exit 1; }
step() { log "── $*"; }
usage() { sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

MODE="" SRC="" IDENTITY="" FORCE=0 STRICT=0 WITH_MEDIA=0 DRY=0 FINALIZE=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --from-spool) MODE=spool; SRC="${2:-}"; shift ;;
        --archive) MODE=archive; SRC="${2:-}"; shift ;;
        --identity) IDENTITY="${2:-}"; shift ;;
        --dir) MODE=dir; SRC="${2:-}"; shift ;;
        --finalize) FINALIZE="${2:-}"; shift ;;
        --force) FORCE=1 ;;
        --strict) STRICT=1 ;;
        --with-media) WITH_MEDIA=1 ;;
        --dry-run) DRY=1 ;;
        -h|--help) usage ;;
        *) die "خيار غير معروف: $1" ;;
    esac
    shift
done

mkdir -p "$BASE/archive"
chmod 700 "$BASE"
exec 9>"$BASE/.lock"
flock -n 9 || die "عملية نسخ أو استعادة أخرى تعمل الآن"

encrypt_to_archive() {  # <مجلد داخل $BASE> <اسم الأرشيف>
    local dir="$1" name="$2"
    grep -q '^age1' "$CFG/recipients.txt" 2>/dev/null || die "لا مفتاح عام في $CFG/recipients.txt"
    tar -C "$BASE" -czf - "$dir" \
        | docker run --rm -i --pull=never --network none -v "$CFG":/keys:ro --entrypoint age "$IMAGE" -R /keys/recipients.txt \
        > "$BASE/archive/$name.part"
    [[ -s "$BASE/archive/$name.part" ]] || die "التشفير أنتج ملفاً فارغاً"
    mv "$BASE/archive/$name.part" "$BASE/archive/$name"
    local sha
    sha="$(sha256sum "$BASE/archive/$name" | cut -d' ' -f1)"
    { grep -vF -- "  $name" "$BASE/archive/SHA256SUMS" 2>/dev/null || true; echo "$sha  $name"; } > "$BASE/archive/SHA256SUMS.tmp"
    mv "$BASE/archive/SHA256SUMS.tmp" "$BASE/archive/SHA256SUMS"
}

# ── إنهاء: لقطة ما قبل الاستعادة لا تبقى صريحة على القرص ─────────────────────
if [[ -n "$FINALIZE" ]]; then
    [[ "$FINALIZE" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || die "صيغة الطابع غير صحيحة"
    PRE_NAME="$FINALIZE-pre-restore"
    [[ -d "$BASE/$PRE_NAME" ]] || die "لا توجد $BASE/$PRE_NAME"
    encrypt_to_archive "$PRE_NAME" "$PROJECT-$PRE_NAME.tar.gz.age"
    rm -rf "${BASE:?}/$PRE_NAME"
    log "✅ شُفّرت $PRE_NAME إلى archive/ وحُذفت نسختها الصريحة"
    exit 0
fi

[[ -n "$MODE" && -n "$SRC" ]] || usage
TS="$(date -u +%Y%m%dT%H%M%SZ)"
STAGE="$BASE/restore-staging/$TS"
KEYTMP=""
cleanup() {
    rm -rf "$STAGE"
    [[ -n "$KEYTMP" ]] && rm -f "$KEYTMP"
    rmdir "$BASE/restore-staging" 2>/dev/null || true
}
trap cleanup EXIT

# ── 1) تجهيز النسخة وفحصها ─────────────────────────────────────────────────
step "1/8 تجهيز النسخة في $STAGE"
mkdir -p "$STAGE"
case "$MODE" in
    spool)
        [[ "$SRC" =~ $SNAP_RE ]] || die "اسم نسخة غير صالح"
        docker exec -u www-data "$CONTAINER" tar -C "$SPOOL" -cf - "$SRC" | tar -C "$STAGE" -xf - \
            || die "تعذّر نسخ $SRC من spool الحاوية"
        ;;
    archive)
        [[ -f "$SRC" && -f "$IDENTITY" ]] || die "--archive يحتاج ملفاً و--identity لمفتاح موجود"
        KEYTMP="$(mktemp -p /dev/shm restore-key.XXXXXX)"
        chmod 600 "$KEYTMP"
        cat "$IDENTITY" > "$KEYTMP"
        docker run --rm -i --pull=never --network none -v "$KEYTMP":/key:ro --entrypoint age "$IMAGE" -d -i /key < "$SRC" \
            | tar -C "$STAGE" -xzf - \
            || die "تعذّر فك التشفير: مفتاح غير مطابق أو أرشيف تالف"
        rm -f "$KEYTMP"; KEYTMP=""
        ;;
    dir)
        [[ -f "$SRC/manifest.json" ]] || die "المجلد بلا manifest.json"
        cp -a "$SRC" "$STAGE/"
        ;;
esac

mapfile -t found < <(find "$STAGE" -mindepth 1 -maxdepth 1 -type d)
[[ ${#found[@]} -eq 1 ]] || die "المتوقع مجلد نسخة واحد داخل الأرشيف"
CAND="${found[0]}"
SNAP="$(basename "$CAND")"
[[ "$SNAP" =~ $SNAP_RE ]] || die "اسم مجلد النسخة غير صالح: $SNAP"
[[ -f "$CAND/manifest.json" && -f "$CAND/database.sqlite" ]] || die "النسخة ناقصة"
if [[ $WITH_MEDIA -eq 1 && ! -f "$CAND/storage-public.tar.gz" ]]; then
    die "--with-media لكن النسخة بلا أرشيف وسائط"
fi
SINCE="$(sed -n 's/^ *"created_at": *"\([^"]*\)".*/\1/p' "$CAND/manifest.json" | head -1)"

step "2/8 فحص النسخة (backup:verify$([[ $STRICT -eq 1 ]] && echo ' --strict')) في حاوية بلا شبكة"
verify_args=(/restore)
[[ $STRICT -eq 1 ]] && verify_args+=(--strict)
docker run --rm --pull=never --network none -v "$CAND":/restore:ro --entrypoint php "$IMAGE" \
    artisan backup:verify "${verify_args[@]}" || die "النسخة لم تجتز الفحص"

# ── 2) مقارنة بالقاعدة الحية (قراءة فقط) ─────────────────────────────────────
step "3/8 مقارنة بالقاعدة الحية"
if docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    set +e
    docker exec -i -u www-data "$CONTAINER" php artisan backup:verify --live --compare=- < "$CAND/manifest.json"
    rc=$?
    set -e
    if [[ $rc -eq 3 && $FORCE -eq 0 ]]; then
        die "في القاعدة الحية بيانات أحدث من النسخة ستضيع. اقرأ الجدول أعلاه ثم أعد التشغيل بـ --force"
    elif [[ $rc -ne 0 && $rc -ne 3 && $FORCE -eq 0 ]]; then
        die "تعذّر فحص القاعدة الحية (الرمز $rc). مع --force تتابع دون مقارنة"
    fi
else
    [[ $FORCE -eq 1 ]] || die "الحاوية $CONTAINER لا تعمل فلا مقارنة ممكنة؛ أعد التشغيل بـ --force"
    log "الحاوية لا تعمل؛ متابعة بلا مقارنة (--force)"
fi

if [[ $DRY -eq 1 ]]; then
    log "✅ تجربة فقط (--dry-run): النسخة $SNAP سليمة، ولم يتغير شيء"
    exit 0
fi

# الأحجام من الحاوية نفسها: تعمل قبل فصل حجم القاعدة وبعده
mounts="$(docker inspect -f '{{range .Mounts}}{{.Name}}|{{.Destination}}{{"\n"}}{{end}}' "$CONTAINER")"
DB_VOLUME="$(awk -F'|' '$2=="/var/www/html/database/data"{print $1}' <<<"$mounts")"
[[ -n "$DB_VOLUME" ]] || DB_VOLUME="$(awk -F'|' '$2=="/var/www/html/database"{print $1}' <<<"$mounts")"
STORAGE_VOLUME="$(awk -F'|' '$2=="/var/www/html/storage"{print $1}' <<<"$mounts")"
[[ -n "$DB_VOLUME" && -n "$STORAGE_VOLUME" ]] || die "تعذّر تحديد حجمي القاعدة والتخزين من $CONTAINER"
log "حجم القاعدة: $DB_VOLUME، حجم التخزين: $STORAGE_VOLUME"

# ── 3) الإيقاف ─────────────────────────────────────────────────────────────
step "4/8 إيقاف $SERVICE"
docker compose -f "$COMPOSE_FILE" stop "$SERVICE"

PRE_NAME="$TS-pre-restore"
PRE="$BASE/$PRE_NAME"
rollback_hint() {
    log "للرجوع إلى ما قبل الاستعادة: bash $HERE/restore.sh --dir $PRE --force$([[ $WITH_MEDIA -eq 1 ]] && echo ' --with-media')"
    log "أو أقلع الحاوية كما هي: docker compose -f $COMPOSE_FILE up -d $SERVICE"
}
trap 'rc=$?; [[ $rc -ne 0 ]] && rollback_hint; cleanup; exit $rc' EXIT

# ── 4) لقطة ما قبل الاستعادة (خارج الحجم) ───────────────────────────────────
step "5/8 لقطة ما قبل الاستعادة في $PRE"
mkdir -p "$PRE"
media_mount=()
media_cmd=""
if [[ $WITH_MEDIA -eq 1 ]]; then
    media_mount=(-v "$STORAGE_VOLUME":/st:ro)
    media_cmd="tar -czf /out/storage-public.tar.gz -C /st/app public && "
fi
docker run --rm --pull=never --network none -v "$DB_VOLUME":/db:ro -v "$PRE":/out "${media_mount[@]}" \
    --entrypoint sh "$IMAGE" -c "
        set -e
        if [ -f /db/database.sqlite ]; then cp -p /db/database.sqlite* /out/; else echo 'لا قاعدة حية لنسخها'; fi
        ${media_cmd}chown -R $(id -u):$(id -g) /out"
if [[ -f "$PRE/database.sqlite" ]]; then
    docker run --rm --pull=never --network none -v "$PRE":/out --entrypoint sh "$IMAGE" -c \
        "php artisan backup:manifest /out --reason=pre-restore && chown -R $(id -u):$(id -g) /out" \
        || log "تعذّر كتابة manifest للقطة ما قبل الاستعادة (الملفات نفسها محفوظة)"
fi

# ── 5) الاستبدال الذري ──────────────────────────────────────────────────────
step "6/8 استبدال القاعدة$([[ $WITH_MEDIA -eq 1 ]] && echo ' والوسائط')"
media_mount=()
media_cmd=""
if [[ $WITH_MEDIA -eq 1 ]]; then
    media_mount=(-v "$STORAGE_VOLUME":/st)
    media_cmd="
        rm -rf /st/app/.restoring && mkdir -p /st/app/.restoring
        tar -xzf /restore/storage-public.tar.gz -C /st/app/.restoring
        rm -rf /st/app/public && mv /st/app/.restoring/public /st/app/public && rmdir /st/app/.restoring
        chown -R $APP_UID:$APP_UID /st/app/public"
fi
docker run --rm --pull=never --network none -v "$DB_VOLUME":/db -v "$CAND":/restore:ro "${media_mount[@]}" \
    --entrypoint sh "$IMAGE" -c "
        set -e
        rm -f /db/database.sqlite-journal /db/database.sqlite-wal /db/database.sqlite-shm
        cp /restore/database.sqlite /db/database.sqlite.restoring
        php -r 'exit((new PDO(\"sqlite:/db/database.sqlite.restoring\"))->query(\"PRAGMA integrity_check\")->fetchColumn() === \"ok\" ? 0 : 1);'
        mv /db/database.sqlite.restoring /db/database.sqlite
        chown $APP_UID:$APP_UID /db /db/database.sqlite
        chmod 600 /db/database.sqlite
        $media_cmd"

# ── 6) الوضع الآمن قبل الإقلاع ──────────────────────────────────────────────
step "7/8 الوضع الآمن (backup:safe-mode)"
docker compose -f "$COMPOSE_FILE" run --rm --no-deps -T --user www-data --entrypoint php "$SERVICE" \
    artisan backup:safe-mode --restored-from="$SNAP" --pre-restore="$TS" \
    || die "فشل الوضع الآمن: لا تُقلع التطبيق قبل إصلاحه (الإرسال غير مقفل)"

# ── 7) الإقلاع والفحص ───────────────────────────────────────────────────────
step "8/8 الإقلاع والفحص"
docker compose -f "$COMPOSE_FILE" up -d "$SERVICE"
status=unknown
for _ in $(seq 1 60); do
    status="$(docker inspect "$CONTAINER" --format '{{.State.Health.Status}}' 2>/dev/null || echo unknown)"
    [[ "$status" == "healthy" ]] && break
    sleep 5
done
[[ "$status" == "healthy" ]] || die "الحاوية ليست healthy بعد الإقلاع ($status). راجع: docker logs $CONTAINER"
docker exec -i -u www-data "$CONTAINER" php artisan backup:verify --live --expect=- < "$CAND/manifest.json" \
    || die "القاعدة الحية بعد الإقلاع أقل من النسخة"
# فهرس البحث في الرسائل (E30): الاستعادة قد تخلّ بتطابقه مع الرسائل، فيُفحص ويُصلح. فشله لا يوقف الاستعادة (البحث يعود إلى LIKE)
docker exec -u www-data "$CONTAINER" php artisan whatsapp:fts-check --repair \
    || log "⚠️ فهرس البحث لم يُصلح: شغّل whatsapp:fts-check --repair يدوياً"

trap cleanup EXIT
cat >&2 <<EOF

✅ استُعيدت $SNAP. البوت مطفأ والإرسال مقفل حتى يعتمد المدير المراجعة من /settings/backups.

الخطوات التالية:
  1) استرجاع رسائل واتساب التي وصلت بعد وقت النسخة ($SINCE) من لقطة ما قبل الاستعادة:
     docker cp $PRE/database.sqlite $CONTAINER:/tmp/pre-restore-$TS.sqlite
     docker exec -u root $CONTAINER chown www-data /tmp/pre-restore-$TS.sqlite
     docker exec -u www-data $CONTAINER php artisan backup:replay-messages /tmp/pre-restore-$TS.sqlite --since=$SINCE --dry-run
     (ثم بلا --dry-run)
  2) تقرير ما يجب إدخاله يدوياً (أرقام مستندات فقط):
     docker exec -u www-data $CONTAINER php artisan backup:diff-report /tmp/pre-restore-$TS.sqlite --since=$SINCE
  3) خلال 7 أيام: bash $HERE/restore.sh --finalize $TS
EOF
