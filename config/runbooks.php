<?php

/*
| ربط تنبيهات المدراء بكتيّباتها في docs/RUNBOOK.md (E25). المفتاح بادئة مفتاح OpsAlerts::notifyAdmins،
| تُطابق بحدود المقاطع (':')، والأطول يغلب: 'health:queue_stale' قبل 'health'. القيمة معرّف الكتيّب ومرساته
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
        // أي رمز آخر من فحص الصحة (OpsHealth::codes)
        'health' => 'RB-11',

        // E20/E23/E37: الويبهوك وصمت الوارد
        'wa:webhook' => 'RB-04',
        'wa:sig_fail' => 'RB-04',
        'inbound_silence' => 'RB-04',

        // E17/E19: الردود العالقة والإرسال غير المؤكد
        'wa:stuck' => 'RB-01',
        'wa:reap' => 'RB-13',

        // E23/E31/E38: الفحوص الدورية والمطابقات
        'wa:doctor' => 'RB-17',
        'wa:blocks-check' => 'RB-17',

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
        'ops:channels_down' => 'RB-12',
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
        'whatsapp:snooze-wake' => 'RB-01',
        'whatsapp:llm-probe' => 'RB-10',
        'whatsapp:reap-pending' => 'RB-13',
        // OpenWA (2026-11-06): مراقبة الأرقام، وطابور رسائل النظام وجدولتها، وربط معرّفات lid بالهواتف
        'whatsapp:channel-watch' => 'RB-12',
        'wa:outbox-tick' => 'RB-20',
        'wa:schedule-system' => 'RB-20',
        'whatsapp:resolve-lids' => 'RB-11',
        'whatsapp:media-sweep' => 'RB-08',
        'whatsapp:media-prune' => 'RB-08',
        'whatsapp:voice-tmp-sweep' => 'RB-08',
        'backup:run' => 'RB-06',
        'backup:watchdog' => 'RB-06',
        'whatsapp:doctor' => 'RB-17',
        'whatsapp:fts-check' => 'RB-17',
        'whatsapp:blocks-check' => 'RB-17',
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
