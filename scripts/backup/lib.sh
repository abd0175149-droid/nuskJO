# إعدادات مشتركة لسكربتات النسخ على المضيف. كل قيمة قابلة للتجاوز من البيئة
# (لاستعمال السكربتات نفسها مع مشروع compose آخر بالمعاملات).
# shellcheck shell=bash disable=SC2034

PROJECT="${PROJECT:-nuskjo}"
CONTAINER="${CONTAINER:-nuskjo-app}"
IMAGE="${IMAGE:-nuskjo-nuskjo-app:latest}"
BASE="${BASE:-$HOME/backups/$PROJECT}"
CFG="${CFG:-$HOME/.config/$PROJECT-backup}"
SPOOL="${SPOOL:-/var/www/html/storage/app/backups}"
DISK_WARN_PCT="${DISK_WARN_PCT:-90}"
# سقف صلب: لا أرشيف جديد فوقه (الخادم مشترك؛ امتلاؤه يوقف كل المشاريع)
DISK_HARD_PCT="${DISK_HARD_PCT:-95}"
BASE_MAX_MB="${BASE_MAX_MB:-20480}"

# اسم مجلد نسخة صالح فقط: يمنع أي حقن في أوامر docker exec
SNAP_RE='^[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9_.-]{1,48}$'

log() { printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >&2; }

artisan() { docker exec -u www-data "$CONTAINER" php artisan "$@"; }

# تنبيه لا يُفشل السكربت إن تعذّر (الحاوية متوقفة مثلاً)؛ يبقى السجل
alert() {
    log "ALERT: $*"
    artisan backup:alert "$*" >/dev/null 2>&1 || log "تعذّر إرسال التنبيه عبر الحاوية"
}
