#!/usr/bin/env node
/*
 * nuskjo:rawtype — يضيف rawType (نوع whatsapp-web.js الأصلي) إلى IncomingMessage في الـ webhook والسجل.
 * OpenWA يحوّل كل نوع لا يعرفه إلى «unknown» فيضيع الفرق بين إشعار التشفير (e2e_notification) وتغيير رمز الأمان
 * (notification_template) ورسالة لم تُفك بعد (ciphertext) ورسالة فيديو دائرية (ptv). نُسك يُسقط الداخلية منها
 * ويعرض الباقي (OpenWa\InboundParser::SYSTEM_TYPES).
 *
 * يُشغَّل وقت بناء الصورة (docker/openwa/Dockerfile) على /app/dist. «أفضل جهد»: إن تغيّرت المرساة يتخطّى ويخرج 0،
 * فتعمل البوابة كما هي (unknown بلا rawType)، ونُسك يعامل unknown الفارغ معاملة إشعار داخلي.
 */
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = process.argv[2] || '/app/dist';
const MARK = 'nuskjo:rawtype';
const file = path.join(ROOT, 'engine/adapters/message-mapper.js');
const skip = (m) => { console.error(`⚠ ${MARK}: ${m} — تُتخطّى (البوابة تعمل بلا rawType)`); process.exit(0); };

if (!fs.existsSync(file)) skip(`الملف غير موجود (${file})`);
const src = fs.readFileSync(file, 'utf8');
if (src.includes(MARK)) { console.log(`✔ ${MARK}: مطبّقة مسبقاً`); process.exit(0); }

const ANCHOR = '        type: mapWwebjsMessageType(msg.type),\n';
const n = src.split(ANCHOR).length - 1;
if (n !== 1) skip(`المرساة وُجدت ${n} مرة بدل مرة واحدة`);

const out = src.replace(ANCHOR, ANCHOR + `        /* ${MARK} */ rawType: typeof msg.type === 'string' ? msg.type : undefined,\n`);
fs.writeFileSync(file, out);
console.log(`✔ ${MARK}: أُضيف rawType إلى IncomingMessage`);
