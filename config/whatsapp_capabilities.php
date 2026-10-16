<?php

/*
| مصفوفة قدرات WhatsApp Cloud API (E25): ما بنيناه، وما ينتظر ملحمته، وما رفضناه ولماذا. لا env ولا معرّف حساب هنا.
| يولّد منها `php artisan docs:capabilities` الملف docs/whatsapp/capabilities.md، و`--check` يقارنه بلا كتابة.
| CapabilityMatrixTest يربطها بالكود: كل دالة إرسال عامة في WhatsAppChannel في methods لصف ما، وكل أداة بوت أو
| مساعد إداري هنا، والقرار المرفوض أو المؤجل بسبب، والمخطط بملحمة. قدرة جديدة: صف هنا ثم docs:capabilities.
|
| status: implemented (منفّذ؛ وflag إن كان خلف علم مطفأ) · built_unused (مبني بلا مستعمل) · planned (يحتاج epic)
|         · deferred (يحتاج reason) · rejected (يحتاج reason وdecided) · not_implemented
*/

$messages = 'https://developers.facebook.com/docs/whatsapp/cloud-api/messages/';

return [
    'rows' => [
        // ===== الرسائل الصادرة =====
        ['key' => 'text', 'label' => 'رسالة نصية', 'official' => $messages.'text-messages', 'status' => 'implemented',
            'methods' => ['sendText'], 'epic' => 'E05'],
        ['key' => 'image', 'label' => 'صورة بالرابط أو بالمعرّف (بطاقة العرض وملف الموظف)', 'official' => $messages.'image-messages', 'status' => 'implemented',
            'methods' => ['sendImage', 'send'], 'epic' => 'E32'],
        ['key' => 'document', 'label' => 'مستند PDF وفاتورة', 'official' => $messages.'document-messages', 'status' => 'implemented',
            'methods' => ['send', 'uploadMedia', 'uploadTimeout'], 'epic' => 'E32', 'flag' => 'staff_file_send'],
        ['key' => 'video', 'label' => 'فيديو MP4 من الصندوق', 'official' => $messages.'video-messages', 'status' => 'implemented',
            'methods' => ['send', 'uploadMedia'], 'epic' => 'E32', 'flag' => 'staff_file_send'],
        ['key' => 'audio', 'label' => 'رسالة الموظف الصوتية', 'official' => $messages.'audio-messages', 'status' => 'implemented',
            'methods' => ['sendVoice'], 'epic' => 'E34', 'flag' => 'staff_voice_notes'],
        ['key' => 'sticker', 'label' => 'ملصق', 'official' => $messages.'sticker-messages', 'status' => 'deferred',
            'reason' => 'لا حاجة تشغيلية'],
        ['key' => 'location', 'label' => 'دبوس موقع المكتب من القائمة المعتمدة', 'official' => $messages.'location-messages', 'status' => 'implemented',
            'methods' => ['sendLocation'], 'epic' => 'E33', 'flag' => 'wa_share_cards'],
        ['key' => 'contacts', 'label' => 'بطاقة جهة اتصال من القائمة المعتمدة', 'official' => $messages.'contacts-messages', 'status' => 'implemented',
            'methods' => ['sendContacts'], 'epic' => 'E33', 'flag' => 'wa_share_cards'],
        ['key' => 'reaction', 'label' => 'تفاعل الموظف بإيموجي', 'official' => $messages.'reaction-messages', 'status' => 'implemented',
            'methods' => ['sendReaction'], 'epic' => 'E33', 'flag' => 'wa_staff_reactions'],
        ['key' => 'context', 'label' => 'الرد المقتبس على رسالة بعينها', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages', 'status' => 'implemented',
            'methods' => ['sendText', 'send'], 'epic' => 'E33', 'flag' => 'wa_reply_quote'],
        ['key' => 'interactive.button', 'label' => 'أزرار رد (إشعار حفظ الملفات اليوم)', 'official' => $messages.'interactive-reply-buttons-messages', 'status' => 'implemented',
            'methods' => ['sendButtons'], 'epic' => 'E14', 'flag' => 'media_consent_prompt', 'reason' => 'الاختيارات التفاعلية للعملاء قيد العمل في E36'],
        ['key' => 'interactive.list', 'label' => 'قائمة اختيار', 'official' => $messages.'interactive-list-messages', 'status' => 'built_unused',
            'methods' => ['sendList'], 'epic' => 'E36', 'reason' => 'يستعملها الإرسال التجريبي وحده؛ قائمة العروض قيد العمل في E36'],
        ['key' => 'interactive.request_contact_info', 'label' => 'زر «مشاركة رقم الهاتف» لعميل بلا رقم', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages', 'status' => 'implemented',
            'methods' => ['sendContactRequest'], 'epic' => 'E11'],
        ['key' => 'interactive.cta_url', 'label' => 'زر رابط', 'official' => $messages.'interactive-cta-url-messages', 'status' => 'planned',
            'epic' => 'E36', 'reason' => 'قيد العمل'],
        ['key' => 'interactive.flow', 'label' => 'نموذج Flow', 'official' => 'https://developers.facebook.com/docs/whatsapp/flows', 'status' => 'planned',
            'epic' => 'E36', 'reason' => 'قيد العمل: Flow ثابت'],
        ['key' => 'interactive.location_request', 'label' => 'طلب موقع العميل', 'official' => $messages.'location-request-messages', 'status' => 'deferred',
            'reason' => 'لا حاجة تشغيلية اليوم'],
        ['key' => 'interactive.call_permission_request', 'label' => 'طلب إذن الاتصال بالعميل', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/calling', 'status' => 'deferred',
            'epic' => 'E39', 'reason' => 'لا مكالمات صادرة؛ نقرأ رد العميل على الإذن وحده'],
        ['key' => 'interactive.product', 'label' => 'منتج أو قائمة منتجات أو رسالة كتالوج', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/guides/sell-products-and-services', 'status' => 'rejected',
            'reason' => 'لا كتالوج ميتا لعروضنا، والعروض في نظامنا', 'decided' => 'خطة E25'],
        ['key' => 'template', 'label' => 'قوالب الرسائل', 'official' => 'https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates', 'status' => 'rejected',
            'reason' => 'لا قوالب: الرد داخل نافذة 24 ساعة فقط. إن تغيّر القرار فكل قالب رسالة مسعّرة', 'decided' => 'قرار المالك 2026-10-11'],
        ['key' => 'marketing_bulk', 'label' => 'إرسال تسويقي أو جماعي', 'official' => 'https://whatsappbusiness.com/policy/', 'status' => 'rejected',
            'reason' => 'لا إرسال بارد ولا جماعي: هذا ما يعرّض الرقم الوحيد للحظر', 'decided' => 'قرار المالك'],
        ['key' => 'groups', 'label' => 'المجموعات', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/groups', 'status' => 'rejected',
            'reason' => 'خارج نموذج عملنا', 'decided' => 'خطة E25'],
        ['key' => 'status_stories', 'label' => 'الحالات (stories)', 'official' => 'https://whatsappbusiness.com/policy/', 'status' => 'rejected',
            'reason' => 'لا نشر آلي؛ بطاقة الحالة تُنزَّل للنشر اليدوي (E38)', 'decided' => 'قرار المالك'],
        ['key' => 'mark_as_read', 'label' => 'إشعار القراءة', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/guides/mark-message-as-read', 'status' => 'implemented',
            'methods' => ['markRead'], 'epic' => 'E35', 'flag' => 'read_receipts'],
        ['key' => 'typing_indicator', 'label' => 'مؤشر «يكتب…»', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/typing-indicators', 'status' => 'implemented',
            'methods' => ['markRead'], 'epic' => 'E35', 'flag' => 'read_receipts'],

        // ===== الوسائط =====
        ['key' => 'media.download', 'label' => 'تنزيل الوسائط الواردة (الصوت للتفريغ، والأرشيف)', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media', 'status' => 'implemented',
            'methods' => ['downloadMedia', 'fetchMedia', 'mediaMeta'], 'epic' => 'E14'],
        ['key' => 'media.upload', 'label' => 'رفع الوسائط عبر /media (مؤجل دائماً على الطابور)', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media', 'status' => 'implemented',
            'methods' => ['uploadMedia', 'uploadTimeout'], 'epic' => 'E32'],
        ['key' => 'media.delete', 'label' => 'حذف الملف المرسل من خوادم ميتا', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/reference/media', 'status' => 'implemented',
            'methods' => ['deleteMedia'], 'epic' => 'E32', 'flag' => 'outbound_media_remote_delete'],

        // ===== الويبهوك =====
        ['key' => 'webhook.messages', 'label' => 'الرسائل الواردة بكل أنواعها', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components', 'status' => 'implemented',
            'epic' => 'E11'],
        ['key' => 'webhook.statuses', 'label' => 'حالات التسليم بلا تراجع', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components', 'status' => 'implemented',
            'epic' => 'E21', 'flag' => 'delivery_status_v2'],
        ['key' => 'webhook.account', 'label' => 'أحداث الحساب والجودة والتنبيهات والأمن والقدرات', 'official' => 'https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/account_update/', 'status' => 'implemented',
            'epic' => 'E22', 'flag' => 'channel_health'],
        ['key' => 'webhook.user_preferences', 'label' => 'إيقاف العميل للتسويق من إعدادات واتساب', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components', 'status' => 'implemented',
            'epic' => 'E21', 'flag' => 'user_preferences_optout'],
        ['key' => 'webhook.calls', 'label' => 'أحداث المكالمات', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/calling', 'status' => 'implemented',
            'epic' => 'E39', 'flag' => 'calls_webhook', 'reason' => 'جاهزية مشروطة بقرار مكتوب من المالك'],
        ['key' => 'bsuid_usernames', 'label' => 'عميل باسم مستخدم بلا رقم (BSUID)', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages', 'status' => 'implemented',
            'epic' => 'E11'],
        ['key' => 'coexistence', 'label' => 'التعايش مع تطبيق الأعمال (الصدى والسجل السابق)', 'official' => 'https://developers.facebook.com/docs/whatsapp/embedded-signup/custom-flows/onboarding-business-app-users', 'status' => 'implemented',
            'epic' => 'E39', 'flag' => 'coexistence_guard', 'reason' => 'جاهزية مشروطة بقرار مكتوب من المالك'],

        // ===== إدارة الرقم والحساب =====
        ['key' => 'health_status', 'label' => 'صحة الرقم وقدرته على الإرسال', 'official' => 'https://developers.facebook.com/documentation/business-messaging/whatsapp/support/health-status/', 'status' => 'implemented',
            'methods' => ['healthStatus'], 'epic' => 'E22'],
        ['key' => 'debug_token', 'label' => 'فحص الرمز وصلاحياته', 'official' => 'https://developers.facebook.com/docs/graph-api/reference/debug_token', 'status' => 'implemented',
            'methods' => ['debugToken'], 'epic' => 'E08'],
        ['key' => 'phone_number', 'label' => 'بيانات الرقم والتحقق من معرّفه', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/reference/phone-numbers', 'status' => 'implemented',
            'methods' => ['phoneNumberInfo', 'probePhoneNumberId', 'getPhoneIdentity'], 'epic' => 'E23'],
        ['key' => 'two_step_verification', 'label' => 'PIN التحقق بخطوتين للرقم', 'official' => 'https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/two-step-verification/', 'status' => 'deferred',
            'epic' => 'E27', 'reason' => 'يدوي من WhatsApp Manager، ولا يُحفظ PIN في القاعدة'],
        ['key' => 'block_users', 'label' => 'حظر جهة اتصال وفكّه عند ميتا', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/block-users', 'status' => 'implemented',
            'methods' => ['blockUsers', 'unblockUsers', 'listBlocked', 'blockTarget'], 'epic' => 'E31', 'flag' => 'wa_block'],
        ['key' => 'business_profile', 'label' => 'ملف واتساب للأعمال وصورته', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/reference/business-profiles', 'status' => 'implemented',
            'methods' => ['getBusinessProfile', 'updateBusinessProfile', 'uploadProfilePicture'], 'epic' => 'E31', 'flag' => 'business_profile_sync'],
        ['key' => 'qr_codes', 'label' => 'رموز QR وروابط قصيرة برسالة جاهزة', 'official' => 'https://developers.facebook.com/docs/whatsapp/business-management-api/qr-codes', 'status' => 'implemented',
            'methods' => ['createQrdl', 'updateQrdl', 'deleteQrdl', 'listQrdls'], 'epic' => 'E38', 'flag' => 'wa_qr_links'],
        ['key' => 'conversational_components', 'label' => 'الأوامر وأسئلة البداية', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/phone-numbers/conversational-components', 'status' => 'implemented',
            'methods' => ['setConversationalAutomation', 'getConversationalAutomation'], 'epic' => 'E37', 'flag' => 'conversational_components'],
        ['key' => 'calling_settings', 'label' => 'إعدادات الاتصال ورفض الرنين', 'official' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/calling', 'status' => 'implemented',
            'methods' => ['getCallingSettings', 'applyCallingSettings', 'rejectCall'], 'epic' => 'E39', 'reason' => 'الكتابة بالأمر whatsapp:calling-settings وحده'],
        ['key' => 'analytics', 'label' => 'تحليلات ميتا للحساب للمطابقة', 'official' => 'https://developers.facebook.com/docs/whatsapp/business-management-api/analytics', 'status' => 'implemented',
            'epic' => 'E24', 'reason' => 'نداء قراءة يومي واحد بعلم المطابقة'],
    ],

    // أدوات بوت العملاء (BotTools::TOOLS): escalating = نجاحها يحوّل المحادثة لموظف
    'bot_tools' => [
        'get_offers' => ['status' => 'implemented', 'escalating' => false],
        'get_offer_details' => ['status' => 'implemented', 'escalating' => false],
        'send_offer_card' => ['status' => 'implemented', 'escalating' => false],
        'set_topic' => ['status' => 'implemented', 'escalating' => false],
        'get_my_balance' => ['status' => 'implemented', 'escalating' => false],
        'get_my_invoices' => ['status' => 'implemented', 'escalating' => false],
        'get_my_trips' => ['status' => 'implemented', 'escalating' => false],
        'request_quote' => ['status' => 'implemented', 'escalating' => true],
        'confirm_booking' => ['status' => 'implemented', 'escalating' => true],
        'save_note' => ['status' => 'implemented', 'escalating' => false],
        'send_office_location' => ['status' => 'implemented', 'escalating' => false, 'flag' => 'wa_share_cards'],
        'send_contact_card' => ['status' => 'implemented', 'escalating' => false, 'flag' => 'wa_share_cards'],
        'handoff_to_human' => ['status' => 'implemented', 'escalating' => true],
    ],

    // أدوات المساعد الإداري (AdminTools::TOOLS): كل تعديل معاينة ثم «تأكيد»
    'admin_tools' => [
        'get_daily_summary' => ['status' => 'implemented', 'escalating' => false],
        'get_sales_report' => ['status' => 'implemented', 'escalating' => false],
        'get_travelers' => ['status' => 'implemented', 'escalating' => false],
        'get_pending_approvals' => ['status' => 'implemented', 'escalating' => false],
        'get_open_quotes' => ['status' => 'implemented', 'escalating' => false],
        'get_attention_queue' => ['status' => 'implemented', 'escalating' => false],
        'find_client' => ['status' => 'implemented', 'escalating' => false],
        'manage_knowledge' => ['status' => 'implemented', 'escalating' => false],
        'manage_bot_tools' => ['status' => 'implemented', 'escalating' => false],
        'set_customer_bot' => ['status' => 'implemented', 'escalating' => false],
        'block_contact' => ['status' => 'implemented', 'escalating' => false, 'flag' => 'wa_block_admin_tool'],
        'get_channel_status' => ['status' => 'implemented', 'escalating' => false],
    ],
];
