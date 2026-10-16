<?php

/*
| العامل الثاني والتحقق المعزّز للموظفين (E27).
|
| التسجيل الطوعي وتحدّي الدخول خلف العلم staff_mfa، والتحقق المعزّز خلف العلم step_up (App\Support\FeatureFlags).
| هنا ما يُضبط من البيئة وحدها: وضع الفرض ومنفذ الطوارئ والمدد. كلها تحتاج docker compose up -d nuskjo-app.
*/
return [
    'mfa' => [
        // فرض التسجيل على أصحاب الصلاحيات الحساسة: off ثم warn (شريط تنبيه) ثم on (لا صفحة غير «الأمان» حتى يسجّل).
        // on لا يُفرض قبل enforce_from، ولا يعمل والعلم staff_mfa مطفأ (لا تسجيل ممكن، فلا فرض)
        'enforce' => env('MFA_ENFORCE', 'off'),
        'enforce_from' => env('MFA_ENFORCE_FROM'),

        // منفذ طوارئ: off يعطّل تحدّي الدخول للجميع (يُسجَّل في التدقيق مرة في الساعة لكل مستخدم، ويُكتب في CHANGELOG)
        'challenge' => env('MFA_CHALLENGE', 'on'),

        // من يملك أيّاً منها (أو دور المدير) يُطلب منه العامل الثاني حين يُفرض
        'sensitive_permissions' => [
            'users.create', 'users.update', 'users.delete', 'roles.manage',
            'settings.update', 'whatsapp.settings', 'whatsapp.admin_chats',
            'accounting.manage', 'accounting.close',
            'invoices.approve', 'receipts.approve', 'disbursements.approve', 'payroll.approve', 'advances.approve',
            'offers.view_cost', 'audit.export',
        ],

        // الاسم الظاهر في تطبيق المصادقة
        'issuer' => env('MFA_ISSUER', env('APP_NAME', 'nuskJO')),

        // محاولات التحدّي: 5 في الدقيقة لكل مستخدم و20 لكل عنوان، وعشرة إخفاقات خلال 15 دقيقة تُخرج المستخدم
        'per_minute' => 5,
        'per_ip_per_minute' => 20,
        'lockout_failures' => 10,
        'lockout_minutes' => 15,
    ],

    // نافذة التحقق المعزّز بالثواني: فعل حساس بعدها يطلب تأكيد الهوية من جديد
    'stepup' => [
        'ttl' => (int) env('STEPUP_TTL', 900),

        // الأفعال الحساسة (اسم المسار، أو «الطريقة المسار» لمسار بلا اسم)، لا تصفح الصفحات اليومي. routes/web.php يلحق بها
        // الوسيط stepup بعد صلاحيتها. الإيقاف الطارئ ليس هنا (حماية لا تُعاق)، واستئنافه يُفحص في المتحكم.
        // إدارة العامل الثاني نفسه (التفعيل والتعطيل وتجديد الرموز وإعادة التعيين) stepup:always في مساراتها
        'routes' => [
            // المستخدمون والأدوار
            'users.store', 'users.update', 'users.destroy', 'users.reset-password',
            'roles.store', 'roles.update', 'roles.destroy',
            // الإعدادات واعتماد ما بعد الاستعادة
            'settings.update', 'settings.brand-asset', 'settings.upload-template', 'settings.save-print-layout', 'settings.backups.ack',
            // واتساب: الإعدادات والأسرار والأعلام والأسعار وأرقام الإدارة والتوجيه
            'whatsapp.settings.patch', 'whatsapp.settings.update',
            'POST api/whatsapp/settings/secrets/{secret}', 'DELETE api/whatsapp/settings/secrets/{secret}',
            'POST api/whatsapp/settings/verify-token', 'PATCH api/whatsapp/settings/flags/{flag}', 'PATCH api/whatsapp/settings/identity-mode',
            'whatsapp.prices', 'whatsapp.admin-numbers.store', 'whatsapp.admin-numbers.update', 'whatsapp.admin-numbers.destroy',
            'whatsapp.routing.save',
            // الاعتمادات وفتح المعتمد للتعديل والحذف
            'invoices.approve', 'invoices.start-edit', 'invoices.destroy',
            'receipts.approve', 'receipts.start-edit', 'receipts.update-approved', 'receipts.destroy',
            'disbursements.approve', 'disbursements.start-edit', 'disbursements.update-approved', 'disbursements.destroy',
            'payrolls.approve', 'advances.approve', 'advances.destroy',
            // المحاسبة
            'accounting.periods.close', 'accounting.periods.open', 'accounting.close-year',
            'accounting.trial-balance.relocate-line', 'accounting.journal-entries.destroy',
            // التصدير
            'audit-logs.export',
        ],
    ],

    // الحسابات الخاملة (تقرير أسبوعي للمدراء، بلا تعطيل آلي)
    'idle' => [
        'user_days' => (int) env('IDLE_USER_DAYS', 60),
        'admin_number_days' => (int) env('IDLE_ADMIN_NUMBER_DAYS', 90),
    ],

    // سياسة كلمة المرور (الملف الشخصي وإعادة التعيين وإنشاء المستخدم). الافتراضي سلوك اليوم؛ 10 مع حروف وأرقام قرار المالك
    'password' => [
        'min' => (int) env('PASSWORD_MIN_LENGTH', 8),
        'letters_and_numbers' => (bool) env('PASSWORD_LETTERS_AND_NUMBERS', false),
    ],
];
