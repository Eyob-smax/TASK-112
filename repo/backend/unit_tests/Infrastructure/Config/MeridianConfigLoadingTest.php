<?php

/**
 * Unit tests for config/meridian.php — default values and env-var loading.
 *
 * These tests verify that every business-critical configuration key:
 *   1. Has a safe, documented default when the env var is absent.
 *   2. Can be overridden via config() in tests (mimicking env override).
 *
 * No database access required. The Laravel application is booted, so
 * config() resolves correctly from config/meridian.php.
 *
 * These tests are important for Prompt 9 (Docker hardening): if a container
 * starts without an env file, the system must use sane defaults rather than
 * failing silently or using null.
 */
describe('Meridian Config — defaults and env-var loading', function () {

    // -------------------------------------------------------------------------
    // Backup and retention
    // -------------------------------------------------------------------------

    it('backup retention defaults to 14 days', function () {
        expect(config('meridian.backup.retention_days'))->toBe(14);
    });

    it('metrics retention defaults to 90 days', function () {
        expect(config('meridian.retention.metrics_days'))->toBe(90);
    });

    it('log retention defaults to 90 days', function () {
        expect(config('meridian.retention.log_days'))->toBe(90);
    });

    it('backup schedule time defaults to 02:00', function () {
        expect(config('meridian.backup.schedule_time'))->toBe('02:00');
    });

    // -------------------------------------------------------------------------
    // Attachment constraints
    // -------------------------------------------------------------------------

    it('attachment max size defaults to 25 MB in bytes (26,214,400 bytes)', function () {
        // 25 * 1024 * 1024 = 26214400
        expect(config('meridian.attachments.max_size_bytes'))->toBe(26_214_400);
    });

    it('attachment max files per record defaults to 20', function () {
        expect(config('meridian.attachments.max_files_per_record'))->toBe(20);
    });

    it('attachment default validity defaults to 365 days', function () {
        expect(config('meridian.attachments.default_validity_days'))->toBe(365);
    });

    it('attachment allowed MIME types include the five required types', function () {
        $allowed = config('meridian.attachments.allowed_mime_types');

        expect($allowed)->toContain('application/pdf');
        expect($allowed)->toContain('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        expect($allowed)->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        expect($allowed)->toContain('image/png');
        expect($allowed)->toContain('image/jpeg');
        expect(count($allowed))->toBe(5);
    });

    // -------------------------------------------------------------------------
    // Link sharing
    // -------------------------------------------------------------------------

    it('link max TTL defaults to 72 hours', function () {
        expect(config('meridian.links.max_ttl_hours'))->toBe(72);
    });

    // -------------------------------------------------------------------------
    // Authentication and lockout
    // -------------------------------------------------------------------------

    it('auth max failed attempts defaults to 5', function () {
        expect(config('meridian.auth.max_failed_attempts'))->toBe(5);
    });

    it('auth lockout duration defaults to 15 minutes', function () {
        expect(config('meridian.auth.lockout_minutes'))->toBe(15);
    });

    it('password minimum length is 12 characters', function () {
        expect(config('meridian.auth.password_min_length'))->toBe(12);
    });

    it('password requires uppercase, lowercase, and a digit', function () {
        expect(config('meridian.auth.password_require_uppercase'))->toBeTrue();
        expect(config('meridian.auth.password_require_lowercase'))->toBeTrue();
        expect(config('meridian.auth.password_require_digit'))->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // Canary rollout
    // -------------------------------------------------------------------------

    it('canary max percent defaults to 10', function () {
        // Stored as float; 10% cap hard-coded in requirements
        expect((float) config('meridian.canary.max_percent'))->toBe(10.0);
    });

    it('canary minimum promotion hours defaults to 24', function () {
        expect(config('meridian.canary.min_promotion_hours'))->toBe(24);
    });

    it('canary store count defaults to 0 (fail-closed until deployment sets it)', function () {
        expect(config('meridian.canary.store_count'))->toBe(0);
    });

    it('canary store IDs default to an empty array', function () {
        expect(config('meridian.canary.store_ids'))->toBeArray();
        expect(config('meridian.canary.store_ids'))->toBe([]);
    });

    // -------------------------------------------------------------------------
    // Workflow SLA
    // -------------------------------------------------------------------------

    it('workflow default SLA is 2 business days', function () {
        expect(config('meridian.workflow.default_sla_business_days'))->toBe(2);
    });

    // -------------------------------------------------------------------------
    // Sales and returns
    // -------------------------------------------------------------------------

    it('restock fee defaults to 10 percent', function () {
        expect((float) config('meridian.sales.restock_fee_default_percent'))->toBe(10.0);
    });

    it('restock fee qualifying window defaults to 30 days', function () {
        expect(config('meridian.sales.restock_fee_qualifying_days'))->toBe(30);
    });

    // -------------------------------------------------------------------------
    // LAN base URL
    // -------------------------------------------------------------------------

    it('lan_base_url has a non-empty default', function () {
        // phpunit.xml sets LAN_BASE_URL=http://localhost:8000
        $url = config('meridian.lan_base_url');
        expect($url)->not->toBeEmpty();
        expect(filter_var($url, FILTER_VALIDATE_URL))->not->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Idempotency
    // -------------------------------------------------------------------------

    it('idempotency TTL defaults to 24 hours', function () {
        expect(config('meridian.idempotency.ttl_hours'))->toBe(24);
    });

    it('.env.example documents canary store count variable', function () {
        $envExample = file_get_contents(base_path('.env.example'));

        expect($envExample)->not->toBeFalse();
        expect($envExample)->toContain('CANARY_STORE_COUNT=');
        expect($envExample)->toContain('CANARY_STORE_IDS=');
    });

    // -------------------------------------------------------------------------
    // Config overridability
    // -------------------------------------------------------------------------

    it('backup retention can be overridden via config() in tests', function () {
        config(['meridian.backup.retention_days' => 30]);

        expect(config('meridian.backup.retention_days'))->toBe(30);

        // Restore default for subsequent tests
        config(['meridian.backup.retention_days' => 14]);
    });

    it('all config keys exist and return non-null values', function () {
        $keys = [
            'meridian.backup.retention_days',
            'meridian.retention.metrics_days',
            'meridian.retention.log_days',
            'meridian.backup.schedule_time',
            'meridian.attachments.max_size_bytes',
            'meridian.attachments.max_files_per_record',
            'meridian.attachments.default_validity_days',
            'meridian.attachments.allowed_mime_types',
            'meridian.links.max_ttl_hours',
            'meridian.auth.max_failed_attempts',
            'meridian.auth.lockout_minutes',
            'meridian.auth.password_min_length',
            'meridian.canary.max_percent',
            'meridian.canary.min_promotion_hours',
            'meridian.canary.store_count',
            'meridian.canary.store_ids',
            'meridian.workflow.default_sla_business_days',
            'meridian.sales.restock_fee_default_percent',
            'meridian.sales.restock_fee_qualifying_days',
            'meridian.idempotency.ttl_hours',
        ];

        foreach ($keys as $key) {
            expect(config($key))->not->toBeNull("Config key '{$key}' must not be null");
        }
    });

});
