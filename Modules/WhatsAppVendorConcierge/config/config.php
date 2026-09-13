<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Meta WhatsApp Business Platform / Cloud API
    |
    */

    'api' => [
        'version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'base_url' => 'https://graph.facebook.com/',
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_id' => env('WHATSAPP_APP_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'webhook_url' => env('WHATSAPP_WEBHOOK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    */

    'webhook' => [
        'path' => 'webhooks/whatsapp',
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'enable_signature_validation' => true,
        'signature_header' => 'X-Hub-Signature-256',
    ],

    /*
    |--------------------------------------------------------------------------
    | Messaging Configuration
    |--------------------------------------------------------------------------
    */

    'messaging' => [
        'default_locale' => 'en',
        'supported_locales' => ['en', 'yo', 'ha', 'ig'], // English, Yoruba, Hausa, Igbo
        'max_message_length' => 4096,
        'retry_attempts' => 3,
        'retry_delay' => 1000, // ms
        'rate_limit' => [
            'messages_per_second' => 80, // Meta limit
            'burst_allowance' => 1000,
        ],
        'templates' => [
            'approved' => env('WHATSAPP_TEMPLATE_VENDOR_APPROVED', 'vendor_application_approved'),
            'denied' => env('WHATSAPP_TEMPLATE_VENDOR_DENIED', 'vendor_application_denied'),
            'suspended' => env('WHATSAPP_TEMPLATE_VENDOR_SUSPENDED', 'vendor_store_suspended'),
            'unsuspended' => env('WHATSAPP_TEMPLATE_VENDOR_UNSUSPENDED', 'vendor_store_reactivated'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Configuration
    |--------------------------------------------------------------------------
    */

    'media' => [
        // Meta downloads can contain KYC. Keep the original outside the web root;
        // selected public branding is copied to its canonical store path separately.
        'storage_disk' => env('WHATSAPP_MEDIA_DISK', 'local'),
        'storage_path' => 'whatsapp/media',
        'max_file_size' => 100 * 1024 * 1024, // 100MB (Meta limit)
        'allowed_mime_types' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'video/mp4',
            'video/3gpp',
            'audio/mpeg',
            'audio/ogg',
            'audio/amr',
        ],
        'cleanup_after_days' => 30,
        'download_timeout' => 30, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding Configuration
    |--------------------------------------------------------------------------
    */

    'onboarding' => [
        'session_ttl_minutes' => 60 * 24 * 7, // 7 days
        'max_inactive_minutes' => 30,
        'flow_version' => '1.0',
        'steps' => [
            'welcome',
            'business_basics',
            'module_selection',
            'location',
            'zone_selection',
            'pickup_zone_selection',
            'contact_info',
            'operating_hours',
            'delivery_time',
            'owner_info',
            'account_password',
            'store_branding',
            'cover_branding',
            'business_plan',
            'subscription_package',
            'terms_acceptance',
            'privacy_acceptance',
            'kyc_documents',
            'review_submit',
        ],
        'required_fields' => [
            'business_name',
            'category_id',
            'latitude',
            'longitude',
            'address',
            'phone',
            'email',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Concierge Configuration
    |--------------------------------------------------------------------------
    */

    'ai' => [
        'enabled' => true,
        'provider' => env('WHATSAPP_AI_PROVIDER', 'openai'), // openai, anthropic
        'model' => env('WHATSAPP_AI_MODEL', 'gpt-4o'),
        'max_tokens' => 2000,
        'temperature' => 0.3,
        'conversation_history_limit' => 20,
        'tool_confirmation_required' => true,
        'fallback_to_deterministic' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'connection' => env('QUEUE_CONNECTION_WHATSAPP', 'database'),
        'jobs' => [
            'process_incoming' => 'whatsapp.process_incoming',
            'send_message' => 'whatsapp.send_message',
            'process_media' => 'whatsapp.process_media',
            'process_document' => 'whatsapp.process_document',
            'run_ai_conversation' => 'whatsapp.run_ai_conversation',
            'process_onboarding' => 'whatsapp.process_onboarding',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    */

    'security' => [
        'require_verification_for_writes' => true,
        'verification_ttl_minutes' => 10,
        'max_verification_attempts' => 3,
        'audit_log_retention_days' => 90,
        'encrypt_sensitive_data' => true,
        // Password-link failures are bounded separately from route throttling.
        // Reaching this limit revokes the hashed credential token.
        'credential_token_max_attempts' => (int) env('WHATSAPP_CREDENTIAL_TOKEN_MAX_ATTEMPTS', 5),
        'credential_token_rate_limit_per_minute' => (int) env('WHATSAPP_CREDENTIAL_TOKEN_RATE_LIMIT_PER_MINUTE', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */

    'features' => [
        'ai_concierge' => true,
        // These stay off until their canonical core services replace the legacy
        // controller/model writes. Dashboard operations remain available.
        'product_creation' => false,
        'order_management' => false,
        'shop_management' => true,
        'document_ocr' => false, // Requires external OCR service
        'human_handoff' => true,
        'analytics_events' => true,
        'field_agent_attribution' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Templates (for WhatsApp Template Messages)
    |--------------------------------------------------------------------------
    */

    'templates' => [
        'vendor_approved' => 'vendor_approved_notification',
        'vendor_rejected' => 'vendor_rejected_notification',
        'onboarding_resume' => 'onboarding_resume_reminder',
        'document_request' => 'document_upload_request',
        'order_notification' => 'vendor_order_notification',
    ],

];
