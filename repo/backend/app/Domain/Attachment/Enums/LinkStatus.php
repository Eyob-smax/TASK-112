<?php

namespace App\Domain\Attachment\Enums;

enum LinkStatus: string
{
    case Active   = 'active';
    case Consumed = 'consumed';
    case Expired  = 'expired';
    case Revoked  = 'revoked';

    /**
     * Whether this link can still be used to download an attachment.
     */
    public function isUsable(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether this link has been permanently terminated (consumed, expired, or revoked).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Consumed, self::Expired, self::Revoked], strict: true);
    }
}
