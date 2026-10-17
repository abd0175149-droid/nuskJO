<?php

/*
| حارس الصلاحيات على المسارات (E03).
| كل مسار داخل auth يعلن صلاحيته بالوسيط perm: (أو can:)، أو يكون في allowlist بسبب موثّق.
|
| mode=log: أسبوع مراجعة؛ ما لم يُعلن أو لا يملكه المستخدم يُسجَّل في audit_logs ويُسمح به،
| إلا always_enforce فتُفرض دائماً. mode=enforce: رفض 403. الافتراضي في الكود الرفض.
| المراجعة: php artisan authz:report
*/
return [
    'mode' => env('AUTHZ_MODE', 'enforce'),

    // ثغرات معروفة لا تنتظر أسبوع التسجيل
    'always_enforce' => [
        'admin',
        'users.view', 'users.create', 'users.update', 'users.delete', 'roles.manage',
        'settings.update',
        'accounting.close',
        'invoices.approve', 'invoices.reject',
        'receipts.approve', 'receipts.reject',
        'disbursements.approve', 'disbursements.reject',
        'backups.view', 'backups.run', 'backups.restore_ack',
    ],

    // مسارات بلا صلاحية عن قصد: كلها للمستخدم نفسه وتقيّد داخل المتحكم
    'allowlist' => [
        'logout',
        'dashboard',                                   // يفرّع على isAdmin؛ الموظف يرى لوحته فقط
        'profile', 'profile.update', 'profile.password',
        'POST api/fcm-token', 'DELETE api/fcm-token',  // رموز جهاز المستخدم نفسه
        'POST api/fcm-token/test',                     // إشعار تجريبي لتوكن مسجّل لحساب المستخدم نفسه (FcmTokenController::test)
        'GET api/notifications', 'POST api/notifications/{notification}/read', 'POST api/notifications/read-all',
        'attendance.check-in', 'attendance.check-out', 'attendance.status',
        'hr.my-attendance', 'hr.my-requests', 'hr.my-payslip',
        'payslip',                                     // قسيمة الموظف نفسه أو payroll.view (PayrollController)
        // E27: تحدّي العامل الثاني وتأكيد الهوية وصفحة الأمان، كلها على حساب المستخدم نفسه
        'two-factor.challenge', 'two-factor.verify', 'security.confirm', 'security.confirm.store',
        'profile.security', 'profile.two-factor.enable', 'profile.two-factor.confirm',
        'profile.two-factor.recovery-codes', 'profile.two-factor.disable',
    ],
];
