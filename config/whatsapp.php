<?php

/*
| أرشيف مرفقات العملاء (E14). الأرشفة خلف العلم media_archive (مطفأ)، ولا يُفعَّل قبل تحديث /privacy
| و/data-deletion واعتماد المالك لنص الإشعار ومدة الاحتفاظ (docs/RELEASE.md «أرشيف المرفقات»).
*/

return [
    'media' => [
        // R2/S3 لاحقاً بقرار المالك (يُذكر المزوّد في /privacy)
        'disk' => env('WA_MEDIA_DISK', 'wa_media'),
        'queue' => 'wa-media',
        // الأنواع المؤرشفة آلياً. الفيديو والصوت بقرار المالك
        'types' => array_values(array_filter(array_map('trim', explode(',', (string) env('WA_MEDIA_TYPES', 'image,document'))))),
        'max_bytes' => 25 * 1024 * 1024,
        // التنزيل اليدوي لملف أكبر أو فشل (خلال صلاحية المعرّف عند ميتا)
        'manual_max_bytes' => 100 * 1024 * 1024,
        'retention_days' => (int) env('WA_MEDIA_RETENTION_DAYS', 120),
        'quota_mb' => (int) env('WA_MEDIA_QUOTA_MB', 5120),
        'quota_warn_pct' => 80,
        // log: يُحفظ الملف رغم اختلاف البصمة ويُعلَّم sha_mismatch. enforce بعد أسبوع نظيف
        'sha_mode' => env('WA_MEDIA_SHA_MODE', 'log'),
        // معرّف الوسائط عند ميتا يعيش 7 أيام: آخر محاولة قبلها
        'id_lifetime_minutes' => 6 * 24 * 60 + 20 * 60,
        // أقل مساحة حرة (نسبة من القرص) قبل أي تنزيل
        'min_free_pct' => 5,
        // النوع المكتشف بـ finfo => [الامتداد، يُعاين في الصندوق]. كل ما سواه مرفوض (SVG وHTML وx-* والأرشيفات)
        'mimes' => [
            'image/jpeg' => ['jpg', true],
            'image/png' => ['png', true],
            'image/webp' => ['webp', true],
            'application/pdf' => ['pdf', true],
            'video/mp4' => ['mp4', false],
            'video/3gpp' => ['3gp', false],
            'audio/ogg' => ['ogg', false],
            'audio/mpeg' => ['mp3', false],
            'audio/mp4' => ['m4a', false],
            'audio/aac' => ['aac', false],
            'audio/amr' => ['amr', false],
        ],
        // أنواع Office للتنزيل فقط: تُقبل بقرار المالك (WA_MEDIA_ALLOW_OFFICE=true)
        'office_mimes' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', false],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', false],
        ],
        'allow_office' => (bool) env('WA_MEDIA_ALLOW_OFFICE', false),
    ],
];
