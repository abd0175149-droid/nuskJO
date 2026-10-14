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
        // إصدار Graph للطلبات الصادرة. v21.0 يُزال في 2027-01-21؛ v25.0 ينتهي 2028-07-29.
        // للتراجع: WA_GRAPH_VERSION=v21.0 في .env (صالح حتى 2027-01-21).
        'graph_version' => env('WA_GRAPH_VERSION', 'v25.0'),
        'suspended' => (bool) env('WA_SUSPENDED', false),
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
    ],

];
