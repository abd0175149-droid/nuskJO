<?php

/*
 * التشغيل: فحص /up الحقيقي ونبضات المجدول والعمّال وفحوص ميتا (E08).
 * العلَمان من البيئة (docker-compose.yml)، فتغييرهما يحتاج docker compose up -d nuskjo-app.
 */
return [
    // DiagnosingHealth على /up: القاعدة والمجدول والعاملان والطابور. false يعيد /up إلى 200 دائماً (التراجع)
    'health_checks' => (bool) env('OPS_HEALTH_CHECKS', true),

    // مخزن نتيجة الفحص وكبح التنبيهات: file لا database، فهو يعمل والقاعدة معطلة (array في الاختبارات)
    'cache_store' => env('OPS_CACHE_STORE', 'file'),

    // عمر النبضة الذي بعده يُعدّ المكوّن متوقفاً (المجدول ينبض كل دقيقة، والعاملان كذلك)
    'schedule_stale_seconds' => 180,
    'queue_stale_seconds' => 300,
    // وظيفة متاحة لم يلتقطها عامل منذ هذه المدة
    'backlog_stale_seconds' => 300,

    // فحص رمز ميتا يومياً وصحة القناة كل ساعة، والتنبيه الفوري على أخطاء الاعتماد
    'meta_checks' => (bool) env('WA_META_CHECKS', true),
    'token_warn_days' => 14,
    'token_critical_days' => 3,
    // بتوقيت عمّان
    'credentials_check_at' => '08:00',
];
