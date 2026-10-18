#!/usr/bin/env bash
# اختبار دخان بعد كل نشر (E26.8). يستدعيه scripts/deploy.sh بعد up -d، ويُشغَّل يدوياً أيضاً:
#   cd /opt/nuskJO && bash scripts/smoke.sh
# قراءة فقط، ولا يرسل شيئاً لأحد. سطر لكل فحص، ويعيد 1 إن فشل أي منها (الحاوية تبقى كما هي؛ التراجع في docs/RELEASE.md).
set -uo pipefail

APP_CONTAINER="${APP_CONTAINER:-nuskjo-app}"
BASE="${SMOKE_BASE:-http://localhost:4020}"
WAIT="${SMOKE_WAIT_SECONDS:-180}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
failed=0

pass() { echo "✅ $*"; }
fail() { echo "❌ $*"; failed=1; }

# 1) healthy (فحص /up كل 30 ثانية، ومهلة الإقلاع 120)
status=unknown
for _ in $(seq 1 $((WAIT / 5))); do
    status="$(docker inspect "$APP_CONTAINER" --format '{{.State.Health.Status}}' 2>/dev/null || echo unknown)"
    [[ "$status" == "healthy" ]] && break
    sleep 5
done
if [[ "$status" == "healthy" ]]; then pass "الحاوية healthy"; else fail "الحاوية ليست healthy (${status})"; fi

# 2) /up من المضيف
if curl -fsS -o /dev/null -H 'Accept: application/json' "$BASE/up"; then pass "/up يرد 200"; else fail "/up لا يرد 200"; fi

# 3) كل برامج supervisor تعمل، وعددها كما في docker/supervisord.conf
expected="$(grep -c '^\[program:' "$ROOT/docker/supervisord.conf")"
programs="$(docker exec "$APP_CONTAINER" supervisorctl status 2>&1 || true)"
running="$(grep -c ' RUNNING ' <<<"$programs" || true)"
if [[ "$running" -ge "$expected" ]] && ! grep -qv ' RUNNING ' <<<"$programs"; then
    pass "supervisor: ${running} برامج RUNNING"
else
    fail "supervisor: ${running} من ${expected} RUNNING"
    sed 's/^/   /' <<<"$programs"
fi

# 4) توليد بطاقة حقيقي بكروميوم داخل الحاوية المقيّدة (يطبع الزمن والذاكرة)
if docker exec -u www-data "$APP_CONTAINER" php artisan ops:smoke-card --no-ansi; then pass "ops:smoke-card"; else fail "ops:smoke-card"; fi

# 5) إصدار كروميوم المسجّل عند البناء
chromium="$(docker exec "$APP_CONTAINER" cat /etc/chromium-version 2>/dev/null || true)"
if [[ -n "$chromium" ]]; then pass "كروميوم: ${chromium}"; else fail "لا /etc/chromium-version في الصورة"; fi
# 5ب) ffmpeg لرسائل الموظف الصوتية (E34)
ffmpeg="$(docker exec "$APP_CONTAINER" ffmpeg -hide_banner -version 2>/dev/null | head -1 || true)"
if [[ -n "$ffmpeg" ]]; then pass "ffmpeg: ${ffmpeg}"; else fail "لا ffmpeg في الصورة (الرسائل الصوتية)"; fi
# 5ج) pdftoppm لورقة الشركة الرسمية المرفوعة PDF
poppler="$(docker exec "$APP_CONTAINER" pdftoppm -v 2>&1 | head -1 || true)"
if [[ "$poppler" == pdftoppm* ]]; then pass "pdftoppm: ${poppler}"; else fail "لا pdftoppm في الصورة (ورقة الشركة PDF)"; fi

# 6) الجوازات والنسخ لـ www-data وحده بعد الإقلاع (A17): لا بت للمجموعة أو الغير، وnginx لا يفتح المجلد
leaks="$(docker exec "$APP_CONTAINER" sh -c 'find storage/app/wa-media -perm /077 -print 2>/dev/null | head -3
    for d in storage/app/wa-media storage/app/backups; do [ -d "$d" ] && [ "$(stat -c %a "$d")" != 700 ] && echo "$d"; done' 2>/dev/null || true)"
if [[ -z "$leaks" ]]; then pass "صلاحيات المرفقات والنسخ 700/600"; else fail "صلاحيات أوسع من 700/600: ${leaks//$'\n'/ }"; fi
if docker exec -u nginx "$APP_CONTAINER" ls storage/app/wa-media >/dev/null 2>&1; then
    fail "عامل nginx يقرأ مجلد المرفقات"
else
    pass "عامل nginx لا يقرأ مجلد المرفقات"
fi

# 7) ترويسات الأمان من nginx على كل نوع رد (E26.5)، وsandbox على /storage، وCSP من التطبيق على الصفحات
headers() { curl -sS -o /dev/null -D - "$BASE$1" 2>/dev/null | tr -d '\r'; }
check() {
    local path="$1" extra="${2:-}" h missing=()
    h="$(headers "$path")"
    for name in X-Frame-Options X-Content-Type-Options Referrer-Policy Strict-Transport-Security Permissions-Policy Cross-Origin-Opener-Policy; do
        grep -qi "^${name}:" <<<"$h" || missing+=("$name")
    done
    grep -qi '^X-XSS-Protection:' <<<"$h" && missing+=("X-XSS-Protection موجودة")
    grep -qi '^Server: nginx/' <<<"$h" && missing+=("رقم إصدار nginx ظاهر")
    [[ -n "$extra" ]] && ! grep -qiE "$extra" <<<"$h" && missing+=("$extra")
    if [[ ${#missing[@]} -eq 0 ]]; then pass "ترويسات ${path}"; else fail "ترويسات ${path}: ${missing[*]}"; fi
}
check /login '^Content-Security-Policy(-Report-Only)?: .*nonce-'
check /build/manifest.json
check /firebase-messaging-sw.js
check /storage/__smoke__.png "^Content-Security-Policy: .*sandbox"

# 8) وحدات .mjs من /build بنوع JavaScript: عامل pdf.js بنوع آخر يرفضه المتصفح فتختفي خلفية صفحات الطباعة
mjs="$(docker exec "$APP_CONTAINER" sh -c "grep -o 'assets/[^\"]*\.mjs' public/build/manifest.json | head -1" 2>/dev/null || true)"
if [[ -z "$mjs" ]]; then
    pass "لا وحدات .mjs في البناء"
elif headers "/build/$mjs" | grep -qiE '^Content-Type: (application|text)/javascript'; then
    pass "عامل pdf.js يُخدم JavaScript"
else
    fail "عامل pdf.js (/build/$mjs) لا يُخدم بنوع JavaScript: قوالب الطباعة لن تُرسم"
fi

exit "$failed"
