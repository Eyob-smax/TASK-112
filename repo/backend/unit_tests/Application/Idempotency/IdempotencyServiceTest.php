<?php

use App\Application\Idempotency\IdempotencyService;

/**
 * Unit tests for IdempotencyService.
 *
 * Tests that do not hit the database (hashKey, isValidKey) run without
 * database setup. getCachedResponse/storeResponse tests require DB.
 */
describe('IdempotencyService', function () {

    beforeEach(function () {
        $this->service = new IdempotencyService();
    });

    // -------------------------------------------------------------------------
    // hashKey
    // -------------------------------------------------------------------------

    it('hashKey produces a consistent SHA-256 hex string for the same input', function () {
        $key  = '550e8400-e29b-41d4-a716-446655440000';
        $hash1 = $this->service->hashKey($key);
        $hash2 = $this->service->hashKey($key);

        expect($hash1)->toBe($hash2);
    });

    it('hashKey produces exactly 64 hex characters', function () {
        $hash = $this->service->hashKey('any-key-value');

        expect(strlen($hash))->toBe(64);
        expect(ctype_xdigit($hash))->toBeTrue();
    });

    it('hashKey produces different hashes for different keys', function () {
        $hash1 = $this->service->hashKey('key-one');
        $hash2 = $this->service->hashKey('key-two');

        expect($hash1)->not->toBe($hash2);
    });

    it('hashKey is case-sensitive — different cases produce different hashes', function () {
        $hash1 = $this->service->hashKey('MyKey');
        $hash2 = $this->service->hashKey('mykey');

        expect($hash1)->not->toBe($hash2);
    });

    // -------------------------------------------------------------------------
    // isValidKey — UUID v4 format validation
    // -------------------------------------------------------------------------

    it('accepts a valid UUID v4 lowercase', function () {
        expect($this->service->isValidKey('550e8400-e29b-41d4-a716-446655440000'))->toBeTrue();
    });

    it('accepts a valid UUID v4 uppercase', function () {
        expect($this->service->isValidKey('550E8400-E29B-41D4-A716-446655440000'))->toBeTrue();
    });

    it('accepts a UUID with version 4 marker', function () {
        // Version 4: third group starts with 4
        expect($this->service->isValidKey('f47ac10b-58cc-4372-a567-0e02b2c3d479'))->toBeTrue();
    });

    it('rejects an empty string', function () {
        expect($this->service->isValidKey(''))->toBeFalse();
    });

    it('rejects a plain string', function () {
        expect($this->service->isValidKey('not-a-uuid'))->toBeFalse();
    });

    it('rejects a UUID without hyphens', function () {
        expect($this->service->isValidKey('550e8400e29b41d4a716446655440000'))->toBeFalse();
    });

    it('rejects a UUID v1 (version marker is 1, not 4)', function () {
        // Third group starts with 1
        expect($this->service->isValidKey('550e8400-e29b-11d4-a716-446655440000'))->toBeFalse();
    });

    it('rejects a UUID with wrong variant bits in fourth group', function () {
        // Fourth group must start with 8, 9, a, or b
        expect($this->service->isValidKey('550e8400-e29b-41d4-c716-446655440000'))->toBeFalse();
    });

});
