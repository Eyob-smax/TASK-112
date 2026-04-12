<?php

namespace App\Exceptions\Attachment;

/**
 * Thrown when a LAN share link is resolved after its expiry timestamp has passed.
 *
 * HTTP mapping: 410 Gone (registered in bootstrap/app.php)
 */
class LinkExpiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This share link has expired.');
    }
}
