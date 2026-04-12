<?php

namespace App\Infrastructure\Security;

use App\Models\Attachment;
use App\Models\AttachmentLink;

/**
 * Evaluates expiry and usability state for attachments and their share links.
 *
 * All checks are based on the current wall-clock time (now()). There is no
 * side-effect — this service only reads state, it does not mutate models.
 */
class ExpiryEvaluator
{
    /**
     * Whether an attachment has passed its validity window.
     * An attachment with no expires_at is considered permanently valid.
     */
    public function isAttachmentExpired(Attachment $attachment): bool
    {
        return $attachment->expires_at !== null
            && $attachment->expires_at->isPast();
    }

    /**
     * Whether a share link has passed its expiry timestamp.
     */
    public function isLinkExpired(AttachmentLink $link): bool
    {
        return $link->expires_at->isPast();
    }

    /**
     * Whether a single-use link has already been consumed.
     */
    public function isLinkConsumed(AttachmentLink $link): bool
    {
        return $link->is_single_use && $link->consumed_at !== null;
    }

    /**
     * Whether a link has been administratively revoked.
     */
    public function isLinkRevoked(AttachmentLink $link): bool
    {
        return $link->revoked_at !== null;
    }

    /**
     * Whether a share link can still be used to access its attachment.
     * A link is usable only if it is not expired, not consumed, and not revoked.
     */
    public function isLinkUsable(AttachmentLink $link): bool
    {
        return !$this->isLinkExpired($link)
            && !$this->isLinkConsumed($link)
            && !$this->isLinkRevoked($link);
    }
}
