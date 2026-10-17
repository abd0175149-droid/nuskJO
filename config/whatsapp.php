<?php

/*
| أرشيف مرفقات العملاء (E14). الأرشفة خلف العلم media_archive (مطفأ)، ولا يُفعَّل قبل تحديث /privacy
| و/data-deletion واعتماد المالك لنص الإشعار ومدة الاحتفاظ (docs/RELEASE.md «أرشيف المرفقات»).
*/

return [
    // استقبال الويبهوك (E20، docs/whatsapp-webhook-contract.md؛ أرقامها يحرسها WebhookContractDocTest)
    'webhook' => [
        // حد الجسم قبل حساب التوقيع (413)، مساوٍ لكتلة nginx ولا يقل عن 3MB (أقصى حمولة عند ميتا)
        'max_bytes' => max(3 * 1024 * 1024, (int) env('WA_WEBHOOK_MAX_BYTES', 4 * 1024 * 1024)),
        // all: كل توصيلة موقّعة تُحفظ مشفّرة قبل المعالجة. failures: تُحفظ عند الخطأ وحده (إن ظهر ضغط كتابة)
        'log_mode' => env('WA_WEBHOOK_LOG_MODE', 'all') === 'failures' ? 'failures' : 'all',
        // الجسم المشفّر للفاشل حداً أقصى، والأثر الوصفي بلا جسم
        'body_hours' => 72,
        'meta_days' => 14,
        // محاولات المعالجة (الأولى في الطلب)، والتأخير بعد الأولى ثم الثانية (دقائق)؛ بعد الثالثة dead
        'max_attempts' => 3,
        'retry_minutes' => [2, 10],
        // pending أقدم من هذا انقطع طلبه أثناء المعالجة
        'pending_stale_minutes' => 2,
        // توقيعات مرفوضة في الساعة قبل التنبيه
        'bad_signature_alert' => 20,
        // أقل من مهلة كتلة nginx (60 ثانية)
        'time_limit' => 55,
    ],

    // دفتر الإرسال (E19): بادئة biz_opaque_callback_data ({بادئة}:m:{رقم الصف}) وحدود الإعادة المحدودة
    'send' => [
        'opaque_prefix' => preg_replace('/[^a-z0-9]/', '', strtolower((string) env('WA_OPAQUE_PREFIX', 'nj'))) ?: 'nj',
        // مجموع انتظار الإعادات لرسالة واحدة (ثوانٍ)
        'retry_max_sleep' => (int) env('WA_SEND_RETRY_MAX_SLEEP', 8),
        // محاولات إضافية: الموظف طلب ويب متزامن، والبوت والنظام في الطابور
        'retry_staff_max' => 1,
        'retry_system_max' => 2,
        // المنظّف: queued أقدم من هذا صار «غير مؤكد»
        'reap_minutes' => 15,
        // E28: حد wa-send لكل موظف في الدقيقة (الرد والسعر وطلب الرقم): 429 «تمهّل قليلاً»
        'per_minute' => max(1, (int) env('WA_SEND_PER_MINUTE', 30)),
    ],

    // حالات التسليم (E21، العلم delivery_status_v2): مهلة الإيصال اليتيم في الكاش (ثوانٍ)، وسقف عناصر statusHistory
    'status' => [
        'orphan_ttl' => 600,
        'history_max' => 12,
    ],

    // إشعار القراءة ومؤشر الكتابة (E35): مفتاح طوارئ داخلي للمؤشر وحده. إن رفض إصدار Graph المضبوط typing_indicator
    // يُطفأ (WA_TYPING_INDICATOR=false) فيبقى إشعار القراءة عند رد الموظف وحده حتى يُرقّى الإصدار
    'read_receipts' => [
        'typing' => (bool) env('WA_TYPING_INDICATOR', true),
    ],

    // الملفات الصادرة (E32): مرفقات الصندوق (staff_file_send)، وفاتورة PDF (invoice_whatsapp_send)، وبطاقة العرض بالمعرّف
    // (offer_card_by_media_id)، والحذف من خوادم ميتا (outbound_media_remote_delete). الحدود بقرار المالك
    'outbound' => [
        // حد المستند بالميغابايت (ميتا تقبل 100MB). 16 افتراضياً لحماية الخادم المشترك والنفق، ولا يتجاوز 19: كتلة nginx
        // لمسار الإرسال 20m، ورفعه فوقها يتطلب تعديلها أيضاً (docs/RELEASE.md «الملفات من الصندوق وفاتورة PDF»)
        'max_document_mb' => max(1, min(19, (int) env('WA_OUTBOUND_MAX_DOCUMENT_MB', 16))),
        // docx وxlsx وpptx وtxt بقرار المالك (مطفأة). الصيغ القديمة doc وxls وppt مرفوضة دائماً (الماكرو)
        'office_docs' => (bool) env('WA_OUTBOUND_OFFICE_DOCS', false),
        // الفيديو MP4/3GP بترميز H.264؛ يُخرج من القائمة بلا نشر إن ظهرت مشاكل عند العملاء
        'video' => (bool) env('WA_OUTBOUND_VIDEO', true),
        // الرفع إلى ميتا مؤجل دائماً في طابور مستقل يسبق أرشفة الوارد عند العامل نفسه (laravel-media)
        'queue' => 'wa-media-out',
        // مهلة الرفع: 30 ثانية + ثانية لكل 256KB حتى هذا الحد، فيبقى الرفع والإرسال (25) تحت مهلة الوظيفة (120)
        'upload_timeout_max' => 85,
        // ملف بقي في الطابور أكثر من هذا لا يُرسل (لا يصل العميلَ ملف بعد ساعات من ضغط الموظف)، وأقل من منظّف الدفتر (15)
        'queue_max_minutes' => max(1, min(14, (int) env('WA_OUTBOUND_QUEUE_MAX_MINUTES', 10))),
        // بطاقة العرض بالمعرّف: يُعاد استعماله 25 يوماً (ميتا تحفظ المرفوع 30)
        'card_id_days' => 25,
        // الحذف من خوادم ميتا بعد التسليم (whatsapp:outbound-media-cleanup، كل ساعة): الأنواع والحالات بقرار المالك
        'remote_delete' => [
            'types' => array_values(array_filter(array_map('trim', explode(',', (string) env('WA_OUTBOUND_REMOTE_DELETE_TYPES', 'document'))))),
            'statuses' => array_values(array_filter(array_map('trim', explode(',', (string) env('WA_OUTBOUND_REMOTE_DELETE_STATUSES', 'read'))))),
            'min_age_minutes' => 60,
            'batch' => 50,
        ],
        // فاتورة PDF: نسخة مخزّنة مؤقتاً على wa_media تحت invoices/ تُكنس بعد هذه المدة (المرسلة لها نسختها في outbound/)
        'invoice_cache_days' => 7,
        // شكل الفاتورة بقرار المالك: ورق الشركة الرسمي (card_letterhead_path) خلفية كاملة، والرقم الضريبي (مطفأ)
        'invoice_letterhead' => (bool) env('INVOICE_PDF_LETTERHEAD', true),
        'invoice_show_tax' => (bool) env('INVOICE_PDF_SHOW_TAX', false),
        // الفواتير القابلة للإرسال: المعتمدة وحدها (approved). pending بقرار المالك تُرسل بعلامة «مسودة»
        'invoice_send_statuses' => array_values(array_filter(array_map('trim', explode(',', (string) env('INVOICE_WHATSAPP_STATUSES', 'approved'))))),
    ],

    // رسائل الموظف الصوتية (E34، العلم staff_voice_notes): تسجيل المتصفح يُحوَّل على الخادم إلى ogg/opus أحادي (الصيغة الوحيدة
    // التي تظهر عند العميل رسالة صوتية بزر تشغيل)، ثم يمر بمسار send-file المؤجل نفسه. ffmpeg في الصورة منذ E34
    'voice' => [
        'ffmpeg_path' => env('WA_VOICE_FFMPEG', '/usr/bin/ffmpeg'),
        'ffprobe_path' => env('WA_VOICE_FFPROBE', '/usr/bin/ffprobe'),
        // حد التسجيل في المتصفح وفي الخادم (مع سماح 5 ثوانٍ لفرق القياس)
        'max_seconds' => max(10, min(300, (int) env('WA_VOICE_MAX_SECONDS', 120))),
        // الملف الخام من المتصفح قبل التحويل (webm أو mp4 أو ogg)
        'max_upload_bytes' => 3_000_000,
        // ميتا تعرض زر التشغيل لما يبلغ 512KB أو أقل، وإلا زر تنزيل: إعادة ترميز واحدة بالمعدل الأدنى ثم الرفض
        'max_voice_bytes' => 512 * 1024,
        'bitrate' => env('WA_VOICE_BITRATE', '32k'),
        'fallback_bitrate' => '24k',
        // مهلة ffmpeg، وانتظار القفل (تحويل واحد على الحاوية) قبل رد 503 «المحوّل مشغول». المجموع (3 + 5 + 45 + 5) تحت مهلة
        // كتلة nginx لمسار send-file (60 ثانية)؛ التحويل الفعلي لدقيقتين أقل من ثانية، فالمهلة حارس لملف مرضي لا أكثر
        'timeout' => 45,
        'probe_timeout' => 5,
        'lock_seconds' => 75,
        'lock_wait' => 3,
        // تفريغ صوت الموظف للزملاء (العلم staff_voice_transcript): مهلة نداء Gemini
        'transcribe_timeout' => 60,
    ],

    // بوابة المحتوى الصادر (E15.9): حسابات الشركة المسموح ظهورها في الرسائل الآلية (IBAN بلا مسافات، مفصولة بفواصل)
    'outbound_policy' => [
        'allow_ibans' => array_values(array_filter(array_map(fn ($i) => strtoupper(str_replace(' ', '', trim($i))), explode(',', (string) env('WA_POLICY_ALLOW_IBANS', ''))))),
    ],

    'media' => [
        // R2/S3 لاحقاً بقرار المالك (يُذكر المزوّد في /privacy)
        'disk' => env('WA_MEDIA_DISK', 'wa_media'),
        'queue' => 'wa-media',
        // الأنواع المؤرشفة آلياً. الفيديو والصوت بقرار المالك
        'types' => array_values(array_filter(array_map('trim', explode(',', (string) env('WA_MEDIA_TYPES', 'image,document'))))),
        'max_bytes' => 25 * 1024 * 1024,
        // التنزيل اليدوي لملف أكبر أو فشل (خلال صلاحية المعرّف عند ميتا)
        'manual_max_bytes' => 100 * 1024 * 1024,
        'retention_days' => (int) env('WA_MEDIA_RETENTION_DAYS', 120),
        'quota_mb' => (int) env('WA_MEDIA_QUOTA_MB', 5120),
        'quota_warn_pct' => 80,
        // log: يُحفظ الملف رغم اختلاف البصمة ويُعلَّم sha_mismatch. enforce بعد أسبوع نظيف
        'sha_mode' => env('WA_MEDIA_SHA_MODE', 'log'),
        // معرّف الوسائط عند ميتا يعيش 7 أيام: آخر محاولة قبلها
        'id_lifetime_minutes' => 6 * 24 * 60 + 20 * 60,
        // أقل مساحة حرة (نسبة من القرص) قبل أي تنزيل
        'min_free_pct' => (int) env('WA_MEDIA_MIN_FREE_PCT', 5),
        // النوع المكتشف بـ finfo => [الامتداد، يُعاين في الصندوق]. كل ما سواه مرفوض (SVG وHTML وx-* والأرشيفات)
        'mimes' => [
            'image/jpeg' => ['jpg', true],
            'image/png' => ['png', true],
            'image/webp' => ['webp', true],
            'application/pdf' => ['pdf', true],
            'video/mp4' => ['mp4', false],
            'video/3gpp' => ['3gp', false],
            'audio/ogg' => ['ogg', false],
            'audio/mpeg' => ['mp3', false],
            'audio/mp4' => ['m4a', false],
            'audio/aac' => ['aac', false],
            'audio/amr' => ['amr', false],
        ],
        // أنواع Office للتنزيل فقط: تُقبل بقرار المالك (WA_MEDIA_ALLOW_OFFICE=true)
        'office_mimes' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', false],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', false],
        ],
        'allow_office' => (bool) env('WA_MEDIA_ALLOW_OFFICE', false),
    ],

    // البحث في الرسائل (E30): التدقيق صف واحد لكل موظف كل audit_minutes (بلا نص الاستعلام)، وسطر warn
    // حين يبحث موظف burst_per_minute مرة أو أكثر في كل دقيقة من burst_minutes دقائق متتالية (استكشاف جماعي)
    'search' => [
        'audit_minutes' => 10,
        'burst_per_minute' => 20,
        'burst_minutes' => 3,
    ],

    // تصدير محادثة واحدة (E30): ما زاد على هذا يُرفض «ضيّق الفترة»
    'export' => [
        'max_rows' => 20000,
    ],

    // الحظر الرسمي (E31): إشعار المدراء بكل حظر، بلا رقم ولا اسم عميل (قرار المالك: يمكن إطفاؤه)
    'block' => [
        'notify_admins' => (bool) env('WA_BLOCK_NOTIFY', true),
    ],

    // صحة القناة كآلة حالات (E22، docs/whatsapp/channel-health.md). القفل من قاطع النزاهة بالعلم integrity_breaker_lock وحده
    'health' => [
        'breaker' => [
            // 131031 أو 131048 بهذا العدد خلال النافذة: تنبيه حرج وفحص فوري (أو BLOCKED بالعلم)
            'integrity_threshold' => (int) env('WA_INTEGRITY_THRESHOLD', 3),
            'window_minutes' => (int) env('WA_INTEGRITY_WINDOW_MINUTES', 15),
        ],
        // فشل الإرسال المتتالي: تنبيه عند الثالث ثم كل خامس (3 و8 و13…)
        'consecutive_alert_at' => 3,
        'consecutive_alert_every' => 5,
        // الرفع اليدوي يحتاج فحصاً لا يعيد BLOCKED خلال هذه المدة، وإلا يُفحص الآن (دقائق)
        'unblock_poll_max_age_minutes' => 10,
        // الفحص بعد حدث حساب (ثوانٍ): يجمع الأحداث المتتالية في فحص واحد
        'poll_delay_seconds' => 15,
        // حمولة الحدث الخام في wa_account_events
        'event_payload_max_bytes' => 16384,
        // الأحداث المعلوماتية تُقلَّم بعدها (الحرج والتحذير يبقيان)
        'info_retention_days' => 180,
    ],

    // الرسائل الغنية (E33). معاينة الرابط لرد الموظف وحده: مضيفات لا تُعاين أبداً (مضيف APP_URL دائماً، وهذه ونطاقاتها
    // الفرعية: خوادمنا المشتركة)، بفواصل في WA_PREVIEW_BLOCK_HOSTS
    'preview_block_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('WA_PREVIEW_BLOCK_HOSTS', 'grade.sbs'))))),
    // القائمة البيضاء لتفاعل الموظف (قرار المالك)، والسلسلة الفارغة تزيل التفاعل دائماً
    'staff_reactions' => array_values(array_filter(array_map('trim', explode(',', (string) env('WA_STAFF_REACTIONS', '✅,👍,🙏,❤️,👀'))))),
    // ميتا لا تسلّم تفاعلاً على رسالة أقدم من 30 يوماً
    'reaction_max_days' => 30,

    // الناقل إلى ميتا (E23): cloud وحده في الإنتاج. log بلا شبكة للاختبار والتطوير المحلي، ويُرفض في production
    'transport' => env('WA_TRANSPORT', 'cloud'),

    // المراقب الدوري وأدوات التشخيص (E23، docs/whatsapp/diagnostics.md). العلم channel_monitor يقرر القراءات الإضافية
    'monitor' => [
        // حقول ويبهوك ميتا التي يجب أن يكون تطبيقنا مشتركاً فيها (قائمة E22 مع user_preferences من E21)
        'required_webhook_fields' => array_values(array_filter(array_map('trim', explode(',', (string) env('WA_REQUIRED_WEBHOOK_FIELDS',
            'messages,account_update,phone_number_quality_update,account_alerts,security,business_capability_update,phone_number_name_update,user_preferences'))))),
        // أعمار القراءات المخزّنة (ثوانٍ): الرقم 10 دقائق، والاشتراكات وأرقام الحساب ساعة
        'probe_ttl' => ['node' => 600, 'subs' => 3600, 'numbers' => 3600],
        // الإرسال التجريبي (العلم wa_test_send): حد النظام كله في الساعة
        'test_send_per_hour' => 10,
    ],

    // المكالمات (E39، docs/whatsapp/calls.md): كلها خلف العلمين calls_webhook وcalls_followup المطفأين
    'calls' => [
        // إشعار المكالمة الفائتة: مرة لكل محادثة في هذه المدة (دقائق)
        'notify_minutes' => 10,
        // المتابعة النصية بعد الفائتة: مرة لكل محادثة في هذه المدة (ساعات)
        'followup_hours' => (int) env('WA_CALLS_FOLLOWUP_HOURS', 6),
        // توقيت ما يُكتب في الخيط والإشعار
        'timezone' => 'Asia/Amman',
    ],

    // التعايش مع تطبيق الأعمال (E39، docs/whatsapp/coexistence.md): خلف الأعلام coexistence_* المطفأة
    'coexistence' => [
        // الحارس المبكر يوقف البوت مدة لا تقل عن هذه (دقائق)
        'guard_pause_minutes' => 60,
        // سجل history المحفوظ مشفّراً بانتظار قرار المالك (أيام)، ثم يُمحى كل ساعة
        'history_hold_days' => (int) env('WA_COEX_HISTORY_HOLD_DAYS', 30),
        // رسائل الاستيراد في المعاملة الواحدة
        'import_batch' => 500,
    ],

    // الاختيارات التفاعلية (E36). المحادثات التجريبية: إن لم تكن القائمة فارغة تُعلن أدوات القوائم والأزرار والنموذج لها وحدها
    // (رقم المدير الشخصي كعميل قبل التعميم)، بأرقام المحادثات مفصولة بفواصل في WA_INTERACTIVE_PILOT_CONVERSATIONS
    'interactive_pilot_conversations' => array_values(array_filter(array_map('intval', explode(',', (string) env('WA_INTERACTIVE_PILOT_CONVERSATIONS', ''))))),
    'interactive' => [
        // لا تُعلن أداة تفاعلية إن بقي من النافذة أقل من هذا (دقائق)، ولا النموذج إن بقي أقل من الثاني
        'min_window_minutes' => 10,
        'flow_min_window_minutes' => 30,
        // رد الـ Flow (response_json) أكبر من هذا لا يُقرأ
        'flow_response_max_bytes' => 4096,
    ],
];
