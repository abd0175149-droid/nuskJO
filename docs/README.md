# وثائق nuskJO

فهرس الوثائق، وسياسة ما يدخل المستودع العام، ومن يحدّث ماذا. الوثائق لا تُخدم من الموقع ولا تدخل صورة الإنتاج (`docs/` و`**/*.md` في `.dockerignore`)، وتدخل حاوية بوابة النشر لأن اختباراتها تقرؤها.

## الفهرس

التشغيل:
- [RUNBOOK.md](RUNBOOK.md) — كتيّبات الطوارئ RB-NN، وجدول أعلام الميزات بوصفها مقابض تراجع.
- [RISKS.md](RISKS.md) — سجل المخاطر، ومصفوفة الاستجابة، وربط كل تنبيه بكتيّبه، والمراجعة الشهرية.
- [runbooks/token-leak.md](runbooks/token-leak.md) — خطة تسرّب الرمز أو السر بالترتيب (تفصيل RB-09).
- [RELEASE.md](RELEASE.md) — قائمة النشر، وقسم لكل ملحمة بخطواتها وأعلامها.
- [BACKUP.md](BACKUP.md) — النسخ الاحتياطي وتنبيهاته.
- [RESTORE.md](RESTORE.md) — الاستعادة وفقدان الخادم.
- [ops/load-budget.md](ops/load-budget.md) — ميزانية الحمل والقائمة الموحدة للمهام المجدولة (E24).

طبقة الواتساب:
- [whatsapp/channel.md](whatsapp/channel.md) — قناة الإرسال والأقفال والمغلف.
- [whatsapp/webhook.md](whatsapp/webhook.md) — استقبال الويبهوك، ومعه العقد [whatsapp-webhook-contract.md](whatsapp-webhook-contract.md).
- [whatsapp/inbox.md](whatsapp/inbox.md) — طبقة الوارد والهوية والحالات وسياج الرؤية.
- [whatsapp/bot-engine.md](whatsapp/bot-engine.md) — محرّك البوت وبواباته والقاطع.
- [whatsapp/bot-tools.md](whatsapp/bot-tools.md) — أدوات بوت العملاء وحدودها.
- [whatsapp/handoff.md](whatsapp/handoff.md) — التحويل لموظف.
- [whatsapp/topic-routing.md](whatsapp/topic-routing.md) — توجيه المحادثات ومن يرى ماذا.
- [whatsapp/admin-assistant.md](whatsapp/admin-assistant.md) — المساعد الإداري لأرقام الإدارة.
- [whatsapp/offers-costing.md](whatsapp/offers-costing.md) — العروض وطلبات التسعير والفاتورة.
- [whatsapp/settings-secrets.md](whatsapp/settings-secrets.md) — الإعدادات والأسرار والأعلام.
- [whatsapp/health-monitoring.md](whatsapp/health-monitoring.md) — الصحة والمراقبة، خريطة تحيل إلى التفصيل.
- [whatsapp/capabilities.md](whatsapp/capabilities.md) — مصفوفة قدرات Cloud API (مولَّدة).
- [whatsapp/channel-health.md](whatsapp/channel-health.md) — صحة القناة كآلة حالات (E22).
- [whatsapp/diagnostics.md](whatsapp/diagnostics.md) — المراقب الدوري وأدوات التشخيص (E23).
- [whatsapp/ops-alerts.md](whatsapp/ops-alerts.md) — قواعد التنبيه ومركز الإخفاقات (E24).
- [whatsapp/events.md](whatsapp/events.md) — أحداث الواتساب الداخلية (E21).
- [whatsapp/auto-rules.md](whatsapp/auto-rules.md) — الردود الحتمية وصمت الوارد (E37).
- [whatsapp/voice-notes.md](whatsapp/voice-notes.md) — رسائل الموظف الصوتية (E34).
- [whatsapp/calls.md](whatsapp/calls.md) — المكالمات، جاهزية مشروطة (E39).
- [whatsapp/coexistence.md](whatsapp/coexistence.md) — التعايش مع تطبيق الأعمال (E39).
- [whatsapp/interactive-choices.md](whatsapp/interactive-choices.md) — الاختيارات التفاعلية ونموذج طلب التسعير (E36).

التكاملات والأمن:
- [integrations/webhooks.md](integrations/webhooks.md) — الأحداث الصادرة للتكاملات (E40).
- [integrations/mcp.md](integrations/mcp.md) — خادم MCP للقراءة (E40).
- [security/DATA-MINIMIZATION.md](security/DATA-MINIMIZATION.md) — تقليل البيانات في كل مخرج.
- [../SECURITY.md](../SECURITY.md) — سياسة الإبلاغ عن الثغرات، و`public/.well-known/security.txt`.
- [../CHANGELOG.md](../CHANGELOG.md) — التغييرات وملاحظات الترقية والتراجع.

