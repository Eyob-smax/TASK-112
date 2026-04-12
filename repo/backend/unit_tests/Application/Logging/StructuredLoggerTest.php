<?php

use App\Application\Logging\StructuredLogger;

/**
 * Unit tests for StructuredLogger::sanitize().
 *
 * sanitize() is the critical security method — it must strip all sensitive
 * fields from context before they are persisted to the structured_logs table.
 *
 * These tests verify the sanitize() method directly without database interaction.
 */
describe('StructuredLogger — sanitize()', function () {

    beforeEach(function () {
        $this->logger = new StructuredLogger();
    });

    it('redacts the password key', function () {
        $result = $this->logger->sanitize(['password' => 'super-secret-123']);

        expect($result['password'])->toBe('[REDACTED]');
    });

    it('redacts the token key', function () {
        $result = $this->logger->sanitize(['token' => 'sanctum-bearer-token']);

        expect($result['token'])->toBe('[REDACTED]');
    });

    it('redacts the secret key', function () {
        $result = $this->logger->sanitize(['secret' => 'my-app-secret']);

        expect($result['secret'])->toBe('[REDACTED]');
    });

    it('redacts the api_key key', function () {
        $result = $this->logger->sanitize(['api_key' => 'key-value-here']);

        expect($result['api_key'])->toBe('[REDACTED]');
    });

    it('redacts the authorization key (case-insensitive)', function () {
        $lower = $this->logger->sanitize(['authorization' => 'Bearer token123']);
        $upper = $this->logger->sanitize(['Authorization' => 'Bearer token123']);
        $mixed = $this->logger->sanitize(['AUTHORIZATION' => 'Bearer token123']);

        expect($lower['authorization'])->toBe('[REDACTED]');
        expect($upper['Authorization'])->toBe('[REDACTED]');
        expect($mixed['AUTHORIZATION'])->toBe('[REDACTED]');
    });

    it('redacts keys that contain sensitive substrings', function () {
        // 'encryption_key' contains 'key'
        $result = $this->logger->sanitize(['encryption_key' => 'raw-key-value']);
        expect($result['encryption_key'])->toBe('[REDACTED]');

        // 'reset_token' contains 'token'
        $result = $this->logger->sanitize(['reset_token' => 'abc123']);
        expect($result['reset_token'])->toBe('[REDACTED]');

        // 'user_password_hash' contains 'password'
        $result = $this->logger->sanitize(['user_password_hash' => '$2y$10$...']);
        expect($result['user_password_hash'])->toBe('[REDACTED]');
    });

    it('preserves unrelated keys unchanged', function () {
        $context = [
            'user_id'   => 'abc-123',
            'action'    => 'document_created',
            'document'  => 'DOC-001',
            'ip'        => '192.168.1.100',
        ];

        $result = $this->logger->sanitize($context);

        expect($result)->toBe($context);
    });

    it('handles an empty context array', function () {
        expect($this->logger->sanitize([]))->toBe([]);
    });

    it('preserves non-sensitive values of various types', function () {
        $result = $this->logger->sanitize([
            'count'   => 42,
            'active'  => true,
            'tags'    => ['a', 'b'],
            'message' => 'all good',
        ]);

        expect($result['count'])->toBe(42);
        expect($result['active'])->toBeTrue();
        expect($result['tags'])->toBe(['a', 'b']);
        expect($result['message'])->toBe('all good');
    });

    it('handles mixed sensitive and non-sensitive keys', function () {
        $result = $this->logger->sanitize([
            'username'  => 'jdoe',         // safe
            'password'  => 'secret123',    // redacted
            'action'    => 'login',        // safe
            'token'     => 'bearer-xyz',   // redacted
        ]);

        expect($result['username'])->toBe('jdoe');
        expect($result['password'])->toBe('[REDACTED]');
        expect($result['action'])->toBe('login');
        expect($result['token'])->toBe('[REDACTED]');
    });

    it('recursively redacts sensitive keys inside nested arrays', function () {
        $result = $this->logger->sanitize([
            'user'    => 'jdoe',
            'request' => [
                'token'    => 'inner-bearer-token',
                'endpoint' => '/api/v1/auth/login',
            ],
        ]);

        expect($result['user'])->toBe('jdoe');
        expect($result['request']['token'])->toBe('[REDACTED]');
        expect($result['request']['endpoint'])->toBe('/api/v1/auth/login');
    });

    it('recursively redacts sensitive keys at arbitrary nesting depth', function () {
        $result = $this->logger->sanitize([
            'level1' => [
                'level2' => [
                    'password' => 'deep-secret',
                    'safe_key' => 'visible',
                ],
            ],
        ]);

        expect($result['level1']['level2']['password'])->toBe('[REDACTED]');
        expect($result['level1']['level2']['safe_key'])->toBe('visible');
    });

    it('does not redact values in nested arrays when the parent key is not sensitive', function () {
        $result = $this->logger->sanitize([
            'metadata' => [
                'user_id' => 'abc-123',
                'action'  => 'document_created',
            ],
        ]);

        // Neither 'metadata' nor its children are sensitive
        expect($result['metadata']['user_id'])->toBe('abc-123');
        expect($result['metadata']['action'])->toBe('document_created');
    });

});
