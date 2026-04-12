<?php

namespace App\Domain\Sales\Enums;

enum SalesStatus: string
{
    case Draft     = 'draft';
    case Reviewed  = 'reviewed';
    case Completed = 'completed';
    case Voided    = 'voided';

    /**
     * Whether this document can still be edited (line items, notes, etc.).
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /**
     * Whether outbound linkage is permitted for this document.
     * Outbound linkage requires final approval (completed state).
     */
    public function allowsOutboundLinkage(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether a return/exchange can be initiated against this document.
     */
    public function allowsReturn(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Whether this document can be voided.
     */
    public function canBeVoided(): bool
    {
        return match ($this) {
            self::Completed => false,  // Completed documents cannot be voided
            self::Voided    => false,  // Already voided
            default         => true,
        };
    }

    /**
     * Valid transitions from this status.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Reviewed, self::Voided],
            self::Reviewed  => [self::Completed, self::Voided],
            self::Completed => [],  // Terminal — returns/exchanges handled separately
            self::Voided    => [],  // Terminal
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
