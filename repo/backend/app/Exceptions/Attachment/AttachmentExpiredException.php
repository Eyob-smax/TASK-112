<?php

namespace App\Exceptions\Attachment;

/**
 * Thrown when an operation targets an attachment that has passed its validity window.
 *
 * HTTP mapping: 410 Gone (registered in bootstrap/app.php)
 */
class AttachmentExpiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Attachment has expired and is no longer accessible.');
    }
}
