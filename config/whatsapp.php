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
    ],

    // حالات التسليم (E21، العلم delivery_status_v2): مهلة الإيصال اليتيم في الكاش (ثوانٍ)، وسقف عناصر statusHistory
    'status' => [
        'orphan_ttl' => 600,
        'history_max' => 12,
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
        'min_free_pct' => 5,
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
];
