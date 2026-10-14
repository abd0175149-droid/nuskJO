<?php

return [

    /*
    | قناة واتساب ومفاتيح النماذج — تجاوزٌ اختياري لما يُحفظ من صفحة إعدادات البوت.
    | تُقرأ هنا لا بـ env() في الكود: بعد config:cache لا يُحمَّل ‎.env‎، وphp-fpm
    | يمسح متغيّرات البيئة، فكان env() يعيد فراغاً في طلبات الويب وحدها.
    */
    'whatsapp' => [
        'token' => env('WA_TOKEN'),
        'phone_number_id' => env('WA_PHONE_NUMBER_ID'),
        'verify_token' => env('WA_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WA_APP_SECRET'),
        // إصدار Graph للطلبات الصادرة — القيمة الافتراضية الوحيدة في المستودع. تواريخ النهاية في
        // App\Services\WhatsApp\GraphVersions، والتنبيه قبل 60 يوماً (whatsapp:graph-version-check).
        // للتراجع: WA_GRAPH_VERSION=<إصدار سابق ما زال مدعوماً> ثم config:cache.
        'graph_version' => env('WA_GRAPH_VERSION') ?: 'v25.0',
        // إصدار الويبهوك المضبوط في لوحة ميتا (لا يُقرأ من الطلبات): للتنبيه قبل نهايته فقط
        'webhook_version' => env('WA_WEBHOOK_VERSION'),
        // appsecret_proof مع كل نداء Graph. يُفعَّل قبل «Require App Secret» في لوحة ميتا بأسبوع على الأقل
        'appsecret_proof' => (bool) env('WA_APPSECRET_PROOF', false),
        // تنزيل الوسائط: log يسجّل المضيف الغريب ويكمل، enforce يرفضه. https إلزامي في الوضعين
        'media_host_mode' => env('WA_MEDIA_HOST_MODE', 'log'),
        'media_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('WA_MEDIA_HOSTS', 'fbsbx.com,whatsapp.net,facebook.com,fbcdn.net'))))),
        'suspended' => (bool) env('WA_SUSPENDED', false),
        // إيقاف الإرسال آلياً عند رمز ميتا خطير. 368 وحده افتراضياً: 131031 قد يعني PIN تحقق خاطئاً،
        // فهو و131048 للتنبيه فقط (App\Services\WhatsApp\MetaErrorCatalog)
        'health_autosuspend' => (bool) env('WA_HEALTH_AUTOSUSPEND', true),
        'autosuspend_codes' => env('WA_AUTOSUSPEND_CODES', '368'),
        // طلبات التسعير بنطاق المحادثات نفسه (E04). يُفعَّل بعد ملء /whatsapp/routing ومراجعة whatsapp:fence-report
        'fence_quotes' => (bool) env('WA_FENCE_QUOTES', false),
    ],

    // شبكة أمان لكل نداء Http جديد: لا تحويل يحمل ترويسة سرية إلى مضيف آخر (E05)
    'outbound' => [
        'block_redirects' => (bool) env('OUTBOUND_HTTP_NO_REDIRECTS', true),
    ],

    'llm' => [
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        'google_key' => env('GOOGLE_API_KEY') ?: env('GEMINI_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // مُصيّر بطاقات العروض — يُكتشف تلقائياً إن تُرك فارغاً
    'chromium' => [
        'path' => env('CHROMIUM_PATH'),
        // بلا شبكة: بروكسي ميت وحلّ أسماء فاشل (القالب كله data:). false للتراجع دون تعديل الكود
        'net_isolation' => (bool) env('CHROMIUM_NET_ISOLATION', true),
    ],

];
