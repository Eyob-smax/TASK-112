<?php

namespace App\Domain\Attachment\Enums;

enum AttachmentStatus: string
{
    case Active  = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /**
     * Whether this attachment is accessible for download or link generation.
     */
    public function isAccessible(): bool
    {
        return $this === self::Active;
    }
}
