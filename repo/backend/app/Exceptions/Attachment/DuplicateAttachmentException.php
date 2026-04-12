<?php

namespace App\Exceptions\Attachment;

/**
 * Thrown when a file with an identical SHA-256 fingerprint already exists.
 *
 * HTTP mapping: 409 Conflict (registered in bootstrap/app.php)
 *
 * Deduplication is global — the fingerprint check is not scoped to a single record.
 */
class DuplicateAttachmentException extends \RuntimeException
{
    public function __construct(string $fingerprint)
    {
        parent::__construct(
            "An attachment with identical content already exists (SHA-256: {$fingerprint})."
        );
    }
}
