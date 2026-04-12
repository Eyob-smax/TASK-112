<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meridian Application Configuration
    |--------------------------------------------------------------------------
    | Central home for all Meridian-specific business rule configuration.
    | Values are sourced from environment variables with safe defaults.
    */

    /*
    |--------------------------------------------------------------------------
    | LAN Integration
    |--------------------------------------------------------------------------
    | LAN_BASE_URL: The host:port accessible to LAN clients for link resolution.
    | All generated attachment share links use this base URL.
    | Must not be a public internet URL.
    */
    'lan_base_url' => env('LAN_BASE_URL', 'http://localhost:8000'),

    /*
    |--------------------------------------------------------------------------
    | Attachment Constraints
    |--------------------------------------------------------------------------
    */
    'attachments' => [
        // Maximum file size per attachment in bytes (25 MB)
        'max_size_bytes'         => (int) env('ATTACHMENT_MAX_SIZE_MB', 25) * 1024 * 1024,
        // Maximum number of files per business record
        'max_files_per_record'   => (int) env('ATTACHMENT_MAX_FILES_PER_RECORD', 20),
        // Default validity period in days (evidence auto-expires after this)
        'default_validity_days'  => (int) env('ATTACHMENT_VALIDITY_DAYS', 365),
        // Maximum validity period in days (upper bound for request validation — configurable for operational policies)
        'max_validity_days'      => (int) env('ATTACHMENT_MAX_VALIDITY_DAYS', 3650),
        // Accepted MIME types — do not modify without also updating FileConstraints value object
        'allowed_mime_types'     => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/png',
            'image/jpeg',
        ],
        // Encryption key for attachment payloads (separate from APP_KEY)
        'encryption_key'         => env('ATTACHMENT_ENCRYPTION_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LAN Share Link Constraints
    |--------------------------------------------------------------------------
    */
    'links' => [
        // Hard maximum TTL in hours — never exceed 72
        'max_ttl_hours' => (int) env('LINK_MAX_TTL_HOURS', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication & Lockout Policy
    |--------------------------------------------------------------------------
    */
    'auth' => [
        'max_failed_attempts' => (int) env('AUTH_MAX_FAILED_ATTEMPTS', 5),
        'lockout_minutes'     => (int) env('AUTH_LOCKOUT_MINUTES', 15),
        // Password complexity rules (enforced in PasswordPolicy value object)
        'password_min_length' => 12,
        'password_require_uppercase' => true,
        'password_require_lowercase' => true,
        'password_require_digit'     => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Canary Rollout Policy
    |--------------------------------------------------------------------------
    */
    'canary' => [
        // Maximum percentage of eligible targets for canary rollout (hard cap)
        'max_percent'            => (float) env('CANARY_MAX_PERCENT', 10),
        // Minimum hours a canary must run before full promotion is allowed
        'min_promotion_hours'    => (int) env('CANARY_MIN_PROMOTION_HOURS', 24),
        // Server-authoritative count of eligible store targets (set per deployment).
        // Used as the denominator for 10% cap enforcement when target_type = 'store'.
        // Client-provided eligible_count is never trusted.
        'store_count'            => (int) env('CANARY_STORE_COUNT', 0),
        // Server-authoritative store target IDs for store-level canary rollouts.
        // Required for validating target_ids integrity on store rollouts.
        'store_ids'              => array_values(array_filter(array_map(
            static fn (string $id): string => trim($id),
            explode(',', (string) env('CANARY_STORE_IDS', ''))
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow / Approval SLA Defaults
    |--------------------------------------------------------------------------
    */
    'workflow' => [
        // Default SLA in business days (Monday–Friday) per approval node
        'default_sla_business_days' => (int) env('WORKFLOW_SLA_BUSINESS_DAYS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sales and Returns Policy
    |--------------------------------------------------------------------------
    */
    'sales' => [
        // Default restock fee percentage for non-defective returns within qualifying window
        'restock_fee_default_percent' => (float) env('RESTOCK_FEE_DEFAULT_PERCENT', 10),
        // Qualifying return window in days
        'restock_fee_qualifying_days' => (int) env('RESTOCK_FEE_QUALIFYING_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup and Retention
    |--------------------------------------------------------------------------
    */
    'backup' => [
        'retention_days'   => (int) env('BACKUP_RETENTION_DAYS', 14),
        'schedule_time'    => env('BACKUP_SCHEDULE_TIME', '02:00'),
    ],

    'retention' => [
        'metrics_days'     => (int) env('METRICS_RETENTION_DAYS', 90),
        'log_days'         => (int) env('LOG_RETENTION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        // How long to cache idempotency key responses (hours)
        'ttl_hours' => 24,
    ],

];
