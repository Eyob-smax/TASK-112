<?php

use App\Infrastructure\Security\EncryptionService;

/**
 * Unit tests for EncryptionService (AES-256-CBC).
 *
 * Uses a freshly generated key for each test run.
 */
describe('EncryptionService', function () {

    beforeEach(function () {
        // Set a valid 32-byte base64-encoded key in config for the service constructor
        $rawKey = random_bytes(32);
        config(['meridian.attachments.encryption_key' => base64_encode($rawKey)]);

        $this->service = new EncryptionService();
    });

    it('roundtrips plaintext through encrypt and decrypt unchanged', function () {
        $plaintext = 'This is the secret attachment content — 🔒';

        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt(
            $encrypted['ciphertext'],
            $encrypted['iv'],
            $encrypted['key_id'],
        );

        expect($decrypted)->toBe($plaintext);
    });

    it('produces different ciphertext for the same plaintext due to random IV', function () {
        $plaintext  = 'same content every time';
        $encrypted1 = $this->service->encrypt($plaintext);
        $encrypted2 = $this->service->encrypt($plaintext);

        // IVs must differ (different random bytes each call)
        expect($encrypted1['iv'])->not->toBe($encrypted2['iv']);
        // Ciphertexts must differ as a result
        expect($encrypted1['ciphertext'])->not->toBe($encrypted2['ciphertext']);
    });

    it('returns base64-encoded ciphertext and IV strings', function () {
        $result = $this->service->encrypt('test payload');

        expect(base64_decode($result['ciphertext'], strict: true))->not->toBeFalse();
        expect(base64_decode($result['iv'], strict: true))->not->toBeFalse();
        expect($result['key_id'])->toBe('v1');
    });

    it('throws on corrupted ciphertext during decryption', function () {
        $result = $this->service->encrypt('valid content');

        expect(fn () => $this->service->decrypt(
            'not-valid-base64!!!',
            $result['iv'],
            $result['key_id'],
        ))->toThrow(\RuntimeException::class);
    });

    it('throws when ATTACHMENT_ENCRYPTION_KEY is missing', function () {
        config(['meridian.attachments.encryption_key' => null]);

        expect(fn () => new EncryptionService())
            ->toThrow(\RuntimeException::class, 'ATTACHMENT_ENCRYPTION_KEY is not configured');
    });

    it('throws when key is not exactly 32 bytes after base64 decode', function () {
        // 16-byte key (too short for AES-256)
        config(['meridian.attachments.encryption_key' => base64_encode(random_bytes(16))]);

        expect(fn () => new EncryptionService())
            ->toThrow(\RuntimeException::class, '32-byte string');
    });

});
