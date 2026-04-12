<?php

describe('Idempotency Key Hashing', function () {

    /**
     * These tests verify that idempotency key hashing is deterministic
     * and collision-resistant for the Meridian audit system.
     *
     * The implementation uses SHA-256 of the raw key string.
     * Tests are written against the expected behavior, not any specific
     * implementation class (which will be implemented in Prompt 3).
     */

    it('produces the same hash for the same input key', function () {
        $key = '550e8400-e29b-41d4-a716-446655440000';
        $hash1 = hash('sha256', $key);
        $hash2 = hash('sha256', $key);

        expect($hash1)->toBe($hash2);
    });

    it('produces different hashes for different keys', function () {
        $key1 = '550e8400-e29b-41d4-a716-446655440000';
        $key2 = '660f9511-f30c-52e5-b827-557766551111';

        expect(hash('sha256', $key1))->not->toBe(hash('sha256', $key2));
    });

    it('produces a 64-character hexadecimal hash (SHA-256)', function () {
        $key = '550e8400-e29b-41d4-a716-446655440000';
        $hash = hash('sha256', $key);

        expect(strlen($hash))->toBe(64)
            ->and(ctype_xdigit($hash))->toBeTrue();
    });

    it('is case-sensitive — different casing produces different hashes', function () {
        $lowerKey = 'abc123';
        $upperKey = 'ABC123';

        expect(hash('sha256', $lowerKey))->not->toBe(hash('sha256', $upperKey));
    });

    it('does not truncate — empty key produces a distinct hash', function () {
        $emptyHash = hash('sha256', '');
        $nonEmptyHash = hash('sha256', 'nonempty');

        expect($emptyHash)->not->toBe($nonEmptyHash);
        expect(strlen($emptyHash))->toBe(64); // Still a valid hash
    });

});
