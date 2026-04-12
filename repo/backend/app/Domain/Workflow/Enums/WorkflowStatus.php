<?php

namespace App\Domain\Workflow\Enums;

enum WorkflowStatus: string
{
    case Pending    = 'pending';
    case InProgress = 'in_progress';
    case Approved   = 'approved';
    case Rejected   = 'rejected';
    case Withdrawn  = 'withdrawn';
    case Expired    = 'expired';

    /**
     * Terminal states — no further transitions possible.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Approved, self::Rejected,
            self::Withdrawn, self::Expired => true,
            default                        => false,
        };
    }

    /**
     * Whether the workflow/node is still in an actionable state.
     */
    public function isActionable(): bool
    {
        return match ($this) {
            self::Pending, self::InProgress => true,
            default                          => false,
        };
    }

    /**
     * Whether withdrawal is permitted in this state.
     * Withdrawal is only allowed before final approval.
     */
    public function allowsWithdrawal(): bool
    {
        return $this->isActionable();
    }

    /**
     * Valid transitions for an instance or node in this status.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending    => [self::InProgress, self::Withdrawn],
            self::InProgress => [self::Approved, self::Rejected, self::Withdrawn, self::Expired],
            default          => [],  // All terminal states have no outbound transitions
        };
    }
}
