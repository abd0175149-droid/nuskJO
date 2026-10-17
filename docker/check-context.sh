#!/bin/sh
# حارس قبل «docker compose build»: يبني صورة مؤقتة من سياق البناء نفسه (بقواعد .dockerignore نفسها)
# ويفشل إن وصل إليها ملف بيئة أو قاعدة أو نسخة أو مفتاح.
#   ./docker/check-context.sh            (من جذر المستودع)
#   CONTEXT=<مجلد> CHECK_IMAGE=<صورة صغيرة فيها find>   لتغيير المجلد أو الصورة
set -eu

CONTEXT="${CONTEXT:-$(cd "$(dirname "$0")/.." && pwd)}"
CHECK_IMAGE="${CHECK_IMAGE:-busybox:1.36}"
TAG="nuskjo-context-check:$$"

printf 'FROM %s\nCOPY . /ctx\n' "$CHECK_IMAGE" | docker build --no-cache -q -t "$TAG" -f - "$CONTEXT" >/dev/null

found=$(docker run --rm --network none "$TAG" find /ctx \
    \( -name '*.sqlite*' -o -name '.env' -o -name '.env.production' -o -name '.env.*.local' \
       -o -name 'firebase-auth.json' -o -name '.mcp.json' -o -name '*.bak' -o -name '*.sql' \
       -o -name '*.age' -o -path '/ctx/storage/*' \) -print)
docker rmi -f "$TAG" >/dev/null 2>&1 || true

if [ -n "$found" ]; then
    echo "❌ سياق البناء يحمل ما لا يدخل الصورة (أضفه إلى .dockerignore):"
    echo "$found" | sed 's#^/ctx/#  #'
    exit 1
fi
echo "✅ سياق البناء نظيف: لا بيئة ولا قواعد ولا نسخ ولا مفاتيح"