ملفات `context/` القديمة أرشيف يصف بنية سابقة، وليست مرجعاً.

## سياسة التوثيق العام والحساس

المستودع عام. تحققتُ في 2026-10-16 أن إتاحته public، وقرار جعله خاصاً عند المالك. حتى ذلك الحين:

- **يدخل المستودع:** جداول الحالة، و«لماذا وُجد»، والقواعد الثابتة مع روابط ميتا الرسمية، و«ما لا تضمنه الطبقة» بصياغة عامة، والكتيّبات، وخارطة الطريق.
- **يبقى خارج المستودع**، عند المالك في مكانه الخاص: تفصيل أي فجوة مفتوحة (اسم الملف والسطر وخطوات الاستغلال)، ونموذج التهديد التفصيلي، ودليل تدوير الأسرار التفصيلي، وروابط نماذج ميتا الخاصة بالحساب.
- **مكان الفجوة المفتوحة في الوثيقة العامة** سطر واحد: «⚠️ فجوة — المرجع OPS-NN، التفاصيل خارج المستودع». حين تُغلق بملحمتها يُكتب «أُغلق في EXX» بلا تفاصيل استغلال.
- **لا قيمة لأي سر، ولا رقم هاتف حقيقي، ولا معرّف حساب ميتا** (phone_number_id أو WABA ID أو App ID)، ولا مقتطف محادثة. أسماء المتغيرات وحدها، وأمثلة الصيغ بين <> مثل <PHONE_NUMBER_ID>.
- **الاختبارات ورسائل commit** بصياغة سلوكية («غير المدير لا يسند دور مدير») لا استغلالية.

إن صار المستودع خاصاً: يُجهَّز قبله مفتاح نشر للقراءة فقط على الخادم ويُجرَّب السحب به، ثم تُحدَّث خطوة السحب في `scripts/deploy.sh` و[RELEASE.md](RELEASE.md)، ويتغيّر Policy في `security.txt` إن لزم.

## من يحدّث ماذا

قاعدة الصيانة: كل تغيير يمس `app/Services/WhatsApp/` أو متحكمات `WhatsApp*` يحدّث جدول الحالة في وثيقة المكوّن في التغيير نفسه، وقالب طلب الدمج يذكّر بذلك.

| إذا أضفت | فحدّث | ويحرسه |
|---|---|---|
| مهمة مجدولة | [ops/load-budget.md](ops/load-budget.md) وكتيّبها في `config/runbooks.php` (`schedules`) | `LoadBudgetTest` و`RunbookScheduleCoverageTest` |
| تنبيهاً للمدراء | بادئته في `config/runbooks.php` (`alerts`) وصفاً في [RISKS.md](RISKS.md)، أو `runbook:` في النداء | `RunbookAlertCoverageTest` |
| كتيّباً | قسماً بالقالب ومرساة `rb-NN` في [RUNBOOK.md](RUNBOOK.md) | `RunbookAlertCoverageTest` |
| علم ميزة أو علماً تشغيلياً | صفاً في جدول الأعلام في [RUNBOOK.md](RUNBOOK.md) | `FeatureFlagsDocumentedTest` |
| متغير بيئة | سطراً في `.env.example` (ولو `# KEY=`) | `EnvExampleCoverageTest` |
| قدرة واتساب أو دالة إرسال أو أداة | صفاً في `config/whatsapp_capabilities.php` ثم `php artisan docs:capabilities` | `CapabilityMatrixTest` |
| وثيقة | سطراً في هذا الفهرس | `ComponentDocsTest` |
| مساراً في وثيقة | تأكد أنه موجود | `DocPathsExistTest` |
| أي نص في وثيقة | لا سر ولا هاتف ولا عنوان داخلي | `DocsNoSecretsTest` |

## الضمانات المحروسة بالاختبارات

- `tests/Feature/Docs/`: الكتيّبات والتنبيهات والجدولة، ومصفوفة القدرات، وعقد الويبهوك (`WebhookContractDocTest`).
- `tests/Unit/Docs/`: الأعلام، و`.env.example`، والمسارات، والأسرار، وقالب وثائق المكوّنات، و`security.txt`، وأفق إصدار Graph.
- مجموعة `horizon` (أفق الإصدار وExpires) مرتبطة بالتاريخ: تعمل في CI، وتُستثنى من بوابة النشر في `Dockerfile.test`.
- صلاحية كل مسار (`RouteAuthorizationTest`) وتغطية التدقيق (`AuditCoverageTest` و`AuditRouteCoverageTest`) من ملاحمها، ولا تُكرَّر هنا.
