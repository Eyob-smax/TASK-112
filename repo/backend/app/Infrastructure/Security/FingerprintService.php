<?php

namespace App\Infrastructure\Security;

/**
 * SHA-256 fingerprinting for attachment deduplication and integrity verification.
 *
 * The fingerprint is a 64-character lowercase hex string — identical to
 * what the sha256_fingerprint column stores in the attachments table.
 */
class FingerprintService
{
    /**
     * Compute a SHA-256 fingerprint from an in-memory string.
     *
     * @return string 64-character lowercase hex string
     */
    public function compute(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Compute a SHA-256 fingerprint by streaming from a file path.
     * Preferred over compute() for large files to avoid memory exhaustion.
     *
     * @param string $absolutePath Absolute filesystem path to the file
     * @return string 64-character lowercase hex string
     */
    public function computeFromPath(string $absolutePath): string
    {
        $result = hash_file('sha256', $absolutePath);

        if ($result === false) {
            throw new \RuntimeException("Unable to compute fingerprint for path: {$absolutePath}");
        }

        return $result;
    }

    /**
     * Verify that a content string matches a known fingerprint.
     * Uses constant-time comparison to prevent timing attacks.
     */
    public function verify(string $content, string $expectedFingerprint): bool
    {
        return hash_equals($expectedFingerprint, $this->compute($content));
    }

    /**
     * Verify a file at the given path against a known fingerprint.
     */
    public function verifyPath(string $absolutePath, string $expectedFingerprint): bool
    {
        return hash_equals($expectedFingerprint, $this->computeFromPath($absolutePath));
    }
}
