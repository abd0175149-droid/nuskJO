# قناة الإرسال: WhatsAppChannel

البوابة الوحيدة من نُسك إلى ميتا. كل رسالة صادرة، من البوت أو الموظف أو النظام، تمر من هنا.

## الحالة

| البند | الحالة | الملف | الملحمة |
|---|---|---|---|
| السائق الرسمي وحده: WhatsApp Cloud API عبر Graph | منفّذ ✅ | `app/Services/WhatsApp/WhatsAppChannel.php` | قبل الخطة |
| الأقفال: النطاق، والقناة، والإيقاف وحجب ميتا، والنافذة، والمحظور | منفّذ ✅ | `app/Services/WhatsApp/WhatsAppChannel.php` | E04، E10، E22، E31 |
| المغلف الموحّد `send()` ثم `dispatch()`، والأنواع المسجّلة | منفّذ ✅ | `app/Services/WhatsApp/Outbound/OutboundMessage.php` | E32 |
| بوابة المحتوى الصادر (بيانات حساسة أو داخلية) | منفّذ ✅ (التسجيل افتراضياً، والحجب بالعلم `outbound_policy_enforce`) | `app/Services/WhatsApp/Outbound/OutboundPolicy.php` | E15، E33 |
| دفتر الإرسال ومفتاح كل إرسال آلي | منفّذ ✅ (العلم `send_ledger`) | `app/Services/WhatsApp/SendKey.php` | E19 |
| عميل Graph واحد، وappsecret_proof، والإصدار من الإعداد | منفّذ ✅ | `app/Services/WhatsApp/Graph/GraphClient.php` | E05، E18 |
| الناقل (cloud على الإنتاج، وlog للتطوير المحلي وحده) | منفّذ ✅ | `app/Services/WhatsApp/Transport/CloudApiTransport.php` | E23 |
| تصنيف أخطاء ميتا في جدول واحد | منفّذ ✅ | `app/Services/WhatsApp/MetaErrorCatalog.php` | E18 |
| القدرات الرسمية وحالة كل منها | مولَّد | [capabilities.md](capabilities.md) | E25 |

## لماذا وُجد

رقم الشركة واحد، وحظره يوقف المبيعات. فكل ما يمس ميتا في مكان واحد تحرسه اختبارات معمارية: لا عميل غير رسمي، ولا نداء Graph خارج العميل الواحد، ولا POST إلى `/messages` خارج المغلف. والقوالب مرفوضة بقرار المالك: نرد داخل نافذة 24 ساعة فقط.

## القواعد الثابتة

- **الأقفال بالترتيب**، وأولها يرفض: (0) الموظف يرى المحادثة (لرسائله وحدها)، (1) القناة مهيّأة، (2) الإيقاف الطارئ ثم حجب ميتا، (3) نافذة 24 ساعة من آخر رسالة واردة بلا استثناء لأحد، (3ب) المحظور رسمياً لا يصله شيء. ثم بوابة المحتوى، ثم الدفتر، ثم الإرسال.
- **نافذة خدمة العملاء:** لا رسالة حرة خارج 24 ساعة من آخر رسالة للعميل. — https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-messages
- **لا إرسال بارد ولا جماعي، والانسحاب يُحترم.** — https://whatsappbusiness.com/policy/
- **كل نداء Graph عبر `GraphClient`** بالإصدار المضبوط ومقاطع مسار مرمَّزة وبلا تحويلات (`tests/Unit/Arch/GraphCallsUseClientTest.php`).
- **كل POST إلى `/messages` من المغلف وحده**، ومعه إشعار القراءة بالاسم (`tests/Unit/WhatsApp/NoDirectGraphMessagesTest.php`).
- **كل إرسال آلي يحمل مفتاحاً** يمنع تكراره (`tests/Unit/Arch/AutomatedSendsHaveKeyTest.php`).
- **لا whatsapp-web.js ولا Baileys ولا أي عميل غير رسمي** (`tests/Unit/Arch/OfficialChannelOnlyTest.php`).
- **إصدارات Graph:** بعد نهاية الإصدار تُوجَّه النداءات بصمت إلى أحدث منه. الجدول في `app/Services/WhatsApp/GraphVersions.php`، والكتيّب RB-07. — https://developers.facebook.com/docs/graph-api/changelog/versions/
- **appsecret_proof** على الرمز المرسل نفسه، خلف `WA_APPSECRET_PROOF`. — https://developers.facebook.com/docs/graph-api/securing-requests/
- إشعار القراءة وحظر جهات الاتصال ورموز QR وملف الأعمال ليست رسائل: خارج `dispatch()`، وتحترم القناة والإيقاف.

## نموذج التهديد (عام)

- **نص من النموذج أو الموظف يحمل بيانات لا يجب أن تخرج** (رقم حساب، تكلفة، بيانات داخلية): بوابة المحتوى قبل الإرسال، والأرقام يكتبها الكود.
- **إرسال مكرر بعد مهلة أو إعادة:** الدفتر يحجز الصف قبل ميتا، والمفتاح نفسه لا يرسل مرتين، و«غير مؤكد» لا يُعاد آلياً.
- **موظف يرسل في محادثة خارج نطاقه:** القفل 0 يرفض قبل أي نداء.
- **رمز مسرَّب:** appsecret_proof مع «Require App Secret» يجعله بلا قيمة وحده. الخطة في [../runbooks/token-leak.md](../runbooks/token-leak.md).

## ما لا تضمنه الطبقة

- لا نضمن التسليم إلا بما تعيده حالات ميتا (`statuses`). «أُرسلت» تعني أن ميتا قبلت الطلب.
- ميتا تحفظ الرسائل حتى 30 يوماً بصفتها معالِجاً للبيانات. ما نحفظه نحن مسؤوليتنا، ومدته في [inbox.md](inbox.md).
- قرار ميتا بتقييد الحساب أو حظره خارج سيطرتنا. نقرؤه ونوقف الإرسال، والطعن يدوي (RB-03).

## خارطة الطريق

- الاختيارات التفاعلية وقائمة العروض وFlow ثابت: E36 (قيد العمل).
- ترحيل إصدار Graph قبل نهاية الإصدار المضبوط، بتنبيه يومي قبل 60 يوماً.
