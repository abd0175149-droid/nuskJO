#!/bin/sh
# داخل حاوية nuskjo-drill (بلا شبكة، نظام ملفات للقراءة فقط، /tmp في الذاكرة):
# يستقبل أرشيف لقطة على stdin، ويفكه في /tmp/drill، ويفحصه ويجرّب الهجرات عليه.
# المخرج سطر JSON واحد على stdout (backup:verify --json) يسجّله drill.sh.
set -eu

mkdir -p /tmp/drill-in /tmp/drill
tar -xzf - -C /tmp/drill-in
set -- /tmp/drill-in/*/
[ "$#" -eq 1 ] && [ -f "$1/manifest.json" ] || { echo '{"ok":false,"errors":["أرشيف التمرين لا يحوي لقطة واحدة"]}'; exit 1; }
mv "$1"* /tmp/drill/

cd /var/www/html
exec php artisan backup:verify /tmp/drill --strict --rehearse-migrations --json
