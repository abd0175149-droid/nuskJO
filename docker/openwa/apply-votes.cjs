#!/usr/bin/env node
/*
 * wbagent:votes — يلتقط أصوات الاستفتاء من whatsapp-web.js ويبثّها عبر webhook باسم `poll.vote`:
 *   { sessionId, pollMessageId, chatId, voter, selectedOptions:[نص الخيار], at }
 * يعتمد على حدث whatsapp-web.js `vote_update` (غير مكشوف في OpenWA الرسمي حتى 0.24.0).
 *
 * يُشغَّل وقت بناء الصورة (docker/openwa/Dockerfile) على /app/dist، بعد apply-receipts.cjs.
 * «أفضل جهد»: إن تغيّرت المراسي في إصدار جديد، أو لم يَصِح الناتج، يتخطّى الميزة ويخرج 0 دون كتابة أي شيء —
 * فلا يتوقف البناء ولا تتأثر رقعة receipts؛ تعمل البوابة دون التقاط أصوات حتى تُحدَّث المراسي (راجع README.md).
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = process.argv[2] || '/app/dist';
const MARK = 'wbagent:votes';
const f = (p) => path.join(ROOT, p);
const files = {
  events: f('engine/adapters/wwebjs-message-events.js'),
  wiring: f('modules/session/session-engine-event-wiring.js'),
  webhookDto: f('modules/webhook/dto/webhook.dto.js'),
};
const skip = (m) => { console.error(`⚠ ${MARK}: ${m} — تُتخطّى ميزة التقاط الأصوات (البناء وreceipts سليمان)`); process.exit(0); };

for (const [k, p] of Object.entries(files)) if (!fs.existsSync(p)) skip(`الملف ${k} غير موجود (${p}) — تغيّرت بنية OpenWA`);
const src = Object.fromEntries(Object.entries(files).map(([k, p]) => [k, fs.readFileSync(p, 'utf8')]));
if (Object.values(src).every((s) => s.includes(MARK))) { console.log(`✔ ${MARK}: مطبّقة مسبقاً`); process.exit(0); }

// مراسٍ من الكود المُصرَّف الأصلي — كلٌّ يجب أن يوجد مرة واحدة بالضبط (الكل أو لا شيء)
const ANCHORS = {
  events:
    "            host.getCallbacks().onMessageReaction?.(event);\n" +
    "        }\n" +
    "        catch (error) {\n" +
    "            host.logger.error('Error processing message_reaction', String(error));\n" +
    "        }\n" +
    "    });\n",
  wiring:
    "            onPresenceUpdate: (event) => {\n" +
    "                if (!host.isLiveEngine(id, engine))\n" +
    "                    return;\n" +
    "                if (!host.presence.record(id, event))\n" +
    "                    return;\n" +
    "                const payload = { sessionId: id, ...event };\n" +
    "                host.eventsGateway.emitPresenceUpdate(id, payload);\n" +
    "                void host.webhookService.dispatch(id, 'presence.update', payload);\n" +
    "            },\n",
  webhookDto:
    "    'call.missed',\n" +
    "    ...exports.WEBHOOK_RESERVED_EVENTS,\n",
};
for (const [k, a] of Object.entries(ANCHORS)) { const n = src[k].split(a).length - 1; if (n !== 1) skip(`مرساة ${k} وُجدت ${n} مرة بدل مرة واحدة`); }

const VOTE_LISTENER =
  `\n    /* ${MARK} — التقاط صوت الاستفتاء من vote_update */\n` +
  "    client.on('vote_update', vote => {\n" +
  '        try {\n' +
  '            const pid = vote?.parentMessage?.id;\n' +
  '            const event = {\n' +
  "                pollMessageId: pid?._serialized ?? pid?.$1 ?? '',\n" +
  "                chatId: pid?.remote ?? '',\n" +
  "                voter: vote?.voter ?? '',\n" +
  '                selectedOptions: Array.isArray(vote?.selectedOptions) ? vote.selectedOptions.map(o => o?.name ?? String(o?.id ?? \'\')).filter(Boolean) : [],\n' +
  '                at: Number(vote?.interractedAtTs) || Date.now(),\n' +
  '            };\n' +
  '            host.getCallbacks().onPollVote?.(event);\n' +
  '        }\n' +
  '        catch (error) {\n' +
  "            host.logger.error('Error processing vote_update', String(error));\n" +
  '        }\n' +
  '    });\n';

const VOTE_CALLBACK =
  `            /* ${MARK} — بثّ صوت الاستفتاء عبر webhook */\n` +
  '            onPollVote: (event) => {\n' +
  '                if (!host.isLiveEngine(id, engine))\n' +
  '                    return;\n' +
  '                if (!event.pollMessageId)\n' +
  '                    return;\n' +
  '                const payload = { sessionId: id, ...event };\n' +
  "                void host.webhookService.dispatch(id, 'poll.vote', payload);\n" +
  '            },\n';

const out = {
  events: src.events.replace(ANCHORS.events, ANCHORS.events + VOTE_LISTENER),
  wiring: src.wiring.replace(ANCHORS.wiring, ANCHORS.wiring + VOTE_CALLBACK),
  webhookDto: src.webhookDto.replace(
    ANCHORS.webhookDto,
    `    'call.missed',\n    'poll.vote', /* ${MARK} */\n    ...exports.WEBHOOK_RESERVED_EVENTS,\n`,
  ),
};

// تحقّق من صحّة بناء الجملة قبل الكتابة — إن لم يَصِح الناتج، لا نكتب شيئاً ونتخطّى
for (const [k, s] of Object.entries(out)) { try { new vm.Script(s, { filename: files[k] }); } catch (e) { skip(`الناتج بعد الرقعة لم يَصِح في ${k}: ${e.message}`); } }

for (const [k, p] of Object.entries(files)) fs.writeFileSync(p, out[k]);
console.log(`✔ ${MARK}: التقاط أصوات الاستفتاء مطبّق (vote_update → webhook poll.vote)`);
