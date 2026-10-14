<?php

/*
| النسخ الاحتياطي لقاعدة SQLite (E01).
| النسخ تُكتب في spool داخل حجم storage (خارج public)، ثم يسحبها المجمّع على المضيف مشفّرة.
| لا يدخل أي نسخة .env ولا firebase-auth.json ولا APP_KEY.
*/
return [
    'enabled' => (bool) env('BACKUP_ENABLED', false),
    'pre_migrate' => (bool) env('BACKUP_PRE_MIGRATE', false),

    // يضبطه Dockerfile من ARG GIT_COMMIT؛ يُقرأ هنا لأن env() خارج الإعداد لا يعمل بعد config:cache
    'app_commit' => env('APP_COMMIT'),

    'spool_path' => env('BACKUP_SPOOL_PATH', storage_path('app/backups')),
    'media_dirs' => [storage_path('app/public')],

    'core_tables' => [
        'users', 'clients', 'agents', 'invoices', 'invoice_items', 'receipts',
        'journal_entries', 'journal_entry_lines', 'wa_conversations', 'wa_messages',
        'quote_requests', 'offers', 'employees', 'payrolls',
    ],

    // جداول يجب ألا تكون فارغة في نسخة الإنتاج؛ فراغها يعني «القاعدة الخطأ».
    'required_nonempty' => ['users', 'invoices', 'journal_entries'],

    // جداول تُضاف إليها الصفوف فقط؛ النسخة لا تقل عن عددها الحي قبل النسخ.
    'append_only' => ['wa_messages', 'audit_logs'],

    // أي جدول أساسي يقل عن هذه النسبة من آخر نسخة ناجحة يُفشل النسخ (إلا مع --allow-shrink).
    'shrink_tolerance' => 0.9,

    // A12: الوسائط تُنسخ كاملة ما دامت تحت هذا السقف؛ فوقه تُتخطى (والقاعدة تُنسخ دائماً) مع تنبيه.
    // النسخ التزايدي بالبصمة يأتي قبل إضافة wa-media إلى media_dirs (E14).
    'media_max_mb' => (int) env('BACKUP_MEDIA_MAX_MB', 1024),

    // سقف صلب: لا نسخة إن كانت ستترك أقل من هذا فارغاً على القرص (الخادم مشترك بين مشاريع كثيرة)
    'disk_min_free_mb' => (int) env('BACKUP_DISK_MIN_FREE_MB', 5120),

    'local_keep' => (int) env('BACKUP_LOCAL_KEEP', 3),
    'stale_hours' => 26,
    'disk_warn_pct' => 90,
    'spool_max_mb' => 500,
];
