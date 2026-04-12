<?php

namespace App\Exceptions\Attachment;

/**
 * Thrown when a single-use LAN share link is resolved for a second time.
 *
 * HTTP mapping: 410 Gone (registered in bootstrap/app.php)
 */
class LinkConsumedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This share link has already been used and is no longer valid.');
    }
}
