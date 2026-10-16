#!/usr/bin/env bash
# قياس ذاكرة الحاوية وعدد عملياتها لضبط mem_limit وpids_limit (E26.1 وE26.8). للقراءة فقط على المضيف:
#   bash scripts/security/measure-memory.sh [الدقائق=10]
# خلال القياس: ولّد 3 بطاقات عروض متتالية من /offers، ويُفضَّل في ساعة ذروة الصندوق.
# يطبع القمة والحد المقترح: ضعف قمة الذاكرة ولا يقل عن 2g، ثم يُكتب NUSKJO_MEM_LIMIT في /opt/nuskJO/.env.
set -euo pipefail

CONTAINER="${APP_CONTAINER:-nuskjo-app}"
MINUTES="${1:-10}"
peak_mib=0
peak_pids=0

for _ in $(seq 1 $((MINUTES * 6))); do
    read -r usage pids < <(docker stats --no-stream --format '{{.MemUsage}} {{.PIDs}}' "$CONTAINER" | awk '{print $1, $NF}')
    mib="$(awk -v v="$usage" 'BEGIN {
        n = v; u = v; gsub(/[A-Za-z]+$/, "", n); gsub(/^[0-9.]+/, "", u)
        f = (u == "GiB") ? 1024 : (u == "MiB") ? 1 : (u == "KiB") ? 1 / 1024 : (u == "kB") ? 1 / 1024 : 1 / 1048576
        printf "%d", n * f }')"
    (( mib > peak_mib )) && peak_mib=$mib
    (( pids > peak_pids )) && peak_pids=$pids
    printf '%s  %6s MiB  %4s pids  (القمة %s MiB / %s)\n' "$(date +%H:%M:%S)" "$mib" "$pids" "$peak_mib" "$peak_pids"
    sleep 10
done

suggest=$(( peak_mib * 2 ))
(( suggest < 2048 )) && suggest=2048
echo
echo "قمة الذاكرة: ${peak_mib} MiB — المقترح: NUSKJO_MEM_LIMIT=$(( (suggest + 1023) / 1024 ))g"
echo "قمة العمليات والخيوط: ${peak_pids} — pids_limit الحالي 1024 (NUSKJO_PIDS_LIMIT)"
