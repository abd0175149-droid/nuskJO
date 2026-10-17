<?php

/*
| سياسة أمن المحتوى للمتصفح (E26، App\Support\Csp والوسيط SecurityHeaders). الموضع الوحيد لقراءة البيئة لهذه الميزة.
|
| mode: off | report | enforce.
|   report (الافتراضي): Content-Security-Policy-Report-Only، فلا يُحجب شيء وتصل التقارير إلى /csp-report.
|   enforce: Content-Security-Policy، والمورد غير المسموح يُحجب.
|   off: لا ترويسة ولا nonce (طوارئ: CSP_MODE=off ثم docker compose up -d).
| العلم csp_enforce (لوحة الأعلام في إعدادات الواتساب) يحوّل report إلى enforce بلا نشر، وإطفاؤه يعيد التقارير فوراً.
*/
return [
    'mode' => env('CSP_MODE', 'report'),

    'report_uri' => '/csp-report',

    // مصادر اتصال إضافية مفصولة بفاصلة، مثل wss://ws.example.com إن لم يكن Reverb على مضيف APP_URL نفسه
    'connect_extra' => array_values(array_filter(array_map('trim', explode(',', (string) env('CSP_CONNECT_EXTRA', ''))))),

    // تقارير المتصفحات (/csp-report): حد الجسم، ومدة الاحتفاظ بالعدّادات، ومخزنها. file لا database:
    // تقرير لكل مورد محجوب في كل صفحة لا يكتب في SQLite (array في الاختبارات)
    'report_max_bytes' => 16384,
    'report_days' => 8,
    'report_store' => env('CSP_REPORT_STORE', 'file'),
    // سقف الأنماط المختلفة في اليوم: ما بعده يُعدّ في سطر «أخرى» واحد، فلا يملأ أحدٌ المخزن بتقارير مصنوعة
    'report_max_distinct' => 300,
];
