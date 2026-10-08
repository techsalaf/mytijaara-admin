<?php

return [
    'enabled' => env('WHATSAPP_FLOW_ONBOARDING_ENABLED', false),
    'test_phones' => array_filter(explode(',', env('WHATSAPP_FLOW_TEST_PHONES', ''))),
    'rollout_percent' => (int) env('WHATSAPP_FLOW_ROLLOUT_PERCENT', 0),
    'fallback_to_chat' => env('WHATSAPP_FLOW_CHAT_FALLBACK', true),
    'flow_id' => env('WHATSAPP_VENDOR_FLOW_ID', ''),
    'definition_version' => 'vendor-onboarding-2026-10-07.1',
    'mode' => env('WHATSAPP_VENDOR_FLOW_MODE', 'draft'),
    'graph_version' => env('WHATSAPP_FLOW_GRAPH_VERSION', 'v26.0'),
    'session_minutes' => 60,
    'cleanup_schedule' => 'disabled',
    'abandonment_hours' => 24,
    'analytics_retention_days' => null,
    'private_root_configured' => (bool) env('WHATSAPP_FLOW_PRIVATE_ROOT'),
    'private_root' => env('WHATSAPP_FLOW_PRIVATE_ROOT', sys_get_temp_dir().'/mytijaara-flow-private/'.hash('sha256', base_path())),
    'max_image_bytes' => 2097152, 'max_document_bytes' => 2097152, 'max_dimension' => 6000, 'max_pixels' => 16000000,
    'private_key_path' => env('WHATSAPP_FLOW_PRIVATE_KEY_PATH', ''),
    'private_key_passphrase' => env('WHATSAPP_FLOW_PRIVATE_KEY_PASSPHRASE', ''),
    'endpoint_url' => env('WHATSAPP_FLOW_ENDPOINT_URL', ''),
    'sync_name' => 'MyTijaara vendor onboarding',
];
