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
        'webhook_fence_enforce' => env('WA_FEATURE_WEBHOOK_FENCE_ENFORCE'),
        'retention_purge' => env('WA_FEATURE_RETENTION_PURGE'),
        'media_archive' => env('WA_FEATURE_MEDIA_ARCHIVE'),
        'media_consent_prompt' => env('WA_FEATURE_MEDIA_CONSENT_PROMPT'),
        'tool_validation_enforce' => env('WA_FEATURE_TOOL_VALIDATION_ENFORCE'),
        'admin_readonly' => env('WA_FEATURE_ADMIN_READONLY'),
        'inbox_v2' => env('WA_FEATURE_INBOX_V2'),
        'admin_rbac_enforce' => env('WA_FEATURE_ADMIN_RBAC_ENFORCE'),
        'inbox_followup' => env('WA_FEATURE_INBOX_FOLLOWUP'),
        'quote_dedupe' => env('WA_FEATURE_QUOTE_DEDUPE'),
        'reprocess_button' => env('WA_FEATURE_REPROCESS_BUTTON'),
        'quick_replies' => env('WA_FEATURE_QUICK_REPLIES'),
        'meta_error_v2' => env('WA_FEATURE_META_ERROR_V2'),
        'send_ledger' => env('WA_FEATURE_SEND_LEDGER'),
        'send_retry' => env('WA_FEATURE_SEND_RETRY'),
        'outbound_policy_enforce' => env('WA_FEATURE_OUTBOUND_POLICY_ENFORCE'),
        'delivery_status_v2' => env('WA_FEATURE_DELIVERY_STATUS_V2'),
        'wa_event_bus' => env('WA_FEATURE_WA_EVENT_BUS'),
        'user_preferences_optout' => env('WA_FEATURE_USER_PREFERENCES_OPTOUT'),
        'wa_labels' => env('WA_FEATURE_WA_LABELS'),
        'thread_pins' => env('WA_FEATURE_THREAD_PINS'),
        'staff_translation' => env('WA_FEATURE_STAFF_TRANSLATION'),
        'message_search' => env('WA_FEATURE_MESSAGE_SEARCH'),
        'message_search_all' => env('WA_FEATURE_MESSAGE_SEARCH_ALL'),
        'conversation_export' => env('WA_FEATURE_CONVERSATION_EXPORT'),
        'csp_enforce' => env('WA_FEATURE_CSP_ENFORCE'),
        'read_receipts' => env('WA_FEATURE_READ_RECEIPTS'),
        'auto_rules' => env('WA_FEATURE_AUTO_RULES'),
        'conversational_components' => env('WA_FEATURE_CONVERSATIONAL_COMPONENTS'),
        'inbound_silence_alert' => env('WA_FEATURE_INBOUND_SILENCE_ALERT'),
        // E32
        'staff_file_send' => env('WA_FEATURE_STAFF_FILE_SEND'),
        'invoice_whatsapp_send' => env('WA_FEATURE_INVOICE_WHATSAPP_SEND'),
        'offer_card_by_media_id' => env('WA_FEATURE_OFFER_CARD_BY_MEDIA_ID'),
        'outbound_media_remote_delete' => env('WA_FEATURE_OUTBOUND_MEDIA_REMOTE_DELETE'),
        // E27: العامل الثاني والتحقق المعزّز وأرقام الإدارة وتقرير الخمول
        'staff_mfa' => env('WA_FEATURE_STAFF_MFA'),
        'step_up' => env('WA_FEATURE_STEP_UP'),
        'admin_number_mfa' => env('WA_FEATURE_ADMIN_NUMBER_MFA'),
        'idle_report' => env('WA_FEATURE_IDLE_REPORT'),
        // E31
        'wa_block' => env('WA_FEATURE_WA_BLOCK'),
        'wa_block_bsuid' => env('WA_FEATURE_WA_BLOCK_BSUID'),
        'wa_block_admin_tool' => env('WA_FEATURE_WA_BLOCK_ADMIN_TOOL'),
        'business_profile_sync' => env('WA_FEATURE_BUSINESS_PROFILE_SYNC'),
        'business_profile_photo' => env('WA_FEATURE_BUSINESS_PROFILE_PHOTO'),
        // E38
        'wa_qr_links' => env('WA_FEATURE_WA_QR_LINKS'),
        'entry_tracking' => env('WA_FEATURE_ENTRY_TRACKING'),
        'offer_story_card' => env('WA_FEATURE_OFFER_STORY_CARD'),
        'inbox_realtime' => env('WA_FEATURE_INBOX_REALTIME'),
        'connection_resilience' => env('WA_FEATURE_CONNECTION_RESILIENCE'),
        'channel_health' => env('WA_FEATURE_CHANNEL_HEALTH'),
        'channel_health_block_on_token' => env('WA_FEATURE_CHANNEL_HEALTH_BLOCK_ON_TOKEN'),
        'integrity_breaker_lock' => env('WA_FEATURE_INTEGRITY_BREAKER_LOCK'),
        // E34: رسائل الموظف الصوتية وتفريغها للزملاء
        'staff_voice_notes' => env('WA_FEATURE_STAFF_VOICE_NOTES'),
        'staff_voice_transcript' => env('WA_FEATURE_STAFF_VOICE_TRANSCRIPT'),
        // E40: الصندوق الصادر وخادم MCP
        'integration_outbox' => env('WA_FEATURE_INTEGRATION_OUTBOX'),
        'mcp_server' => env('WA_FEATURE_MCP_SERVER'),
        // E33: الاقتباس والتصحيح، وتفاعل الموظف، ومعاينة الرابط، والموقع والبطاقات
        'wa_reply_quote' => env('WA_FEATURE_WA_REPLY_QUOTE'),
        'wa_staff_reactions' => env('WA_FEATURE_WA_STAFF_REACTIONS'),
        'wa_link_preview' => env('WA_FEATURE_WA_LINK_PREVIEW'),
        'wa_share_cards' => env('WA_FEATURE_WA_SHARE_CARDS'),
        // E23
        'channel_monitor' => env('WA_FEATURE_CHANNEL_MONITOR'),
        'channel_selftest' => env('WA_FEATURE_CHANNEL_SELFTEST'),
        'wa_test_send' => env('WA_FEATURE_WA_TEST_SEND'),
        'ops_status_page' => env('WA_FEATURE_OPS_STATUS_PAGE'),
    ],
];
