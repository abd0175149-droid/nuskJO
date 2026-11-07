# بوابة OpenWA الخاصة بنُسك

خدمة `nuskjo-openwa` في `docker-compose.yml` هي بوابة واتساب نُسك وحدها (قرار المالك 2026-11-06): كل أرقام نُسك جلسات عليها، ولا يشاركها مشروع آخر.

- **الصورة:** `ghcr.io/rmyndharis/openwa:0.24.0`، ويُضاف فوقها رقعتان منسوختان من `C:\Projects\WB web\openwa-patch`.
- **المحرك:** whatsapp-web.js، وتستهلك كل جلسة (كروميوم) نحو 300 إلى 500 ميغابايت.
- **الاتصال:** نُسك يتحدث مع البوابة عبر HTTP فقط، من `App\Services\WhatsApp\OpenWa\OpenWaClient`، داخل شبكة compose. البوابة لا تنشر أي منفذ للإنترنت، ولوحتها على `127.0.0.1:4021` تُفتح عبر نفق SSH فقط.

## الرقع

| الرقعة | ما تضيفه | إن انحرفت المرساة بعد ترقية |
|---|---|---|
| `apply-receipts.cjs` | المسار `GET /api/sessions/:id/messages/:chatId/:messageId/receipts`: من استلم ومن قرأ | **يفشل البناء** (exit 2)، فتبقى الصورة العاملة كما هي |
| `apply-votes.cjs` | الحدث `poll.vote` لأصوات الاستطلاعات | تتخطّى الرقعة نفسها (exit 0)، ويعود اختيار الخيارات إلى الرد بالرقم |
| `apply-rawtype.cjs` | الحقل `rawType` (نوع whatsapp-web.js الأصلي) في كل رسالة: البوابة تسمّي ما لا تعرفه `unknown` | تتخطّى نفسها (exit 0). نُسك يُسقط `unknown` الفارغ إشعاراً داخلياً، ويعرض ما له نص |

إن وجد سكربت receipts مساراً رسمياً للغرض نفسه يتوقف (exit 3). عندها احذف الرقعة واستخدم المسار الرسمي.

## الترقية

1. غيّر `OPENWA_VERSION` في `docker/openwa/Dockerfile` وفي `docker-compose.yml`.
2. راجع CHANGELOG الإصدار الجديد. ابحث عن `receipts` و`getInfo` و`poll vote`.
3. جرّب الرقعتين محلياً على نسخة من dist الجديدة:
   ```
   docker run --rm --entrypoint sh ghcr.io/rmyndharis/openwa:<v> -c 'tar -C /app -c dist' | tar -x -C /tmp/owa
   node docker/openwa/apply-receipts.cjs /tmp/owa/dist && node docker/openwa/apply-votes.cjs /tmp/owa/dist && node docker/openwa/apply-rawtype.cjs /tmp/owa/dist
   ```
4. انشر بـ `bash scripts/deploy.sh`. يكتشف تغيّر هذا المجلد بالبصمة فيبني البوابة ويعيد تشغيلها، وتتوقف الجلسات نحو دقيقة.
5. شغّل `php artisan whatsapp:doctor` داخل `nuskjo-app` وتأكد أن كل الجلسات جاهزة.

## حدود المحرك المطفأة عمداً

حدود الإرسال تُدار في نُسك (`wa_send_limits` و`SendGovernor`) لكل رقم ولكل نوع رسالة، فتُطفأ حدود البوابة حتى لا تتعارض معها:

| المتغير | القيمة | السبب |
|---|---|---|
| `SEND_PACING_ENABLED` | `false` | سقف 300 رسالة يومياً و60 محادثة باردة وقاطع البوابة. استبدلها SendGovernor |
| `SIMULATE_TYPING` | `false` | كانت تؤخر كل إرسال حتى 5 ثوانٍ. نُسك يرسل مؤشر الكتابة بنفسه للردود |
| `MAX_CONCURRENT_SESSIONS` | `0` | 0 يعني بلا سقف. السقف الفعلي هو ذاكرة الحاوية (`NUSKJO_OPENWA_MEM_LIMIT`) |
| `WEBHOOK_MAX_PER_SESSION` | `0` | بلا سقف |
| `RATE_LIMIT_*_LIMIT` | `100000` | الحد يُحسب لكل عنوان IP ولا يُطفأ (أقله 1). كل طلبات نُسك تأتي من عنوان واحد |
| `BODY_SIZE_LIMIT` | `70mb` | الوسائط تُرسل base64 داخل الطلب، وحجم base64 أكبر من الملف بنحو الثلث |

## بيانات الجلسات

الحجم `nuskjo-openwa-data:/app/data` فيه جلسات كروميوم وملف `main.sqlite` (مفاتيح API والـ webhooks). **النسخ الاحتياطي لا يشمله** عمداً، كما في WB web:

- فقدان الجلسات يعني إعادة مسح QR لكل رقم، ولا يعني فقدان أي رسالة. الرسائل محفوظة في قاعدة نُسك.
- فقدان `main.sqlite` يُبطل مفاتيح الأرقام. يُعيدها زر «إعادة الإعداد» في صفحة الأرقام، أو `php artisan whatsapp:channel-add --repair`.
- `OPENWA_API_KEY_PEPPER` في `/opt/nuskJO/.env` **لا يتغير أبداً**، لأن تغييره يُبطل كل المفاتيح.
