<?php

/*
| أعلام الميزات (App\Support\FeatureFlags): البيئة تتقدّم على المحفوظ في wa_bot_settings.feature_flags،
| فالإطفاء الطارئ لأي ميزة: WA_FEATURE_<KEY>=false ثم up -d. فارغ = غير مضبوط.
| كل علم في FeatureFlags::DEFINITIONS له سطر هنا (FeatureFlagsTest).
*/

return [
    'env' => [
        'llm_circuit_breaker' => env('WA_FEATURE_LLM_CIRCUIT_BREAKER'),
        'inbound_event_log' => env('WA_FEATURE_INBOUND_EVENT_LOG'),
        'retention_purge' => env('WA_FEATURE_RETENTION_PURGE'),
    ],
];
