#!/bin/sh
# فحص البيئة قبل الإقلاع (E06). في الإنتاج يرفض الإقلاع في حالتين فقط:
#   - APP_KEY لا يُفك إلى 32 بايتاً (AES-256-CBC): الإعدادات المشفّرة (رموز واتساب ومفاتيح النماذج) لا تُقرأ بغيره؛
#   - APP_DEBUG مفعّل: صفحات الخطأ تكشف البيئة والاستعلامات.
# BOOT_GUARD=warn يحوّل الرفض إلى تحذير للطوارئ، ولا يولّد مفتاحاً أبداً في الإنتاج.
set -u

[ "${APP_ENV:-}" = "production" ] || exit 0

fail() {
    if [ "${BOOT_GUARD:-strict}" = "warn" ]; then
        echo "⚠️  $1 (BOOT_GUARD=warn: يكمل الإقلاع)"
    else
        echo "❌ $1 — رفض الإقلاع. صحّح .env.production، أو BOOT_GUARD=warn مؤقتاً."
        exit 1
    fi
}

# المفتاح عبر البيئة لا عبر وسائط الأمر (الوسائط تظهر في قائمة العمليات)
# shellcheck disable=SC2016  # كود PHP بين علامتين مفردتين عن قصد
key_ok=$(php -r '
    $k = (string) getenv("APP_KEY");
    if (str_starts_with($k, "base64:")) {
        $d = base64_decode(substr($k, 7), true);
        echo ($d !== false && strlen($d) === 32) ? "1" : "0";
    } else {
        echo strlen($k) === 32 ? "1" : "0";
    }')
[ "$key_ok" = "1" ] || fail "APP_KEY مفقود أو غير صالح (يجب أن يُفك إلى 32 بايتاً)"

case "$(printf '%s' "${APP_DEBUG:-false}" | tr '[:upper:]' '[:lower:]')" in
    true|1|on|yes) fail "APP_DEBUG مفعّل في الإنتاج" ;;
esac

exit 0
