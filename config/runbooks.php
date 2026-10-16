<?php

/*
| ربط تنبيهات المدراء بكتيّباتها في docs/RUNBOOK.md (E25). المفتاح بادئة مفتاح OpsAlerts::notifyAdmins،
| تُطابق بحدود المقاطع (':')، والأطول يغلب: 'meta:send:190' قبل 'meta'. القيمة معرّف الكتيّب ومرساته
| <a id="rb-NN"> في الوثيقة. تنبيهات WhatsAppInbox::notifyAdmins تمرّر كتيّبها بالمعامل runbook.
|
| كل بادئة تنبيه جديدة تُضاف هنا مع سطرها في مصفوفة الاستجابة في docs/RISKS.md، وإلا فشل
| RunbookAlertCoverageTest باسم الملف والمفتاح. لا env هنا: الربط توثيق لا إعداد.
*/

return [
    'alerts' => [
        // E08: صحة النظام (/up) والوظائف الخلفية
        'health:queue_stale' => 'RB-11',
        'health:reply_queue_stale' => 'RB-11',
        'health:schedule_stale' => 'RB-11',
        'health:backlog' => 'RB-11',
        'jobfailed' => 'RB-11',

        // E06/E18: أخطاء ميتا عند الإرسال (meta:send:<الرمز> في الجدول القديم، وmeta:<الرمز> في التصنيف الجديد)
        'meta:send:190' => 'RB-02',
        'meta:send:0' => 'RB-02',
        'meta:send:10' => 'RB-02',
        'meta:190' => 'RB-02',
        'meta:0' => 'RB-02',
        'meta:10' => 'RB-02',
        'meta:send:368' => 'RB-03',
        'meta:send:131031' => 'RB-03',
        'meta:send:131048' => 'RB-03',
        'meta:368' => 'RB-03',
        'meta:131031' => 'RB-03',
        'meta:131048' => 'RB-03',
        'meta:send:131042' => 'RB-12',
        'meta:131042' => 'RB-12',
        'meta:send:130429' => 'RB-13',
        'meta:130429' => 'RB-13',
        // E08: فحص الرمز اليومي وفحص صحة الرقم (meta:<credentials|channel>:<الحالة>:<السبب>)
        'meta:credentials' => 'RB-02',
        'meta:channel' => 'RB-12',
        'meta' => 'RB-13',

        // E22: صحة القناة كآلة حالات، وأحداث الحساب، ومراقب الإرسال
        'health:TOKEN_INVALID' => 'RB-02',
        'health:acct' => 'RB-03',
        'health:integrity' => 'RB-03',
        'health:send_fail' => 'RB-13',
        'health:country' => 'RB-13',
        'health:waba_mismatch' => 'RB-04',
        'health' => 'RB-12',

        // E20/E23/E37: الويبهوك وصمت الوارد
        'wa:webhook' => 'RB-04',
        'wa:sig_fail' => 'RB-04',
        'wa:fence' => 'RB-04',
        'wa:field' => 'RB-04',
        'wa:webhook-drift' => 'RB-04',
        'inbound_silence' => 'RB-04',

        // E20/E39: التعايش مع تطبيق الأعمال
        'wa:coexistence' => 'RB-18',

        // E17/E19: الردود العالقة والإرسال غير المؤكد
        'wa:stuck' => 'RB-01',
        'wa:reap' => 'RB-13',

        // E23/E31/E38: الفحوص الدورية والمطابقات
        'wa:doctor' => 'RB-17',
        'wa:blocks-check' => 'RB-17',
        'wa:qr-sync' => 'RB-17',

        // E26: فحص الصورة الأسبوعي
        'security:scan' => 'RB-14',

        // E40: التكاملات الصادرة
        'integrations' => 'RB-19',

        // E24: قواعد whatsapp:ops-check (المفتاح ops:<القاعدة>، ومصفوفتها في docs/whatsapp/ops-alerts.md)
        'ops:queue_backlog' => 'RB-11',
        'ops:failed_jobs' => 'RB-11',
        'ops:send_auth' => 'RB-02',
        'ops:send_integrity' => 'RB-03',
        'ops:send_recipient' => 'RB-13',
        'ops:unknown_sends' => 'RB-13',
        'ops:webhook_failed' => 'RB-04',
        'ops:waiting_human' => 'RB-01',
        'ops:bot_latency' => 'RB-01',
        'ops:daily_outbound' => 'RB-20',
        'ops:service_free_tier' => 'RB-20',
        'ops:automated_budget' => 'RB-20',
        'ops' => 'RB-11',
    ],

    /*
    | الكتيّب عند فشل كل مهمة مجدولة. اسم المهمة كما في القائمة الموحدة docs/ops/load-budget.md (أمر artisan أو الاسم
    | المعطى بـ ->name())، والمطابقة بحدود المقاطع كالتنبيهات. مهمة مجدولة جديدة تُضاف هنا وفي القائمة الموحدة في
    | التغيير نفسه، وإلا فشل RunbookScheduleCoverageTest باسمها.
    */
    'schedules' => [
        'ops:schedule-beat' => 'RB-11',
        'ops:queue-beat' => 'RB-11',
        'ops:tick' => 'RB-11',
        'whatsapp:ops-check' => 'RB-11',
        'whatsapp:replay-webhooks' => 'RB-04',
        'whatsapp:webhook-events-prune' => 'RB-04',
        'whatsapp:inbound-silence' => 'RB-04',
        'whatsapp:reconcile-replies' => 'RB-01',
        'whatsapp:stuck-replies' => 'RB-01',
        'whatsapp:window-reminders' => 'RB-01',
        'whatsapp:snooze-wake' => 'RB-01',
        'whatsapp:credentials-check' => 'RB-02',
        'whatsapp:graph-version-check' => 'RB-07',
        'whatsapp:llm-probe' => 'RB-10',
        'whatsapp:health' => 'RB-12',
        'whatsapp:health-expiry' => 'RB-12',
        'whatsapp:reap-pending' => 'RB-13',
        'whatsapp:outbound-media-cleanup' => 'RB-13',
        'whatsapp:media-sweep' => 'RB-08',
        'whatsapp:media-prune' => 'RB-08',
        'whatsapp:voice-tmp-sweep' => 'RB-08',
        'backup:run' => 'RB-06',
        'backup:watchdog' => 'RB-06',
        'whatsapp:doctor' => 'RB-17',
        'whatsapp:fts-check' => 'RB-17',
        'whatsapp:blocks-check' => 'RB-17',
        'whatsapp:qr-sync' => 'RB-17',
        'whatsapp:meta-analytics' => 'RB-17',
        'whatsapp:coexistence-history' => 'RB-18',
        'integrations:redrive' => 'RB-19',
        'integrations:prune' => 'RB-19',
        // صيانة دورية: فشلها لا يمس العملاء فوراً، والخطوات العامة في RB-11
        'queue:prune-failed' => 'RB-11',
        'model:prune' => 'RB-11',
        'ops:prune-cache' => 'RB-11',
        'whatsapp:prune' => 'RB-11',
        'whatsapp:usage-rollup' => 'RB-11',
        'whatsapp:erasure-sla' => 'RB-11',
        'users:sync-last-seen' => 'RB-11',
        'users:idle-report' => 'RB-11',
        'accounting:reconcile' => 'RB-11',
    ],
];
