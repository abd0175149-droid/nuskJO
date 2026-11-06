<?php

return [

    /*
    | قناة واتساب ومفاتيح النماذج — تجاوزٌ اختياري لما يُحفظ من صفحة إعدادات البوت.
    | تُقرأ هنا لا بـ env() في الكود: بعد config:cache لا يُحمَّل ‎.env‎، وphp-fpm
    | يمسح متغيّرات البيئة، فكان env() يعيد فراغاً في طلبات الويب وحدها.
    */
    'whatsapp' => [
        // طابور ردود البوت وحده (عامل laravel-wa في supervisord): الإشعارات لا تنتظر خلف رد يطول.
        // الرجوع: WA_REPLY_QUEUE=default ثم تصريف المتبقي (docs/RELEASE.md)
        'reply_queue' => env('WA_REPLY_QUEUE', 'wa-replies'),
        'suspended' => (bool) env('WA_SUSPENDED', false),
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
