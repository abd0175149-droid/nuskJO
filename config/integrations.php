<?php

/*
| التكاملات الصادرة وخادم MCP للقراءة (E40).
|
| العلمان integration_outbox وmcp_server (App\Support\FeatureFlags) يقرّران العمل كله؛ هنا ما يُضبط من البيئة وحدها،
| وتغييره يحتاج docker compose up -d nuskjo-app. لا إعداد هنا يُبدَّل من الواجهة.
*/

$csv = fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value))));

return [
    // ===== الصندوق الصادر =====
    'queue' => 'integrations',

    // منافذ غير 443 مسموحة صراحة (بفواصل). الافتراضي لا شيء
    'allowed_ports' => array_map('intval', $csv(env('INTEGRATIONS_ALLOWED_PORTS'))),

    // استثناء داخلي واحد بصيغة host:port (مثل n8n على المضيف نفسه). لا يُضبط من الواجهة أبداً، والصفحة تحذّر منه دائماً.
    // الموصى به أن يُخاطَب المستقبل برابطه العام عبر Cloudflare، فلا حاجة له
    'internal_allow' => env('INTEGRATIONS_INTERNAL_ALLOW'),

    // مضيفاتنا نحن: لا وجهة تعود إلينا (حلقة). مضيف APP_URL دائماً، وما يُضاف هنا بفواصل
    'self_hosts' => array_values(array_unique(array_filter([
        parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: null,
        ...$csv(env('INTEGRATIONS_SELF_HOSTS')),
    ]))),

    // التسليم: بلا تحويلات، ومهلة اتصال 5 ثوانٍ وكلية 10، و64KB حداً أقصى نقرؤه من الرد (الرمز وحده يهمنا)
    'connect_timeout' => 5,
    'timeout' => 10,
    'max_response_bytes' => 65536,

    // الإعادة يديرها الصندوق لا الطابور: انتظار بالثواني بعد كل محاولة فاشلة عابرة، ثم dead بعد 6 محاولات
    'backoff' => [30, 120, 600, 3600, 21600],
    'max_attempts' => 6,
    'retry_after_cap' => 3600,
    // فشل متتالٍ يوقف الوجهة آلياً
    'auto_pause_after' => 20,

    // المُصالِح: «قيد التسليم» أقدم من هذا يعود معلّقاً (عامل قُتل)، وحد الصفوف لكل تشغيل، وعتبة تنبيه المعلّق
    'stale_minutes' => 10,
    'redrive_batch' => 100,
    'pending_alert' => 500,

    // الاحتفاظ بسجل التسليمات (قرار المالك)
    'delivered_days' => 30,
    'dead_days' => 90,

    // تدوير السر: السابق يبقى صالحاً للتوقيع 24 ساعة
    'rotation_hours' => 24,

    // ===== خادم MCP للقراءة =====
    'mcp' => [
        // رموز الوصول: 30 يوماً حداً أقصى (قرار المالك)
        'token_max_days' => 30,
        'per_minute' => 30,
        // سقف نتيجة الأداة بالبايت
        'max_result_bytes' => 20480,
        // Cloudflare Access أمام /mcp/*: فريق Zero Trust (مثل team.cloudflareaccess.com) ووسم AUD للتطبيق.
        // بلا ضبط في الإنتاج يرد الخادم 404 (يفشل مغلقاً). خارج الإنتاج يُتخطى الفحص ما لم يُضبط
        'cloudflare' => [
            'team_domain' => env('MCP_CF_TEAM_DOMAIN'),
            'aud' => env('MCP_CF_AUD'),
        ],
    ],
];
