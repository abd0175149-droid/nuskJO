<?php

return [
    /*
    | مزوّد بيانات الرحلات:
    |   demo         = بيانات تجريبية (يعمل بلا حساب — لمعاينة الشكل)
    |   travelfusion = اتصال حقيقي (يتطلب LoginId من حساب Travelfusion)
    */
    'driver' => env('FLIGHTS_DRIVER', 'demo'),

    'travelfusion' => [
        // يُسلَّم لك من Travelfusion بعد تفعيل الحساب
        'login_id' => env('TF_LOGIN_ID'),
        // XmlLoginId: يوضع فيه نفس LoginId مبدئياً حسب توثيقهم
        'xml_login_id' => env('TF_XML_LOGIN_ID') ?: env('TF_LOGIN_ID'),

        // xmltest.travelfusion.com للاختبار · api.travelfusion.com للإنتاج
        'endpoint' => env('TF_ENDPOINT', 'https://api.travelfusion.com'),

        // قصر البحث على ناقلات معيّنة (مثال: flynas) — فارغ = كل المتاح على حسابك
        'suppliers' => array_filter(array_map('trim', explode(',', (string) env('TF_SUPPLIERS', '')))),

        'search_timeout' => (int) env('TF_SEARCH_TIMEOUT', 40), // ثانية — يُمرَّر لهم
        'poll_seconds'   => (int) env('TF_POLL_SECONDS', 30),   // أقصى مدة استطلاع عندنا
        'max_changes'    => (int) env('TF_MAX_CHANGES', 1),
        'max_hops'       => (int) env('TF_MAX_HOPS', 2),
    ],
];
