<?php

/*
 * سجل التدقيق المركزي (E09). العلمان من البيئة (docker-compose.yml)، فتغييرهما يحتاج docker compose up -d nuskjo-app.
 */
return [
    // الأحداث الجديدة غير المالية (واتساب والدخول والوكلاء وميتا). المالية والصلاحيات تُكتب دائماً
    'extended' => (bool) env('AUDIT_EXTENDED', false),
    // صفحة /audit-logs (بعد التحقق من إغلاق تصعيد الصلاحيات في E03)
    'page' => (bool) env('AUDIT_PAGE', false),

    // العيّنات: لكل مفتاح في الدقيقة، ولكل فعل في الساعة (المفتاح بالعنوان يمكن التهرب منه)
    'sample_per_min' => 10,
    'sample_global_per_hour' => 300,
    // file لا database: الضجيج لا يكتب في SQLite (array في الاختبارات)
    'sampler_store' => env('AUDIT_SAMPLER_STORE', 'file'),

    'conversation_view_minutes' => 30,
    // أحداث الضجيج (AuditAction::noiseValues) تُحذف بعدها؛ المال والصلاحيات وميتا تبقى
    'noise_retention_days' => 90,
    'export_max_rows' => 50000,
    'changes_max_bytes' => 16384,
];
