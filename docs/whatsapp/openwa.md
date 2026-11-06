# بوابة OpenWA (منذ 2026-11-06)

نُسك يرسل ويستقبل رسائل واتساب عبر بوابة OpenWA خاصة به: الخدمة `nuskjo-openwa` في `docker-compose.yml`، وصورتها مبنية من `docker/openwa`. هذا بديل Meta Cloud API بقرار المالك.

- **الأرقام:** لكل رقم صف في `wa_channels`، وجلسة مستقلة على البوابة، ومفتاح API محصور بجلسته، وسر توقيع webhook خاص به.
- **الإدارة:** تُدار الأرقام من صفحة «الأرقام» (`/whatsapp/channels`)، أو بالأمرين `whatsapp:channel-add` و`whatsapp:channel-qr` قبل اكتمال الواجهة.

## المسار

```
هاتف العميل ⇄ واتساب ⇄ كروميوم (جلسة الرقم) ⇄ OpenWA :2785
   ↑ إرسال: OpenWaClient ← OpenWaTransport ← WhatsAppChannel
   ↓ أحداث: POST http://nuskjo-app:4020/api/openwa/webhook/{uuid الرقم}
            موقّعة بـ X-OpenWA-Signature: sha256=HMAC(الجسم، سر الرقم)
            → WebhookIntake → EventRouter → InboundParser → WhatsAppInbox::storeInbound
```

- **نداء HTTP:** يمر كل نداء إلى البوابة عبر `App\Services\WhatsApp\OpenWa\OpenWaClient` وحده. يحرس ذلك `tests/Unit/Arch/GatewayOnlyTest.php`.
- **التبعيات:** لا توجد حزمة واتساب في composer ولا في npm.

## أول تشغيل (مرة واحدة على الخادم)

1. **الأسرار.** ولّد السرّين، واكتبهما في `/opt/nuskJO/.env`. المجلد ملك root، فالكتابة تتم عبر حاوية مؤقتة:
   ```
   M=$(openssl rand -hex 32); P=$(openssl rand -hex 32)
   docker run --rm -v /opt/nuskJO:/w alpine sh -c "printf 'OPENWA_API_MASTER_KEY=%s\nOPENWA_API_KEY_PEPPER=%s\n' $M $P >> /w/.env"
   ```
   ⚠ **`OPENWA_API_KEY_PEPPER` لا يتغير أبداً.** تغييره يُبطل كل مفاتيح الأرقام.
2. **النشر.** شغّل `bash scripts/deploy.sh`. هو يبني البوابة لأول مرة، ثم ينتظر `healthy`، ثم ينشر التطبيق.
   - إن غاب أحد السرّين يتوقف النشر قبل البناء برسالة واضحة.
3. **الفحص:**
   ```
   docker exec nuskjo-app curl -fsS http://nuskjo-openwa:2785/api/health/ready
   docker exec nuskjo-app php artisan whatsapp:doctor
   ```
4. **الصلاحيات.** امنح صلاحية `whatsapp.channels` للمدير بسكربت موجّه: `scripts/permissions/grant-openwa.php`.
   - ⚠ لا تشغّل `RolePermissionSeeder` على الإنتاج، لأن `sync()` فيه يمسح المنح المخصصة.
5. **ربط الأرقام.** من صفحة «الأرقام»: إضافة رقم ← «ربط» ← مسح QR من واتساب الهاتف (الأجهزة المرتبطة)، أو إدخال كود الربط.

## الحدود

- **حدود البوابة مطفأة.** الجدول في `docker/openwa/README.md`. البقاء الوحيد حد المعدّل لكل عنوان IP، وقد رُفع إلى 100000.
- **حدود الإرسال في نُسك.** تُضبط لكل رقم ولكل نوع رسالة من صفحة «الحدود»، ويطبقها `SendGovernor`. التفاصيل في `docs/whatsapp/limits.md`.
- **الردود خارج السقوف.** ردود البوت والموظف لا تخضع للسقوف، فهي مسار سريع. تمر فقط على الإيقاف العام وإيقاف الرقم والحظر وقاطع الإخفاقات.

## صحة الرقم

- **`whatsapp:channel-watch` كل دقيقتين.** يفحص كل رقم فحصاً حقيقياً: `contacts/check` على رقم الرقم نفسه.
  - لا يكتفي بحالة «ready»، لأن الجلسة قد تبقى «ready» وهي عالقة بعد إعادة تشغيل.
  - بعد 3 إخفاقات يعيد تشغيل الجلسة، مرة كل 30 دقيقة على الأكثر.
  - ينبّه عند الانقطاع وعند العودة.
- **`session.restriction`** (علامة حظر محتمل) يوقف الرقم فوراً وينبّه الإدارة.
- **بعد إعادة الاتصال** يسترجع `BackfillChannel` ما فات: آخر 40 محادثة × 15 رسالة، مع منع التكرار.

## الهوية

- **jid المحادثة.** `wa_conversations.jid` هو معرّف المحادثة على البوابة، ويكون `…@c.us` أو `…@lid`.
- **المرسل خلف معرّف خصوصية (`@lid`).** يصل رقمه الحقيقي في `senderPhone` (`RESOLVE_LID_TO_PHONE=true`). وما بقي بلا رقم يحله `whatsapp:resolve-lids` كل 30 دقيقة.
- **عميل يراسل رقمين** تُفتح له محادثتان منفصلتان: `unique(channel_id, phone)`. ولوحة العميل تجمع الاثنتين.
