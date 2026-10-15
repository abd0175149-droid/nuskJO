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
        'media_archive' => env('WA_FEATURE_MEDIA_ARCHIVE'),
        'media_consent_prompt' => env('WA_FEATURE_MEDIA_CONSENT_PROMPT'),
        'tool_validation_enforce' => env('WA_FEATURE_TOOL_VALIDATION_ENFORCE'),
        'admin_readonly' => env('WA_FEATURE_ADMIN_READONLY'),
        'inbox_v2' => env('WA_FEATURE_INBOX_V2'),
        'admin_rbac_enforce' => env('WA_FEATURE_ADMIN_RBAC_ENFORCE'),
        'outbound_policy_enforce' => env('WA_FEATURE_OUTBOUND_POLICY_ENFORCE'),
    ],
];
