# مصفوفة قدرات WhatsApp Cloud API

> مولَّد — لا تحرّره يدوياً. المصدر `config/whatsapp_capabilities.php`، والتوليد `php artisan docs:capabilities`، والفحص `php artisan docs:capabilities --check`.

ما بنيناه من قدرات المنصة الرسمية، وما ينتظر ملحمته، وما رفضناه ولماذا. «منفّذ» يعني أن الكود موجود ومختبر، وقد يكون خلف علم مطفأ.

| القدرة | الحالة | دوال `WhatsAppChannel` | الملحمة | العلم | السبب أو القرار | المرجع الرسمي |
|---|---|---|---|---|---|---|
| `text` رسالة نصية | منفّذ ✅ | `sendText` | E05 | — | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/text-messages) |
| `image` صورة بالرابط أو بالمعرّف (بطاقة العرض وملف الموظف) | منفّذ ✅ | `sendImage`، `send` | E32 | — | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/image-messages) |
| `document` مستند PDF وفاتورة | منفّذ ✅ | `send`، `uploadMedia`، `uploadTimeout` | E32 | `staff_file_send` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/document-messages) |
| `video` فيديو MP4 من الصندوق | منفّذ ✅ | `send`، `uploadMedia` | E32 | `staff_file_send` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/video-messages) |
| `audio` رسالة الموظف الصوتية | منفّذ ✅ | `sendVoice` | E34 | `staff_voice_notes` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/audio-messages) |
| `location` دبوس موقع المكتب من القائمة المعتمدة | منفّذ ✅ | `sendLocation` | E33 | `wa_share_cards` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/location-messages) |
| `contacts` بطاقة جهة اتصال من القائمة المعتمدة | منفّذ ✅ | `sendContacts` | E33 | `wa_share_cards` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/contacts-messages) |
| `reaction` تفاعل الموظف بإيموجي | منفّذ ✅ | `sendReaction` | E33 | `wa_staff_reactions` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/reaction-messages) |
| `context` الرد المقتبس على رسالة بعينها | منفّذ ✅ | `sendText`، `send` | E33 | `wa_reply_quote` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages) |
| `interactive.button` أزرار رد (إشعار حفظ الملفات اليوم) | منفّذ ✅ | `sendButtons` | E14 | `media_consent_prompt` | الاختيارات التفاعلية للعملاء قيد العمل في E36 | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/interactive-reply-buttons-messages) |
| `interactive.request_contact_info` زر «مشاركة رقم الهاتف» لعميل بلا رقم | منفّذ ✅ | `sendContactRequest` | E11 | — | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages) |
| `mark_as_read` إشعار القراءة | منفّذ ✅ | `markRead` | E35 | `read_receipts` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/guides/mark-message-as-read) |
| `typing_indicator` مؤشر «يكتب…» | منفّذ ✅ | `markRead` | E35 | `read_receipts` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/typing-indicators) |
| `media.download` تنزيل الوسائط الواردة (الصوت للتفريغ، والأرشيف) | منفّذ ✅ | `downloadMedia`، `fetchMedia`، `mediaMeta` | E14 | — | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media) |
| `media.upload` رفع الوسائط عبر /media (مؤجل دائماً على الطابور) | منفّذ ✅ | `uploadMedia`، `uploadTimeout` | E32 | — | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media) |
| `media.delete` حذف الملف المرسل من خوادم ميتا | منفّذ ✅ | `deleteMedia` | E32 | `outbound_media_remote_delete` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media) |
| `webhook.messages` الرسائل الواردة بكل أنواعها | منفّذ ✅ | — | E11 | — | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components) |
| `webhook.statuses` حالات التسليم بلا تراجع | منفّذ ✅ | — | E21 | `delivery_status_v2` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components) |
| `webhook.account` أحداث الحساب والجودة والتنبيهات والأمن والقدرات | منفّذ ✅ | — | E22 | `channel_health` | — | [ميتا](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/account_update/) |
| `webhook.user_preferences` إيقاف العميل للتسويق من إعدادات واتساب | منفّذ ✅ | — | E21 | `user_preferences_optout` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components) |
| `webhook.calls` أحداث المكالمات | منفّذ ✅ | — | E39 | `calls_webhook` | جاهزية مشروطة بقرار مكتوب من المالك | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/calling) |
| `bsuid_usernames` عميل باسم مستخدم بلا رقم (BSUID) | منفّذ ✅ | — | E11 | — | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages) |
| `coexistence` التعايش مع تطبيق الأعمال (الصدى والسجل السابق) | منفّذ ✅ | — | E39 | `coexistence_guard` | جاهزية مشروطة بقرار مكتوب من المالك | [ميتا](https://developers.facebook.com/docs/whatsapp/embedded-signup/custom-flows/onboarding-business-app-users) |
| `health_status` صحة الرقم وقدرته على الإرسال | منفّذ ✅ | `healthStatus` | E22 | — | — | [ميتا](https://developers.facebook.com/documentation/business-messaging/whatsapp/support/health-status/) |
| `debug_token` فحص الرمز وصلاحياته | منفّذ ✅ | `debugToken` | E08 | — | — | [ميتا](https://developers.facebook.com/docs/graph-api/reference/debug_token) |
| `phone_number` بيانات الرقم والتحقق من معرّفه | منفّذ ✅ | `phoneNumberInfo`، `probePhoneNumberId`، `getPhoneIdentity` | E23 | — | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/phone-numbers) |
| `block_users` حظر جهة اتصال وفكّه عند ميتا | منفّذ ✅ | `blockUsers`، `unblockUsers`، `listBlocked`، `blockTarget` | E31 | `wa_block` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/block-users) |
| `business_profile` ملف واتساب للأعمال وصورته | منفّذ ✅ | `getBusinessProfile`، `updateBusinessProfile`، `uploadProfilePicture` | E31 | `business_profile_sync` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/reference/business-profiles) |
| `qr_codes` رموز QR وروابط قصيرة برسالة جاهزة | منفّذ ✅ | `createQrdl`، `updateQrdl`، `deleteQrdl`، `listQrdls` | E38 | `wa_qr_links` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/business-management-api/qr-codes) |
| `conversational_components` الأوامر وأسئلة البداية | منفّذ ✅ | `setConversationalAutomation`، `getConversationalAutomation` | E37 | `conversational_components` | — | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/phone-numbers/conversational-components) |
| `calling_settings` إعدادات الاتصال ورفض الرنين | منفّذ ✅ | `getCallingSettings`، `applyCallingSettings`، `rejectCall` | E39 | — | الكتابة بالأمر whatsapp:calling-settings وحده | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/calling) |
| `analytics` تحليلات ميتا للحساب للمطابقة | منفّذ ✅ | — | E24 | — | نداء قراءة يومي واحد بعلم المطابقة | [ميتا](https://developers.facebook.com/docs/whatsapp/business-management-api/analytics) |
| `interactive.list` قائمة اختيار | مبني بلا مستعمل | `sendList` | E36 | — | يستعملها الإرسال التجريبي وحده؛ قائمة العروض قيد العمل في E36 | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/interactive-list-messages) |
| `interactive.cta_url` زر رابط | مخطّط ⏳ | — | E36 | — | قيد العمل | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/interactive-cta-url-messages) |
| `interactive.flow` نموذج Flow | مخطّط ⏳ | — | E36 | — | قيد العمل: Flow ثابت | [ميتا](https://developers.facebook.com/docs/whatsapp/flows) |
| `sticker` ملصق | مؤجل | — | — | — | لا حاجة تشغيلية | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/sticker-messages) |
| `interactive.location_request` طلب موقع العميل | مؤجل | — | — | — | لا حاجة تشغيلية اليوم | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/messages/location-request-messages) |
| `interactive.call_permission_request` طلب إذن الاتصال بالعميل | مؤجل | — | E39 | — | لا مكالمات صادرة؛ نقرأ رد العميل على الإذن وحده | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/calling) |
| `two_step_verification` PIN التحقق بخطوتين للرقم | مؤجل | — | E27 | — | يدوي من WhatsApp Manager، ولا يُحفظ PIN في القاعدة | [ميتا](https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/two-step-verification/) |
| `interactive.product` منتج أو قائمة منتجات أو رسالة كتالوج | مرفوض ⛔ | — | — | — | لا كتالوج ميتا لعروضنا، والعروض في نظامنا (خطة E25) | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/guides/sell-products-and-services) |
| `template` قوالب الرسائل | مرفوض ⛔ | — | — | — | لا قوالب: الرد داخل نافذة 24 ساعة فقط. إن تغيّر القرار فكل قالب رسالة مسعّرة (قرار المالك 2026-10-11) | [ميتا](https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates) |
| `marketing_bulk` إرسال تسويقي أو جماعي | مرفوض ⛔ | — | — | — | لا إرسال بارد ولا جماعي: هذا ما يعرّض الرقم الوحيد للحظر (قرار المالك) | [ميتا](https://whatsappbusiness.com/policy/) |
| `groups` المجموعات | مرفوض ⛔ | — | — | — | خارج نموذج عملنا (خطة E25) | [ميتا](https://developers.facebook.com/docs/whatsapp/cloud-api/groups) |
| `status_stories` الحالات (stories) | مرفوض ⛔ | — | — | — | لا نشر آلي؛ بطاقة الحالة تُنزَّل للنشر اليدوي (E38) (قرار المالك) | [ميتا](https://whatsappbusiness.com/policy/) |

## أدوات بوت العملاء (`BotTools::TOOLS`)

| الأداة | الحالة | تحوّل لموظف | العلم |
|---|---|---|---|
| `get_offers` | منفّذ ✅ | — | — |
| `get_offer_details` | منفّذ ✅ | — | — |
| `send_offer_card` | منفّذ ✅ | — | — |
| `set_topic` | منفّذ ✅ | — | — |
| `get_my_balance` | منفّذ ✅ | — | — |
| `get_my_invoices` | منفّذ ✅ | — | — |
| `get_my_trips` | منفّذ ✅ | — | — |
| `request_quote` | منفّذ ✅ | نعم | — |
| `confirm_booking` | منفّذ ✅ | نعم | — |
| `save_note` | منفّذ ✅ | — | — |
| `send_office_location` | منفّذ ✅ | — | `wa_share_cards` |
| `send_contact_card` | منفّذ ✅ | — | `wa_share_cards` |
| `handoff_to_human` | منفّذ ✅ | نعم | — |

## أدوات المساعد الإداري (`AdminTools::TOOLS`)

| الأداة | الحالة | تحوّل لموظف | العلم |
|---|---|---|---|
| `get_daily_summary` | منفّذ ✅ | — | — |
| `get_sales_report` | منفّذ ✅ | — | — |
| `get_travelers` | منفّذ ✅ | — | — |
| `get_pending_approvals` | منفّذ ✅ | — | — |
| `get_open_quotes` | منفّذ ✅ | — | — |
| `get_attention_queue` | منفّذ ✅ | — | — |
| `find_client` | منفّذ ✅ | — | — |
| `manage_knowledge` | منفّذ ✅ | — | — |
| `manage_bot_tools` | منفّذ ✅ | — | — |
| `set_customer_bot` | منفّذ ✅ | — | — |
| `block_contact` | منفّذ ✅ | — | `wa_block_admin_tool` |
| `get_channel_status` | منفّذ ✅ | — | — |
