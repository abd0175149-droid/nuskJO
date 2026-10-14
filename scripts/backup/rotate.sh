#!/usr/bin/env bash
# تدوير الأرشيفات المشفّرة على المضيف.
#
#   rotate.sh --dir ~/backups/nuskjo/archive [--dry-run] [--now=2026-10-14T00:00:00Z]
#
# المجدولة (scheduled): أحدث أرشيف لكل يوم لآخر 7 أيام فيها أرشيف، ولكل أسبوع ISO لآخر 4،
# ولكل شهر لآخر 6. الأحداث: أحدث 5 لكل سبب (pre-migrate وpre-reset وmanual...)، وما تجاوز 90 يوماً يُحذف.
# أحدث أرشيف على الإطلاق لا يُحذف أبداً. المخرج: سطر KEEP أو DELETE لكل ملف.
set -euo pipefail

DIR=""
DRY=0
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
KEEP_DAILY="${KEEP_DAILY:-7}"
KEEP_WEEKLY="${KEEP_WEEKLY:-4}"
KEEP_MONTHLY="${KEEP_MONTHLY:-6}"
KEEP_EVENTS="${KEEP_EVENTS:-5}"
EVENT_MAX_DAYS="${EVENT_MAX_DAYS:-90}"

for arg in "$@"; do
    case "$arg" in
        --dir=*) DIR="${arg#--dir=}" ;;
        --dry-run) DRY=1 ;;
        --now=*) NOW="${arg#--now=}" ;;
        *) if [[ -z "$DIR" && "$arg" != --* ]]; then DIR="$arg"; else echo "خيار غير معروف: $arg" >&2; exit 2; fi ;;
    esac
done
[[ -d "$DIR" ]] || { echo "المجلد غير موجود: $DIR" >&2; exit 2; }

now_epoch="$(date -u -d "$NOW" +%s)"
cutoff=$(( now_epoch - EVENT_MAX_DAYS * 86400 ))

# PREFIX-YYYYMMDDTHHMMSSZ-reason[-label].tar.gz.age ، مرتبة بالطابع الزمني الأحدث أولاً
TS_RE='-([0-9]{8}T[0-9]{6}Z)-'
mapfile -t files < <(find "$DIR" -maxdepth 1 -type f -name '*.tar.gz.age' -printf '%f\n' \
    | while read -r f; do
        [[ "$f" =~ $TS_RE ]] && printf '%s %s\n' "${BASH_REMATCH[1]}" "$f"
      done | sort -r | cut -d' ' -f2-)

declare -A keep=() seen_day=() seen_week=() seen_month=() events=()
days=0; weeks=0; months=0

for i in "${!files[@]}"; do
    f="${files[$i]}"
    [[ "$f" =~ $TS_RE ]]
    ts="${BASH_REMATCH[1]}"
    rest="${f#*"$ts"-}"
    reason="${rest%%.tar.gz.age}"
    d="${ts:0:8}"
    [[ $i -eq 0 ]] && keep[$f]=1

    if [[ "$reason" == scheduled* ]]; then
        iso="${d:0:4}-${d:4:2}-${d:6:2}"
        week="$(date -u -d "$iso" +%G-W%V)"
        month="${d:0:6}"
        if [[ -z "${seen_day[$d]:-}" ]]; then
            seen_day[$d]=1; days=$((days + 1))
            (( days <= KEEP_DAILY )) && keep[$f]=1
        fi
        if [[ -z "${seen_week[$week]:-}" ]]; then
            seen_week[$week]=1; weeks=$((weeks + 1))
            (( weeks <= KEEP_WEEKLY )) && keep[$f]=1
        fi
        if [[ -z "${seen_month[$month]:-}" ]]; then
            seen_month[$month]=1; months=$((months + 1))
            (( months <= KEEP_MONTHLY )) && keep[$f]=1
        fi
    else
        kind=other
        for r in pre-migrate pre-reset pre-restore manual drill; do
            [[ "$reason" == "$r" || "$reason" == "$r"-* ]] && { kind=$r; break; }
        done
        epoch="$(date -u -d "${d:0:4}-${d:4:2}-${d:6:2}T${ts:9:2}:${ts:11:2}:${ts:13:2}Z" +%s)"
        events[$kind]=$(( ${events[$kind]:-0} + 1 ))
        if (( ${events[$kind]} <= KEEP_EVENTS && epoch >= cutoff )); then
            keep[$f]=1
        fi
    fi
done

for f in "${files[@]}"; do
    if [[ -n "${keep[$f]:-}" ]]; then
        echo "KEEP $f"
    else
        echo "DELETE $f"
        if [[ $DRY -eq 0 ]]; then
            rm -f -- "$DIR/$f"
            if [[ -f "$DIR/SHA256SUMS" ]]; then
                grep -vF -- "  $f" "$DIR/SHA256SUMS" > "$DIR/SHA256SUMS.tmp" || true
                mv "$DIR/SHA256SUMS.tmp" "$DIR/SHA256SUMS"
            fi
        fi
    fi
done
