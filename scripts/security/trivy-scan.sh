#!/usr/bin/env bash
# فحص أسبوعي لصورة الإنتاج بـ Trivy مع SBOM لكل نشر (E26.11)، من crontab المستخدم sysadmin (crontab.example بجانبه).
#   bash /opt/nuskJO/scripts/security/trivy-scan.sh
# - لا docker.sock في حاوية Trivy: الصورة تُقرأ من ملف tar يُحذف دائماً (trap)، ويُتوقف إن قلّت المساحة الحرة.
# - النتائج في ~/security/nuskjo/<التاريخ>: report.json (HIGH وCRITICAL المصلَّحة فقط، بعد .trivyignore) وsbom.cdx.json.
#   يُحتفظ بآخر 12 فحصاً.
# - الأعداد وحدها تصل التطبيق (security:scan-report): سطر في بطاقة الصحة، وتنبيه للمدراء عند حرجة أو زيادة العالية.
set -euo pipefail

IMAGE="${SCAN_IMAGE:-nuskjo-nuskjo-app:latest}"
APP_CONTAINER="${APP_CONTAINER:-nuskjo-app}"
REPO_DIR="${REPO_DIR:-/opt/nuskJO}"
BASE="${SCAN_DIR:-$HOME/security/nuskjo}"
KEEP="${SCAN_KEEP:-12}"
MIN_FREE_GB="${SCAN_MIN_FREE_GB:-5}"
# aquasec/trivy:0.75.0، البصمة في 2026-10-16. يُحدَّث يدوياً بعد مراجعة الإصدار (الوسوم العائمة لأداة أمن خطر بذاته)
TRIVY_IMAGE="${TRIVY_IMAGE:-aquasec/trivy:0.75.0@sha256:af6acf9a6b85dfe389a1941505c0ce9efef52a4719635e1a962f022a3d855daa}"

free_gb="$(df -Pk / | awk 'NR == 2 { print int($4 / 1048576) }')"
if (( free_gb < MIN_FREE_GB )); then
    echo "⚠️ المساحة الحرة على / ${free_gb}GB أقل من ${MIN_FREE_GB}GB: لا فحص هذا الأسبوع" >&2
    exit 1
fi

OUT="$BASE/$(date +%F)"
mkdir -p "$OUT"
trap 'rm -f "$OUT/img.tar"' EXIT

docker save "$IMAGE" -o "$OUT/img.tar"

ignore=()
if [[ -f "$REPO_DIR/.trivyignore" ]]; then
    ignore=(-v "$REPO_DIR/.trivyignore:/trivyignore:ro")
fi
trivy() {
    docker run --rm -v trivy-cache:/root/.cache/ -v "$OUT":/scan "${ignore[@]}" "$TRIVY_IMAGE" image --input /scan/img.tar "$@"
}
filter=(--scanners vuln --severity "HIGH,CRITICAL" --ignore-unfixed)
[[ ${#ignore[@]} -gt 0 ]] && filter+=(--ignorefile /trivyignore)

trivy "${filter[@]}" --format json --output /scan/report.json
trivy --format cyclonedx --output /scan/sbom.cdx.json
rm -f "$OUT/img.tar"

count() {
    if command -v jq >/dev/null 2>&1; then
        jq --arg s "$1" '[.Results[]?.Vulnerabilities[]? | select(.Severity == $s)] | length' "$OUT/report.json"
    else
        python3 -c 'import json, sys
d = json.load(open(sys.argv[1]))
print(sum(1 for r in d.get("Results") or [] for v in r.get("Vulnerabilities") or [] if v.get("Severity") == sys.argv[2]))' "$OUT/report.json" "$1"
    fi
}
high="$(count HIGH)"
critical="$(count CRITICAL)"
echo "$(date -u +%FT%TZ) image=$IMAGE high=$high critical=$critical out=$OUT"

if ! docker exec -u www-data "$APP_CONTAINER" php artisan security:scan-report --high="$high" --critical="$critical" --scanner=trivy; then
    echo "⚠️ تعذّر تسجيل الملخص في التطبيق (الحاوية متوقفة؟)" >&2
fi

# آخر KEEP فحصاً فقط
find "$BASE" -mindepth 1 -maxdepth 1 -type d -name '20??-??-??' | sort | head -n -"$KEEP" | xargs -r rm -rf
