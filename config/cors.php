<?php

/*
|--------------------------------------------------------------------------
| CORS — مغلق كلياً (E26.6)
|--------------------------------------------------------------------------
| التطبيق من أصل واحد، فلا مسار يحتاج طلبات من أصل آخر:
| - ويبهوك ميتا نداء من خادم إلى خادم، لا من متصفح.
| - Reverb على wss من الأصل نفسه، ولا يمر بوسيط HandleCors.
| - لا routes/api.php.
| بلا هذا الملف كان الافتراضي Access-Control-Allow-Origin: * على api/*. HandleCors يبقى في المكدّس العام ولا يضيف شيئاً.
| whatsapp:doctor ينبّه إن عادت paths غير فارغة أو ظهر '*' في الأصول.
*/

return [

    'paths' => [],

    'allowed_methods' => ['GET', 'POST'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
