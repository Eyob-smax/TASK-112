<?php

namespace App\Domain\Configuration\Enums;

enum RolloutStatus: string
{
    case Draft      = 'draft';
    case Canary     = 'canary';
    case Promoted   = 'promoted';
    case RolledBack = 'rolled_back';

    /**
     * Valid transitions from this rollout status.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft      => [self::Canary],
            self::Canary     => [self::Promoted, self::RolledBack],
            self::Promoted   => [self::RolledBack],
            self::RolledBack => [],  // Terminal state
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Whether this version is currently active (receiving traffic).
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Canary, self::Promoted => true,
            default                      => false,
        };
    }

    /**
     * Whether full promotion is allowed from this status.
     * Canary must run for at least 24 hours before promotion.
     */
    public function canPromote(): bool
    {
        return $this === self::Canary;
    }
}
