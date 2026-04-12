<?php

namespace App\Exceptions\Attachment;

/**
 * Thrown when an operation targets an attachment that has been administratively revoked.
 *
 * HTTP mapping: 410 Gone (registered in bootstrap/app.php)
 */
class AttachmentRevokedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Attachment has been revoked.');
    }
}
