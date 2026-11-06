#!/usr/bin/env node
/*
 * wbagent:receipts — يضيف إلى OpenWA مساراً يعيد من استلم ومن قرأ رسالة أرسلناها:
 *   GET /api/sessions/:sessionId/messages/:chatId/:messageId/receipts
 * يعتمد على Message.getInfo() في whatsapp-web.js (ليست مكشوفة في OpenWA الرسمي حتى 0.24.0).
 *
 * يُشغَّل وقت بناء الصورة (docker/openwa/Dockerfile) على /app/dist.
 * كل إدراج مربوط بسطر «مرساة» من الكود الأصلي: إن تغيّر الكود في إصدار جديد يفشل السكربت برسالة واضحة
 * بدل أن يُدرج في مكان خاطئ — وفي نُسك يفشل بناء صورة البوابة كلها (لا رجوع صامت للرسمية). راجع README.md.
 * إن صار في OpenWA مسار رسمي لهذا الغرض يتوقف السكربت (exit 3) لتُحذف الرقعة ويُستخدم الرسمي.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = process.argv[2] || '/app/dist';
const MARK = 'wbagent:receipts';
const f = (p) => path.join(ROOT, p);
const files = {
  messaging: f('engine/adapters/wwebjs-messaging.js'),
  adapter: f('engine/adapters/whatsapp-web-js.adapter.js'),
  service: f('modules/message/message.service.js'),
  controller: f('modules/message/message.controller.js'),
};

function die(code, msg) { console.error(`\n✖ ${MARK}: ${msg}\n  راجع docker/openwa/README.md\n`); process.exit(code); }

for (const [k, p] of Object.entries(files)) if (!fs.existsSync(p)) die(2, `الملف ${k} غير موجود (${p}) — تغيّرت بنية OpenWA`);
const src = Object.fromEntries(Object.entries(files).map(([k, p]) => [k, fs.readFileSync(p, 'utf8')]));

// صار رسمياً؟ (مسار receipts أو استدعاء getInfo في الكود الأصلي)
if (!src.controller.includes(MARK) && (/\/receipts'\)/.test(src.controller) || /\.getInfo\(\)/.test(src.messaging))) {
  die(3, 'يبدو أن OpenWA صار يكشف معلومات الرسالة رسمياً — احذف الرقعة واستخدم المسار الرسمي في Studio (openwa.js receipts)');
}
if (Object.values(src).every((s) => s.includes(MARK))) { console.log(`✔ ${MARK}: مطبّقة مسبقاً`); process.exit(0); }

function insertBefore(key, anchor, code) {
  const s = src[key];
  const n = s.split(anchor).length - 1;
  if (n !== 1) die(2, `مرساة ${key} وُجدت ${n} مرة بدل مرة واحدة: «${anchor.trim()}»`);
  src[key] = s.replace(anchor, code + anchor);
}
function insertAfter(key, anchor, code) {
  const s = src[key];
  const n = s.split(anchor).length - 1;
  if (n !== 1) die(2, `مرساة ${key} وُجدت ${n} مرة بدل مرة واحدة: «${anchor.trim()}»`);
  src[key] = s.replace(anchor, anchor + code);
}

// 1) المحرك: whatsapp-web.js
insertBefore('messaging', '    async getMessageReactions(chatId, messageId) {\n', `    /* ${MARK} — من استلم ومن قرأ رسالة أرسلناها (Message.getInfo) */
    async getMessageReceipts(chatId, messageId) {
        this.host.ensureReady();
        const out = await this.withPage('getMessageReceipts', async () => {
            let message = null;
            try { message = await this.client().getMessageById(messageId); } catch { message = null; }
            if (!message) {
                const chat = await this.client().getChatById(chatId);
                if (!chat) throw new message_not_found_error_1.MessageNotFoundError(messageId, chatId);
                const recent = await chat.fetchMessages({ limit: 200 });
                message = recent.find((m) => m.id._serialized === messageId) || null;
            }
            const remote = String(message?.id?.remote?._serialized ?? message?.id?.remote ?? '');
            // الرسالة يجب أن تكون من المحادثة المطلوبة نفسها — المفتاح المحصور يُفحص على chatId
            if (!message || (remote !== chatId && !String(messageId).includes('_' + chatId + '_'))) throw new message_not_found_error_1.MessageNotFoundError(messageId, chatId);
            if (!message.fromMe) return { messageId, chatId, fromMe: false, available: false, ack: message.ack ?? null, delivered: [], read: [], played: [] };
            const info = await message.getInfo();
            const ids = (arr) => (Array.isArray(arr) ? arr : []).map((x) => ({ id: String(x?.id?._serialized ?? x?.id ?? ''), t: Number(x?.t) || null })).filter((x) => x.id && x.id !== '[object Object]');
            return {
                messageId, chatId, fromMe: true, available: !!info, ack: message.ack ?? null,
                delivered: ids(info?.delivery), read: ids(info?.read), played: ids(info?.played),
                deliveryRemaining: info?.deliveryRemaining ?? null, readRemaining: info?.readRemaining ?? null, playedRemaining: info?.playedRemaining ?? null,
            };
        });
        if (!out) throw new message_not_found_error_1.MessageNotFoundError(messageId, chatId);
        return out;
    }
