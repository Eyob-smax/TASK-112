<?php

namespace App\Infrastructure\Security;

use RuntimeException;

/**
 * AES-256-CBC encryption service for attachment payloads.
 *
 * Key source: ATTACHMENT_ENCRYPTION_KEY env var (base64-encoded 32 bytes).
 * This service intentionally does NOT use Laravel's APP_KEY or Crypt facade —
 * attachment encryption must remain independent of the application key so that
 * key rotation can be managed separately.
 */
class EncryptionService
{
    private const CIPHER = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    /**
     * Key registry for rotation support.
     * Format: ['v1' => <raw 32-byte string>, ...]
     */
    private array $keys;

    public function __construct()
    {
        $encoded = config('meridian.attachments.encryption_key');

        if (empty($encoded)) {
            throw new RuntimeException(
                'ATTACHMENT_ENCRYPTION_KEY is not configured. ' .
                'Generate one with: php -r "echo base64_encode(random_bytes(32));"'
            );
        }

        $rawKey = base64_decode($encoded, strict: true);

        if ($rawKey === false || strlen($rawKey) !== 32) {
            throw new RuntimeException(
                'ATTACHMENT_ENCRYPTION_KEY must be a base64-encoded 32-byte string.'
            );
        }

        $this->keys = ['v1' => $rawKey];
    }

    /**
     * Encrypt plaintext using AES-256-CBC with a random IV.
     *
     * @return array{ciphertext: string, iv: string, key_id: string}
     *              All values are base64-encoded strings safe for storage.
     */
    public function encrypt(string $plaintext): array
    {
        $iv         = random_bytes(self::IV_LENGTH);
        $key        = $this->resolveKey('v1');
        $encrypted  = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return [
            'ciphertext' => base64_encode($encrypted),
            'iv'         => base64_encode($iv),
            'key_id'     => 'v1',
        ];
    }

    /**
     * Decrypt a ciphertext produced by encrypt().
     *
     * @param string $ciphertext Base64-encoded ciphertext
     * @param string $iv         Base64-encoded IV
     * @param string $keyId      Key identifier used during encryption
     */
    public function decrypt(string $ciphertext, string $iv, string $keyId): string
    {
        $key       = $this->resolveKey($keyId);
        $rawIv     = base64_decode($iv, strict: true);
        $rawCipher = base64_decode($ciphertext, strict: true);

        if ($rawIv === false || $rawCipher === false) {
            throw new RuntimeException('Invalid base64 encoding in ciphertext or IV.');
        }

        $decrypted = openssl_decrypt($rawCipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $rawIv);

        if ($decrypted === false) {
            throw new RuntimeException('Decryption failed: ' . openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * The currently active key ID (used when encrypting new payloads).
     */
    public function getActiveKeyId(): string
    {
        return 'v1';
    }

    /**
     * Resolve a raw encryption key by its ID.
     */
    private function resolveKey(string $keyId): string
    {
        if (!isset($this->keys[$keyId])) {
            throw new RuntimeException("Unknown encryption key ID: {$keyId}");
        }

        return $this->keys[$keyId];
    }
}
