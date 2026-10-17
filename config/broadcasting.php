<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    | حد /broadcasting/auth (E28): كل تنقّل Inertia يعيد الاشتراك في user.{id} عبر التخطيط، فالحد بعيد عن
    | الاستعمال العادي. لكل مستخدم، ولكل عنوان (المكتب خلف عنوان واحد).
    */
    'auth_limit' => [
        'per_user' => max(1, (int) env('BROADCAST_AUTH_PER_MINUTE', 60)),
        'per_ip' => max(1, (int) env('BROADCAST_AUTH_PER_IP_MINUTE', 120)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            // E28: النشر من الخادم إلى Reverb داخل الحاوية (REVERB_PUBLISH_*، 127.0.0.1:8080 في compose) لا عبر النفق.
            // بلا قيمة: REVERB_HOST كما كان. المتصفح يبقى على المضيف العام (VITE_REVERB_*)
            'options' => [
                'host' => env('REVERB_PUBLISH_HOST') ?: env('REVERB_HOST'),
                'port' => env('REVERB_PUBLISH_PORT') ?: env('REVERB_PORT', 443),
                'scheme' => env('REVERB_PUBLISH_SCHEME') ?: env('REVERB_SCHEME', 'https'),
                'useTLS' => (env('REVERB_PUBLISH_SCHEME') ?: env('REVERB_SCHEME', 'https')) === 'https',
            ],
            // مهلة قصيرة: Reverb متعطّل لا يعلّق طلباً ولا وظيفة (الجرس والصندوق اللحظي)
            'client_options' => [
                'connect_timeout' => (float) env('REVERB_PUBLISH_CONNECT_TIMEOUT', 1),
                'timeout' => (float) env('REVERB_PUBLISH_TIMEOUT', 2),
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'ably' => [
            'driver' => 'ably',
            'key' => env('ABLY_KEY'),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
