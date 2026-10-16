# أحداث الواتساب الداخلية (E21)

هذه الوثيقة عقد أحداث `app/Events/WhatsApp` لمن يبني عليها: صحة الحساب (E22)، ومركز التشغيل والتحليلات (E24)، والبث اللحظي للصندوق (E28).

## القواعد

- **بعد الاعتماد:** كل حدث يُطلق عبر `App\Services\WhatsApp\EventBus::emit`. يصل المستمعين بعد اعتماد المعاملة الجارية، وإن تراجعت المعاملة لا يصل شيء. وكل حدث يطبّق `ShouldDispatchAfterCommit` أيضاً.
- **عند التغيّر الفعلي وحده:** إعادة ميتا للإشعار نفسه، أو حالة أدنى وصلت متأخرة، أو wamid مكرر، لا تطلق حدثاً.
- **معرّفات ورموز فقط:** الخصائص من `int` و`string` و`bool` أو nullable منها، ولا اسم يدل على نص أو هاتف أو اسم. يحرس ذلك `tests/Unit/Arch/WhatsAppEventPayloadTest.php`. من يحتاج النص يقرؤه من القاعدة بصلاحيته.
- **الأحداث تُطلق دائماً**، والأعلام تحكم المستمعين وحدهم. فلا أثر لها والأعلام مطفأة.
- **المستمع fail-open:** استثناؤه يصير سطر `wa.listener_failed` باسم صنفه ومعرّفاته، بلا رسالة الاستثناء ولا الحمولة. ولا يمس رد الويبهوك (200) ولا الإرسال ولا جدولة رد البوت.
- **القفل fail-closed:** أقفال `WhatsAppChannel::dispatch` وبوابات `BotEngine` متزامنة في مواضعها. لا حدث قبل الإرسال يستطيع منعه أو تحويله، ولا يُقرأ ما يعيده مستمع.
- **رد البوت ليس مستمعاً:** يبقى استدعاء `ProcessWhatsAppReply::schedule` الصريح في مسار الويبهوك.

## الأحداث

| الحدث | الحمولة | يُطلق من | لا يُطلق |
|---|---|---|---|
| `WaMessageStored` | messageId, conversationId, msgType | `WhatsAppInbox::storeInboundRows` للوارد والتفاعل، و`ContactIdentity::applySystemChange` لرسالة النظام (`system`) | لتكرار wamid، ولرسالة بلا مرسل |
| `WaMessageSent` | messageId, conversationId, source, staffId | `WhatsAppChannel` بعد كتابة wamid: الحسم إلى sent في دفتر الإرسال، أو الحفظ بعد قبول ميتا في المسار القديم | لـ «غير مؤكد» (يحسمه ويبهوك الحالة أو المنظّف) |
| `WaMessageFailed` | messageId, conversationId, source, metaCode, category, scope, origin, staffId | origin=`send`: رفض ميتا المتزامن أو تعذّر الاتصال قبل الإرسال، وصف صار failed. origin=`webhook`: حالة failed تقدّمت فعلاً | لرفض الأقفال قبل ميتا (لا صف)، وللمهلة التي تُحسم «غير مؤكد» |
| `WaMessageStatusChanged` | messageId, conversationId, status, metaTs, metaCode | مسار الحالة: `StatusApplier` مع `delivery_status_v2`، أو المسار القديم بدونه | لحالة لم تتقدّم |
| `ConversationHandedOff` | conversationId, assigneeId, quoteRequestId, reason | `BotTools::escalate` وحدها. reason من `App\Enums\HandoffReason`: quote وbooking وhandoff_tool وguard_claim وrate_limit وhuman_request وerasure وbudget | لتحويل وضع العطل (E12) ولـ failSafe، فليسا تحويلاً كاملاً |
| `ChannelHealthChanged` | from, to, source, reasonCode | `App\Services\WhatsApp\ChannelHealth` وحدها (E22): انتقال آلة الحالات، والمفتاح اليدوي، والإيقاف الآلي القديم والعلم `channel_health` مطفأ | لانتقال مرفوض أو للحالة نفسها، ولحدث يصل والقناة موقوفة أصلاً، ولوضع الاستعادة الآمن، ولنقل الهجرة القفل القديم إلى BLOCKED |

