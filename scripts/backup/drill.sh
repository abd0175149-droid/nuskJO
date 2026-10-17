#!/usr/bin/env bash
# تمرين الاستعادة الشهري المعزول: أحدث لقطة من spool تُستعاد في حاوية nuskjo-drill
# (بلا شبكة ولا حجم إنتاجي)، وتُفحص وتُجرَّب عليها الهجرات وتدقيق الدفاتر وفك الأسرار.
#
#   bash /opt/nuskJO/scripts/backup/drill.sh                     # الشهري (crontab.example)
#   DRILL_IMAGE=nuskjo-nuskjo-app:candidate bash drill.sh --rehearse   # قبل النشر: هجرات الصورة الجديدة على بيانات اليوم
#
# النتيجة تُسجَّل بـ backup:mark --drill وتظهر في صفحة النسخ؛ والفشل ينبّه المدراء.
# --rehearse لا يسجّل نتيجة التمرين الشهري: فشله يعني «لا تنشر الصورة المرشّحة».
set -euo pipefail
umask 077

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR source=lib.sh
. "$HERE/lib.sh"

COMPOSE_FILE="${COMPOSE_FILE:-/opt/nuskJO/docker-compose.yml}"
DRILL_SERVICE="${DRILL_SERVICE:-nuskjo-drill}"
REHEARSE=0
for arg in "$@"; do
    case "$arg" in
        --rehearse) REHEARSE=1 ;;
        *) echo "خيار غير معروف: $arg" >&2; exit 2 ;;
    esac
done

mkdir -p "$BASE"
exec 9>"$BASE/.lock"
flock -w 900 9 || { log "عملية نسخ أو استعادة أخرى ما زالت تعمل"; exit 1; }

snap="$(docker exec -u www-data "$CONTAINER" ls -1 "$SPOOL" | grep -E "$SNAP_RE" | sort | tail -1 || true)"
if [[ -z "$snap" ]]; then
    [[ $REHEARSE -eq 1 ]] || alert "تمرين الاستعادة ($PROJECT): لا توجد لقطة في spool"
    exit 1
fi
log "التمرين على $snap بالصورة ${DRILL_IMAGE:-الحالية}"

set +e
result="$(docker exec -u www-data "$CONTAINER" tar -C "$SPOOL" -czf - "$snap" \
    | docker compose -f "$COMPOSE_FILE" --profile drill run --rm -T "$DRILL_SERVICE" 2>"$BASE/drill-last.log" | tail -1)"
rc=$?
set -e
echo "$result"

if [[ $rc -eq 0 && "$result" == '{"ok":true'* ]]; then
    outcome=ok
else
    outcome=failed
fi

if [[ $REHEARSE -eq 1 ]]; then
    log "تجربة الصورة المرشّحة: $outcome"
    [[ $outcome == ok ]] || exit 1
    exit 0
fi

[[ "$result" == '{'* ]] || result='{"ok":false,"errors":["لا مخرج من حاوية التمرين"]}'
artisan backup:mark "$snap" --drill="$outcome" --summary="$result" >/dev/null \
    || log "تعذّر تسجيل نتيجة التمرين"
if [[ $outcome != ok ]]; then
    alert "تمرين الاستعادة ($PROJECT) فشل على النسخة $snap"
    exit 1
fi
log "✅ نجح التمرين على $snap"
