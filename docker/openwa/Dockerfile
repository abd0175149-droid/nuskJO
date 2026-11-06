# بوابة واتساب الخاصة بنُسك: صورة OpenWA الرسمية المثبّتة الإصدار + رقعتان من WB web (openwa-patch/).
# طبقة صغيرة فوق الرسمية، لا بناء لكروميوم. يبنيها scripts/deploy.sh فقط عند تغيّر هذا المجلد.
# ⚠ الترقية = تغيير OPENWA_VERSION هنا وفي docker-compose.yml، ثم مراجعة docker/openwa/README.md.
ARG OPENWA_VERSION=0.24.0
FROM ghcr.io/rmyndharis/openwa:${OPENWA_VERSION}

# receipts: من استلم ومن قرأ رسالة أرسلناها. انحراف المرساة (exit 2) يُفشل البناء كله عمداً:
# الصورة العاملة تبقى السابقة، ولا تعمل البوابة صامتةً بلا الرقعة كما في WB web.
COPY apply-receipts.cjs /tmp/apply-receipts.cjs
RUN node /tmp/apply-receipts.cjs /app/dist \
 && node -e "require('/app/dist/engine/adapters/whatsapp-web-js.adapter.js'); require('/app/dist/modules/message/message.service.js'); require('/app/dist/modules/message/message.controller.js')" \
 && rm /tmp/apply-receipts.cjs

# votes: حدث poll.vote لاستطلاعات الخيارات (بديل أزرار ميتا). أفضل جهد: تتخطّى نفسها إن تغيّرت المراسي.
COPY apply-votes.cjs /tmp/apply-votes.cjs
RUN node /tmp/apply-votes.cjs /app/dist \
 && node --check /app/dist/engine/adapters/wwebjs-message-events.js \
 && node --check /app/dist/modules/session/session-engine-event-wiring.js \
 && node --check /app/dist/modules/webhook/dto/webhook.dto.js \
 && rm /tmp/apply-votes.cjs

LABEL nuskjo.patches="receipts votes"