- `ChannelHealthChanged` (E22، `docs/whatsapp/channel-health.md`)، يُطلق مرة واحدة لكل تغيّر فعلي:
  - **انتقال الآلة** (`ChannelHealth::transition`، العلم `channel_health`): `from` و`to` من `WaHealth`: `AVAILABLE` و`LIMITED` و`BLOCKED` و`TOKEN_INVALID` و`UNKNOWN`. و`source` من `HealthWriter`: `webhook` أو `poll` أو `send_error` أو `token_check` أو `manual`. و`reasonCode` رمز داخلي بلا نص، مثل `ban:DISABLE` و`integrity:368` و`token:190` و`manual`.
  - **المفتاح اليدوي** (`ChannelHealth::manualSwitched`): `SUSPENDED_MANUAL` في طرف، وفي الآخر حالة الآلة (أو `AVAILABLE` والعلم مطفأ). و`source=manual`، و`reasonCode` عند الاستئناف مصدر الإيقاف السابق.
  - **الإيقاف الآلي القديم** (العلم مطفأ، `ChannelHealth::suspendLegacy`): `AVAILABLE` إلى `SUSPENDED_AUTO`، و`source` إما `meta_event` أو `graph_error`.
  - التدقيق `wa.health_change` لانتقال الآلة: صف مباشر من `ChannelHealth` والناقل مطفأ، ومن المستمع `AuditWhatsAppEvents` والناقل مفعّل. ويحرس `tests/Unit/Arch/ChannelHealthWritersTest.php` أن الحدث لا يُطلق من مكان آخر.
- `WaMessageFailed.scope` من `MetaErrorCatalog`: `recipient` أو `message` أو `channel`.

## المستمعون

كلها في `app/Listeners/WhatsApp`، متزامنة (لا `ShouldQueue`، فالعامل الواحد يخدم ردود البوت)، وتعمل والعلم `wa_event_bus` مفعّل وحده، إلا `TouchInboxRealtime` فعلمه `inbox_realtime` (E28).

| المستمع | يستمع لـ | يفعل |
|---|---|---|
| `NotifyHandoffRecipients` | `ConversationHandedOff` | إشعار المدراء والموكَّل كما كان في escalate (`HandoffNotice`). تحويلان للمحادثة والطلب نفسيهما خلال دقيقتين إشعار واحد. نص دفع الجهاز عام |
| `AuditWhatsAppEvents` | `ConversationHandedOff` و`ChannelHealthChanged` | `WaHandoff` بالسبب (بفاعل البوت)، و`WaHealthChange` (بفاعل المستخدم لليدوي، وإلا ميتا) |
| `NotifyDeliveryFailure` | `WaMessageFailed` | «لم تصل رسالتك» للنطاق recipient أو message وحده: رسالة الموظف عبر الويبهوك، ورسالة النظام المرفوضة فوراً. المستلم المرسل، ثم الموكَّل، ثم المدراء. مرة لكل رسالة، ومرة في 10 دقائق للمحادثة والرمز |
| `TouchInboxRealtime` (E28) | `WaMessageStored` و`WaMessageSent` و`WaMessageFailed` و`WaMessageStatusChanged` و`ConversationHandedOff` | البث اللحظي للصندوق عبر `InboxBroadcaster`: الثلاثة الأولى «message»، والحالة «status»، والتحويل «meta». حدث `WaConversationChanged` بحمولة `{c, k}` على قنوات `user.{id}` لمن يرى المحادثة لحظتها، بعد الرد أو بعد الاعتماد. `ChannelHealthChanged` لا يُبث |

**موضع قرار واحد لكل إشعار:** والعلم مطفأ يبقى الإشعار المباشر (`escalate` و`StatusApplier::notifySenderDirect`)، ومفعّلاً يتوقف المباشر ويعمل المستمع. فلا يصل إشعاران أبداً.

## مستمع جديد

- يرث `App\Listeners\WhatsApp\BusListener` ويلف عمله في `guarded()`، أو يلفه في `try/catch` بالسطر نفسه.
- يفحص علمه أولاً، ويستعمل `Cache::add` إن احتاج منع التكرار.
- يُكتشف تلقائياً من `app/Listeners` (دالة `handle*` بنوع الحدث)، فلا `Event::listen` يدوياً لصنف في هذا المجلد، وإلا سُجّل مرتين. `entrypoint.sh` يشغّل `event:cache` عند كل إقلاع. التحقق: `php artisan event:list | grep WhatsApp`.
- العمل الثقيل في وظيفة طابور منفصلة خفيفة، لا في `default` ولا في طابور الردود.
