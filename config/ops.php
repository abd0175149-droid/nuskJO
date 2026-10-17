<?php

/*
 * التشغيل: فحص /up الحقيقي ونبضات المجدول والعمّال وفحوص ميتا (E08).
 * العلَمان من البيئة (docker-compose.yml)، فتغييرهما يحتاج docker compose up -d nuskjo-app.
 */
return [
    // DiagnosingHealth على /up: القاعدة والمجدول والعاملان والطابور. false يعيد /up إلى 200 دائماً (التراجع)
    'health_checks' => (bool) env('OPS_HEALTH_CHECKS', true),

    // مخزن نتيجة الفحص وكبح التنبيهات: file لا database، فهو يعمل والقاعدة معطلة (array في الاختبارات)
    'cache_store' => env('OPS_CACHE_STORE', 'file'),

    // عمر النبضة الذي بعده يُعدّ المكوّن متوقفاً (المجدول ينبض كل دقيقة، والعاملان كذلك)
    'schedule_stale_seconds' => 180,
    'queue_stale_seconds' => 300,
    // وظيفة متاحة لم يلتقطها عامل منذ هذه المدة
    'backlog_stale_seconds' => 300,

    // فحص رمز ميتا يومياً وصحة القناة كل ساعة، والتنبيه الفوري على أخطاء الاعتماد
    'meta_checks' => (bool) env('WA_META_CHECKS', true),
    'token_warn_days' => 14,
    'token_critical_days' => 3,
    // بتوقيت عمّان
    'credentials_check_at' => '08:00',

    // صمت الوارد (E37، العلم inbound_silence_alert): ساعات الدوام المنظّمة للحساب (القيمة الحرة company_hours لا تصلح).
    // الأيام بترقيم Carbon: 0 الأحد … 6 السبت؛ الافتراضي السبت إلى الخميس
    'business_hours' => [
        'days' => [6, 0, 1, 2, 3, 4],
        'from' => '09:00',
        'to' => '21:00',
        'timezone' => 'Asia/Amman',
    ],
    // لا حدث من ميتا منذ هذه المدة في وقت الدوام، والساعة نفسها فيها حركة خلال 14 يوماً: تنبيه مرة كل 6 ساعات
    'inbound_silence_minutes' => (int) env('OPS_INBOUND_SILENCE_MINUTES', 180),

    // ===== المراقبة التشغيلية (E24، docs/whatsapp/ops-alerts.md) =====
    // whatsapp:ops-check كل 5 دقائق: لقطة أرقام مجمّعة في مخزن cache_store أعلاه (لا SQLite)، يقرؤها /internal/metrics
    // وصفحة الإخفاقات. التنبيه بالعلم ops_alerts؛ مطفأ = تُحسب القواعد وتُكتب سطراً في سجل perf بلا إشعار (الأسبوع الأول)
    'metrics_ttl_minutes' => 15,
    // رسائل الخدمة المجانية شهرياً لكل رقم (تسعير ميتا منذ 2026-10-01)، والتنبيه قبل نفادها
    'service_free_tier' => 1000,

    /*
     * مصفوفة الاستجابة (بديل Alertmanager): لكل قاعدة عتبتها وخطورتها وعنوانها والإجراء ورابطها، والمقياس في
     * App\Services\Ops\OpsRules. period: hour (تنبيه مرة في الساعة ما دامت القاعدة قائمة) أو day أو month.
     * النصوص أرقام وفئات فقط: لا هاتف ولا اسم ولا نص رسالة. العتبات يعتمدها المالك بعد أسبوع المراقبة.
     */
    'rules' => [
        'queue_backlog' => ['threshold' => 300, 'severity' => 'critical', 'period' => 'hour', 'url' => '/whatsapp/failures#queue',
            'title' => '⏳ الطابور متأخر — الردود تتأخر', 'action' => 'راجع صحة النظام: العامل قد يكون متوقفاً أو مثقلاً.'],
        'failed_jobs' => ['threshold' => 3, 'severity' => 'warn', 'period' => 'hour', 'url' => '/whatsapp/failures#jobs',
            'title' => '⚠️ مهام خلفية تفشل', 'action' => 'افتح «مهام خلفية فاشلة» في صفحة الإخفاقات.'],
        'send_auth' => ['threshold' => 1, 'severity' => 'critical', 'period' => 'hour', 'url' => '/whatsapp/settings#ops',
            'title' => '⛔ ميتا ترفض الإرسال: خطأ اعتماد', 'action' => 'جدّد رمز ميتا أو راجع الصلاحيات من WhatsApp Manager.'],
        'send_integrity' => ['threshold' => 1, 'severity' => 'critical', 'period' => 'hour', 'url' => '/whatsapp/settings#ops',
            'title' => '⛔ ميتا تقيّد الحساب', 'action' => 'راجع صحة القناة وجودة الرقم في WhatsApp Manager.'],
        'send_recipient' => ['threshold' => 10, 'severity' => 'warn', 'period' => 'hour', 'url' => '/whatsapp/failures#messages',
            'title' => '⚠️ رسائل كثيرة لم تصل عملاءها', 'action' => 'قد تكون نوافذ مغلقة أو أرقاماً لا تستقبل؛ راجع «رسائل لم تصل».'],
        'unknown_sends' => ['threshold' => 1, 'severity' => 'warn', 'period' => 'hour', 'url' => '/whatsapp/failures#messages',
            'title' => '❔ إرسال غير مؤكد', 'action' => 'راجع الإخفاقات قبل أي إعادة: قد تكون الرسالة وصلت.'],
        'webhook_failed' => ['threshold' => 1, 'severity' => 'warn', 'period' => 'hour', 'url' => '/whatsapp/failures#webhooks',
            'title' => '📥 إشعارات ميتا لم تُعالج', 'action' => 'افتح «ويبهوك فاشل» في صفحة الإخفاقات.'],
        'waiting_human' => ['threshold' => 5, 'severity' => 'warn', 'period' => 'hour', 'url' => '/whatsapp/inbox',
            'title' => '👥 عملاء ينتظرون موظفاً', 'action' => 'محادثات محوّلة لم يرد عليها أحد منذ أكثر من 30 دقيقة.'],
        'bot_latency' => ['threshold' => 60000, 'severity' => 'warn', 'period' => 'hour', 'url' => '/whatsapp/analytics',
            'title' => '🐢 البوت بطيء', 'action' => 'زمن الرد (p90) تجاوز دقيقة: راجع مزوّد الذكاء وطابور الردود.'],
        // العتبة من إعداد wa_daily_alert_threshold (صفحة الإعدادات)، وهذه القيمة احتياطية فقط
        'daily_outbound' => ['threshold' => 300, 'severity' => 'warn', 'period' => 'day', 'url' => '/whatsapp/analytics',
            'title' => '📤 الصادر اليوم تجاوز الحد', 'action' => 'راجع مصادر الصادر في التحليلات؛ رسائل الخدمة تُحتسب بعد 1000 شهرياً.'],
        'service_free_tier' => ['threshold' => 900, 'severity' => 'info', 'period' => 'month', 'url' => '/whatsapp/analytics',
            'title' => '💵 رسائل الخدمة المجانية تقترب من النفاد', 'action' => 'بعد 1000 رسالة خدمة في الشهر تُحتسب كل رسالة؛ راجع وسيلة الدفع.'],
        'automated_budget' => ['threshold' => 1, 'severity' => 'warn', 'period' => 'day', 'url' => '/whatsapp/analytics',
            'title' => '🤖 رسائل آلية فوق ميزانيتها اليومية', 'action' => 'مصدر رسائل آلية تجاوز ميزانيته؛ راجعه قبل أن ترتفع الفاتورة.'],
    ],

    /*
     * ميزانية يومية لكل مصدر رسائل آلية (بادئة SendKey، E19)، يقرّها المالك (AMENDMENTS: التسعير). null = بلا ميزانية،
     * يُعرض العدد وحده. تجاوزها تنبيه automated_budget مرة في اليوم، ولا يحجب شيئاً: الحجب للإرسال المبادر وحده
     * (wa_system_daily_cap). reply ردود البوت نفسها، فتراقبها daily_outbound.
     */
    'automated_budgets' => [
        'reply' => null,
        'rule' => null,
        'admin' => null,
        'outage' => 100,
        'reqcontact' => 50,
        'consent' => 100,
        'optout' => 30,
    ],

    // «إعادة المعالجة» في صفحة الإخفاقات: queue:retry لهذه الأصناف وحدها. ProcessWhatsAppReply لا يُعاد أبداً (رد قديم بعد
    // انتهاء رمز الدمج)، ولا وظيفة ترسل لعميل
    'retryable_jobs' => [
        \App\Jobs\SendPushNotification::class,
        \App\Jobs\RefreshChannelHealth::class,
    ],

    // سجل الطلبات البطيئة و5xx (storage/logs/perf.log): بقالب المسار، بلا رابط كامل ولا query ولا IP. 0 يطفئه
    'slow_ms' => (int) env('OPS_SLOW_MS', 1500),

    // /internal/metrics بصيغة Prometheus: 404 ما لم يُضبط الرمز (32 بايتاً عشوائياً فأكثر)، ويقرأ آخر لقطة فقط
    'metrics_token' => env('OPS_METRICS_TOKEN'),

    /*
     * ميزانية الحمل على الخادم المشترك (E24، docs/ops/load-budget.md): عمّال الطابور كلهم معاً، وأقل sleep لكل عامل،
     * وأقصى أوامر تعمل كل دقيقة. LoadBudgetTest يفحص supervisord.conf والجدولة مقابلها وقائمة المهام الموحدة.
     */
    'load_budget' => [
        'max_workers' => 4,
        'min_sleep' => 3,
        'max_per_minute' => 6,
    ],
];
