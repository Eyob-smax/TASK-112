<?php

namespace App\Exceptions\Attachment;

class InvalidMimeTypeException extends \RuntimeException
{
    public function __construct(string $declaredMime, string $detectedMime)
    {
        parent::__construct(
            "MIME type mismatch: declared [{$declaredMime}] does not match detected content type [{$detectedMime}]."
        );
    }
}