`);

// 2) المحوّل
insertBefore('adapter', '    getMessageReactions(chatId, messageId) {\n', `    /* ${MARK} */
    getMessageReceipts(chatId, messageId) {
        return this.messaging.getMessageReceipts(chatId, messageId);
    }
`);

// 3) الخدمة
insertBefore('service', '    async getMessageReactions(sessionId, chatId, messageId) {\n', `    /* ${MARK} */
    async getMessageReceipts(sessionId, chatId, messageId) {
        const engine = this.getEngine(sessionId);
        if (typeof engine.getMessageReceipts !== 'function') throw new common_1.NotImplementedException('Message receipts are available on whatsapp-web.js only (wbagent patch)');
        return engine.getMessageReceipts(chatId, messageId);
    }
`);

// 4) المتحكم: الدالة + مسارها بنفس حماية مسار reactions (ChatScoped fenced: المفتاح المحصور لا يصل إلا لمحادثاته)
insertBefore('controller', '    async getReactions(sessionId, chatId, messageId) {\n', `    /* ${MARK} */
    async getReceipts(sessionId, chatId, messageId) {
        return this.messageService.getMessageReceipts(sessionId, chatId, messageId);
    }
`);
insertAfter('controller', '], MessageController.prototype, "getReactions", null);\n', `/* ${MARK} */
__decorate([
    (0, auth_decorators_1.ChatScoped)('fenced'),
    (0, common_1.Get)(':chatId/:messageId/receipts'),
    (0, swagger_1.ApiOperation)({
        summary: 'Who received / read / played a message this account sent (wbagent patch, whatsapp-web.js)',
        description: 'Backed by whatsapp-web.js Message.getInfo(). Members who turned off read receipts appear as delivered only. Not part of upstream OpenWA.',
    }),
    (0, swagger_1.ApiParam)({ name: 'sessionId', description: 'Session ID' }),
    (0, swagger_1.ApiParam)({ name: 'chatId', description: 'Chat ID containing the message' }),
    (0, swagger_1.ApiParam)({ name: 'messageId', description: 'Serialized message ID' }),
    (0, swagger_1.ApiResponse)({ status: 404, description: 'Message not found in this chat' }),
    (0, swagger_1.ApiResponse)({ status: 501, description: 'Engine without message info (Baileys)' }),
    __param(0, (0, common_1.Param)('sessionId')),
    __param(1, (0, common_1.Param)('chatId')),
    __param(2, (0, common_1.Param)('messageId')),
    __metadata("design:type", Function),
    __metadata("design:paramtypes", [String, String, String]),
    __metadata("design:returntype", Promise)
], MessageController.prototype, "getReceipts", null);
`);

for (const [k, p] of Object.entries(files)) fs.writeFileSync(p, src[k]);
console.log(`✔ ${MARK}: طُبّقت على ${Object.keys(files).length} ملفات`);
