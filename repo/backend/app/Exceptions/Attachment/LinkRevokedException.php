<?php

namespace App\Exceptions\Attachment;

/**
 * Thrown when a LAN share link has been administratively revoked.
 *
 * HTTP mapping: 410 Gone (registered in bootstrap/app.php)
 */
class LinkRevokedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This share link has been revoked.');
    }
}
