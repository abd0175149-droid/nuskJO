#!/usr/bin/env bash
# المجمّع على المضيف: يسحب كل لقطة جديدة من spool الحاوية مشفّرةً بـ age إلى $BASE/archive.
#
#   bash /opt/nuskJO/scripts/backup/collect.sh            # من cron (انظر crontab.example)
#   bash /opt/nuskJO/scripts/backup/collect.sh --dry-run  # يعرض ما سيُسحب ويُدوَّر فقط
#
# لا يُكتب ملف غير مشفّر على قرص المضيف أبداً، والمفتاح الخاص ليس على الخادم:
# tar داخل الحاوية → age داخل حاوية بلا شبكة بالمفتاح العام → ملف .age.
# الإعداد لمرة واحدة:
#   mkdir -p ~/backups/nuskjo/archive ~/.config/nuskjo-backup && chmod 700 ~/backups/nuskjo ~/.config/nuskjo-backup
#   echo 'age1...' > ~/.config/nuskjo-backup/recipients.txt   # المفتاح العام فقط (ومستلم ثانٍ للضمان إن شئت)
set -euo pipefail
umask 077

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR source=lib.sh
. "$HERE/lib.sh"

DRY=0
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY=1 ;;
        *) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
    esac
done

ARCHIVE="$BASE/archive"
mkdir -p "$ARCHIVE"
chmod 700 "$BASE" "$ARCHIVE"

# BACKUP_LOCK_HELD=1 حين يستدعيه سكربت يحمل القفل نفسه (move-db-volume.sh)
if [[ "${BACKUP_LOCK_HELD:-0}" != 1 ]]; then
    exec 9>"$BASE/.lock"
    flock -n 9 || { log "مجمّع آخر يعمل؛ خروج"; exit 0; }
fi

current_step="البداية"
on_error() {
    local code=$?
    alert "collect.sh ($PROJECT) فشل عند: $current_step"
    rm -f "$ARCHIVE"/*.part
    exit "$code"
}
trap on_error ERR

current_step="فحص المفتاح العام"
RECIPIENTS="$CFG/recipients.txt"
if ! grep -q '^age1' "$RECIPIENTS" 2>/dev/null; then
    false  # يطلق on_error برسالة واضحة
fi
if grep -q 'AGE-SECRET-KEY' "$RECIPIENTS"; then
    current_step="مفتاح خاص في recipients.txt — احذفه من الخادم"
    false
fi

current_step="قراءة قائمة اللقطات"
mapfile -t snaps < <(docker exec -u www-data "$CONTAINER" ls -1 "$SPOOL" | grep -E "$SNAP_RE" | grep -v '\.partial$' || true)

collected=0
for snap in "${snaps[@]}"; do
    name="$PROJECT-$snap.tar.gz.age"
    [[ -f "$ARCHIVE/$name" ]] && continue

    if [[ $DRY -eq 1 ]]; then
        log "سيُسحب: $snap"
        continue
    fi

    current_step="فحص القرص قبل $snap"
    used_now="$(df -P "$BASE" | awk 'NR==2 {gsub("%", "", $5); print $5}')"
    if (( used_now >= DISK_HARD_PCT )); then
        alert "collect.sh ($PROJECT): القرص ${used_now}% — أُوقف السحب حتى لا يمتلئ (الحد ${DISK_HARD_PCT}%)"
        exit 1
    fi

    current_step="تشفير $snap"
    part="$ARCHIVE/$name.part"
    docker exec -u www-data "$CONTAINER" tar -C "$SPOOL" -czf - "$snap" \
        | docker run --rm -i --pull=never --network none -v "$CFG":/keys:ro --entrypoint age "$IMAGE" -R /keys/recipients.txt \
        > "$part"
    [[ -s "$part" ]] || false
    mv "$part" "$ARCHIVE/$name"

    current_step="بصمة $snap"
    bytes="$(stat -c %s "$ARCHIVE/$name")"
    sha="$(sha256sum "$ARCHIVE/$name" | cut -d' ' -f1)"
    { grep -vF -- "  $name" "$ARCHIVE/SHA256SUMS" 2>/dev/null || true; echo "$sha  $name"; } > "$ARCHIVE/SHA256SUMS.tmp"
    mv "$ARCHIVE/SHA256SUMS.tmp" "$ARCHIVE/SHA256SUMS"

    current_step="تسجيل $snap"
    artisan backup:mark "$snap" --collected --archive="$name" --bytes="$bytes" --sha256="$sha" >/dev/null

    log "سُحب $snap ($bytes بايت)"
    collected=$((collected + 1))
done

current_step="التدوير"
if [[ $DRY -eq 1 ]]; then
    bash "$HERE/rotate.sh" --dir="$ARCHIVE" --dry-run
else
    bash "$HERE/rotate.sh" --dir="$ARCHIVE" | grep '^DELETE' | while read -r _ f; do log "حُذف بالتدوير: $f"; done || true
fi

current_step="فحص القرص"
used_pct="$(df -P "$BASE" | awk 'NR==2 {gsub("%", "", $5); print $5}')"
base_mb="$(du -sm "$BASE" | cut -f1)"
if (( used_pct >= DISK_WARN_PCT )); then
    alert "قرص النسخ على المضيف ممتلئ بنسبة ${used_pct}%"
fi
if (( base_mb >= BASE_MAX_MB )); then
    alert "حجم أرشيف النسخ ${base_mb}MB تجاوز الحد ${BASE_MAX_MB}MB"
fi

log "انتهى: سُحب $collected، القرص ${used_pct}%، الأرشيف ${base_mb}MB"
