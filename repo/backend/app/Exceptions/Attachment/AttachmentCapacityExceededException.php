<?php

namespace App\Exceptions\Attachment;

/**
 * Thrown when uploading an attachment would exceed the per-record file count limit.
 *
 * HTTP mapping: 422 Unprocessable Entity (registered in bootstrap/app.php)
 */
class AttachmentCapacityExceededException extends \RuntimeException
{
    public function __construct(int $limit = 20)
    {
        parent::__construct("Record already has the maximum of {$limit} attachments.");
    }
}
